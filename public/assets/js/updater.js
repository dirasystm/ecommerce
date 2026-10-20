/* Website updates (Admin → System → Website updates): chunked upload, step-by-step preparation and installation with a
   spinner, the current task and a percentage, the browser's own checks of the updated site, and automatic emergency
   rollback if the new version fails them. */
(function () {
    'use strict';
    var root = document.querySelector('[data-updater]');
    if (!root) { return; }

    var base = root.getAttribute('data-base');
    var csrf = root.getAttribute('data-csrf');
    var maxBytes = parseInt(root.getAttribute('data-max'), 10);
    var CHUNK = 2 * 1024 * 1024;
    var job = JSON.parse(root.getAttribute('data-active') || 'null');
    var rescueToken = null;
    var $ = function (sel) { return root.querySelector(sel); };

    var PREPARE = [['upload', 'Upload'], ['inspecting', 'Check the package'], ['extracting', 'Unpack'], ['comparing', 'Compare with the live website'], ['planning', 'Check database changes']];
    var INSTALL = [['backing_up_db', 'Back up the database'], ['backing_up_files', 'Back up files that will change'], ['staging', 'Prepare new files beside the live ones'],
                   ['applying', 'Apply database additions and switch files'], ['verifying', 'Check the updated website'], ['finalizing', 'Finish']];
    var ORDER = ['uploading', 'uploaded', 'inspecting', 'extracting', 'comparing', 'planning', 'approved', 'backing_up_db', 'backing_up_files', 'staging', 'applying', 'verifying', 'finalizing'];

    function show(view) {
        root.querySelectorAll('[data-view]').forEach(function (v) { v.hidden = v.getAttribute('data-view') !== view; });
    }

    function steps(list, status) {
        var at = ORDER.indexOf(status === 'uploading' ? 'uploading' : status);
        var ol = $('[data-steps]');
        ol.innerHTML = '';
        list.forEach(function (s) {
            var key = s[0] === 'upload' ? 'uploading' : s[0];
            var idx = ORDER.indexOf(key);
            var li = document.createElement('li');
            var state = idx < at ? 'done' : (idx === at || (key === 'inspecting' && status === 'uploaded') ? 'now' : 'todo');
            li.className = 'wm-update-step ' + state;
            li.innerHTML = '<i class="bi ' + (state === 'done' ? 'bi-check-circle-fill' : (state === 'now' ? 'bi-arrow-right-circle-fill' : 'bi-circle')) + '" aria-hidden="true"></i> ';
            li.appendChild(document.createTextNode(s[1] + (state === 'done' ? ' (done)' : '')));
            ol.appendChild(li);
        });
    }

    function progress(title, percent, message, list, status) {
        show('progress');
        $('[data-phase]').textContent = title;
        $('[data-percent]').textContent = percent + '%';
        $('[data-message]').textContent = message;
        $('[data-bar]').style.width = percent + '%';
        $('[data-bar-wrap]').setAttribute('aria-valuenow', String(percent));
        steps(list, status);
    }

    function li(list, text, cls) {
        var item = document.createElement('li');
        if (cls) { item.className = cls; }
        item.textContent = text;
        list.appendChild(item);
    }

    function review(j) {
        show('review');
        $('[data-package-name]').textContent = j.package.name;
        $('[data-count="changed"]').textContent = j.files.changed_count;
        $('[data-count="added"]').textContent = j.files.added_count;
        $('[data-count="unchanged"]').textContent = j.files.unchanged;
        $('[data-count="db"]').textContent = j.plan.length;
        ['changed', 'added'].forEach(function (k) {
            var ul = $('[data-list="' + k + '"]');
            ul.innerHTML = '';
            j.files[k].forEach(function (f) { li(ul, f); });
            if (j.files[k + '_count'] > j.files[k].length) { li(ul, '… and ' + (j.files[k + '_count'] - j.files[k].length) + ' more'); }
            if (!j.files[k].length) { li(ul, 'None', 'text-muted'); }
        });
        var db = $('[data-list="db"]');
        db.innerHTML = '';
        j.plan.forEach(function (m) {
            m.statements.forEach(function (s) { li(db, m.file + ': ' + s.summary, s.ok ? '' : 'text-danger'); });
        });
        if (!j.plan.length) { li(db, 'None', 'text-muted'); }
        var blocked = j.blocked.length > 0;
        $('[data-blocked]').hidden = !blocked;
        var bl = $('[data-blocked-list]');
        bl.innerHTML = '';
        j.blocked.forEach(function (b) { li(bl, b); });
        $('[data-install-button]').disabled = blocked;
        $('#installPassword').disabled = blocked;
    }

    function result(j) {
        show('result');
        var ok = j.status === 'completed' || j.status === 'nothing';
        var icon = $('[data-result-icon]');
        icon.className = 'bi fs-1 ' + (ok ? 'bi-check-circle-fill text-success' : (j.status === 'rolled_back' ? 'bi-arrow-counterclockwise text-warning' : 'bi-x-circle-fill text-danger'));
        $('[data-result-title]').textContent = { completed: 'Update installed', nothing: 'Nothing to install', rolled_back: 'Update rolled back', failed: 'Update not installed', cancelled: 'Cancelled' }[j.status] || 'Finished';
        $('[data-result-message]').textContent = j.progress.message;
        var log = $('[data-result-log]');
        log.innerHTML = '';
        (j.log || []).forEach(function (l) { li(log, l.at + '  ' + l.message); });
    }

    function render(j) {
        job = j;
        if (j.final) { return result(j); }
        if (j.status === 'ready' || j.status === 'blocked') { return review(j); }
        if (j.status === 'uploading') {
            return progress('Uploading the package', j.progress.percent, 'Uploading ' + j.package.name + '…', PREPARE, 'uploading');
        }
        if (j.installing) { return progress('Installing the update', j.progress.percent, j.progress.message, INSTALL, j.status); }
        return progress('Checking the package', j.progress.percent, j.progress.message, PREPARE, j.status);
    }

    function request(method, url, body) {
        return fetch(url, {
            method: method, body: body, credentials: 'same-origin', cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Unexpected response (' + r.status + ')' }; }).then(function (data) {
                if (!r.ok) { var e = new Error(data.message || ('Error ' + r.status)); e.status = r.status; e.data = data; throw e; }
                return data;
            });
        });
    }

    function form(fields) {
        var fd = new FormData();
        fd.append('_csrf', csrf);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fd;
    }

    var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };

    // The browser's own checks of the updated website: the health check and the home page must answer normally.
    function siteWorks() {
        return fetch(root.getAttribute('data-health'), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('Health check answered ' + r.status)); })
            .then(function (h) { if (h.status !== 'ok') { throw new Error('Health check: ' + JSON.stringify(h)); } })
            .then(function () { return fetch(root.getAttribute('data-home'), { cache: 'no-store', credentials: 'same-origin' }); })
            .then(function (r) {
                if (!r.ok) { throw new Error('The home page answered ' + r.status); }
                return r.text();
            })
            .then(function (html) { if (/Fatal error|Parse error|Uncaught/i.test(html)) { throw new Error('The home page shows a PHP error'); } });
    }

    function rescue(reason) {
        progress('Rolling back', 99, 'A problem was found (' + reason + '). Putting the website back as it was…', INSTALL, job ? job.status : 'applying');
        var token = rescueToken || readToken();
        if (!token) { return Promise.reject(new Error('Rollback token missing: run php bin/update-rescue.php ' + job.uuid + ' --confirm on the server.')); }
        var fd = new FormData();
        fd.append('update', job.uuid);
        fd.append('token', token);
        fd.append('reason', reason);
        return fetch(root.getAttribute('data-rescue'), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                forgetToken();
                result({ status: 'rolled_back', final: true, progress: { message: (data.message || 'Rolled back.') + ' Reason: ' + reason }, log: job.log || [] });
            });
    }

    function remember(token) { try { sessionStorage.setItem('bm-update-' + job.uuid, token); } catch (e) { /* private mode */ } }
    function readToken() { try { return sessionStorage.getItem('bm-update-' + job.uuid); } catch (e) { return null; } }
    function forgetToken() { try { sessionStorage.removeItem('bm-update-' + job.uuid); } catch (e) { /* ignore */ } }

    // Runs steps until the update stops for review or finishes.
    function run() {
        if (!job || job.final || job.status === 'ready' || job.status === 'blocked' || job.status === 'uploading') { return Promise.resolve(); }
        var before = Promise.resolve();
        if (job.status === 'verifying') {
            before = siteWorks();
        }
        return before.then(function () {
            return request('POST', base + '/' + job.uuid + '/step', form({}));
        }).then(function (j) {
            render(j);
            if (j.final) { forgetToken(); return; }
            return (j.busy ? sleep(400) : Promise.resolve()).then(run);
        }, function (err) {
            // After the switch, a failing check or a failing step means the new version is not working: roll back.
            if (job && ['staging', 'applying', 'verifying', 'finalizing'].indexOf(job.status) !== -1) {
                return rescue(err.message);
            }
            return request('GET', base + '/' + job.uuid).then(function (j) { render(j); if (!j.final) { return sleep(1000).then(run); } });
        });
    }

    function upload(file) {
        var err = $('[data-upload-error]');
        err.hidden = true;
        if (!/\.zip$/i.test(file.name)) { err.textContent = 'Choose a .zip file.'; err.hidden = false; return; }
        if (file.size > maxBytes) { err.textContent = 'The package is too large.'; err.hidden = false; return; }
        progress('Uploading the package', 0, 'Uploading ' + file.name + '…', PREPARE, 'uploading');
        request('POST', base, form({ name: file.name, size: file.size })).then(function (j) {
            job = j;
            var total = Math.ceil(file.size / CHUNK);
            var sendChunk = function (i) {
                if (i >= total) { return Promise.resolve(); }
                return new Promise(function (resolve, reject) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', base + '/' + job.uuid + '/chunk');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                    xhr.upload.onprogress = function (e) {
                        var sent = i * CHUNK + (e.lengthComputable ? e.loaded : 0);
                        var pct = Math.min(99, Math.floor(sent * 100 / file.size));
                        progress('Uploading the package', pct, 'Uploading ' + file.name + ': ' + (sent / 1048576).toFixed(1) + ' of ' + (file.size / 1048576).toFixed(1) + ' MB', PREPARE, 'uploading');
                    };
                    xhr.onload = function () {
                        var data = {};
                        try { data = JSON.parse(xhr.responseText); } catch (e) { /* handled below */ }
                        if (xhr.status >= 200 && xhr.status < 300) { job = data; resolve(); } else { reject(new Error(data.message || ('Upload failed (' + xhr.status + ')'))); }
                    };
                    xhr.onerror = function () { reject(new Error('The connection was interrupted during the upload.')); };
                    xhr.send(form({ index: i, chunk: file.slice(i * CHUNK, Math.min(file.size, (i + 1) * CHUNK), 'application/zip') }));
                }).then(function () { return sendChunk(i + 1); });
            };
            return sendChunk(0);
        }).then(function () {
            render(job);
            return run();
        }).catch(function (e) {
            show('upload');
            err.textContent = e.message;
            err.hidden = false;
            if (job && job.uuid && job.status === 'uploading') { request('POST', base + '/' + job.uuid + '/cancel', form({})).catch(function () {}); }
        });
    }

    $('[data-upload-form]').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = $('#package').files[0];
        if (f) { upload(f); }
    });

    $('[data-install-form]').addEventListener('submit', function (e) {
        e.preventDefault();
        var err = $('[data-install-error]');
        err.hidden = true;
        var pw = $('#installPassword');
        request('POST', base + '/' + job.uuid + '/approve', form({ password: pw.value })).then(function (j) {
            pw.value = '';
            rescueToken = j.rescue_token;
            job = j;
            remember(rescueToken);
            render(j);
            return run();
        }).catch(function (e2) {
            err.textContent = (e2.data && e2.data.errors && e2.data.errors.password) || e2.message;
            err.hidden = false;
        });
    });

    $('[data-cancel]').addEventListener('click', function () {
        request('POST', base + '/' + job.uuid + '/cancel', form({})).then(function () { window.location.reload(); });
    });

    // Resume where we left off (e.g. the page was reloaded during an update).
    if (job) { render(job); run(); }
})();
