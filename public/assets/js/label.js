/* Package labels: the QR code of each parcel's staff page, and the Print button (CSP-safe, no inline handlers). */
(function () {
    'use strict';
    // QR codes first, so they are on the page before any print dialog opens.
    if (window.qrcode) {
        document.querySelectorAll('[data-parcel-qr]').forEach(function (box) {
            var qr = window.qrcode(0, 'M');
            qr.addData(box.getAttribute('data-parcel-qr'));
            qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 3, margin: 9, scalable: true });
            var svg = box.querySelector('svg');
            if (svg) { svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false'); svg.setAttribute('shape-rendering', 'crispEdges'); }
        });
    }
    document.querySelectorAll('[data-print]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });
    // Opened with ?print=1 (the "Print label" buttons): show the print dialog straight away.
    if (/[?&]print=1\b/.test(location.search)) {
        window.addEventListener('load', function () { window.print(); });
    }
})();
