/* Package labels: the Print button (CSP-safe, no inline handlers). */
(function () {
    'use strict';
    document.querySelectorAll('[data-print]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });
    // Opened with ?print=1 (the "Print label" buttons): show the print dialog straight away.
    if (/[?&]print=1\b/.test(location.search)) {
        window.addEventListener('load', function () { window.print(); });
    }
})();
