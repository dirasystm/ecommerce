/* Ecommerce storefront behaviour. No inline scripts (CSP: script-src 'self'). */
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
            window.WM.setButtonLoading(btn, 'Adding…');
            postForm(form.action, form).then(function (res) {
                window.WM.clearButtonLoading(btn);
                toast(res.message || (res.ok ? 'Added to cart.' : 'Could not add to cart.'), !!res.ok);
                if (res.ok) { setCartCount(res.count); }
            }).catch(function () { window.WM.clearButtonLoading(btn); form.submit(); });
        });
    });

    // Wishlist heart toggle
    document.querySelectorAll('form[data-wishlist]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.fetch) { return; }
            e.preventDefault();
            var heart = form.querySelector('button');
            window.WM.setButtonLoading(heart, '');
            postForm(form.action, form).then(function (res) {
                window.WM.clearButtonLoading(heart);
                if (!res.ok) { return; }
                var btn = form.querySelector('button');
                var icon = btn.querySelector('i');
                btn.classList.toggle('saved', res.saved);
                btn.setAttribute('aria-pressed', res.saved ? 'true' : 'false');
                icon.className = 'bi bi-heart' + (res.saved ? '-fill' : '');
                toast(res.saved ? 'Saved to your wishlist.' : 'Removed from your wishlist.', true);
            }).catch(function () { window.WM.clearButtonLoading(heart); toast('Could not update your wishlist. Check your connection and try again.', false); });
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

    // ---------------------------------------------------------------- "Other" reason boxes
    // <select data-other-target="id">: choosing "other" shows the element with that id (a text box for the reason)
    // and makes its field required; any other choice hides it again.
    document.querySelectorAll('select[data-other-target]').forEach(function (sel) {
        var wrap = document.getElementById(sel.getAttribute('data-other-target'));
        if (!wrap) { return; }
        var field = wrap.querySelector('textarea, input');
        var sync = function () {
            var other = sel.value === 'other';
            wrap.hidden = !other;
            if (field) { field.required = other; }
            if (other && field && document.activeElement === sel) { field.focus(); }
        };
        sel.addEventListener('change', sync);
        sync();
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

        var summaryBox = total ? (total.closest('.wm-panel, .card, aside, section') || total.parentElement) : null;
        var quoteSeq = 0;

        function requestQuote() {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var mine = ++quoteSeq;                         // only the newest answer updates the totals
                window.WM.showInlineLoader(summaryBox, 'Updating totals…');
                postForm(quoteUrl, checkout).then(function (q) {
                    if (mine !== quoteSeq) { return; }
                    window.WM.hideInlineLoader(summaryBox);
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
                }).catch(function () {
                    if (mine !== quoteSeq) { return; }
                    window.WM.hideInlineLoader(summaryBox);
                    msg.textContent = 'Could not update the delivery fee and total. Check your connection; the final amount is confirmed when you place the order.';
                    msg.className = 'small mt-2 mb-0 text-danger';
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
    // ---------------------------------------------------------------- pickup station suggestions (checkout)
    // Ranks stations for this customer: same town first, then same county, then distance when the customer shares
    // their location (stations with map coordinates). One tap chooses the station, exactly like ticking it below.
    var suggest = document.querySelector('[data-station-suggest]');
    if (suggest) {
        var sFinder = suggest.closest('[data-station-finder]');
        var sList = suggest.querySelector('[data-suggest-list]');
        var sEmpty = suggest.querySelector('[data-suggest-empty]');
        var sNearBtn = suggest.querySelector('[data-near-me]');
        var sNearStatus = suggest.querySelector('[data-near-status]');
        var sStations = Array.prototype.slice.call(sFinder.querySelectorAll('[data-station-id]'));
        var me = null;
        var norm = function (t) { return (t || '').toString().trim().toLowerCase(); };
        var km = function (a, b, c, d) {
            var r = Math.PI / 180, x = Math.sin((c - a) * r / 2), y = Math.sin((d - b) * r / 2);
            var h = x * x + Math.cos(a * r) * Math.cos(c * r) * y * y;
            return 12742 * Math.asin(Math.sqrt(h));
        };
        var place = function () {
            var saved = document.querySelector('input[name="address_id"]:checked[data-county-id]');
            if (saved) { return { county: saved.getAttribute('data-county-id'), town: norm(saved.getAttribute('data-town')) }; }
            var c = document.getElementById('county_id');
            var t = document.getElementById('town');
            return { county: c ? c.value : '', town: norm(t ? t.value : '') };
        };
        var render = function () {
            var where = place();
            var ranked = sStations.map(function (el) {
                var town = norm(el.getAttribute('data-town'));
                var score = 0, why = '';
                if (where.town && town && (town === where.town || town.indexOf(where.town) === 0 || where.town.indexOf(town) === 0)) {
                    score = 300; why = 'In ' + el.getAttribute('data-town');
                } else if (where.county && el.getAttribute('data-county-id') === where.county) {
                    score = 200; why = 'In ' + el.getAttribute('data-county') + ' county';
                }
                var lat = parseFloat(el.getAttribute('data-lat')), lng = parseFloat(el.getAttribute('data-lng'));
                if (me && !isNaN(lat) && !isNaN(lng)) {
                    var d = km(me.lat, me.lng, lat, lng);
                    score = Math.max(score, 400 - Math.min(d, 390)) + (score ? 1 : 0);
                    why = (d < 1 ? Math.round(d * 1000) + ' m' : d.toFixed(d < 10 ? 1 : 0) + ' km') + ' away';
                }
                return { el: el, score: score, why: why };
            }).filter(function (r) { return r.score > 0; }).sort(function (a, b) { return b.score - a.score; }).slice(0, 3);

            sList.textContent = '';
            sEmpty.hidden = ranked.length > 0;
            ranked.forEach(function (r) {
                var input = r.el.querySelector('input[name="pickup_point_id"]');
                var li = document.createElement('div');
                li.setAttribute('role', 'listitem');
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'wm-station-pick';
                b.setAttribute('aria-pressed', input && input.checked ? 'true' : 'false');
                var icon = document.createElement('i');
                icon.className = 'bi bi-geo-alt-fill text-success';
                icon.setAttribute('aria-hidden', 'true');
                var box = document.createElement('span');
                var name = document.createElement('strong');
                name.className = 'd-block';
                name.textContent = r.el.getAttribute('data-name');
                var addr = document.createElement('span');
                addr.className = 'd-block text-muted';
                addr.textContent = r.el.getAttribute('data-address') + ' · ' + r.el.getAttribute('data-fee');
                var why = document.createElement('span');
                why.className = 'wm-why';
                why.textContent = r.why;
                box.appendChild(name); box.appendChild(addr); box.appendChild(why);
                b.appendChild(icon); b.appendChild(box);
                b.addEventListener('click', function () {
                    if (!input) { return; }
                    input.checked = true;
                    r.el.hidden = false;
                    input.dispatchEvent(new Event('change', { bubbles: true }));   // redraws this list with the new choice pressed
                    var again = sList.querySelector('[data-pick-id="' + r.el.getAttribute('data-station-id') + '"]');
                    if (again) { again.focus(); }
                });
                b.setAttribute('data-pick-id', r.el.getAttribute('data-station-id'));
                li.appendChild(b);
                sList.appendChild(li);
            });
        };
        ['change', 'input'].forEach(function (ev) {
            document.addEventListener(ev, function (e) {
                var t = e.target;
                if (t && (t.name === 'address_id' || t.id === 'county_id' || t.id === 'town' || t.name === 'fulfilment_method' || t.name === 'pickup_point_id')) { render(); }
            });
        });
        if (sNearBtn) {
            if (!navigator.geolocation) { sNearBtn.hidden = true; }
            sNearBtn.addEventListener('click', function () {
                sNearStatus.textContent = 'Finding your location…';
                navigator.geolocation.getCurrentPosition(function (pos) {
                    me = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                    var withMap = sStations.filter(function (el) { return el.hasAttribute('data-lat'); }).length;
                    sNearStatus.textContent = withMap ? 'Showing the stations nearest to you.' : 'Stations have no map locations yet, so we used your county and town.';
                    render();
                }, function () {
                    sNearStatus.textContent = 'We could not get your location. Choose your county and town instead.';
                }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 600000 });
            });
        }
        render();
    }

    // ---------------------------------------------------------------- become a pickup station (application form)
    // Town suggestions follow the county; "Use my current location" stores the shop's coordinates; Closed days.
    document.querySelectorAll('[data-town-source]').forEach(function (sel) {
        var list = document.querySelector('[data-town-list]');
        var fill = function () {
            if (!list) { return; }
            list.textContent = '';
            var opt = sel.options[sel.selectedIndex];
            (opt ? (opt.getAttribute('data-towns') || '') : '').split('|').filter(Boolean).forEach(function (t) {
                var o = document.createElement('option');
                o.value = t;
                list.appendChild(o);
            });
        };
        sel.addEventListener('change', fill);
        fill();
    });
    document.querySelectorAll('[data-locate]').forEach(function (btn) {
        var form = btn.closest('form');
        var status = form.querySelector('[data-locate-status]');
        if (!navigator.geolocation) { btn.hidden = true; return; }
        btn.addEventListener('click', function () {
            status.textContent = 'Finding your location…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                form.querySelector('[data-lat]').value = pos.coords.latitude.toFixed(7);
                form.querySelector('[data-lng]').value = pos.coords.longitude.toFixed(7);
                status.textContent = 'Location saved (accurate to about ' + Math.round(pos.coords.accuracy) + ' m). Customers will see your shop on the map.';
            }, function () {
                status.textContent = 'We could not get your location. You can paste a Google Maps link below instead.';
            }, { enableHighAccuracy: true, timeout: 15000 });
        });
    });
    document.querySelectorAll('[data-day-closed]').forEach(function (box) {
        var sync = function () {
            box.closest('[data-day]').querySelectorAll('input[type="time"]').forEach(function (t) { t.disabled = box.checked; });
        };
        box.addEventListener('change', sync);
        sync();
    });
    // ---------------------------------------------------------------- privacy banner and "Not interested"
    // Both work without JavaScript (normal form posts); with it, they update in place.
    var postInPlace = function (form, submitter) {
        var data = new FormData(form);
        if (submitter && submitter.name) { data.append(submitter.name, submitter.value); }
        return fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    };
    // While the banner is open, the page gets room below its content so nothing (e.g. "Add to cart") stays hidden behind it.
    var privacyBanner = document.querySelector('[data-privacy-banner]');
    var reserveBannerSpace = function () {
        if (!privacyBanner || !privacyBanner.isConnected) { document.body.style.paddingBottom = ''; return; }
        var r = privacyBanner.getBoundingClientRect();
        document.body.style.paddingBottom = Math.ceil(window.innerHeight - r.top + 12) + 'px';
    };
    if (privacyBanner) {
        reserveBannerSpace();
        window.addEventListener('resize', reserveBannerSpace);
    }
    document.querySelectorAll('[data-privacy-form]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (e.submitter) { window.WM.setButtonLoading(e.submitter, 'Saving…'); }
            postInPlace(form, e.submitter).then(function () {
                var banner = form.closest('[data-privacy-banner]');
                if (banner) { banner.remove(); }
                reserveBannerSpace();
            }).catch(function () { if (e.submitter) { window.WM.clearButtonLoading(e.submitter); } form.submit(); });
        });
    });
    document.addEventListener('submit', function (e) {
        var form = e.target.closest ? e.target.closest('[data-rec-dismiss]') : null;
        if (!form) { return; }
        e.preventDefault();
        var dismissBtn = form.querySelector('button');
        window.WM.setButtonLoading(dismissBtn, '');
        postInPlace(form, null).then(function () {
            var card = form.closest('.wm-product');
            var rail = card ? card.parentElement : null;
            if (card) { card.remove(); }
            if (rail && rail.focus) { rail.focus(); }
        }).catch(function () { window.WM.clearButtonLoading(dismissBtn); form.submit(); });
    });
})();
