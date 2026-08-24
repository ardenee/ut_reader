(function () {
    'use strict';

    const body = document.getElementById('jobs-file-body');
    if (!body) return;

    const supported = new Set([
        'default',
        'u', 'ut2', 'ut3', 'unr', 'un2', 'umap',
        'utx', 'usx', 'ukx', 'uax', 'umx', 'upx', 'ugx',
        'upk', 'uasset', 'md5', 'bak',
        'umod', 'ut2mod', 'ut3mod', 'ut4mod',
        'zip', 'rar', '7z', 'pak', 'uz', 'uz2', 'uz3'
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

    function addIcon(row) {
        if (!(row instanceof HTMLElement) || !row.classList.contains('jobs-file-row')) return;
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
        use.setAttribute('href', 'assets/file-icons.svg#file-icon-' + key);
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
