{{--
    hub-src r2 — Source-marker hover popover (desktop only), for the book hub.

    A near-verbatim sibling of bible/partials/footnote-popover: hovering a
    superscript .src-marker floats the source citation in a panel with a
    chevron pointing at the letter. Clicking the panel is identical to
    clicking the letter itself — it calls .click() on the real marker, so
    the native #source-{slug} jump (and the .source-item:target highlight)
    supplies the behaviour. No behaviour lives here; presentation only.

    Content is cloned from the already-rendered bibliography row (marker
    href "#source-{slug}" → that row's .src-line, minus the letter badge),
    so there is no fetching and no duplicated markup. Panel styles: the
    .fn-pop block copied into book.blade's styles.

    Gated on hover-capable fine pointers, so touch devices never build any
    of it — a tap on the letter just jumps to the bibliography, same as the
    reader. Include once, inside @section('scripts').
--}}
<script>
    (function () {
        if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

        let pop = null, popMarker = null, showTimer = 0, hideTimer = 0;

        const hidePop = () => {
            clearTimeout(showTimer);
            if (pop) { pop.remove(); pop = null; popMarker = null; }
        };

        // Keep the panel while the pointer travels marker → panel.
        const scheduleHide = () => {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(hidePop, 250);
        };
        const cancelHide = () => clearTimeout(hideTimer);

        const showPop = (marker) => {
            if (popMarker === marker) { cancelHide(); return; }
            hidePop();

            // "#source-{slug}" → the bibliography row; clone its line minus
            // the letter badge.
            const id  = marker.getAttribute('href').slice(1);
            const row = document.getElementById(id);
            if (!row) return;
            const src = row.querySelector('.src-line');
            if (!src) return;
            const line = src.cloneNode(true);
            line.querySelectorAll('.src-letter').forEach(s => s.remove());

            pop = document.createElement('div');
            pop.className = 'fn-pop';
            pop.appendChild(line);
            document.body.appendChild(pop);
            popMarker = marker;

            // Position: centred above the marker, clamped to the viewport;
            // flip below (chevron up) when there's no headroom.
            const r = marker.getBoundingClientRect();
            const w = Math.min(320, document.documentElement.clientWidth - 16);
            pop.style.width = w + 'px';
            const h = pop.offsetHeight;

            let left = r.left + r.width / 2 - w / 2 + window.scrollX;
            left = Math.max(window.scrollX + 8,
                   Math.min(left, window.scrollX + document.documentElement.clientWidth - w - 8));
            const below = r.top < h + 18;
            pop.classList.toggle('is-below', below);
            pop.style.left = left + 'px';
            pop.style.top  = (below
                ? r.bottom + window.scrollY + 10
                : r.top    + window.scrollY - h - 10) + 'px';
            // Chevron tracks the marker even when the panel is clamped.
            pop.style.setProperty('--chev-x',
                (r.left + r.width / 2 + window.scrollX - left) + 'px');

            pop.addEventListener('mouseenter', cancelHide);
            pop.addEventListener('mouseleave', scheduleHide);
            pop.addEventListener('click', (ev) => {
                ev.preventDefault();
                ev.stopPropagation();          // the real click comes next
                const m = popMarker;
                hidePop();
                if (m) m.click();              // identical to clicking the letter
            });
        };

        document.addEventListener('mouseover', (e) => {
            const marker = e.target.closest('.src-marker');
            if (marker) {
                cancelHide();
                clearTimeout(showTimer);
                showTimer = setTimeout(() => showPop(marker), 120);
                return;
            }
            if (pop && !e.target.closest('.fn-pop')) scheduleHide();
        });

        // A marker click already navigates (and re-anchors the page), so the
        // panel would linger over the new scroll position — drop it.
        document.addEventListener('click', (e) => {
            if (e.target.closest('.src-marker')) hidePop();
        });
    })();
</script>