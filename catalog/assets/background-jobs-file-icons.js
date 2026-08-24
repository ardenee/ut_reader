(function () {
    'use strict';

    const body = document.getElementById('jobs-file-body');
    if (!body) return;

    const spriteUrl = 'assets/file-icons.svg?v=20260824-2';
    const supported = new Set([
        'default',
        'u', 'ut2', 'ut3', 'unr', 'un2', 'umap',
        'utx', 'usx', 'ukx', 'uax', 'umx', 'upx', 'ugx',
        'uasset', 'md5', 'bak',
        'umod', 'ut2mod', 'ut4mod',
        'zip', 'rar', '7z', 'pak', 'upk', 'uz', 'uz2', 'uz3'
    ]);

    function detectedArchiveKey(identity) {
        if (!identity) return '';
        const text = String(identity.textContent || '').toLowerCase();
        if (text.includes('rar archive')) return 'rar';
        if (text.includes('7-zip archive')) return '7z';
        if (text.includes('zip archive')) return 'zip';
        return '';
    }

    function extensionKey(fileName) {
        const name = String(fileName || '').trim().toLowerCase();
        const dot = name.lastIndexOf('.');
        if (dot < 0 || dot === name.length - 1) return 'default';
        const extension = name.slice(dot + 1);
        return supported.has(extension) ? extension : 'default';
    }

    function iconKey(row) {
        const identity = row.querySelector('.jobs-file-identity');
        const detected = detectedArchiveKey(identity);
        if (detected) return detected;
        const name = identity ? identity.querySelector('strong') : null;
        return extensionKey(name ? name.textContent : '');
    }

    function hierarchyMarker(depth, rootGroup) {
        const alternate = Math.abs(Number(rootGroup || 0)) % 2;
        if (depth <= 1) {
            return alternate === 0
                ? 'rgba(148,163,184,0.90)'
                : 'rgba(96,165,250,0.95)';
        }
        return alternate === 0
            ? 'rgba(34,211,238,0.95)'
            : 'rgba(167,139,250,0.95)';
    }

    function hierarchyIndent(depth) {
        if (depth < 1) return 0;
        // First-level archive members move in noticeably. Every nested archive
        // level then gets a much larger step so embedded containers cannot read
        // as a flat list beneath the top-level source.
        return 42 + (Math.min(depth - 1, 5) * 64);
    }

    function applyHierarchy(row) {
        if (!(row instanceof HTMLElement) || !row.classList.contains('jobs-file-row')) return;

        const tree = row.querySelector('.jobs-file-tree');
        const fileCell = row.querySelector('.jobs-file-name-cell');
        if (!tree || !fileCell) return;

        const depth = Math.max(0, parseInt(String(row.dataset.depth || '0'), 10) || 0);
        const rootGroup = Number(row.dataset.rootGroup || 0);

        // The old guide was painted on the table-cell edge, which made every
        // descendant share one vertical rail. Remove it and put the rail at the
        // actual nesting position instead.
        fileCell.style.setProperty('border-left', '0', 'important');
        tree.style.setProperty('padding-left', depth > 0 ? '12px' : '0', 'important');
        tree.style.setProperty('margin-left', hierarchyIndent(depth) + 'px', 'important');

        if (depth < 1) {
            tree.style.removeProperty('border-left');
            tree.style.removeProperty('box-shadow');
            return;
        }

        const marker = hierarchyMarker(depth, rootGroup);
        tree.style.setProperty('border-left', (depth > 1 ? '6px' : '4px') + ' solid ' + marker, 'important');
        tree.style.setProperty('box-shadow', depth > 1
            ? '-1px 0 0 rgba(15,23,42,0.65)'
            : 'none', 'important');
    }

    function addIcon(row) {
        if (!(row instanceof HTMLElement) || !row.classList.contains('jobs-file-row')) return;

        applyHierarchy(row);
        if (row.querySelector('.jobs-file-type-icon')) return;

        const tree = row.querySelector('.jobs-file-tree');
        const identity = row.querySelector('.jobs-file-identity');
        if (!tree || !identity) return;

        const key = iconKey(row);
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.classList.add('jobs-file-type-icon');
        svg.setAttribute('viewBox', '0 0 64 64');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', spriteUrl + '#file-icon-' + key);
        svg.appendChild(use);
        tree.insertBefore(svg, identity);
    }

    function refreshIcons(root) {
        if (root instanceof HTMLElement && root.classList.contains('jobs-file-row')) addIcon(root);
        (root || body).querySelectorAll('.jobs-file-row').forEach(addIcon);
    }

    refreshIcons(body);
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node instanceof HTMLElement) refreshIcons(node);
            });
        });
    }).observe(body, {childList: true, subtree: true});
}());
