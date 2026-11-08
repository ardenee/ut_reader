/* Retired privacy acknowledgement and analytics selector.
 * Cached pages may still request this asset. Do not render a popup, block
 * navigation, set a cookie, or transmit analytics.
 */
(function () {
    'use strict';
    function retireOldCookies() {
        for (const name of ['UEDB_PRIVACY_CHOICE', 'UEDB_PRIVACY_NOTICE']) {
            document.cookie = name + '=; Path=/catalog/; Max-Age=0; SameSite=Lax'
                + (location.protocol === 'https:' ? '; Secure' : '');
        }
    }
    retireOldCookies();
})();
