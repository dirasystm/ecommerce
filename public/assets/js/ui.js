/* Ecommerce loading feedback and live tables (CSP-safe, no dependencies). Loaded before app.js on every page.
 *
 *   WM.showGlobalLoader(message) -> id   full-page overlay after a short delay (no flicker on fast requests)
 *   WM.hideGlobalLoader(id)               each caller hides only its own; the overlay stays while any is open
 *   WM.showInlineLoader(el, message) / WM.hideInlineLoader(el)   spinner over one region, aria-busy
 *   WM.setButtonLoading(btn, message) / WM.clearButtonLoading(btn)  spinner in the button, disabled, same width
 *   WM.showSkeleton(el, 'table'|'cards'|'lines', n) / WM.clearSkeleton(el)
 *   WM.fetch(url, options, timeoutMs)     fetch with a timeout (rejects with a readable message)
 *
 * Automatic: POST forms show a spinner on the button that sent them and cannot be sent twice; GET search/filter
 * forms show a spinner; forms with data-loading-overlay="Message" (checkout, uploads) show the full-page overlay;
 * slow page navigations show a thin progress bar; live tables (data-live-table) update in place.
 * Every indicator is removed on success, error, timeout, or when the page is shown again from the back button.
 */
(function () {
    'use strict';

    var WM = window.WM = window.WM || {};
    var DELAY = 300;            // ms before a full-page overlay appears
    var TIMEOUT = 30000;        // ms before a request is treated as failed

    // ------------------------------------------------------------ global overlay (reference-counted)
    var overlay = null, overlayText = null, open = {}, seq = 0, showTimer = null;

    function ensureOverlay() {
        if (overlay) { return; }
        overlay = document.createElement('div');
        overlay.className = 'wm-loader-overlay';
        overlay.hidden = true;
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        var box = document.createElement('div');
        box.className = 'wm-loader-box';
        var spin = document.createElement('span');
        spin.className = 'wm-spinner wm-spinner-lg';
        spin.setAttribute('aria-hidden', 'true');
        overlayText = document.createElement('div');
        overlayText.className = 'wm-loader-text';
        box.appendChild(spin);
        box.appendChild(overlayText);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
    }

    function latestMessage() {
        var ids = Object.keys(open);
        return ids.length ? open[ids[ids.length - 1]] : '';
    }

    WM.showGlobalLoader = function (message) {
        ensureOverlay();
        var id = ++seq;
        open[id] = message || 'Processing your request…';
        overlayText.textContent = latestMessage();
        if (!showTimer && overlay.hidden) {
            showTimer = setTimeout(function () {
                showTimer = null;
                if (Object.keys(open).length) {
                    overlay.hidden = false;
                    document.body.setAttribute('aria-busy', 'true');
                }
            }, DELAY);
        }
        return id;
    };

    WM.hideGlobalLoader = function (id) {
        if (id === undefined) { open = {}; } else { delete open[id]; }
        if (Object.keys(open).length) { if (overlayText) { overlayText.textContent = latestMessage(); } return; }
        if (showTimer) { clearTimeout(showTimer); showTimer = null; }
        if (overlay) { overlay.hidden = true; }
        document.body.removeAttribute('aria-busy');
    };

    // ------------------------------------------------------------ inline loader (one region)
    WM.showInlineLoader = function (el, message) {
        if (!el) { return; }
        el.setAttribute('aria-busy', 'true');
        el.classList.add('wm-is-loading');
        var mask = el.querySelector(':scope > .wm-inline-loader');
        if (!mask) {
            mask = document.createElement('div');
            mask.className = 'wm-inline-loader';
            var spin = document.createElement('span');
            spin.className = 'wm-spinner';
            spin.setAttribute('aria-hidden', 'true');
            var txt = document.createElement('span');
            txt.className = 'wm-inline-text';
            mask.appendChild(spin);
            mask.appendChild(txt);
            el.appendChild(mask);
        }
        mask.querySelector('.wm-inline-text').textContent = message || 'Loading…';
    };

    WM.hideInlineLoader = function (el) {
        if (!el) { return; }
        el.removeAttribute('aria-busy');
        el.classList.remove('wm-is-loading');
        var mask = el.querySelector(':scope > .wm-inline-loader');
        if (mask) { mask.remove(); }
    };

    // ------------------------------------------------------------ buttons
    WM.setButtonLoading = function (btn, message) {
        if (!btn || btn.dataset.wmLoading === '1') { return; }
        btn.dataset.wmLoading = '1';
        if (btn.tagName === 'INPUT') {
            btn.dataset.wmLabel = btn.value;
            btn.value = message || 'Please wait…';
        } else {
            btn.dataset.wmLabel = btn.innerHTML;
            btn.style.minWidth = btn.offsetWidth + 'px';        // keep the button the same size
            btn.textContent = '';
            var spin = document.createElement('span');
            spin.className = 'wm-spinner wm-spinner-sm';
            spin.setAttribute('aria-hidden', 'true');
            btn.appendChild(spin);
            if (message === '') {                                // icon-only buttons: spinner only, words for screen readers
                var sr = document.createElement('span');
                sr.className = 'visually-hidden';
                sr.textContent = 'Please wait…';
                btn.appendChild(sr);
            } else {
                btn.appendChild(document.createTextNode(' ' + (message || 'Please wait…')));
            }
        }
        btn.setAttribute('aria-busy', 'true');
        btn.classList.add('wm-btn-loading');
        // Disabled on the next tick so the clicked button's own name/value is still sent with the form.
        setTimeout(function () { if (btn.dataset.wmLoading === '1') { btn.disabled = true; } }, 0);
    };

    WM.clearButtonLoading = function (btn) {
        if (!btn || btn.dataset.wmLoading !== '1') { return; }
        if (btn.tagName === 'INPUT') { btn.value = btn.dataset.wmLabel; } else { btn.innerHTML = btn.dataset.wmLabel; btn.style.minWidth = ''; }
        delete btn.dataset.wmLoading;
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        btn.classList.remove('wm-btn-loading');
    };

    // ------------------------------------------------------------ skeletons
    WM.showSkeleton = function (el, type, n) {
        if (!el) { return; }
        var sk = document.createElement('div');
        sk.className = 'wm-skeleton wm-skeleton-' + (type || 'lines');
        sk.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < (n || 5); i++) {
            var row = document.createElement('div');
            row.className = 'wm-skeleton-row';
            sk.appendChild(row);
        }
        el.dataset.wmSkeleton = '1';
        el.setAttribute('aria-busy', 'true');
        el.appendChild(sk);
    };

    WM.clearSkeleton = function (el) {
        if (!el) { return; }
        el.querySelectorAll('.wm-skeleton').forEach(function (s) { s.remove(); });
        el.removeAttribute('aria-busy');
        delete el.dataset.wmSkeleton;
    };

    // ------------------------------------------------------------ fetch with timeout and readable errors
    WM.fetch = function (url, options, timeoutMs) {
        options = options || {};
        var ctrl = window.AbortController ? new AbortController() : null;
        var outer = options.signal;
        if (ctrl) {
            if (outer) { outer.addEventListener('abort', function () { ctrl.abort(); }); }
            options.signal = ctrl.signal;
        }
        var timedOut = false;
        var timer = ctrl ? setTimeout(function () { timedOut = true; ctrl.abort(); }, timeoutMs || TIMEOUT) : null;
        options.credentials = options.credentials || 'same-origin';
        return fetch(url, options).then(function (r) {
            clearTimeout(timer);
            if (r.status === 419) { throw new Error('Your session expired. Refresh the page and try again.'); }
            if (r.status === 401 || r.status === 403) { throw new Error('You do not have access to this, or you were signed out. Refresh the page.'); }
            if (r.status === 429) { throw new Error('Too many requests. Wait a moment and try again.'); }
            if (r.status >= 500) { throw new Error('The server could not complete this. Please try again.'); }
            return r;
        }, function (err) {
            clearTimeout(timer);
            if (err && err.name === 'AbortError' && !timedOut) { throw err; }       // replaced by a newer request
            throw new Error(timedOut ? 'This is taking too long. Check your connection and try again.' : 'Network problem. Check your connection and try again.');
        });
    };

    // ------------------------------------------------------------ automatic: forms
    function submitterOf(form, e) {
        return (e && e.submitter) || form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
    }

    function defaultMessage(form, btn) {
        if (btn && btn.getAttribute('data-loading-text')) { return btn.getAttribute('data-loading-text'); }
        if (form.getAttribute('data-loading-text')) { return form.getAttribute('data-loading-text'); }
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') { return 'Searching…'; }
        var hasFile = Array.prototype.some.call(form.querySelectorAll('input[type="file"]'), function (f) { return f.files && f.files.length; });
        if (hasFile) { return 'Uploading…'; }
        var label = btn ? (btn.textContent || btn.value || '').trim().toLowerCase() : '';
        if (/delete|remove/.test(label)) { return 'Removing…'; }
        if (/send|test/.test(label)) { return 'Sending…'; }
        if (/sign in|log in|verify/.test(label)) { return 'Signing in…'; }
        if (/save|update|apply/.test(label)) { return 'Saving…'; }
        return 'Processing…';
    }

    // Checked once every handler has run (other scripts may handle a form in place with preventDefault).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM' || form.hasAttribute('data-no-loader') || form.hasAttribute('data-live-table') || form.hasAttribute('data-live-size')) { return; }
        if (form.getAttribute('target') === '_blank') { return; }
        var post = (form.getAttribute('method') || 'get').toLowerCase() === 'post';
        if (post && form.dataset.wmSent === '1') { e.preventDefault(); return; }           // no double submission
        var btn = submitterOf(form, e);
        setTimeout(function () { if (!e.defaultPrevented) { formBusy(form, btn, post); } }, 0);
    });

    function formBusy(form, btn, post) {
        // Downloads (CSV exports) never navigate away: only a short spinner.
        var download = btn && btn.hasAttribute('data-download');
        if (post) { form.dataset.wmSent = '1'; }
        var msg = defaultMessage(form, btn);
        if (btn) { WM.setButtonLoading(btn, msg); }
        var uploading = Array.prototype.some.call(form.querySelectorAll('input[type="file"]'), function (f) { return f.files && f.files.length; });
        if (form.hasAttribute('data-loading-overlay')) {
            WM.showGlobalLoader(form.getAttribute('data-loading-overlay') || msg);
        } else if (post && uploading) {
            WM.showGlobalLoader('Uploading… Please keep this page open.');
        }
        if (download) {
            setTimeout(function () { WM.clearButtonLoading(btn); delete form.dataset.wmSent; }, 2500);
        } else {
            showProgress();
        }
    }

    // Page restored from the back/forward cache: nothing may still look busy.
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) { return; }
        WM.hideGlobalLoader();
        hideProgress();
        document.querySelectorAll('[data-wm-loading="1"]').forEach(WM.clearButtonLoading);
        document.querySelectorAll('form[data-wm-sent]').forEach(function (f) { delete f.dataset.wmSent; });
    });

    // ------------------------------------------------------------ automatic: slow page navigation (thin top bar)
    var bar = null, barTimer = null;
    function showProgress() {
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'wm-nav-progress';
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-label', 'Loading page');
            document.body.appendChild(bar);
        }
        clearTimeout(barTimer);
        barTimer = setTimeout(function () {
            bar.classList.add('active');
            barTimer = setTimeout(hideProgress, 20000);      // never left running (e.g. a download that did not navigate)
        }, 250);
    }
    function hideProgress() {
        clearTimeout(barTimer);
        if (bar) { bar.classList.remove('active'); }
    }
    document.addEventListener('click', function (e) {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.target === '_blank' || a.hasAttribute('data-bs-toggle')) { return; }
        if (a.hasAttribute('download') || a.hasAttribute('data-download') || /[?&]format=csv\b|\/export(\b|\?|$)/.test(a.getAttribute('href') || '')) {
            if (a.classList.contains('btn') && a.dataset.wmLoading !== '1') {
                var label = a.innerHTML;
                a.dataset.wmLoading = '1';
                a.setAttribute('aria-busy', 'true');
                a.innerHTML = '<span class="wm-spinner wm-spinner-sm" aria-hidden="true"></span> Preparing your file…';
                setTimeout(function () { a.innerHTML = label; delete a.dataset.wmLoading; a.removeAttribute('aria-busy'); }, 3000);
            }
            return;
        }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) { return; }
        if (a.origin !== window.location.origin) { return; }
        if (a.pathname === window.location.pathname && a.search === window.location.search && a.hash) { return; }
        setTimeout(function () { if (!e.defaultPrevented) { showProgress(); } }, 0);
    });
    window.addEventListener('pagehide', hideProgress);

    // ------------------------------------------------------------ live tables
    // <form data-live-table="regionId"> (search box, filters) + <div id="regionId" data-table-region> (table, footer).
    // Typing searches after a pause; filters, sort links, page links and rows-per-page update only the region.
    // A newer request cancels an older one, so slow answers never overwrite newer results. Without JS: normal GET.
    var inflight = {};

    function regionUrl(form, extra) {
        var params = new URLSearchParams(new FormData(form));
        Object.keys(extra || {}).forEach(function (k) { if (extra[k] === null) { params.delete(k); } else { params.set(k, extra[k]); } });
        params.delete('page');                         // a new search or filter starts at page 1
        Array.from(params.keys()).forEach(function (k) { if (params.get(k) === '') { params.delete(k); } });
        var qs = params.toString();
        return form.action.split('?')[0] + (qs ? '?' + qs : '');
    }

    function loadRegion(id, url, focusBack) {
        var region = document.getElementById(id);
        if (!region) { window.location.href = url; return; }
        if (inflight[id]) { inflight[id].abort(); }
        var ctrl = window.AbortController ? new AbortController() : null;
        inflight[id] = ctrl;
        WM.showInlineLoader(region, region.getAttribute('data-loading-text') || 'Loading records…');
        WM.fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }, signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById(id);
                if (!fresh) { window.location.href = url; return; }
                region.innerHTML = fresh.innerHTML;
                WM.hideInlineLoader(region);
                if (window.history && history.replaceState) { history.replaceState(null, '', url); }
                syncForm(id, doc);
                if (focusBack) { var h = region.querySelector('table, [role="status"]'); if (h && h.focus) { region.setAttribute('tabindex', '-1'); region.focus({ preventScroll: true }); } }
                document.dispatchEvent(new CustomEvent('wm:region-updated', { detail: { id: id } }));
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') { return; }
                WM.hideInlineLoader(region);
                showRegionError(region, err.message, function () { loadRegion(id, url, false); });
            });
    }

    // Keep the toolbar's hidden values (sort, rows per page) and Reset link in step with the new results.
    function syncForm(id, doc) {
        var form = document.getElementById(id + '-form');
        var freshForm = doc.getElementById(id + '-form');
        if (!form || !freshForm) { return; }
        form.querySelectorAll('input[type="hidden"]').forEach(function (h) { h.remove(); });
        freshForm.querySelectorAll('input[type="hidden"]').forEach(function (h) { form.insertBefore(h.cloneNode(true), form.firstChild); });
        var reset = form.querySelector('[data-live-reset]');
        var freshReset = freshForm.querySelector('[data-live-reset]');
        if (reset && !freshReset) { reset.remove(); } else if (!reset && freshReset) { form.insertBefore(freshReset.cloneNode(true), form.querySelector('.wm-toolbar-actions')); }
        var actions = form.querySelector('.wm-toolbar-actions');
        var freshActions = freshForm.querySelector('.wm-toolbar-actions');
        if (actions && freshActions) { actions.innerHTML = freshActions.innerHTML; }      // e.g. Export follows the current filters
    }

    function showRegionError(region, message, retry) {
        var old = region.querySelector('.wm-region-error');
        if (old) { old.remove(); }
        var box = document.createElement('div');
        box.className = 'wm-region-error alert alert-danger d-flex align-items-center gap-2 m-2';
        box.setAttribute('role', 'alert');
        var t = document.createElement('span');
        t.textContent = 'Unable to load records. ' + (message || 'Please try again.');
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm btn-outline-danger ms-auto';
        b.textContent = 'Try again';
        b.addEventListener('click', function () { box.remove(); retry(); });
        box.appendChild(t);
        box.appendChild(b);
        region.insertBefore(box, region.firstChild);
    }

    document.querySelectorAll('form[data-live-table]').forEach(function (form) {
        var id = form.getAttribute('data-live-table');
        var input = form.querySelector('[data-live-search]');
        var clear = form.querySelector('[data-search-clear]');
        var timer = null, last = input ? input.value.trim().replace(/\s+/g, ' ') : '';
        var submitBtn = form.querySelector('[data-live-submit]');
        if (submitBtn && window.fetch) { submitBtn.classList.add('wm-live-hidden'); }
        function run() { loadRegion(id, regionUrl(form), false); }
        if (input) {
            input.addEventListener('input', function () {
                if (clear) { clear.hidden = input.value === ''; }
                clearTimeout(timer);
                timer = setTimeout(function () {
                    var now = input.value.trim().replace(/\s+/g, ' ');
                    if (now === last) { return; }
                    last = now;
                    run();
                }, 300);
            });
            input.addEventListener('keydown', function (e) { if (e.key === 'Escape' && input.value) { input.value = ''; input.dispatchEvent(new Event('input')); } });
        }
        if (clear) {
            clear.addEventListener('click', function () { input.value = ''; clear.hidden = true; last = ''; run(); input.focus(); });
        }
        form.querySelectorAll('[data-live-filter]').forEach(function (sel) { sel.addEventListener('change', run); });
        form.addEventListener('submit', function (e) {
            if (!window.fetch) { return; }
            e.preventDefault();
            clearTimeout(timer);
            if (input) { last = input.value.trim().replace(/\s+/g, ' '); }
            run();
        });
    });

    // Instant filter for small lists already on the page (data-client-filter): hides rows that do not match, whole
    // groups that become empty, and says how many match. Only for bounded lists; large ones use live tables above.
    document.querySelectorAll('[data-client-filter]').forEach(function (input) {
        var key = input.getAttribute('data-client-filter');
        var rows = document.querySelectorAll('[data-client-filter-row="' + key + '"]');
        var count = document.querySelector('[data-client-filter-count="' + key + '"]');
        var empty = document.querySelector('[data-client-filter-empty="' + key + '"]');
        var timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var q = input.value.trim().replace(/\s+/g, ' ').toLowerCase();
                var shown = 0;
                rows.forEach(function (r) {
                    var hit = !q || r.textContent.replace(/\s+/g, ' ').toLowerCase().indexOf(q) !== -1;
                    r.hidden = !hit;
                    if (hit) { shown++; }
                });
                document.querySelectorAll('[data-client-filter-group="' + key + '"]').forEach(function (g) {
                    g.hidden = !g.querySelector('[data-client-filter-row]:not([hidden])');
                });
                if (count) { count.textContent = q ? shown + ' of ' + rows.length + ' match' : rows.length + ' messages'; }
                if (empty) { empty.hidden = shown > 0; }
            }, 150);
        });
    });

    // Links and rows-per-page inside a region (delegated, so they keep working after each update).
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
        var region = a.closest('[data-table-region]');
        if (!region || !window.fetch || (!a.closest('.pagination') && !a.hasAttribute('data-live-link'))) { return; }
        if (a.closest('.page-item.disabled')) { e.preventDefault(); return; }
        e.preventDefault();
        loadRegion(region.id, a.href, true);
    });
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel || !sel.matches || !sel.matches('[data-live-per]')) { return; }
        var sizeForm = sel.closest('form[data-live-size]');
        if (!sizeForm || !window.fetch) { if (sizeForm) { sizeForm.submit(); } return; }
        var params = new URLSearchParams(new FormData(sizeForm));
        loadRegion(sizeForm.getAttribute('data-live-size'), sizeForm.action.split('?')[0] + '?' + params.toString(), false);
    });
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f && f.hasAttribute && f.hasAttribute('data-live-size') && window.fetch) {
            e.preventDefault();
            loadRegion(f.getAttribute('data-live-size'), f.action.split('?')[0] + '?' + new URLSearchParams(new FormData(f)).toString(), false);
        }
    }, true);

    // ---------------------------------------------------------------- tables already on the page (data-wm-table)
    // Live search, click-to-sort headings, "Showing 1–10 of N", rows per page and page buttons, all in the browser.
    // For bounded lists the server sends whole (reports, dashboard widgets, settings lists); big lists use the live
    // tables above. data-wm-table="8" sets the first page size; data-wm-noun="orders" names the rows;
    // data-no-sort on a heading keeps it fixed; a row with data-wm-fixed (e.g. a total) always stays last.
    var SIZES = [5, 10, 15, 25, 50];
    function cellValue(td) {
        if (!td) { return ''; }
        return (td.getAttribute('data-sort') !== null ? td.getAttribute('data-sort') : td.textContent).replace(/\s+/g, ' ').trim();
    }
    function asNumber(t) {
        if (t === '' || t === '—' || t === '-') { return null; }
        var m = t.replace(/KSh|Ksh|%|,|\s/g, '').match(/^-?\d+(\.\d+)?$/);
        return m ? parseFloat(m[0]) : NaN;
    }
    function enhanceTable(table) {
        if (table.hasAttribute('data-wm-enhanced') || table.closest('[data-table-region]')) { return; }
        var tbody = table.tBodies[0];
        if (!tbody) { return; }
        table.setAttribute('data-wm-enhanced', '');
        var noun = table.getAttribute('data-wm-noun') || 'rows';
        var key = 'wm-table-per:' + location.pathname + ':' + (table.id || Array.prototype.indexOf.call(document.querySelectorAll('table[data-wm-table]'), table));
        var stored = null;
        try { stored = parseInt(window.localStorage.getItem(key), 10); } catch (e) { stored = null; }
        var first = parseInt(table.getAttribute('data-wm-table'), 10) || 10;
        var sizes = SIZES.indexOf(first) === -1 ? SIZES.concat([first]).sort(function (a, b) { return a - b; }) : SIZES.slice();
        var per = sizes.indexOf(stored) !== -1 ? stored : first;
        var all = Array.prototype.slice.call(tbody.rows).filter(function (tr) {
            return !(tr.cells.length === 1 && tr.cells[0].colSpan > 1) && !tr.hasAttribute('data-wm-fixed');   // skip "No data" rows
        });
        var fixed = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-wm-fixed]'));
        all.forEach(function (tr, i) { tr._wmText = tr.textContent.replace(/\s+/g, ' ').toLowerCase(); tr._wmIndex = i; });
        var page = 1, q = '', sortCol = -1, sortDir = 1, matches = all;
        var holder = table.closest('.table-responsive') || table;
        var uid = 'wmt' + Math.random().toString(36).slice(2, 8);

        // Toolbar: a search box once there is something to search (6 rows or more).
        var input = null;
        if (all.length > 5) {
            var bar = document.createElement('div');
            bar.className = 'wm-table-toolbar wm-table-toolbar-inline';
            bar.setAttribute('role', 'search');
            var box = document.createElement('div');
            box.className = 'wm-search';
            var lab = document.createElement('label');
            lab.className = 'visually-hidden';
            lab.htmlFor = uid + '-q';
            lab.textContent = 'Search ' + noun;
            var icon = document.createElement('i');
            icon.className = 'bi bi-search';
            icon.setAttribute('aria-hidden', 'true');
            input = document.createElement('input');
            input.type = 'search';
            input.id = uid + '-q';
            input.className = 'form-control form-control-sm';
            input.placeholder = 'Search ' + noun + '…';
            input.autocomplete = 'off';
            var clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'wm-search-clear';
            clear.hidden = true;
            clear.setAttribute('aria-label', 'Clear search');
            clear.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            box.appendChild(lab); box.appendChild(icon); box.appendChild(input); box.appendChild(clear);
            bar.appendChild(box);
            holder.parentNode.insertBefore(bar, holder);
            var t = null;
            input.addEventListener('input', function () {
                clear.hidden = input.value === '';
                clearTimeout(t);
                t = setTimeout(function () { q = input.value.trim().toLowerCase(); page = 1; draw(); }, 120);
            });
            clear.addEventListener('click', function () { input.value = ''; clear.hidden = true; q = ''; page = 1; draw(); input.focus(); });
        }

        // Footer: count, rows per page, pages.
        var foot = document.createElement('div');
        foot.className = 'wm-table-footer';
        var count = document.createElement('div');
        count.className = 'wm-table-count';
        count.setAttribute('role', 'status');
        var size = document.createElement('div');
        size.className = 'wm-table-size';
        var sl = document.createElement('label');
        sl.htmlFor = uid + '-per';
        sl.textContent = 'Rows per page';
        var sel = document.createElement('select');
        sel.className = 'form-select form-select-sm';
        sel.id = uid + '-per';
        sizes.forEach(function (n) {
            var o = document.createElement('option');
            o.value = String(n); o.textContent = String(n); o.selected = n === per;
            sel.appendChild(o);
        });
        size.appendChild(sl); size.appendChild(sel);
        var nav = document.createElement('nav');
        nav.setAttribute('aria-label', 'Pages');
        var ul = document.createElement('ul');
        ul.className = 'pagination pagination-sm mb-0';
        nav.appendChild(ul);
        foot.appendChild(count); foot.appendChild(size); foot.appendChild(nav);
        holder.parentNode.insertBefore(foot, holder.nextSibling);
        sel.addEventListener('change', function () {
            per = parseInt(sel.value, 10);
            try { window.localStorage.setItem(key, String(per)); } catch (e) { /* private browsing: this visit only */ }
            page = 1; draw();
        });

        // Row shown when a search matches nothing.
        var cols = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0].cells.length : (all[0] ? all[0].cells.length : 1);
        var none = document.createElement('tr');
        none.className = 'wm-empty-row';
        none.hidden = true;
        var ntd = document.createElement('td');
        ntd.colSpan = cols;
        ntd.className = 'text-center text-muted py-4';
        none.appendChild(ntd);
        tbody.appendChild(none);

        function pageButton(label, target, opts) {
            var li = document.createElement('li');
            li.className = 'page-item' + (opts.disabled ? ' disabled' : '') + (opts.active ? ' active' : '');
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'page-link';
            if (opts.icon) {
                var i = document.createElement('i');
                i.className = 'bi ' + opts.icon;
                i.setAttribute('aria-hidden', 'true');
                b.appendChild(i);
            } else {
                b.textContent = label;
            }
            if (opts.aria) { b.setAttribute('aria-label', opts.aria); }
            if (opts.active) { b.setAttribute('aria-current', 'page'); }
            if (opts.disabled) {
                b.disabled = true;
            } else {
                b.addEventListener('click', function () {
                    page = target; draw();
                    var again = ul.querySelector('[aria-current="page"]');
                    if (again) { again.focus(); }
                });
            }
            li.appendChild(b);
            ul.appendChild(li);
        }

        function draw() {
            matches = q ? all.filter(function (tr) { return tr._wmText.indexOf(q) !== -1; }) : all.slice();
            if (sortCol >= 0) {
                var numeric = matches.every(function (tr) { var n = asNumber(cellValue(tr.cells[sortCol])); return n === null || !isNaN(n); });
                matches.sort(function (a, b) {
                    var x = cellValue(a.cells[sortCol]), y = cellValue(b.cells[sortCol]);
                    var r;
                    if (numeric) {
                        var nx = asNumber(x), ny = asNumber(y);
                        r = (nx === null ? -Infinity : nx) - (ny === null ? -Infinity : ny);
                    } else {
                        r = x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
                    }
                    return r ? r * sortDir : a._wmIndex - b._wmIndex;
                });
            }
            var pages = Math.max(1, Math.ceil(matches.length / per));
            page = Math.min(Math.max(1, page), pages);
            var from = (page - 1) * per, to = from + per;
            all.forEach(function (tr) { tr.hidden = true; });
            matches.forEach(function (tr, i) { tbody.insertBefore(tr, none); tr.hidden = i < from || i >= to; });
            fixed.forEach(function (tr) { tbody.appendChild(tr); });
            none.hidden = matches.length > 0 || all.length === 0;
            ntd.textContent = 'No ' + noun + ' match “' + (input ? input.value.trim() : '') + '”.';

            count.textContent = matches.length === 0 ? (q ? 'No matches' : 'No ' + noun)
                : 'Showing ' + (from + 1) + '–' + Math.min(to, matches.length) + ' of ' + matches.length + ' ' + (q ? 'matching ' : '') + noun
                    + (q ? ' (' + all.length + ' in all)' : '');
            size.hidden = all.length <= sizes[0];
            ul.textContent = '';
            nav.hidden = pages <= 1;
            if (pages > 1) {
                pageButton('', page - 1, { disabled: page <= 1, icon: 'bi-chevron-left', aria: 'Previous page' });
                var prev = 0;
                [1, page - 1, page, page + 1, pages].filter(function (n, i, a) { return n >= 1 && n <= pages && a.indexOf(n) === i; })
                    .sort(function (a, b) { return a - b; }).forEach(function (n) {
                        if (n - prev > 1) { pageButton('…', n, { disabled: true }); }
                        pageButton(String(n), n, { active: n === page });
                        prev = n;
                    });
                pageButton('', page + 1, { disabled: page >= pages, icon: 'bi-chevron-right', aria: 'Next page' });
            }
            foot.hidden = all.length === 0;
            document.dispatchEvent(new CustomEvent('wm:region-updated'));
        }

        // Click-to-sort headings (buttons, so the keyboard works). A third click restores the original order.
        if (table.tHead && table.tHead.rows[0] && all.length > 1) {
            var heads = table.tHead.rows[0].cells;
            Array.prototype.forEach.call(heads, function (th, i) {
                var label = th.textContent.trim();
                if (!label || th.hasAttribute('data-no-sort') || th.colSpan > 1) { return; }
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'wm-sort-btn';
                var span = document.createElement('span');
                span.textContent = label;
                var ic = document.createElement('i');
                ic.className = 'bi bi-arrow-down-up';
                ic.setAttribute('aria-hidden', 'true');
                b.appendChild(span); b.appendChild(ic);
                th.textContent = '';
                th.appendChild(b);
                th.setAttribute('aria-sort', 'none');
                b.addEventListener('click', function () {
                    if (sortCol !== i) { sortCol = i; sortDir = 1; } else if (sortDir === 1) { sortDir = -1; } else { sortCol = -1; }
                    Array.prototype.forEach.call(heads, function (h, j) {
                        var btn = h.querySelector('.wm-sort-btn');
                        if (!btn) { return; }
                        h.setAttribute('aria-sort', j === sortCol ? (sortDir === 1 ? 'ascending' : 'descending') : 'none');
                        btn.lastChild.className = 'bi ' + (j !== sortCol ? 'bi-arrow-down-up' : (sortDir === 1 ? 'bi-sort-up' : 'bi-sort-down'));
                        btn.classList.toggle('active', j === sortCol);
                    });
                    page = 1; draw();
                });
            });
        }
        draw();
    }
    window.WM.enhanceTable = enhanceTable;
    document.querySelectorAll('table[data-wm-table]').forEach(enhanceTable);
})();
