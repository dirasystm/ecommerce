/* Basemall admin behaviour (CSP-safe, no inline handlers). */
(function () {
    'use strict';

    // (Confirmation prompts for form[data-confirm] are handled in app.js, loaded first.)

    // Wide tables that scroll sideways must be reachable by keyboard (WCAG 2.1.1; audit A-5): focusable, labelled region.
    function keyboardScroll() {
        document.querySelectorAll('.table-responsive').forEach(function (box) {
            var scrolls = box.scrollWidth > box.clientWidth + 1;
            if (scrolls && !box.hasAttribute('tabindex')) {
                var cap = box.querySelector('caption');
                var head = box.closest('.wm-panel') && box.closest('.wm-panel').querySelector('.wm-panel-head');
                var label = (cap && cap.textContent.trim()) || (head && head.textContent.trim()) || document.title.split(' — ')[0];
                box.setAttribute('tabindex', '0');
                box.setAttribute('role', 'region');
                box.setAttribute('aria-label', label + ' (scrolls sideways)');
                box.setAttribute('data-wm-scroll', '');
            } else if (!scrolls && box.hasAttribute('data-wm-scroll')) {
                box.removeAttribute('tabindex');
                box.removeAttribute('role');
                box.removeAttribute('aria-label');
                box.removeAttribute('data-wm-scroll');
            }
        });
    }
    keyboardScroll();
    window.addEventListener('resize', keyboardScroll);

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

    // Colour pickers kept in step with their hex text field (Branding). The server validates the value.
    document.querySelectorAll('input[type="color"][data-sync]').forEach(function (picker) {
        var text = document.getElementById(picker.dataset.sync);
        if (!text) { return; }
        picker.addEventListener('input', function () { text.value = picker.value.toUpperCase(); });
        text.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) { picker.value = text.value.toLowerCase(); }
        });
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
