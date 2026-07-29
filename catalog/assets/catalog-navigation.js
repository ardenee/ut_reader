(function () {
    'use strict';

    function navigationGroups() {
        var groups = window.UnrealDbAdminNavigation;
        return groups && typeof groups === 'object' ? groups : null;
    }

    function closeMenus(nav, except) {
        nav.querySelectorAll('details[data-admin-menu]').forEach(function (details) {
            if (details !== except) details.open = false;
        });
    }

    function bindNavigationEvents(nav) {
        if (nav.dataset.canonicalNavigationBound === '1') return;
        nav.dataset.canonicalNavigationBound = '1';

        nav.addEventListener('click', function (event) {
            if (event.target.closest('.nav-menu a')) closeMenus(nav, null);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('nav.primary-nav details[data-admin-menu]')) {
                closeMenus(nav, null);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            var openMenu = nav.querySelector('details[data-admin-menu][open]');
            if (!openMenu) return;
            openMenu.open = false;
            var summary = openMenu.querySelector('summary');
            if (summary) summary.focus();
        });
    }

    function restoreCanonicalNavigation() {
        var nav = document.querySelector('nav.primary-nav');
        var logout = nav && nav.querySelector('form.nav-logout');
        var groups = navigationGroups();
        if (!nav || !logout || !groups) return;

        var current = new URL(window.location.href);
        nav.querySelectorAll('details[data-admin-menu]').forEach(function (details) {
            details.remove();
        });

        Object.keys(groups).forEach(function (label) {
            var links = groups[label];
            if (!links || typeof links !== 'object') return;

            var details = document.createElement('details');
            details.className = 'nav-dropdown';
            details.dataset.adminMenu = label;

            var summary = document.createElement('summary');
            summary.textContent = label;
            summary.setAttribute('aria-label', label + ' menu');
            details.appendChild(summary);

            var menu = document.createElement('div');
            menu.className = 'nav-menu';
            menu.setAttribute('role', 'menu');

            Object.keys(links).forEach(function (title) {
                var href = String(links[title] || '');
                if (!href) return;
                var link = document.createElement('a');
                var url = new URL(href, window.location.href);
                link.href = url.pathname + url.search + url.hash;
                link.textContent = title;
                link.setAttribute('role', 'menuitem');
                if (current.pathname === url.pathname && current.search === url.search) {
                    link.setAttribute('aria-current', 'page');
                }
                menu.appendChild(link);
            });

            details.appendChild(menu);
            details.addEventListener('toggle', function () {
                if (details.open) closeMenus(nav, details);
            });
            nav.insertBefore(details, logout);
        });

        bindNavigationEvents(nav);
    }

    document.addEventListener('DOMContentLoaded', function () {
        restoreCanonicalNavigation();
        window.setTimeout(restoreCanonicalNavigation, 0);
    });
}());
