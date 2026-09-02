{{--
    Shared reading colophon — the single source of truth for the footer format.

    Renders credit lines, in this order:
      1. Heading sources  → "{n} headings from <SourceName> · {license} · Source: {host}"
      2. Footnote sources → "{n} footnotes from <SourceName> · {license} · Source: {host}"
      3. Editions (texts) → "{n} verses from <TranslationName> · {license} · Source: {host}"

    When credit rows share the same identity (name, license, AND source_url all
    match), they collapse into ONE combined line, e.g.:
      "{n} headings and {m} verses from <Name> · …"
      "{n} footnotes and {m} verses from <Name> · …"
      "{n} headings, {m} footnotes and {k} verses from <Name> · …"
    The merged line renders at the position of its EARLIEST member (headings
    are folded in first, so a heading-bearing merge keeps the heading-line
    position, exactly as before). Identity requires all three fields to match:
    the same display name with a different license or URL is editorially a
    different source and keeps its own line. The common case this serves:
    translator footnotes carry the translation's own identity (WEB notes ARE
    part of the WEB), so note credit and edition credit read as one line
    instead of two near-duplicates.

    Callers pass whatever applies:
      $headingCredits   Collection of ['name','license','source_url','count', ...]
                        Already deduped by the caller — one entry per distinct
                        heading source on the page. Omit / empty for none.
      $footnoteCredits  Collection of ['name','license','source_url','count'].
                        One entry per distinct footnote source on the page
                        (built by BibleController::footnoteData() from
                        footnotes.source_key via config/footnote_sources.php).
                        Omit / empty for none.
      $editions         Collection of ['name','license','source_url','verseCount']
                        One entry per translation shown (1 for chapter, N for parallel).

    Only the <name> is bold. license and Source are optional and are dropped
    cleanly (no stray middots) when a field is missing.
--}}
@php
    // Fold all inputs into one uniform row list so the merge is single-pass.
    // Push order = render order for unmerged rows, and decides where a merged
    // row lands (its earliest member's position).
    $rows = collect();

    foreach (($headingCredits ?? collect()) as $c) {
        $rows->push([
            'unit'       => 'heading',
            'count'      => (int) ($c['count'] ?? 0),
            'name'       => $c['name'] ?? '',
            'license'    => $c['license'] ?? null,
            'source_url' => $c['source_url'] ?? null,
        ]);
    }

    foreach (($footnoteCredits ?? collect()) as $c) {
        $rows->push([
            'unit'       => 'footnote',
            'count'      => (int) ($c['count'] ?? 0),
            'name'       => $c['name'] ?? '',
            'license'    => $c['license'] ?? null,
            'source_url' => $c['source_url'] ?? null,
        ]);
    }

    foreach (($editions ?? collect()) as $e) {
        $rows->push([
            'unit'       => 'verse',
            'count'      => (int) ($e['verseCount'] ?? 0),
            'name'       => $e['name'] ?? '',
            'license'    => $e['license'] ?? null,
            'source_url' => $e['source_url'] ?? null,
        ]);
    }

    // Merge pass: group rows by identity (name + license + source_url). Each
    // group becomes one line carrying a unit=>count map; groups keep the
    // position of their earliest member. This generalises the old pairwise
    // heading↔verse merge to any mix of heading / footnote / verse rows.
    $identity = fn ($r) => $r['name'] . '|' . ($r['license'] ?? '') . '|' . ($r['source_url'] ?? '');

    $groups = [];   // identity => ['base' => first row seen, 'units' => [unit => count]]
    $order  = [];   // identities in first-appearance order
    foreach ($rows as $r) {
        $id = $identity($r);
        if (! isset($groups[$id])) {
            $groups[$id] = ['base' => $r, 'units' => []];
            $order[]     = $id;
        }
        $groups[$id]['units'][$r['unit']] = ($groups[$id]['units'][$r['unit']] ?? 0) + $r['count'];
    }

    $rows = collect();
    foreach ($order as $id) {
        $g = $groups[$id];
        $rows->push([
            'units'      => $g['units'],
            'name'       => $g['base']['name'],
            'license'    => $g['base']['license'],
            'source_url' => $g['base']['source_url'],
        ]);
    }

    // Fixed unit order inside a combined line, matching the section order of
    // the page itself: headings, then footnotes, then the text.
    $unitOrder = ['heading', 'footnote', 'verse'];
@endphp

@foreach ($rows as $r)
    @php
        // Build the "· license · Source: host" tail so separators only appear
        // between parts that actually exist.
        $parts = [];
        if (! empty($r['license'])) {
            $parts[] = e($r['license']);
        }
        if (! empty($r['source_url'])) {
            $host = parse_url($r['source_url'], PHP_URL_HOST);
            $parts[] = 'Source: ' . ($host
                ? '<a href="' . e($r['source_url']) . '" rel="noopener" target="_blank">' . e($host) . '</a>'
                : e($r['source_url']));
        }
        $tail = $parts ? ' · ' . implode(' · ', $parts) : '';

        // "3 footnotes" / "2 headings and 25 verses" /
        // "2 headings, 3 footnotes and 25 verses" — comma-joined with a plain
        // "and" before the last segment, matching the previous combined style.
        $segments = [];
        foreach ($unitOrder as $u) {
            if (isset($r['units'][$u])) {
                $n = $r['units'][$u];
                $segments[] = $n . ' ' . ($n === 1 ? $u : $u . 's');
            }
        }
        $lead = count($segments) > 1
            ? implode(', ', array_slice($segments, 0, -1)) . ' and ' . end($segments)
            : ($segments[0] ?? '');

        // CSS hook: the single unit's own class, or 'combined' for merges —
        // same classes the previous version emitted, plus colophon-footnote.
        $unitClass = count($r['units']) === 1 ? array_key_first($r['units']) : 'combined';
    @endphp
    <div class="colophon-line colophon-{{ $unitClass }}">{!! e($lead) !!} from <strong>{{ $r['name'] }}</strong>{!! $tail !!}</div>
@endforeach