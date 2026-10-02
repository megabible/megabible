<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * One way to find a source-data file, shared by every import command.
 *
 * Source data lives in storage/app/private (the `local` disk's root in
 * Laravel 11), which is git-ignored and uploaded separately — on the dev
 * machine it is C:\laragon\www\megabible\storage\app\private, on the server
 * it is the Forge-shared /home/forge/megabible.net/storage/app/private that
 * every release symlinks to. Same relative layout in both places.
 *
 * A path argument may be written in any of these forms, tried in order:
 *
 *   1. absolute                      /home/forge/x/y.tsv   C:\data\y.tsv
 *   2. relative to the project root  storage/app/private/verses/eng-web
 *   3. relative to the private disk  verses/eng-web
 *   4. relative to storage/app       (legacy animation-command form)
 *
 * Returns the first candidate that exists (file OR directory), or null.
 * Callers decide whether a directory is acceptable; this only locates it.
 */
final class DataPath
{
    public static function resolve(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        foreach (self::candidates($raw) as $candidate) {
            if (file_exists($candidate)) {
                return rtrim($candidate, '/\\');
            }
        }

        return null;
    }

    /** Every location resolve() will look in, for error messages. */
    public static function candidates(string $raw): array
    {
        if (self::isAbsolute($raw)) {
            return [$raw];
        }

        $rel = ltrim($raw, '/\\');

        return [
            base_path($rel),
            Storage::path($rel),            // storage/app/private/...
            storage_path('app/' . $rel),    // storage/app/...
        ];
    }

    /** Explain a miss: "Not found: x — looked in: a, b, c". */
    public static function notFound(string $raw): string
    {
        return "Not found: {$raw} — looked in: " . implode(', ', self::candidates($raw));
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
