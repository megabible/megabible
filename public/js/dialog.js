/* ======================================================================
   PSEUDO-SYSTEM DIALOGS                              public/js/dialog.js
   ----------------------------------------------------------------------
   A themed replacement for window.alert / window.confirm. A full-screen
   scrim dims the site; a card sits on top, repainting with the active
   theme (every colour is a CSS custom property). Loaded globally from
   app.blade.php so ANY page can call it — the reader's Focus FAB, the
   Pericope sheet, the Acts record page, and anything later.

   Extracted verbatim from acts-of-the-user.blade.php (Phase 1, step 1a),
   where it first lived as a page-local IIFE. The behaviour is unchanged;
   only its home moved, so it can be shared instead of copied. The CSS it
   needs (.mb-dialog*) moved into app.blade.php's global <style>.

   Public surface (attached to window):

     mbNotify(lines, opts)  — a notice. Promise<true>. One OK button, unless
                              opts.autoReload (then no buttons; caller reloads).
                              opts.check draws a success tick after the last
                              line; opts.okLabel renames the button.

     mbConfirm(lines, opts) — a question. Promise<boolean>: true to proceed,
                              false for Cancel / Escape / backdrop.
                              opts.confirmLabel / opts.cancelLabel name buttons.

     mbDialog.open(cfg)     — the low-level builder both wrap. cfg = {lines,
                              check, buttons:[{label,value,primary}], cancelValue}.

   `lines` is an array of paragraph strings; the first renders as the heading.
   Escape and a backdrop click always take the SAFE exit — dismiss a notice,
   CANCEL a question — so a stray click never overwrites or erases anything.
   ====================================================================== */
(function () {
    'use strict';

    // Local HTML-escaper (was shared with the Acts feed; dialog.js carries its
    // own copy so it has no external dependency). textContent round-trip.
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    var mbDialog = (function () {
        var lockCount = 0, savedY = 0;

        // Body-lock while an overlay is open, using the site's fixed-body
        // pattern so iOS Safari can't scroll behind the scrim. A counter keeps
        // it steady if one dialog chains into another.
        function lock() {
            if (lockCount++ > 0) return;
            savedY = window.scrollY || window.pageYOffset || 0;
            var s = document.body.style;
            s.position = 'fixed'; s.top = (-savedY) + 'px';
            s.left = '0'; s.right = '0'; s.width = '100%';
        }
        function unlock() {
            if (--lockCount > 0) return;
            lockCount = 0;
            var s = document.body.style;
            s.position = ''; s.top = ''; s.left = ''; s.right = ''; s.width = '';
            window.scrollTo(0, savedY);
        }

        // Build + show one overlay; resolve with the chosen button's value,
        // or cfg.cancelValue on Escape / backdrop.
        function open(cfg) {
            return new Promise(function (resolve) {
                var scrim = document.createElement('div');
                scrim.className = 'mb-dialog-scrim';
                scrim.setAttribute('role', 'dialog');
                scrim.setAttribute('aria-modal', 'true');

                var card = document.createElement('div');
                card.className = 'mb-dialog';

                // Message lines; the tick (if any) rides inline after the last.
                var last = cfg.lines.length - 1;
                var msgHtml = cfg.lines.map(function (ln, i) {
                    var tick = (cfg.check && i === last)
                        ? ' <span class="mb-dialog-check" aria-hidden="true">\u2713</span>'
                        : '';
                    return '<p>' + esc(ln) + tick + '</p>';
                }).join('');
                var msg = document.createElement('div');
                msg.className = 'mb-dialog-msg';
                msg.innerHTML = msgHtml;
                card.appendChild(msg);

                var closed = false;
                function finish(val) {
                    if (closed) return;
                    closed = true;
                    document.removeEventListener('keydown', onKey);
                    scrim.classList.remove('is-open');
                    setTimeout(function () {          // let the fade-out play
                        if (scrim.parentNode) scrim.parentNode.removeChild(scrim);
                        unlock();
                        resolve(val);
                    }, 140);
                }

                if (cfg.buttons.length) {
                    var row = document.createElement('div');
                    row.className = 'mb-dialog-buttons';
                    cfg.buttons.forEach(function (b) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'mb-dialog-btn' + (b.primary ? ' is-primary' : ' is-ghost');
                        btn.textContent = b.label;
                        btn.addEventListener('click', function () { finish(b.value); });
                        row.appendChild(btn);
                    });
                    card.appendChild(row);
                }

                function onKey(ev) {
                    if (ev.key === 'Escape') { ev.preventDefault(); finish(cfg.cancelValue); }
                }
                scrim.addEventListener('click', function (ev) {
                    if (ev.target === scrim) finish(cfg.cancelValue);   // backdrop = safe exit
                });
                document.addEventListener('keydown', onKey);

                scrim.appendChild(card);
                document.body.appendChild(scrim);
                lock();
                requestAnimationFrame(function () { scrim.classList.add('is-open'); });

                var focusBtn = card.querySelector('.mb-dialog-btn.is-primary')
                            || card.querySelector('.mb-dialog-btn');
                if (focusBtn) focusBtn.focus();
            });
        }

        return { open: open };
    })();

    function mbNotify(lines, opts) {
        opts = opts || {};
        var buttons = opts.autoReload
            ? []
            : [{ label: opts.okLabel || 'OK', value: true, primary: true }];
        return mbDialog.open({
            lines: lines, check: !!opts.check, buttons: buttons,
            cancelValue: true            // dismissing a notice is harmless
        });
    }

    function mbConfirm(lines, opts) {
        opts = opts || {};
        return mbDialog.open({
            lines: lines, check: false,
            buttons: [
                { label: opts.cancelLabel  || 'Cancel', value: false, primary: false },
                { label: opts.confirmLabel || 'OK',     value: true,  primary: true  }
            ],
            cancelValue: false           // Escape / backdrop = do NOT proceed
        });
    }

    // Publish globally. (Guarded so a double-include can't clobber a live ref.)
    window.mbDialog  = window.mbDialog  || mbDialog;
    window.mbNotify  = window.mbNotify  || mbNotify;
    window.mbConfirm = window.mbConfirm || mbConfirm;
})();
