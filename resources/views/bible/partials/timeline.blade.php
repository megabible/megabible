{{--
  ===========================================================================
  tl-part r3 — TIMELINE markup + scripts (the book hub's Gantt chart)
  ---------------------------------------------------------------------------
  Pairs with bible/partials/timeline-styles (which the page must @include
  inside its <style> block). Include this one on the scrolling surface:
  @include('bible.partials.timeline') — the null-guard lives here, so the
  page includes it unconditionally and nothing renders when the controller
  returned no timeline.

  Expects:
    $timeline   the array BibleController::buildTimeline returns, or null:
                ['ticks','events','legend','books','text'] with all geometry
                (pos/left/width percentages) pre-computed. This partial is a
                pure painter — no math here.

  ONE PER PAGE: both scripts find their targets with document.querySelector
  (singular), so a page must never include this twice. Fine for the book
  hub; revisit the selectors if a timeline ever appears twice on a page.
  ===========================================================================
--}}
@if ($timeline)
    <h2>Timeline</h2>
    <div class="tl">

        @if (! empty($timeline['text']))
            <p class="tl-text">{{ $timeline['text'] }}</p>
        @endif

        {{-- LEGEND BAND — sits above the chart, full reading-column width.
             Never scrolls; wraps to a new line whenever it runs out of room. --}}
        @if (! empty($timeline['legend']))
            <div class="tl-legend">
                @foreach ($timeline['legend'] as $g)
                    <span><i style="background: var(--tl-{{ $g['color'] }});"></i>{{ $g['label'] }}</span>
                @endforeach
            </div>
        @endif

        {{-- CHART ZONE — fixed book-name column + fluid gantt track.
             No horizontal scroll at any width; only the track shrinks. --}}
        <div class="tl-chart">

            {{-- Date markers along the TOP --}}
            <div class="tl-axis">
                <div></div>
                <div class="tl-axis-scale">
                    @foreach ($timeline['ticks'] as $t)
                        <span class="tl-tick" style="left: {{ $t['pos'] }}%;">{{ $t['label'] }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Body: grid lines, event lines, and one row per book --}}
            <div class="tl-body">
                <div class="tl-grid">
                    @foreach ($timeline['ticks'] as $t)
                        <div class="tl-gridline" style="left: {{ $t['pos'] }}%;"></div>
                    @endforeach
                    @foreach ($timeline['events'] as $e)
                        <div class="tl-event" style="left: {{ $e['pos'] }}%;"></div>
                    @endforeach
                </div>

                @foreach ($timeline['books'] as $b)
                    {{-- tl-fix r5: the current row carries its highlight's
                         right edge as a CSS variable — the bar's end as a
                         0–1 fraction of the track, from buildTimeline. --}}
                    <div class="tl-row {{ $b['current'] ? 'current' : '' }}"
                         @if ($b['current'] && $timeline['hl_end'] !== null) style="--tl-hl-end: {{ $timeline['hl_end'] }};" @endif>
                            {{-- tl-fix r7: up to three labels — full
                                 (default), mid (desktop, when the fit pass
                                 tags .is-mid), short (mobile block swaps it
                                 in). Spans stay glued: whitespace between
                                 them would render as a stray space. --}}
                            <div class="tl-book">
                                @if ($b['url'] && ! $b['current'])
                                    <a class="tl-name" href="{{ $b['url'] }}"><span class="tl-name-full">{{ $b['label'] }}</span>@if ($b['mid'])<span class="tl-name-mid">{{ $b['mid'] }}</span>@endif<span class="tl-name-short">{{ $b['short'] ?? $b['label'] }}</span></a>
                                @else
                                    <span class="tl-name"><span class="tl-name-full">{{ $b['label'] }}</span>@if ($b['mid'])<span class="tl-name-mid">{{ $b['mid'] }}</span>@endif<span class="tl-name-short">{{ $b['short'] ?? $b['label'] }}</span></span>
                                @endif
                            </div>
                        <div class="tl-track">
                            @foreach ($b['segments'] as $seg)
                                {{-- tl-fix r10: no title attribute (that was
                                     the system tooltip). The popover reads its
                                     content from the data-tl-* attributes;
                                     tabindex + role make each bar a keyboard
                                     button, same as the hub's .orig-word. --}}
                                <div class="tl-bar" tabindex="0" role="button" aria-expanded="false"
                                     aria-label="{{ $b['label'] }}: {{ $seg['range'] }}"
                                     data-tl-book="{{ $b['label'] }}"
                                     data-tl-label="{{ $seg['label'] }}"
                                     data-tl-full="{{ $seg['full'] }}"
                                     data-tl-range="{{ $seg['range'] }}"
                                     style="left: {{ $seg['left'] }}%; width: {{ $seg['width'] }}%; background: var(--tl-{{ $seg['color'] }});">
                                    @if (! empty($seg['label']))
                                        <span class="tl-seg-label">{{ $seg['label'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Event names — BELOW the bottom markers --}}
            @if (! empty($timeline['events']))
                <div class="tl-events">
                    <div></div>
                    <div class="tl-events-scale">
                        {{-- tl-fix r9: connector lines FIRST, labels after.
                             Tree order is paint order, so every label sits
                             on top of every connector, and a line that has
                             to pass through a higher lane ducks behind that
                             lane's text. The two loops walk the same array,
                             so connector i belongs to label i (the script
                             relies on that pairing). --}}
                        @foreach ($timeline['events'] as $e)
                            <div class="tl-event-conn" style="left: {{ $e['pos'] }}%;"></div>
                        @endforeach
                        @foreach ($timeline['events'] as $e)
                            <span class="tl-event-label" style="left: {{ $e['pos'] }}%;"><span class="tl-event-name">{{ $e['label'] }}</span>@if (! empty($e['date_display']))<span class="tl-event-date">{{ $e['date_display'] }}</span>@endif</span>
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    </div>

    {{-- Stack event labels that would otherwise overlap into separate
         vertical lanes. The labels are positioned by % left, so a fixed
         width alone can't prevent collisions when events sit close together
         (e.g. Genesis); this measures the rendered boxes and bumps any
         overlap downward. Re-runs on resize, since % → px shifts the gaps.
         tl-fix r9: also sizes each label's connector, the dashed line that
         carries its event line from the chart body down to a bumped label. --}}
    <script>
    (function () {
        const scale = document.querySelector('.tl-events-scale');
        if (!scale) return;

        const labels = Array.from(scale.querySelectorAll('.tl-event-label'));
        if (labels.length === 0) return;

        // tl-fix r9: connector i pairs with label i (same loop, same order
        // in the partial). The body is where the grid's event lines end.
        const conns = Array.from(scale.querySelectorAll('.tl-event-conn'));
        const chart = scale.closest('.tl-chart');
        const body  = chart ? chart.querySelector('.tl-body') : null;

        // Dash period of the event lines: 4px ink + 4px gap. Must match the
        // gradients on .tl-event and .tl-event-conn in timeline-styles.
        const DASH = 8;

        // Horizontal breathing room before two labels count as colliding.
        // Read from CSS so it lives next to the other --tl-* knobs.
        const gap = parseFloat(getComputedStyle(scale).getPropertyValue('--tl-event-gap')) || 8;

        function layout() {
            // Lane height = tallest wrapped label + a little vertical gap.
            // Derived, not hard-coded, so changing --tl-event-label-w just works.
            const laneH = Math.max(...labels.map(l => l.offsetHeight)) + 4;

            // Measure each label's rendered left/right edge relative to the
            // track. getBoundingClientRect() already accounts for translateX(-50%).
            // tl-fix r9: keep each label's connector alongside it through
            // the sort, so the pairing survives reordering.
            const scaleRect = scale.getBoundingClientRect();
            const items = labels
                .map((el, i) => {
                    const r = el.getBoundingClientRect();
                    return {
                        el,
                        conn:  conns[i] || null,
                        left:  r.left  - scaleRect.left,
                        right: r.right - scaleRect.left,
                    };
                })
                .sort((a, b) => a.left - b.left);

            // tl-fix r9: connector geometry.
            //   lead  = the gap between the body's bottom edge and lane 0 —
            //           the space every label already keeps above its text.
            //   phase = where the grid's dash pattern stands at the body's
            //           bottom edge, so the connector continues its rhythm
            //           instead of restarting mid-dash.
            // A connector starts at the body's bottom (top: -lead) and runs
            // lane × laneH, ending exactly `lead` above its label — the same
            // breathing room a lane-0 label has. Lane 0 → zero height.
            let lead = 0, phase = 0;
            if (body) {
                const bodyRect = body.getBoundingClientRect();
                lead  = scaleRect.top - bodyRect.bottom;
                phase = bodyRect.height % DASH;
            }

            // Greedy lane packing: each label (left → right) drops into the
            // first lane whose previous occupant ends before this one starts.
            const laneEnds = [];   // rightmost x currently used in each lane
            let lanesUsed = 0;

            items.forEach(item => {
                let lane = 0;
                while (lane < laneEnds.length && laneEnds[lane] + gap > item.left) {
                    lane++;
                }
                laneEnds[lane] = item.right;
                item.el.style.top = (lane * laneH) + 'px';
                lanesUsed = Math.max(lanesUsed, lane + 1);

                if (item.conn) {
                    item.conn.style.top    = (-lead) + 'px';
                    item.conn.style.height = (lane * laneH) + 'px';
                    item.conn.style.backgroundPosition = '0 ' + (-phase) + 'px';
                }
            });

            // Grow the row so stacked lanes aren't clipped by the content below.
            scale.style.height = (lanesUsed * laneH) + 'px';
        }

        // Re-pack whenever the track width changes (resize, rotate).
        let raf = null;
        new ResizeObserver(function () {
            if (raf) cancelAnimationFrame(raf);
            raf = requestAnimationFrame(layout);
        }).observe(scale);
    })();
    </script>

    {{-- Fit pass, re-run on every chart resize:
         1. Seg labels that no longer fit their bar go invisible (the bar's
            popover still carries the text — tl-fix r10).
         2. tl-fix r4: the LAST tick of each axis (the only one wearing the
            BC/AD era) is measured against the chart's right edge; if its
            centred label would clip under .tl-chart's overflow:hidden, it
            gets .is-clamped and right-anchors inside instead. Reset before
            each measure so growing the window un-clamps it again.
         3. tl-fix r8: after clamping, any tick label that would collide
            with the kept tick to its right is hidden (.is-thinned); the
            era-bearing last tick always wins. Gridlines are untouched. --}}
    <script>
    (function () {
        const chart = document.querySelector('.tl-chart');
        if (!chart) return;

        const labels    = Array.from(chart.querySelectorAll('.tl-seg-label'));
        const lastTicks = Array.from(chart.querySelectorAll('.tl-axis-scale'))
            .map(s => s.querySelector('.tl-tick:last-child'))
            .filter(Boolean);

        // tl-fix r8: every tick, grouped per axis, in DOM (left → right)
        // order — the thinning walk needs whole axes, not just the ends.
        const tickSets = Array.from(chart.querySelectorAll('.tl-axis-scale'))
            .map(s => Array.from(s.querySelectorAll('.tl-tick')));
        // Min spacing between labels, from the --tl-* knobs in the styles.
        const tickGap = parseFloat(getComputedStyle(chart).getPropertyValue('--tl-tick-gap')) || 6;

        // tl-fix r7: rows that OWN a mid label (the partial renders the
        // span only when canon.php defines one).
        const names = Array.from(chart.querySelectorAll('.tl-name'))
            .map(n => ({
                el:   n,
                full: n.querySelector('.tl-name-full'),
                mid:  n.querySelector('.tl-name-mid'),
                book: n.closest('.tl-book'),
            }))
            .filter(n => n.full && n.mid && n.book);
        // Which side of the mobile breakpoint is the CSS on? Ask a short
        // span instead of duplicating the media query here.
        const probe = chart.querySelector('.tl-name-short');

        function fit() {
            // tl-fix r7: full name → mid label wherever the full name would
            // ellipsize. Reset first, so growing the window restores fulls;
            // on mobile the reset alone runs (short spans rule there, and
            // the mobile CSS out-ranks a stale .is-mid anyway).
            names.forEach(n => n.el.classList.remove('is-mid'));
            if (probe && getComputedStyle(probe).display === 'none') {
                names.forEach(n => {
                    // The name is an inline box, so its rect reports the
                    // text's LAID-OUT width even while ellipsis paints it
                    // shorter. Compare against the column's content width
                    // (clientWidth minus the gutter padding): the swap fires
                    // as soon as a name would intrude on the gutter, not
                    // only once it reaches the bars.
                    const avail = n.book.clientWidth
                        - parseFloat(getComputedStyle(n.book).paddingRight);
                    if (n.full.getBoundingClientRect().width > avail + 0.5) {
                        n.el.classList.add('is-mid');
                    }
                });
            }

            labels.forEach(el => {
                el.style.visibility = 'visible';            // reset before measuring
                if (el.scrollWidth > el.clientWidth + 0.5) {
                    el.style.visibility = 'hidden';        // too narrow → rely on the popover
                }
            });

            const edge = chart.getBoundingClientRect().right;
            lastTicks.forEach(t => {
                t.classList.remove('is-clamped');           // measure centred
                if (t.getBoundingClientRect().right > edge - 1) {
                    t.classList.add('is-clamped');
                }
            });

            // tl-fix r8: thin colliding tick labels. Runs AFTER clamping,
            // because clamping moves the last tick left — that shift is
            // exactly what caused the "160/180 AD" pile-up on phones.
            // Walk right → left: the last tick (the one wearing BC/AD) is
            // always kept; each tick to its left survives only if its
            // right edge clears the nearest KEPT tick by --tl-tick-gap.
            // Hiding is visibility-only, so it doesn't shift anything
            // and the measurements stay valid mid-walk.
            tickSets.forEach(ticks => {
                ticks.forEach(t => t.classList.remove('is-thinned'));
                let keptLeft = Infinity;
                for (let i = ticks.length - 1; i >= 0; i--) {
                    const r = ticks[i].getBoundingClientRect();
                    if (r.right + tickGap > keptLeft) {
                        ticks[i].classList.add('is-thinned');
                    } else {
                        keptLeft = r.left;
                    }
                }
            });
        }

        let raf = null;
        new ResizeObserver(function () {
            if (raf) cancelAnimationFrame(raf);
            raf = requestAnimationFrame(fit);
        }).observe(chart);
    })();
    </script>

    {{-- tl-fix r10 — SEGMENT POPOVER. Each bar opens a .fn-pop panel with
         its book, segment label, full text, and date range, read from the
         data-tl-* attributes (nothing is fetched). Behaviour is the hub's
         definition popover (xref-popover.js, .orig-word) verbatim:
           desktop — hover opens after a short delay, moving off closes
                     after a grace window; clicking the bar or the panel
                     gives the poke pulse and goes nowhere.
           touch   — tap the bar to open, tap it again or tap away to
                     close; tapping the panel pokes.
           keys    — Enter/Space on a focused bar toggles, Escape closes.
         Speaks the hub's mb:pop-open event (source 'tl'), so opening this
         closes any source/verse/definition panel and vice versa.
         Panel chrome is .fn-pop in book.blade; interiors (.tlp-*) live in
         timeline-styles. All text goes in via textContent. --}}
    <script>
    (function () {
        'use strict';

        var chart = document.querySelector('.tl-chart');
        if (!chart) return;

        /* ---- Knobs: timings match xref-popover.js ---------------------- */
        var SHOW_DELAY = 120;   // ms of hover before the panel opens
        var HIDE_GRACE = 250;   // ms allowed for pointer travel bar → panel
        var MAX_W      = 280;   // panel shrinks to fit, up to this width

        var hoverFine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

        var pop = null;         // the open panel, or null
        var owner = null;       // the bar that opened it
        var showTimer = 0;
        var hideTimer = 0;

        function closePop() {
            clearTimeout(hideTimer);
            clearTimeout(showTimer);
            if (pop) { pop.remove(); pop = null; }
            if (owner) {
                owner.setAttribute('aria-expanded', 'false');
                owner.classList.remove('is-open');
                owner = null;
            }
        }

        function scheduleHide() {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(closePop, HIDE_GRACE);
        }
        function cancelHide() { clearTimeout(hideTimer); }

        // Someone else's panel opened → ours yields. The source guard
        // keeps our own announcement from closing us.
        document.addEventListener('mb:pop-open', function (e) {
            if (!e.detail || e.detail.source !== 'tl') closePop();
        });

        function addLine(parent, cls, text) {
            var el = document.createElement('span');
            el.className = cls;
            el.textContent = text;
            parent.appendChild(el);
            return el;
        }

        function buildPanel(bar) {
            var el = document.createElement('div');
            el.className = 'fn-pop tl-pop';

            var label = bar.getAttribute('data-tl-label') || '';
            var full  = bar.getAttribute('data-tl-full')  || '';
            var range = bar.getAttribute('data-tl-range') || '';

            // Header: book name, with the segment label as a badge.
            var head = document.createElement('div');
            head.className = 'tlp-head';
            addLine(head, 'tlp-book', bar.getAttribute('data-tl-book') || '');
            if (label) addLine(head, 'tlp-label', label);
            el.appendChild(head);

            // Full text, unless it would just repeat the badge.
            if (full && full !== label) addLine(el, 'tlp-full', full);
            if (range) addLine(el, 'tlp-range', range);

            // The grace-travel contract: the pointer may cross the gap
            // between bar and panel without losing it.
            el.addEventListener('mouseenter', cancelHide);
            el.addEventListener('mouseleave', scheduleHide);

            return el;
        }

        function openPop(bar) {
            closePop();
            var el = buildPanel(bar);

            // Shrink-to-fit: park the panel at the page origin with a max
            // width, let the browser size it, then read the result. (Parked
            // first so its available width isn't squeezed by a stale left.)
            var vw = document.documentElement.clientWidth;
            el.style.left = '0px';
            el.style.top  = '0px';
            el.style.maxWidth = Math.min(MAX_W, vw - 16) + 'px';
            document.body.appendChild(el);
            // tl-fix r10.1: measure the fractional width and round UP.
            // offsetWidth rounds to the nearest pixel, and pinning a panel
            // half a pixel narrower than its text wraps the book name.
            var w = Math.ceil(el.getBoundingClientRect().width);
            el.style.width = w + 'px';
            var h = el.offsetHeight;

            pop = el;
            owner = bar;
            bar.setAttribute('aria-expanded', 'true');
            bar.classList.add('is-open');

            // Position: the source-popover math — centred above the bar,
            // viewport-clamped, flipped below when there's no headroom,
            // chevron tracking the bar's centre even when clamped.
            var r = bar.getBoundingClientRect();
            var left = r.left + r.width / 2 - w / 2 + window.scrollX;
            left = Math.max(window.scrollX + 8,
                   Math.min(left, window.scrollX + vw - w - 8));
            var below = r.top < h + 18;
            el.classList.toggle('is-below', below);
            el.style.left = left + 'px';
            el.style.top  = (below
                ? r.bottom + window.scrollY + 10
                : r.top    + window.scrollY - h - 10) + 'px';
            el.style.setProperty('--chev-x',
                (r.left + r.width / 2 + window.scrollX - left) + 'px');

            document.dispatchEvent(new CustomEvent('mb:pop-open', {
                detail: { source: 'tl' },
            }));
        }

        // The click acknowledgement: restart the .is-poked pulse (remove,
        // reflow, re-add — the reflow read lets it replay on every click).
        function poke(el) {
            el.classList.remove('is-poked');
            void el.offsetWidth;
            el.classList.add('is-poked');
        }

        /* ---- Desktop: hover opens, moving off closes ------------------- */
        if (hoverFine) {
            document.addEventListener('mouseover', function (e) {
                var bar = e.target.closest('.tl-bar');
                if (bar) {
                    cancelHide();
                    clearTimeout(showTimer);
                    if (owner === bar) return;
                    showTimer = setTimeout(function () { openPop(bar); }, SHOW_DELAY);
                    return;
                }
                if (e.target.closest('.tl-pop')) return;
                clearTimeout(showTimer);
                if (pop) scheduleHide();
            });
        }

        /* ---- Clicks: poke, toggle, or dismiss -------------------------- */
        document.addEventListener('click', function (e) {
            // The panel is terminal: a click goes nowhere and just pulses.
            var panel = e.target.closest('.tl-pop');
            if (panel) { poke(panel); return; }

            var bar = e.target.closest('.tl-bar');
            if (bar) {
                if (owner === bar && pop) {
                    // Desktop: hover owns open/close, so a click is a dead
                    // end like the panel. Touch: a second tap closes.
                    if (hoverFine) { poke(pop); } else { closePop(); }
                } else {
                    openPop(bar);   // also covers a click that beats the hover delay
                }
                return;
            }

            if (pop) closePop();    // tap-away (mobile's only close gesture)
        });

        /* ---- Keyboard -------------------------------------------------- */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { if (pop) closePop(); return; }
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var bar = e.target && e.target.closest && e.target.closest('.tl-bar');
            if (!bar) return;
            e.preventDefault();
            if (owner === bar) { closePop(); } else { openPop(bar); }
        });
    })();
    </script>
@endif