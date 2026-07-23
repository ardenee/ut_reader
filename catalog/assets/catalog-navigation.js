(function () {
    'use strict';

    function catalogRootPath() {
        var path = window.location.pathname || '/';
        var marker = '/catalog/';
        var index = path.indexOf(marker);
        return index >= 0 ? path.slice(0, index + marker.length) : '/catalog/';
    }

    function replaceFederationMenu() {
        var nav = document.querySelector('nav.primary-nav');
        if (!nav) return;
        var federation = Array.from(nav.querySelectorAll('details[data-admin-menu]')).find(function (details) {
            var summary = details.querySelector('summary');
            return summary && summary.textContent.trim() === 'Federation';
        });
        if (!federation) return;

        var menu = federation.querySelector('.nav-menu');
        if (!menu) return;
        var root = catalogRootPath();
        var current = new URL(window.location.href);
        var links = [
            ['Overview', 'federation/admin.php'],
            ['Settings', 'federation/settings.php'],
            ['Connections', 'federation/peers.php'],
            ['Parents', 'federation/peers.php?role=parent'],
            ['Join a Parent', 'federation/join-main-parent.php'],
            ['Children', 'federation/peers.php?role=child'],
            ['Incoming Child Join Requests', 'federation/join-requests.php'],
            ['Missing Files', 'federation/missing-files.php'],
            ['Requests', 'federation/request-center.php'],
            ['Incoming File Requests', 'federation/requests.php'],
            ['Outgoing File Requests', 'federation/request-status.php'],
            ['Approved Downloads', 'federation/approved-downloads.php'],
            ['Child Inventories', 'federation/peer-inventory.php'],
            ['Parent Pull', 'federation/parent-pull.php'],
            ['Transfer Queue', 'federation/queue.php'],
            ['Run Worker', 'federation/worker-run.php'],
            ['Conflicts', 'federation/conflicts.php'],
            ['Maintenance', 'federation/maintenance.php'],
            ['Logs', 'federation/logs.php'],
            ['Documentation', 'federation/docs.php']
        ];

        menu.replaceChildren();
        links.forEach(function (entry) {
            var link = document.createElement('a');
            var url = new URL(root + entry[1], window.location.origin);
            link.href = url.pathname + url.search + url.hash;
            link.textContent = entry[0];
            link.setAttribute('role', 'menuitem');
            if (current.pathname === url.pathname && current.search === url.search) {
                link.setAttribute('aria-current', 'page');
            }
            menu.appendChild(link);
        });
    }

    document.addEventListener('DOMContentLoaded', replaceFederationMenu);
}());
