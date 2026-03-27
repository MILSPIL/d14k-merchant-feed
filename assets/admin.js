/**
 * MIL SPIL GMC Feed — Admin JS
 * Handles WP admin notice auto-dismiss
 */
(function () {
    'use strict';

    // Auto-dismiss WP success notices after 4 seconds
    document.querySelectorAll('.d14k-wrap .notice-success').forEach(function (notice) {
        setTimeout(function () {
            notice.style.transition = 'opacity .3s ease';
            notice.style.opacity = '0';
            setTimeout(function () { notice.remove(); }, 300);
        }, 4000);
    });
})();
