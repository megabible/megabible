<?php

namespace App\Http\Controllers;

use App\Models\PericopeShare;
use App\Models\PericopeShareVersion;
use App\Support\PericopeCode;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * PERICOPE SHORT LINKS · short-link r1
 *
 * The vanity mask over the share system: megabible.net/SweetHoneyedEmber
 * instead of a 1,800-character fragment URL that Discord and Signal
 * mangle. The board still travels as the frozen p1 string from
 * pericope-store.js — this controller just stores that string under a
 * memorable code and serves it back into the same import shell
 * (PericopeController::sharedShell), so decodeShare / importShared /
 * the verse-refetch train are untouched.
 *
 * OWNERSHIP WITHOUT ACCOUNTS. Minting returns a random 128-bit secret
 * alongside the code. The minting browser keeps {code, secret, version}
 * on the board in localStorage; possession of the secret is the only
 * authority to re-share (bump the version) or delete the code. The
 * server stores sha256(secret), so even a leaked database can't forge
 * ownership. Lose the browser storage, lose the ability to update —
 * the code itself keeps serving forever (the eternal-QR rule), and
 * `php artisan pericope:unmint {code}` remains the admin kill switch.
 *
 * VERSIONS. Mint = version 1. Each re-share inserts version N+1 and
 * prunes rows beyond config('pericope_share.max_versions'). The bare
 * code serves the LATEST version; ?v=N pins one, and a pruned pin
 * falls back to latest rather than dying.
 *
 * PRIVACY POSTURE (the deliberate trade, decided Oct 2026): blobs are
 * stored as-is — readable board data including the board name, heading/
 * note text, and group labels. In exchange: no requester IP or UA on
 * any row, no access logging in the table, no gallery, no index, no
 * enumeration surface (random codes in a 5.1M namespace, 404 for
 * unknown ones, X-Robots-Tag noindex on resolution), size caps and
 * shape checks so only plausible boards are ever stored, and throttles
 * on every endpoint. Storage happens ONLY when a person presses
 * "Create short link" — a board never shared never touches the server.
 */
class PericopeShareController extends Controller
{
    /**
     * POST /extras/pericope/share-links  →  { code, secret, version, url }
     *
     * The ONE moment the secret ever exists in plaintext on the wire.
     * The retry loop closes the mint race against the unique index:
     * a duplicate-key insert just draws a new code.
     */
    public function mint(Request $request): JsonResponse
    {
        $data = $request->validate(['blob' => 'required|string']);

        if ($err = $this->blobError($data['blob'])) {
            return response()->json(['error' => $err], 422);
        }

        $secret   = bin2hex(random_bytes(16));
        $attempts = (int) config('pericope_share.mint_attempts', 25);

        for ($try = 0; $try < $attempts; $try++) {
            $code = PericopeCode::mint();

            try {
                $share = DB::transaction(function () use ($code, $secret, $data) {
                    $share = PericopeShare::create([
                        'code'        => $code,
                        'secret_hash' => hash('sha256', $secret),
                    ]);
                    $share->versions()->create([
                        'version' => 1,
                        'blob'    => $data['blob'],
                    ]);

                    return $share;
                });
            } catch (QueryException $e) {
                continue;   // code already minted — draw again
            }

            return response()->json([
                'code'    => $share->code,
                'secret'  => $secret,
                'version' => 1,
                'url'     => route('pericope.short', ['code' => $share->code]),
            ]);
        }

        return response()->json(['error' => 'could not mint a free code'], 500);
    }

    /**
     * PUT /extras/pericope/share-links/{code}  →  { code, version, url }
     *
     * Re-share after edits: same code, next version number, oldest
     * versions pruned past the cap. The returned url carries ?v=N —
     * the "link grows by a number" the sharer copies — while the bare
     * code now serves this newest revision anyway.
     */
    public function reshare(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'blob'   => 'required|string',
            'secret' => 'required|string|max:64',
        ]);

        if ($err = $this->blobError($data['blob'])) {
            return response()->json(['error' => $err], 422);
        }

        $share = PericopeShare::where('code', $code)->first();
        if (! $share) {
            return response()->json(['error' => 'unknown code'], 404);
        }
        if (! hash_equals($share->secret_hash, hash('sha256', $data['secret']))) {
            return response()->json(['error' => 'not the owner of this code'], 403);
        }

        $version = DB::transaction(function () use ($share, $data) {
            $next = (int) $share->versions()->max('version') + 1;

            $share->versions()->create([
                'version' => $next,
                'blob'    => $data['blob'],
            ]);

            // Prune beyond the cap — newest N survive. skip() needs a
            // take(); the cap is small so a generous take is plenty.
            $keep  = max(1, (int) config('pericope_share.max_versions', 10));
            $stale = $share->versions()
                ->orderByDesc('version')
                ->skip($keep)->take(1000)
                ->pluck('id');
            if ($stale->isNotEmpty()) {
                PericopeShareVersion::whereIn('id', $stale)->delete();
            }

            return $next;
        });

        return response()->json([
            'code'    => $share->code,
            'version' => $version,
            'url'     => route('pericope.short', ['code' => $share->code]) . '?v=' . $version,
        ]);
    }

    /**
     * DELETE /extras/pericope/share-links/{code}  →  204
     *
     * The owner's kill switch — fired (best-effort) when the board is
     * deleted locally, honoring "the code dies with the board". Versions
     * cascade with the share row. Idempotent by design: deleting an
     * already-gone code is a quiet 204, so the client never needs to
     * care whether an earlier attempt landed.
     */
    public function unmint(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['secret' => 'required|string|max:64']);

        $share = PericopeShare::where('code', $code)->first();

        if ($share) {
            if (! hash_equals($share->secret_hash, hash('sha256', $data['secret']))) {
                return response()->json(['error' => 'not the owner of this code'], 403);
            }
            $share->delete();
        }

        return response()->json([], 204);
    }

    /**
     * GET /{code}  ·  the vanity URL itself (route pericope.short —
     * registered LAST in web.php so every real route wins first).
     *
     * Looks up the code (case-insensitively, via the column collation),
     * picks the pinned ?v= revision or the latest, and hands the blob to
     * PericopeController::sharedShell — the same shell the fragment
     * links use, which pericope-share.js reads from config when the
     * fragment is empty (short-link r1 edit). Unknown codes 404 into
     * the site's normal not-found page: indistinguishable from a URL
     * that never existed, so codes can't be probed for history.
     */
    public function resolve(Request $request, string $code): Response
    {
        $share = PericopeShare::where('code', $code)->first();
        abort_if(! $share, 404);

        $row = null;
        $pin = (int) $request->query('v', 0);
        if ($pin > 0) {
            $row = $share->versions()->where('version', $pin)->first();
        }
        $row = $row ?? $share->latestVersion();   // pruned pin → latest
        abort_if(! $row, 404);                     // unreachable in practice

        return app(PericopeController::class)->sharedShell($row->blob);
    }

    /**
     * Shape check for an incoming blob — NOT a parse. The client's
     * decodeShare() stays the one true validator (strict, versioned,
     * frozen); the server only refuses things that are plainly not a
     * p1 share string, so the table can never become a free pastebin:
     *
     *   · byte cap from config
     *   · 'p1!' prefix (a NEW format version will be a deliberate
     *     server change too — p2 support means touching this line)
     *   · exactly 4 !-separated sections (literal '!' inside text is
     *     escaped as %21 by shEsc, so the count is unambiguous)
     *   · the encodeShare output alphabet only: encodeURIComponent's
     *     unreserved set plus the format's own delimiters . ; , ! %
     */
    private function blobError(string $blob): ?string
    {
        if (strlen($blob) > (int) config('pericope_share.max_blob_bytes', 32768)) {
            return 'board too large to short-link';
        }
        if (! str_starts_with($blob, 'p1!')) {
            return 'unrecognized share format';
        }
        if (substr_count($blob, '!') !== 3) {
            return 'malformed share string';
        }
        if (! preg_match("/^[A-Za-z0-9\\-_.!~*'()%;,]+$/", $blob)) {
            return 'illegal characters in share string';
        }

        return null;
    }
}
