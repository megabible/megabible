<?php

namespace App\Support;

/**
 * FONTS  ·  readers over config/fonts.php (fonts r1)
 * ---------------------------------------------------------------------------
 * The manifest is the source of truth; this class only shapes it for its
 * three consumers. No filesystem access, no state — safe anywhere.
 *
 *   families($set)    key => family entry, optionally filtered to a named
 *                     set from config('fonts.sets'). An unknown set name
 *                     returns the whole pool rather than nothing: a page
 *                     asking for fonts should never silently get none.
 *   clientPool($set)  the JSON-safe pool a page ships to its scripts:
 *                     [{key, label, family, maxWeight}] — family UNQUOTED
 *                     (the client quotes names that need it; single quotes,
 *                     because these land inside double-quoted style
 *                     attributes — the scroll r3 lesson).
 */
class Fonts
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function families(?string $set = null): array
    {
        $all = config('fonts.families', []);
        if ($set === null) {
            return $all;
        }
        $keys = config("fonts.sets.{$set}");
        if (! is_array($keys) || $keys === []) {
            return $all;
        }
        $out = [];
        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $out[$key] = $all[$key];
            }
        }
        return $out;
    }

    /**
     * @return list<array{key: string, label: string, family: string, maxWeight: int|null}>
     */
    public static function clientPool(?string $set = null): array
    {
        $out = [];
        foreach (self::families($set) as $key => $f) {
            $out[] = [
                'key'       => $key,
                'label'     => $f['label'] ?? $key,
                'family'    => $f['family'] ?? $key,
                'maxWeight' => $f['max_weight'] ?? null,
            ];
        }
        return $out;
    }
}
