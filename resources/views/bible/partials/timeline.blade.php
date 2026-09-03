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
                    <div class="tl-row {{ $b['current'] ? 'current' : '' }}">
                            {{-- tl-fix r4: full + short labels; CSS swaps
                                 them at the mobile breakpoint. --}}
                            <div class="tl-book">
                                @if ($b['url'] && ! $b['current'])
                                    <a class="tl-name" href="{{ $b['url'] }}"><span class="tl-name-full">{{ $b['label'] }}</span><span class="tl-name-short">{{ $b['short'] ?? $b['label'] }}</span></a>
                                @else
                                    <span class="tl-name"><span class="tl-name-full">{{ $b['label'] }}</span><span class="tl-name-short">{{ $b['short'] ?? $b['label'] }}</span></span>
                                @endif
                            </div>
                        <div class="tl-track">
                            @foreach ($b['segments'] as $seg)
                                <div class="tl-bar" title="{{ $seg['tooltip'] }}"
                                     style="left: {{ $seg['left'] }}%; width: {{ $seg['width'] }}%; background: var(--tl-{{ $seg['color'] }});">
                                    @if (! empty($seg['label']))
                                        <span class="tl-seg-label">{{ $seg['label'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                            @if (! empty($b['date_display']))
                                <div class="tl-bar-date" style="left: {{ $b['date_pos'] }}%;">{{ $b['date_display'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Event names — BELOW the bottom markers --}}
            @if (! empty($timeline['events']))
                <div class="tl-events">
                    <div></div>
                    <div class="tl-events-scale">
                        @foreach ($timeline['events'] as $e)
                            <span class="tl-event-label" style="left: {{ $e['pos'] }}%;">
                                {{ $e['label'] }}
                                @if (! empty($e['date_display']))
                                    <span class="tl-event-date">{{ $e['date_display'] }}</span>
                                @endif
                            </span>
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
         overlap downward. Re-runs on resize, since % → px shifts the gaps. --}}
    <script>
    (function () {
        const scale = document.querySelector('.tl-events-scale');
        if (!scale) return;

        const labels = Array.from(scale.querySelectorAll('.tl-event-label'));
        if (labels.length === 0) return;

        // Horizontal breathing room before two labels count as colliding.
        // Read from CSS so it lives next to the other --tl-* knobs.
        const gap = parseFloat(getComputedStyle(scale).getPropertyValue('--tl-event-gap')) || 8;

        function layout() {
            // Lane height = tallest wrapped label + a little vertical gap.
            // Derived, not hard-coded, so changing --tl-event-label-w just works.
            const laneH = Math.max(...labels.map(l => l.offsetHeight)) + 4;

            // Measure each label's rendered left/right edge relative to the
            // track. getBoundingClientRect() already accounts for translateX(-50%).
            const scaleLeft = scale.getBoundingClientRect().left;
            const items = labels
                .map(el => {
                    const r = el.getBoundingClientRect();
                    return { el, left: r.left - scaleLeft, right: r.right - scaleLeft };
                })
                .sort((a, b) => a.left - b.left);

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
            title tooltip still carries the text).
         2. tl-fix r4: the LAST tick of each axis (the only one wearing the
            BC/AD era) is measured against the chart's right edge; if its
            centred label would clip under .tl-chart's overflow:hidden, it
            gets .is-clamped and right-anchors inside instead. Reset before
            each measure so growing the window un-clamps it again. --}}
    <script>
    (function () {
        const chart = document.querySelector('.tl-chart');
        if (!chart) return;

        const labels    = Array.from(chart.querySelectorAll('.tl-seg-label'));
        const lastTicks = Array.from(chart.querySelectorAll('.tl-axis-scale'))
            .map(s => s.querySelector('.tl-tick:last-child'))
            .filter(Boolean);

        function fit() {
            labels.forEach(el => {
                el.style.visibility = 'visible';            // reset before measuring
                if (el.scrollWidth > el.clientWidth + 0.5) {
                    el.style.visibility = 'hidden';        // too narrow → rely on tooltip
                }
            });

            const edge = chart.getBoundingClientRect().right;
            lastTicks.forEach(t => {
                t.classList.remove('is-clamped');           // measure centred
                if (t.getBoundingClientRect().right > edge - 1) {
                    t.classList.add('is-clamped');
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
@endif