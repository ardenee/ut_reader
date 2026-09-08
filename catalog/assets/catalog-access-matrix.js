(function () {
    'use strict';

    if (!document.documentElement || !document.body) return;

    var endpoint = 'access-event.php';
    var script = document.currentScript;
    if (script && script.src) {
        try {
            endpoint = new URL('../access-event.php', script.src).toString();
        } catch (error) {
            endpoint = 'access-event.php';
        }
    }

    function clean(value, limit) {
        value = String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
        return value.length > limit ? value.slice(0, limit) : value;
    }

    function pageKey() {
        var base = (window.location.pathname.split('/').pop() || 'index.php');
        if (base === 'index.php') {
            var page = new URLSearchParams(window.location.search).get('page');
            if (page) return base + ':' + clean(page, 80).replace(/[^A-Za-z0-9._-]/g, '');
        }
        return base;
    }

    function requestPath() {
        return clean(window.location.pathname + window.location.search, 500);
    }

    function sameSitePath(url) {
        if (!url) return '';
        try {
            var parsed = new URL(url, window.location.href);
            if (parsed.origin !== window.location.origin) return '';
            return clean(parsed.pathname + parsed.search, 500);
        } catch (error) {
            return '';
        }
    }

    function send(fields) {
        var body = new URLSearchParams();
        body.set('event_type', fields.event_type || '');
        body.set('page_key', pageKey());
        body.set('request_path', requestPath());
        if (fields.section_key) body.set('section_key', clean(fields.section_key, 190));
        if (fields.action_key) body.set('action_key', clean(fields.action_key, 190));
        if (fields.target_path) body.set('target_path', clean(fields.target_path, 500));
        var referrer = sameSitePath(document.referrer);
        if (referrer) body.set('referrer_path', referrer);

        fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            keepalive: true,
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}
        }).catch(function () {});
    }

    function sectionName(node) {
        if (!node || !node.querySelector) return '';
        var heading = node.querySelector(':scope > h1, :scope > h2, :scope > h3, :scope > .ui-section__header h2, h1, h2');
        return heading ? clean(heading.textContent, 190) : '';
    }

    function trackPageView() {
        send({event_type: 'page_view'});
    }

    function trackSections() {
        if (typeof IntersectionObserver === 'undefined') return;
        var seen = new Set();
        var candidates = Array.from(document.querySelectorAll('main > .card, main > .ui-section, main section'))
            .filter(function (node) { return sectionName(node) !== ''; });
        if (!candidates.length) return;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting || entry.intersectionRatio < 0.2) return;
                var name = sectionName(entry.target);
                if (!name || seen.has(name)) return;
                seen.add(name);
                observer.unobserve(entry.target);
                send({event_type: 'section', section_key: name});
            });
        }, {threshold: [0.2]});
        candidates.forEach(function (node) { observer.observe(node); });
    }

    function actionLabel(element) {
        return clean(
            element.getAttribute('aria-label')
            || element.getAttribute('title')
            || element.textContent
            || element.value
            || element.name
            || element.tagName,
            190
        );
    }

    document.addEventListener('click', function (event) {
        var element = event.target && event.target.closest
            ? event.target.closest('a[href],button,input[type="submit"],input[type="button"]')
            : null;
        if (!element) return;

        // Normal same-site link navigation is already represented by the
        // destination page_view + referrer pair. Recording the click as another
        // raw activity row only duplicates the same navigation.
        if (element.matches('a[href]') && sameSitePath(element.getAttribute('href'))) {
            return;
        }

        var target = '';
        if (element.matches('a[href]')) {
            target = sameSitePath(element.getAttribute('href'));
        } else if (element.form) {
            target = sameSitePath(element.form.getAttribute('action') || window.location.href);
        }
        send({
            event_type: 'interaction',
            action_key: actionLabel(element),
            target_path: target
        });
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            trackPageView();
            trackSections();
        }, {once: true});
    } else {
        trackPageView();
        trackSections();
    }

    // Back/forward-cache restores do not fire DOMContentLoaded again. Count the
    // restored page as a new navigation without double-counting ordinary loads.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) trackPageView();
    });
})();
