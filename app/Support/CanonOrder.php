<?php

namespace App\Support;

/**
 * The homepage canon order, flattened to a lookup.
 *
 * config/canon.php is the single source of truth for how books are ORDERED
 * and grouped for display. It speaks in slugs and nests books inside sections
 * (and sometimes subgroups). This class walks that structure once, in display
 * order, and hands back a flat  slug => position  map.
 *
 * HEADed uses it instead of Book.book_order because the heading TSV is edited
 * in this homepage order, which deliberately differs from the scholarly
 * book_order seeded in the DB (First-Testament pseudepigrapha display up front
 * but are numbered after the NT; the apostolic apocrypha are ordered
 * differently again). One source, no drift: edit canon.php and this follows.
 */
class CanonOrder
{
    /**
     * @return array<string,int>  book slug => 0-based canon position
     */
    public static function slugOrder(): array
    {
        $order = [];
        $pos   = 0;

        $testaments = config('canon.testaments', []);
        $sections   = config('canon.sections', []);

        foreach ($testaments as $t) {
            foreach ($t['sections'] ?? [] as $sectionKey) {
                $section = $sections[$sectionKey] ?? null;
                if (! $section) {
                    continue;
                }

                if (isset($section['books'])) {
                    foreach ($section['books'] as $slug) {
                        if (! isset($order[$slug])) {
                            $order[$slug] = $pos++;
                        }
                    }
                }

                foreach ($section['subgroups'] ?? [] as $group) {
                    foreach ($group['books'] ?? [] as $slug) {
                        if (! isset($order[$slug])) {
                            $order[$slug] = $pos++;
                        }
                    }
                }
            }
        }

        return $order;
    }
}