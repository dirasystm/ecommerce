/* Basemall storefront behaviour. No inline scripts (CSP: script-src 'self'). */
(function () {
    'use strict';

    // Flash-sale countdowns: <span data-countdown="2026-10-17T23:59:59+03:00">
    // Display only — the server decides whether a sale price applies.
    function pad(n) { return String(n).padStart(2, '0'); }

    function startCountdown(el) {
        var end = Date.parse(el.getAttribute('data-countdown'));
        if (isNaN(end)) { return; }
        var h = el.querySelector('[data-cd="h"]');
        var m = el.querySelector('[data-cd="m"]');
        var s = el.querySelector('[data-cd="s"]');

        function tick() {
            var left = Math.max(0, Math.floor((end - Date.now()) / 1000));
            h.textContent = pad(Math.floor(left / 3600));
            m.textContent = pad(Math.floor((left % 3600) / 60));
            s.textContent = pad(left % 60);
            if (left === 0) {
                clearInterval(timer);
                el.setAttribute('aria-label', 'Sale ended');
            }
        }
        var timer = setInterval(tick, 1000);
        tick();
    }

    document.querySelectorAll('[data-countdown]').forEach(startCountdown);

    // Respect reduced-motion: stop auto-advancing carousels.
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches && window.bootstrap) {
        document.querySelectorAll('.carousel').forEach(function (c) {
            window.bootstrap.Carousel.getOrCreateInstance(c, { ride: false, interval: false }).pause();
        });
    }

    // Product gallery: thumbnails swap the main image.
    var mainImg = document.getElementById('wmMainImage');
    document.querySelectorAll('[data-gallery-src]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!mainImg) { return; }
            mainImg.src = btn.getAttribute('data-gallery-src');
            mainImg.alt = btn.getAttribute('data-gallery-alt') || '';
            document.querySelectorAll('[data-gallery-src]').forEach(function (b) {
                b.classList.toggle('active', b === btn);
                if (b === btn) { b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); }
            });
        });
    });

    // Variant chips update price, stock, SKU and quantity limits (display only;
    // the server re-validates price and stock when adding to cart and at checkout).
    document.querySelectorAll('[data-product-form]').forEach(function (form) {
        var maxPerOrder = parseInt(form.getAttribute('data-max-per-order'), 10) || 1;
        var root = form.closest('section') || document;
        var price = root.querySelector('[data-price]');
        var was = root.querySelector('[data-was]');
        var discount = root.querySelector('[data-discount]');
        var stock = root.querySelector('[data-stock]');
        var sku = form.querySelector('[data-sku]');
        var qty = form.querySelector('[data-qty]');
        var btn = form.querySelector('[data-add-btn]');
        var label = form.querySelector('[data-add-label]');

        function apply(input) {
            var available = parseInt(input.getAttribute('data-available'), 10) || 0;
            var low = input.getAttribute('data-low') === '1';
            price.textContent = input.getAttribute('data-price');
            var w = input.getAttribute('data-was');
            was.textContent = w; was.hidden = !w;
            var d = parseInt(input.getAttribute('data-discount'), 10) || 0;
            discount.textContent = '-' + d + '%'; discount.hidden = d <= 0;
            if (sku) { sku.textContent = input.getAttribute('data-sku'); }
            stock.innerHTML = '';
            var s = document.createElement('span');
            if (available <= 0) { s.className = 'text-danger fw-semibold'; s.textContent = 'Out of stock'; }
            else if (low) { s.className = 'wm-stock-note'; s.textContent = 'Only ' + available + ' left — order soon'; }
            else { s.className = 'text-success fw-semibold'; s.textContent = 'In stock'; }
            stock.appendChild(s);
            var max = Math.max(1, Math.min(available, maxPerOrder));
            qty.max = max;
            if (parseInt(qty.value, 10) > max) { qty.value = max; }
            btn.disabled = available <= 0;
            label.textContent = available <= 0 ? 'Out of stock' : 'Add to cart';
        }
        form.querySelectorAll('input[name="variant_id"][type="radio"]').forEach(function (input) {
            input.addEventListener('change', function () { apply(input); });
        });
    });

    // Sort dropdowns submit on change (a <noscript> button covers no-JS users).
    document.querySelectorAll('form[data-autosubmit] select').forEach(function (sel) {
        sel.addEventListener('change', function () { sel.form.submit(); });
    });

    // ---------------------------------------------------------------- helpers
    function csrfToken(form) {
        var el = (form || document).querySelector('input[name="_csrf"]') || document.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }

    function postForm(url, form, extra) {
        var data = new FormData(form);
        if (extra) { Object.keys(extra).forEach(function (k) { data.set(k, extra[k]); }); }
        return fetch(url, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken(form) }
        }).then(function (r) { return r.json(); });
    }

    function toast(message, ok) {
        var box = document.querySelector('[data-toasts]');
        if (!box || !window.bootstrap) { return; }
        var t = document.createElement('div');
        t.className = 'toast align-items-center border-0 text-bg-' + (ok ? 'success' : 'danger');
        t.setAttribute('role', ok ? 'status' : 'alert');
        var inner = document.createElement('div');
        inner.className = 'd-flex';
        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close btn-close-white me-2 m-auto';
        close.setAttribute('data-bs-dismiss', 'toast');
        close.setAttribute('aria-label', 'Close');
        inner.appendChild(body); inner.appendChild(close); t.appendChild(inner); box.appendChild(t);
        var bt = new window.bootstrap.Toast(t, { delay: 4000 });
        t.addEventListener('hidden.bs.toast', function () { t.remove(); });
        bt.show();
    }

    function setCartCount(n) {
        document.querySelectorAll('[data-cart-count]').forEach(function (el) {
            el.textContent = n; el.hidden = !n;
        });
    }

    // Confirmation prompts: <form data-confirm="Remove this?">
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); }
        });
    });

    // ---------------------------------------------------------------- add to cart (progressive enhancement)
    document.querySelectorAll('[data-product-form]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.fetch) { return; }
            e.preventDefault();
            var btn = form.querySelector('[data-add-btn]');
            btn.disabled = true;
            postForm(form.action, form).then(function (res) {
                toast(res.message || (res.ok ? 'Added to cart.' : 'Could not add to cart.'), !!res.ok);
                if (res.ok) { setCartCount(res.count); }
            }).catch(function () { form.submit(); })
              .finally(function () { btn.disabled = false; });
        });
    });

    // Wishlist heart toggle
    document.querySelectorAll('form[data-wishlist]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.fetch) { return; }
            e.preventDefault();
            postForm(form.action, form).then(function (res) {
                if (!res.ok) { return; }
                var btn = form.querySelector('button');
                var icon = btn.querySelector('i');
                btn.classList.toggle('saved', res.saved);
                btn.setAttribute('aria-pressed', res.saved ? 'true' : 'false');
                icon.className = 'bi bi-heart' + (res.saved ? '-fill' : '');
                toast(res.saved ? 'Saved to your wishlist.' : 'Removed from your wishlist.', true);
            });
        });
    });

    // ---------------------------------------------------------------- towns autocomplete
    document.querySelectorAll('select[data-towns-for]').forEach(function (sel) {
        var list = document.getElementById(sel.getAttribute('data-towns-for'));
        function load() {
            if (!sel.value || !list) { return; }
            fetch(sel.getAttribute('data-towns-url') + '?county=' + encodeURIComponent(sel.value), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    list.innerHTML = '';
                    (res.towns || []).forEach(function (t) { var o = document.createElement('option'); o.value = t; list.appendChild(o); });
                });
        }
        sel.addEventListener('change', load);
        load();
    });

    // ---------------------------------------------------------------- pickup station finder (checkout)
    // Filters the station list as the customer types a town or county; a chosen station always stays visible.
    var finder = document.querySelector('[data-station-finder]');
    var finderInput = finder ? finder.querySelector('[data-station-search]') : null;
    if (finderInput) {
        var stationCount = finder.querySelector('[data-station-count]');
        var stationNone = finder.querySelector('[data-station-none]');
        var stations = finder.querySelectorAll('[data-station]');
        var filterStations = function () {
            var q = finderInput.value.trim().toLowerCase();
            var shown = 0;
            stations.forEach(function (s) {
                var hit = !q || s.getAttribute('data-station').indexOf(q) !== -1 || s.querySelector('input:checked') !== null;
                s.hidden = !hit;
                if (hit) { shown++; }
            });
            finder.querySelectorAll('[data-station-group]').forEach(function (g) {
                g.hidden = !g.querySelector('[data-station]:not([hidden])');
            });
            if (stationNone) { stationNone.hidden = shown > 0; }
            if (stationCount) {
                stationCount.textContent = q ? shown + ' of ' + stations.length + ' stations match “' + finderInput.value.trim() + '”' : stations.length + ' stations across Kenya';
            }
        };
        finderInput.addEventListener('input', filterStations);
        // Switching to pickup starts from the customer's county: the saved address chosen, or the county selected.
        document.querySelectorAll('input[name="fulfilment_method"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.value !== 'pickup' || finderInput.value) { return; }
                var saved = document.querySelector('input[name="address_id"]:checked[data-county-name]');
                var county = document.getElementById('county_id');
                var place = saved ? saved.getAttribute('data-county-name') : (county && county.value ? county.options[county.selectedIndex].text : '');
                if (place) { finderInput.value = place; filterStations(); }
            });
        });
    }

    // ---------------------------------------------------------------- checkout
    var checkout = document.querySelector('form[data-checkout]');
    if (checkout) {
        var quoteUrl = checkout.getAttribute('data-quote-url');
        var fee = checkout.querySelector('[data-fee]');
        var total = checkout.querySelector('[data-total]');
        var eta = checkout.querySelector('[data-eta]');
        var msg = checkout.querySelector('[data-quote-message]');
        var cod = checkout.querySelector('[data-cod]');
        var codReason = cod ? cod.closest('.form-check').querySelector('[data-cod-reason]') : null;
        var timer = null;

        function method() {
            var m = checkout.querySelector('input[name="fulfilment_method"]:checked');
            return m ? m.value : '';
        }

        function syncPanels() {
            var m = method();
            checkout.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== m; });
            var choice = checkout.querySelector('input[name="address_id"]:checked');
            var fields = checkout.querySelector('[data-new-address-fields]');
            if (fields && checkout.querySelector('input[name="address_id"][type="radio"]')) {
                fields.hidden = !(choice && choice.value === 'new');
            }
        }

        function requestQuote() {
            clearTimeout(timer);
            timer = setTimeout(function () {
                postForm(quoteUrl, checkout).then(function (q) {
                    fee.textContent = q.fee_display;
                    total.textContent = q.total_display;
                    var dRow = checkout.querySelector('[data-discount-row]');
                    var cMsg = checkout.querySelector('[data-coupon-message]');
                    if (dRow) {
                        dRow.hidden = !q.discount_display;
                        checkout.querySelector('[data-discount-amount]').textContent = q.discount_display || '';
                    }
                    if (cMsg) {
                        cMsg.textContent = q.coupon_message || '';
                        cMsg.className = 'small mt-1 ' + (q.coupon_ok ? 'text-success' : 'text-danger');
                    }
                    eta.textContent = q.eta || '';
                    msg.textContent = q.message || '';
                    msg.className = 'small mt-2 mb-0 ' + (q.ok ? 'text-success' : 'text-danger');
                    if (cod) {
                        var blocked = q.cod_allowed === false;
                        cod.disabled = blocked || cod.hasAttribute('data-blocked');   // data-blocked: outside the method's order limits
                        if (blocked && cod.checked) { cod.checked = false; }
                        if (codReason) { codReason.hidden = !blocked; codReason.textContent = blocked ? (q.cod_reason || 'Not available for the selected location.') : ''; }
                    }
                });
            }, 250);
        }

        checkout.querySelectorAll('[data-fulfil], input[name="address_id"]').forEach(function (el) {
            el.addEventListener('change', function () { syncPanels(); requestQuote(); });
        });
        checkout.querySelectorAll('[data-quote]').forEach(function (el) {
            el.addEventListener(el.tagName === 'INPUT' && el.type === 'text' ? 'input' : 'change', requestQuote);
        });
        var apply = checkout.querySelector('[data-apply-coupon]');
        if (apply) { apply.addEventListener('click', requestQuote); }

        // One click places one order. (The server's idempotency key is the real guarantee.)
        checkout.addEventListener('submit', function (e) {
            var btn = checkout.querySelector('[data-place-order]');
            if (checkout.dataset.submitted === '1') { e.preventDefault(); return; }
            checkout.dataset.submitted = '1';
            if (btn) { btn.disabled = true; btn.textContent = 'Placing your order…'; }
        });
        // Re-enable if the page is restored from the back/forward cache.
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted) {
                checkout.dataset.submitted = '';
                var btn = checkout.querySelector('[data-place-order]');
                if (btn) { btn.disabled = false; btn.textContent = 'Place order'; }
            }
        });

        syncPanels();
        requestQuote();
    }

    // Select-all on focus for copyable fields (tracking link).
    document.querySelectorAll('[data-select-on-focus]').forEach(function (el) {
        el.addEventListener('focus', function () { el.select(); });
    });

    // Prevent empty searches.
    document.querySelectorAll('form[data-search]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var q = form.querySelector('input[name="q"]');
            if (q && q.value.trim() === '') { e.preventDefault(); q.focus(); }
        });
    });
})();
