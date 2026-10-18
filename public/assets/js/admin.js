/* Waimart admin behaviour (CSP-safe, no inline handlers). */
(function () {
    'use strict';

    // (Confirmation prompts for form[data-confirm] are handled in app.js, loaded first.)

    // Chart tooltips: same details on hover and keyboard focus; labels inserted as text, never HTML.
    document.querySelectorAll('[data-chart]').forEach(function (fig) {
        var tip = fig.querySelector('.wm-chart-tip');
        var svg = fig.querySelector('svg');
        function show(col) {
            var box = col.querySelector('.wm-chart-bar') || col.querySelector('.wm-chart-hit');
            var r = box.getBoundingClientRect();
            var f = fig.getBoundingClientRect();
            tip.querySelector('[data-tip-v]').textContent = col.getAttribute('data-tip-value');
            tip.querySelector('[data-tip-t]').textContent = col.getAttribute('data-tip-title');
            tip.querySelector('[data-tip-s]').textContent = col.getAttribute('data-tip-sub');
            tip.hidden = false;
            var left = r.left - f.left + r.width / 2;
            left = Math.max(70, Math.min(f.width - 70, left));
            tip.style.left = left + 'px';
            tip.style.top = Math.max(48, r.top - f.top - 6) + 'px';
        }
        function hide() { tip.hidden = true; }
        fig.querySelectorAll('.wm-chart-col').forEach(function (col) {
            col.addEventListener('pointerenter', function () { show(col); });
            col.addEventListener('focus', function () { show(col); });
            col.addEventListener('pointerleave', hide);
            col.addEventListener('blur', hide);
        });
        if (svg) { svg.addEventListener('pointerleave', hide); }
    });

    // Prevent double submission of admin forms (the server is the real safeguard).
    document.querySelectorAll('form[method="post"]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) { return; }
            if (form.dataset.submitted === '1') { e.preventDefault(); return; }
            form.dataset.submitted = '1';
            // Disable on the next tick (defensive): some forms send their choice through the clicked
            // button (e.g. outcome=restock). Chromium keeps it either way (verified by
            // tests/Browser/submitter-check.mjs); deferring avoids relying on that engine detail.
            setTimeout(function () {
                form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
            }, 0);
        });
    });
})();
