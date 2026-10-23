/* Ecommerce admin behaviour (CSP-safe, no inline handlers). */
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
    window.addEventListener('load', keyboardScroll);                       // after fonts and images change widths
    document.addEventListener('wm:region-updated', keyboardScroll);        // live tables replaced their rows
    if (window.ResizeObserver) {
        var watchScroll = new ResizeObserver(keyboardScroll);
        document.querySelectorAll('.table-responsive').forEach(function (box) { watchScroll.observe(box); });
    }

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

    // Double submission and loading states for every form: ui.js.

    // Marketing link studio: destination suggestions follow the chosen destination type.
    var destType = document.querySelector('[data-dest-type]');
    var destValue = document.querySelector('[data-dest-value]');
    if (destType && destValue) {
        var syncDest = function () {
            var list = document.getElementById('dest-' + destType.value);
            if (list) { destValue.setAttribute('list', list.id); } else { destValue.removeAttribute('list'); }
            destValue.placeholder = { search: 'Search words, e.g. smartphones', page: '/flash-sales', home: 'Not needed' }[destType.value] || 'Start typing a name';
            destValue.disabled = destType.value === 'home';
        };
        destType.addEventListener('change', function () { destValue.value = ''; syncDest(); });
        syncDest();
    }

    // Copy buttons (data-copy="#id"): copies the field's text and says so.
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = document.querySelector(btn.getAttribute('data-copy'));
            if (!field) { return; }
            var done = function () {
                var label = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = label; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(field.value).then(done, function () { field.select(); document.execCommand('copy'); done(); });
            } else {
                field.select();
                document.execCommand('copy');
                done();
            }
        });
    });

    // QR code (qrcode-generator, loaded only on the link studio page) with a PNG download.
    var qrBox = document.querySelector('[data-qr]');
    if (qrBox && typeof window.qrcode === 'function') {
        var qr = window.qrcode(0, 'M');
        qr.addData(qrBox.getAttribute('data-qr'));
        qr.make();
        var qrUrl = qr.createDataURL(8, 4);
        var qrImg = document.createElement('img');
        qrImg.src = qrUrl;
        qrImg.alt = '';
        qrImg.width = 200;
        qrImg.height = 200;
        qrBox.appendChild(qrImg);
        var qrBtn = document.querySelector('[data-qr-download]');
        if (qrBtn) {
            qrBtn.addEventListener('click', function () {
                var a = document.createElement('a');
                a.href = qrUrl;
                a.download = qrBtn.getAttribute('data-qr-download');
                document.body.appendChild(a);
                a.click();
                a.remove();
            });
        }
    }

    // Creative image download: drawn on a canvas from the facts shown on the page (same-origin image only).
    var creative = document.querySelector('[data-creative]');
    if (creative) {
        var css = getComputedStyle(document.documentElement);
        var green = (css.getPropertyValue('--wm-green') || '#005b3d').trim() || '#005b3d';
        var orange = (css.getPropertyValue('--wm-orange') || '#ff6a00').trim() || '#ff6a00';
        var font = function (weight, size) { return weight + ' ' + size + 'px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'; };
        var wrap = function (ctx, text, maxW) {
            var lines = [], line = '';
            text.split(/\s+/).forEach(function (w) {
                var t = line ? line + ' ' + w : w;
                if (ctx.measureText(t).width > maxW && line) { lines.push(line); line = w; } else { line = t; }
            });
            if (line) { lines.push(line); }
            return lines;
        };
        var draw = function (w, h) {
            var d = creative.dataset;
            var img = creative.querySelector('[data-creative-img]');
            var c = document.createElement('canvas');
            c.width = w;
            c.height = h;
            var ctx = c.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, w, h);
            var imgH = Math.round(h * (h > w ? 0.55 : 0.5));
            if (img && img.complete && img.naturalWidth) {
                var sc = Math.min(w / img.naturalWidth, (imgH - 40) / img.naturalHeight);
                var iw = img.naturalWidth * sc, ih = img.naturalHeight * sc;
                ctx.drawImage(img, (w - iw) / 2, (imgH - ih) / 2, iw, ih);
            }
            ctx.fillStyle = green;
            ctx.fillRect(0, imgH, w, h - imgH);
            ctx.fillStyle = orange;
            ctx.fillRect(0, imgH, w, 12);
            var x = 64, y = imgH + 90;
            ctx.fillStyle = '#ffffff';
            ctx.font = font(700, 56);
            wrap(ctx, d.headline, w - 2 * x).slice(0, 2).forEach(function (l) { ctx.fillText(l, x, y); y += 68; });
            if (d.price) {
                y += 16;
                ctx.font = font(800, 68);
                ctx.fillText(d.price, x, y);
                if (d.was) {
                    var pw = ctx.measureText(d.price).width + 30;
                    ctx.font = font(400, 42);
                    ctx.globalAlpha = 0.8;
                    ctx.fillText(d.was, x + pw, y);
                    ctx.fillRect(x + pw, y - 15, ctx.measureText(d.was).width, 4);
                    ctx.globalAlpha = 1;
                }
            }
            if (d.coupon) {
                y += 64;
                ctx.font = font(600, 40);
                ctx.fillText('Code: ' + d.coupon, x, y);
            }
            var by = h - 140;
            ctx.font = font(700, 42);
            var bw = ctx.measureText(d.cta).width + 80;
            ctx.fillStyle = orange;
            ctx.beginPath();
            if (ctx.roundRect) { ctx.roundRect(x, by - 58, bw, 80, 40); } else { ctx.rect(x, by - 58, bw, 80); }
            ctx.fill();
            ctx.fillStyle = '#111111';
            ctx.fillText(d.cta, x + 40, by - 4);
            ctx.fillStyle = '#ffffff';
            ctx.font = font(500, 34);
            ctx.fillText(d.url, x, h - 44);
            ctx.textAlign = 'right';
            ctx.font = font(800, 38);
            ctx.fillText(d.brand, w - x, h - 44);
            return c;
        };
        document.querySelectorAll('[data-creative-download]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var size = btn.getAttribute('data-creative-download').split('x');
                var err = document.querySelector('[data-creative-error]');
                try {
                    var a = document.createElement('a');
                    a.href = draw(parseInt(size[0], 10), parseInt(size[1], 10)).toDataURL('image/png');
                    a.download = 'creative-' + size.join('x') + '.png';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                } catch (e) {
                    if (err) { err.textContent = 'The image could not be created in this browser. Take a screenshot of the preview instead.'; err.classList.remove('d-none'); }
                }
            });
        });
    }
})();
