/* Product photo chooser (admin + Seller Centre): previews, reorder, remove, drag & drop, and shrinking of large
 * phone photos before upload so several fit in one form. CSP-safe; works without it (plain multiple file input). */
(function () {
    'use strict';

    var TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    var SHRINK_OVER_BYTES = 1.5 * 1048576;   // photos bigger than this, or wider than MAX_EDGE, are re-encoded
    var MAX_EDGE = 2000;                      // the shop stores 1000 px; 2000 leaves room for sharp zoom later

    function canRebuild() {
        try { return typeof DataTransfer === 'function' && !!new DataTransfer().items; } catch (e) { return false; }
    }

    function mb(bytes) {
        return bytes < 1048576 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : (bytes / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';
    }

    function loadImage(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () { resolve({ img: img, url: url }); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('unreadable')); };
            img.src = url;
        });
    }

    /** Resolves to a File no larger than needed (or the original when it is already small). */
    function shrink(file) {
        return loadImage(file).then(function (r) {
            var w = r.img.naturalWidth, h = r.img.naturalHeight;
            URL.revokeObjectURL(r.url);
            if (file.size <= SHRINK_OVER_BYTES && Math.max(w, h) <= MAX_EDGE) { return file; }
            var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
            var canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(w * scale));
            canvas.height = Math.max(1, Math.round(h * scale));
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';   // transparent PNGs get the same white background the shop uses
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(r.img, 0, 0, canvas.width, canvas.height);
            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) {
                    if (!blob || blob.size >= file.size) { resolve(file); return; }
                    var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                    try { resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() })); } catch (e) { resolve(file); }
                }, 'image/jpeg', 0.9);
            });
        });
    }

    function init(root) {
        var input = root.querySelector('input[type="file"]');
        var list = root.querySelector('[data-photo-previews]');
        var drop = root.querySelector('.wm-photo-drop');
        var form = input && input.form;
        if (!input || !list || !form) { return; }
        var max = parseInt(root.getAttribute('data-max'), 10) || 10;
        var maxBytes = (parseFloat(root.getAttribute('data-max-mb')) || 5) * 1048576;
        var postMax = (parseFloat(root.getAttribute('data-post-max-mb')) || 0) * 1048576;
        var rebuild = canRebuild();
        var files = [];          // File objects in display order (first = main photo)
        var urls = [];
        var busy = 0;
        var notes = [];

        if (max < 1) { input.disabled = true; }

        function sync() {
            if (!rebuild) { return; }
            var dt = new DataTransfer();
            files.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
        }

        function total() { return files.reduce(function (n, f) { return n + f.size; }, 0); }

        function button(icon, label, onClick, disabled) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm btn-light';
            b.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i>';
            b.setAttribute('aria-label', label);
            b.title = label;
            b.disabled = !!disabled;
            b.addEventListener('click', onClick);
            return b;
        }

        function render() {
            urls.forEach(function (u) { URL.revokeObjectURL(u); });
            urls = [];
            list.innerHTML = '';
            files.forEach(function (f, i) {
                var url = URL.createObjectURL(f);
                urls.push(url);
                var tile = document.createElement('div');
                tile.className = 'wm-photo-tile';
                tile.setAttribute('data-photo-tile', '');
                var img = document.createElement('img');
                img.src = url;
                img.alt = 'Photo ' + (i + 1) + ': ' + f.name;
                tile.appendChild(img);
                if (i === 0) {
                    var badge = document.createElement('span');
                    badge.className = 'badge bg-success wm-photo-main';
                    badge.textContent = 'Main';
                    tile.appendChild(badge);
                }
                if (rebuild) {
                    var bar = document.createElement('div');
                    bar.className = 'wm-photo-tools';
                    bar.appendChild(button('bi-arrow-left', 'Move photo ' + (i + 1) + ' earlier', function () { move(i, -1); }, i === 0));
                    bar.appendChild(button('bi-arrow-right', 'Move photo ' + (i + 1) + ' later', function () { move(i, 1); }, i === files.length - 1));
                    bar.appendChild(button('bi-x-lg', 'Remove photo ' + (i + 1), function () { remove(i); }));
                    tile.appendChild(bar);
                }
                var cap = document.createElement('div');
                cap.className = 'wm-photo-cap';
                cap.textContent = mb(f.size);
                tile.appendChild(cap);
                list.appendChild(tile);
            });
            var status = document.createElement('div');
            status.className = 'small mt-1 ' + (notes.length ? 'text-danger' : 'text-muted');
            status.setAttribute('data-photo-status', '');
            var parts = [];
            if (busy) { parts.push('Preparing ' + busy + ' photo' + (busy === 1 ? '' : 's') + '…'); }
            if (files.length) { parts.push(files.length + ' of ' + max + ' photo' + (max === 1 ? '' : 's') + ' chosen (' + mb(total()) + ').'); }
            status.textContent = parts.concat(notes).join(' ');
            if (status.textContent) { list.appendChild(status); }
            if (drop) { drop.classList.toggle('is-full', files.length >= max); }
        }

        function move(i, d) {
            var j = i + d;
            if (j < 0 || j >= files.length) { return; }
            var t = files[i]; files[i] = files[j]; files[j] = t;
            notes = [];
            sync(); render();
        }

        function remove(i) {
            files.splice(i, 1);
            notes = [];
            sync(); render();
        }

        function add(chosen) {
            notes = [];
            var room = max - files.length;
            var accepted = [];
            Array.prototype.forEach.call(chosen, function (f) {
                if (TYPES.indexOf(f.type) === -1) { notes.push('"' + f.name + '" is not a JPEG, PNG or WebP photo.'); return; }
                if (accepted.length >= room) { notes.push('"' + f.name + '" was left out: the limit is ' + max + ' photos.'); return; }
                accepted.push(f);
            });
            if (!accepted.length) { sync(); render(); return; }
            busy += accepted.length;
            render();
            Promise.all(accepted.map(function (f) {
                return shrink(f).catch(function () { return f; }).then(function (out) {
                    if (out.size > maxBytes) { notes.push('"' + f.name + '" is too large (' + mb(out.size) + '; the limit is ' + mb(maxBytes) + ').'); return null; }
                    return out;
                });
            })).then(function (done) {
                busy -= accepted.length;
                done.forEach(function (f) { if (f) { files.push(f); } });
                sync(); render();
            });
        }

        input.addEventListener('change', function () {
            if (!rebuild) {   // old browsers: show what the input holds
                files = Array.prototype.slice.call(input.files || []);
                notes = files.length > max ? ['Choose at most ' + max + ' photos.'] : [];
                render();
                return;
            }
            var chosen = Array.prototype.slice.call(input.files || []);
            sync();   // put back the photos chosen earlier until the new ones are ready
            add(chosen);
        });

        if (drop && rebuild) {
            ['dragenter', 'dragover'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
            });
            drop.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { add(e.dataTransfer.files); }
            });
        }

        form.addEventListener('submit', function (e) {
            var stop = null;
            if (busy) { stop = 'Please wait, the photos are still being prepared.'; }
            else if (files.length > max) { stop = 'Choose at most ' + max + ' photos.'; }
            else if (postMax && total() > postMax * 0.95) { stop = 'The photos are too large together (' + mb(total()) + '). Remove some and upload them afterwards.'; }
            else if (input.required && !files.length && !(input.files && input.files.length)) { stop = 'Attach at least one photo.'; }
            if (stop) {
                e.preventDefault();
                e.stopImmediatePropagation();
                notes = [stop];
                render();
                root.scrollIntoView({ block: 'center' });
            }
        }, true);
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-photo-picker]'), init);

    // After the server sends a form back with mistakes, bring the first one into view.
    var form = document.querySelector('form[data-first-error]');
    var bad = form && form.querySelector('.is-invalid, .invalid-feedback.d-block');
    if (bad) {
        var field = bad.matches('input, select, textarea') ? bad : (bad.parentNode && bad.parentNode.querySelector('input, select, textarea'));
        bad.scrollIntoView({ block: 'center' });
        if (field && field.type !== 'file' && typeof field.focus === 'function') { field.focus({ preventScroll: true }); }
    }
})();
