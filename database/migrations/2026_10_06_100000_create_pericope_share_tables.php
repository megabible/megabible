<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERICOPE SHORT LINKS · short-link r1
 *
 * Two tables, deliberately minimal — the whole point is to hold as little
 * as possible:
 *
 *   pericope_shares          one row per minted code (SweetHoneyedEmber).
 *     code                   the vanity code, TitleCase as minted. MySQL's
 *                            default utf8mb4 collation is case-INsensitive,
 *                            so the unique index also makes lookups forgiving
 *                            of hand-typed lowercase.
 *     secret_hash            sha256 of the capability secret handed to the
 *                            minting browser. Possession of the secret IS
 *                            ownership (no accounts): it authorizes re-shares
 *                            and deletion. Stored hashed so a DB leak cannot
 *                            let anyone overwrite or delete links.
 *
 *   pericope_share_versions  one row per shared revision of the board.
 *     version                1, 2, 3… — bare code resolves the LATEST;
 *                            ?v=N pins a revision (pruned pins fall back
 *                            to latest, so a link never dies).
 *     blob                   the frozen p1 share string, verbatim — the
 *                            exact text that used to ride in the URL
 *                            fragment. TEXT (64 KB) comfortably holds the
 *                            32 KB config cap.
 *
 * NOT stored, on purpose: requester IPs, user agents, access counts,
 * last-resolved timestamps. A row is a code, an owner-proof, and board
 * data. Nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pericope_shares', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('secret_hash', 64);
            $table->timestamps();
        });

        Schema::create('pericope_share_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pericope_share_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('blob');
            $table->timestamps();

            $table->unique(['pericope_share_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pericope_share_versions');
        Schema::dropIfExists('pericope_shares');
    }
};
