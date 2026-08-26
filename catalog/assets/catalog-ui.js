(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var dismiss = event.target.closest('[data-ui-dismiss]');
        if (!dismiss) return;
        var alert = dismiss.closest('.ui-alert');
        if (alert) alert.remove();
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ui-loading-form')) return;
        if (form.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (control) {
            control.disabled = true;
        });
    });

    function addStyle(rules) {
        var style = document.createElement('style');
        style.textContent = rules.join('\n');
        document.head.appendChild(style);
    }

    function normalize(value) {
        return (value || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase();
    }

    function catalogRootPath() {
        var path = window.location.pathname || '/';
        var marker = '/catalog/';
        var index = path.indexOf(marker);
        return index >= 0 ? path.slice(0, index + marker.length) : '/catalog/';
    }

    function adminNavigationGroups() {
        return [
            {
                label: 'Admin',
                links: [
                    ['Dashboard', 'dashboard.php'],
                    ['Setup', 'setup.php'],
                    ['Library', 'library.php'],
                    ['Game Browser', 'games.php'],
                    ['Search', 'index.php?page=search'],
                    ['Game Admin', 'game-manager.php'],
                    ['Game Profiles', 'game-profiles.php'],
                    ['Administrator Security', 'admin-security.php']
                ]
            },
            {
                label: 'Catalog',
                links: [
                    ['Missing Dependencies', 'missing.php'],
                    ['Duplicate Files', 'duplicates.php'],
                    ['Unverified Files', 'unverified-files.php'],
                    ['Import Existing Unverified DB', 'unverified-database-import.php'],
                    ['Base Game Protection', 'base-game-files.php'],
                    ['Legacy Data Audit', 'legacy-data-audit.php']
                ]
            },
            {
                label: 'Imports',
                links: [
                    ['Game Sources', 'sources.php'],
                    ['Local Source Scan', 'source-scan.php'],
                    ['HTTP Source Scan', 'http-source-scan.php'],
                    ['Upload Files', 'profiled-upload.php'],
                    ['Upload Bucket', 'upload-bucket-v2.php'],
                    ['PAK Import', 'pak-import.php'],
                    ['Storage Audit', 'storage-audit.php']
                ]
            },
            {
                label: 'Maintenance',
                links: [
                    ['Background Jobs', 'background-jobs.php'],
                    ['Full Sync', 'full-sync.php'],
                    ['Dependency Refresh', 'dependency-refresh.php'],
                    ['Asset Metadata Rebuild', 'asset-metadata-rebuild.php'],
                    ['Source Identity Repair', 'source-identity-repair.php'],
                    ['Package Normalizer', 'package-normalize.php'],
                    ['GUID Normalizer', 'guid-normalize.php'],
                    ['Maintenance Locks', 'maintenance-locks.php']
                ]
            },
            {
                label: 'Downloads',
                links: [
                    ['Transfers', 'transfers.php'],
                    ['Download Administration', 'download-admin.php'],
                    ['Package Download Settings', 'download-package-settings.php'],
                    ['Mirror Providers', 'mirror-providers.php'],
                    ['Mirror Links', 'mirror-links.php'],
                    ['Mirror Queue', 'mirror-queue.php']
                ]
            },
            {
                label: 'Federation',
                links: [
                    ['Federation Admin', 'federation/admin.php'],
                    ['Join Main Parent', 'federation/join-main-parent.php'],
                    ['Settings', 'federation/settings.php'],
                    ['Peers', 'federation/peers.php'],
                    ['Peer Inventory', 'federation/peer-inventory.php'],
                    ['Requests', 'federation/requests.php'],
                    ['Approved Downloads', 'federation/approved-downloads.php'],
                    ['Join Requests', 'federation/join-requests.php'],
                    ['Queue', 'federation/queue.php'],
                    ['Bulk Worker', 'federation/worker-run.php'],
                    ['Conflicts', 'federation/conflicts.php'],
                    ['Maintenance', 'federation/maintenance.php'],
                    ['Logs', 'federation/logs.php'],
                    ['Documentation', 'federation/docs.php'],
                    ['Parent Pull', 'federation/parent-pull.php'],
                    ['Push Inventory', 'federation/inventory-push.php'],
                    ['Upload to Parent', 'federation/upload-to-parent.php'],
                    ['Claim Parent', 'federation/claim-parent.php']
                ]
            }
        ];
    }

    function closeNavigationMenus(nav, except) {
        nav.querySelectorAll('details[data-admin-menu]').forEach(function (menu) {
            if (menu !== except) menu.open = false;
        });
    }

    function initAdminNavigation() {
        var nav = document.querySelector('nav.primary-nav');
        if (!nav || !nav.querySelector('form.nav-logout')) return;

        var logout = nav.querySelector('form.nav-logout');
        var root = catalogRootPath();
        var currentUrl = new URL(window.location.href);

        nav.querySelectorAll('details').forEach(function (menu) {
            menu.remove();
        });

        adminNavigationGroups().forEach(function (group) {
            var details = document.createElement('details');
            details.className = 'nav-dropdown';
            details.dataset.adminMenu = group.label;

            var summary = document.createElement('summary');
            summary.textContent = group.label;
            summary.setAttribute('aria-label', group.label + ' menu');
            details.appendChild(summary);

            var menu = document.createElement('div');
            menu.className = 'nav-menu';
            menu.setAttribute('role', 'menu');

            group.links.forEach(function (entry) {
                var link = document.createElement('a');
                var url = new URL(root + entry[1], window.location.origin);
                link.href = url.pathname + url.search + url.hash;
                link.textContent = entry[0];
                link.setAttribute('role', 'menuitem');
                if (currentUrl.pathname === url.pathname && currentUrl.search === url.search) {
                    link.setAttribute('aria-current', 'page');
                }
                menu.appendChild(link);
            });

            details.appendChild(menu);
            nav.insertBefore(details, logout);
        });

        addStyle([
            'nav.primary-nav details[data-admin-menu] > summary::after { content: "▾"; display: inline-block; margin-left: 6px; font-size: 10px; transition: transform .12s ease; }',
            'nav.primary-nav details[data-admin-menu][open] > summary::after { transform: rotate(180deg); }',
            'nav.primary-nav details[data-admin-menu][open] > summary { background: rgba(118, 169, 255, .18); border-color: rgba(118, 169, 255, .38); }',
            'nav.primary-nav .nav-menu { max-height: min(72vh, 640px); overflow-y: auto; overscroll-behavior: contain; }',
            'nav.primary-nav .nav-menu a[aria-current="page"] { background: rgba(118, 169, 255, .22); border-color: rgba(118, 169, 255, .45); color: #fff; font-weight: 700; }',
            '@media (max-width: 850px) { nav.primary-nav details[data-admin-menu] { width: 100%; } nav.primary-nav details[data-admin-menu] > summary { width: 100%; } nav.primary-nav .nav-menu { max-height: 55vh; } }'
        ]);

        nav.querySelectorAll('details[data-admin-menu]').forEach(function (details) {
            details.addEventListener('toggle', function () {
                if (details.open) closeNavigationMenus(nav, details);
            });
        });

        nav.addEventListener('click', function (event) {
            if (event.target.closest('.nav-menu a')) closeNavigationMenus(nav, null);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('nav.primary-nav details[data-admin-menu]')) {
                closeNavigationMenus(nav, null);
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

    function installSortableHeaderStyle() {
        addStyle([
            '[data-sortable-table] th::after { content: none !important; display: none !important; }',
            '[data-sortable-table] th.is-sort-ascending::after { content: "▲" !important; display: inline-block !important; margin-left: 7px !important; color: var(--blue) !important; font-size: 11px !important; opacity: 1 !important; }',
            '[data-sortable-table] th.is-sort-descending::after { content: "▼" !important; display: inline-block !important; margin-left: 7px !important; color: var(--blue) !important; font-size: 11px !important; opacity: 1 !important; }'
        ]);
    }

    function bindSorting(table) {
        var headerRow = table.tHead && table.tHead.rows.length ? table.tHead.rows[0] : null;
        var body = table.tBodies.length ? table.tBodies[0] : null;
        if (!headerRow || !body || table.dataset.catalogSortBound === '1') return;

        var activeIndex = -1;
        var ascending = true;
        Array.from(headerRow.cells).forEach(function (header, index) {
            header.tabIndex = 0;
            header.setAttribute('role', 'button');
            header.setAttribute('title', 'Click to sort ascending. Click again to sort descending.');
            header.setAttribute('aria-label', header.textContent.trim() + '. Click to sort this table.');

            function sortByThisHeader() {
                if (activeIndex === index) {
                    ascending = !ascending;
                } else {
                    activeIndex = index;
                    ascending = true;
                }

                Array.from(body.rows).sort(function (leftRow, rightRow) {
                    var leftCell = leftRow.cells[index];
                    var rightCell = rightRow.cells[index];
                    var left = leftCell ? (leftCell.dataset.sortValue || leftCell.textContent || '').trim() : '';
                    var right = rightCell ? (rightCell.dataset.sortValue || rightCell.textContent || '').trim() : '';
                    var numeric = /^-?\d+(?:\.\d+)?$/;
                    var comparison = numeric.test(left) && numeric.test(right)
                        ? Number(left) - Number(right)
                        : left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
                    return ascending ? comparison : -comparison;
                }).forEach(function (row) {
                    body.appendChild(row);
                });

                Array.from(headerRow.cells).forEach(function (otherHeader, otherIndex) {
                    otherHeader.classList.remove('is-sort-ascending', 'is-sort-descending');
                    otherHeader.removeAttribute('aria-sort');
                    if (otherIndex === index) {
                        otherHeader.classList.add(ascending ? 'is-sort-ascending' : 'is-sort-descending');
                        otherHeader.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                    }
                });
            }

            header.addEventListener('click', sortByThisHeader);
            header.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sortByThisHeader();
                }
            });
        });
        table.dataset.catalogSortBound = '1';
    }

    function targetIdsFor(link) {
        if (link.dataset.referenceTargets) {
            try {
                var list = JSON.parse(link.dataset.referenceTargets);
                return Array.isArray(list) ? list.filter(function (value) { return typeof value === 'string'; }) : [];
            } catch (error) {
                return [];
            }
        }
        if (link.dataset.xrefTarget) return [link.dataset.xrefTarget];
        var href = link.getAttribute('href') || '';
        if (href.charAt(0) === '#') return [decodeURIComponent(href.slice(1))];
        var hashIndex = href.indexOf('#');
        return hashIndex >= 0 ? [decodeURIComponent(href.slice(hashIndex + 1))] : [];
    }

    function unwrapLink(link) {
        var span = document.createElement('span');
        span.className = link.className.replace(/\bxref\b/g, '').trim();
        if (span.className === '') span.className = 'mono path';
        span.textContent = link.textContent || '';
        link.replaceWith(span);
    }

    function removeSelfReferenceLinks(packageTables) {
        packageTables.querySelectorAll('a.xref, a[data-reference-targets], a[data-xref-target]').forEach(function (link) {
            var row = link.closest('tr');
            if (!row || !row.id) return;
            var targets = targetIdsFor(link);
            if (targets.length === 1 && targets[0] === row.id) unwrapLink(link);
        });
    }

    function movePackageRefsToIndexTooltips(packageTables) {
        packageTables.querySelectorAll('.examine-imports-table, .examine-exports-table').forEach(function (table) {
            if (table.dataset.packageRefMoved === '1') return;
            Array.from(table.rows).forEach(function (row, rowIndex) {
                if (row.cells.length < 2) return;
                if (rowIndex === 0) {
                    row.deleteCell(1);
                    return;
                }
                var indexCell = row.cells[0];
                var packageRef = (row.cells[1].textContent || '').trim();
                var tooltip = 'Package ref: ' + packageRef;
                indexCell.setAttribute('title', tooltip);
                var indexLink = indexCell.querySelector('a');
                if (indexLink) {
                    indexLink.setAttribute('title', tooltip);
                    indexLink.setAttribute('aria-label', (indexLink.textContent || '').trim() + '. ' + tooltip);
                }
                row.deleteCell(1);
            });
            var oldHead = table.tHead;
            if (oldHead) table.replaceChild(oldHead.cloneNode(true), oldHead);
            table.dataset.packageRefMoved = '1';
            bindSorting(table);
        });
    }

    function panels(packageTables) {
        return Array.from(packageTables.querySelectorAll('[data-panel], [data-examine-panel]'));
    }

    function panelName(panel) {
        return panel.dataset.panel || panel.dataset.examinePanel || '';
    }

    function showPanel(packageTables, tabName) {
        panels(packageTables).forEach(function (panel) {
            panel.hidden = panelName(panel) !== tabName;
        });
        packageTables.querySelectorAll('[data-tab], [data-examine-tab]').forEach(function (tab) {
            var active = (tab.dataset.tab || tab.dataset.examineTab) === tabName;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    function showPanelForTarget(packageTables, target) {
        var panel = target ? target.closest('[data-panel], [data-examine-panel]') : null;
        if (panel) showPanel(packageTables, panelName(panel));
    }

    function clearReferenceHighlights(packageTables) {
        packageTables.querySelectorAll('.is-reference-target').forEach(function (row) {
            row.classList.remove('is-reference-target');
        });
        packageTables.querySelectorAll('.examine-reference-target-cell').forEach(function (cell) {
            cell.classList.remove('examine-reference-target-cell');
        });
    }

    function signedPackageRef(link) {
        return /^-?\d+$/.test((link.textContent || '').trim());
    }

    function sourceValue(link) {
        if (link.classList.contains('name-usage-link')) {
            var nameRow = link.closest('tr');
            return nameRow && nameRow.cells.length > 1 ? (nameRow.cells[1].textContent || '') : '';
        }
        return link.textContent || '';
    }

    function destinationCell(row, targetId, referenceValue, isSignedReference) {
        var cells = Array.from(row.cells || []);
        if (isSignedReference || targetId.indexOf('import-') === 0 || (targetId.indexOf('export-') === 0 && !referenceValue)) {
            return cells[0] || null;
        }
        var expected = normalize(referenceValue);
        if (expected) {
            var exact = cells.find(function (cell) {
                return normalize(cell.textContent) === expected;
            });
            if (exact) return exact;
        }
        return targetId.indexOf('name-') === 0 ? (cells[1] || cells[0] || null) : (cells[0] || null);
    }

    function followLocalReference(packageTables, link) {
        var targetIds = targetIdsFor(link);
        if (!targetIds.length) return;
        var signed = signedPackageRef(link);
        var referenceValue = signed ? '' : sourceValue(link);
        var targetRows = targetIds.map(function (targetId) {
            var target = document.getElementById(targetId);
            return target && { id: targetId, target: target, row: target.matches('tr') ? target : target.closest('tr') };
        }).filter(function (item) { return item && item.row; });
        if (!targetRows.length) return;

        clearReferenceHighlights(packageTables);
        showPanelForTarget(packageTables, targetRows[0].target);
        targetRows.forEach(function (item) {
            item.row.classList.add('is-reference-target');
            var cell = destinationCell(item.row, item.id, referenceValue, signed);
            if (cell) cell.classList.add('examine-reference-target-cell');
        });
        window.history.pushState(null, '', '#' + targetRows[0].id);
        window.setTimeout(function () {
            targetRows[0].target.scrollIntoView({ block: 'center' });
        }, 0);
    }

    function initExaminePage() {
        var packageTables = document.getElementById('package-tables');
        if (!packageTables) return;

        addStyle([
            '#package-tables .examine-table-region { width: 100% !important; }',
            '#package-tables .examine-table-region > table { width: 100% !important; min-width: 100% !important; max-width: none !important; table-layout: auto !important; }',
            '#package-tables .examine-imports-table, #package-tables .examine-exports-table { width: 100% !important; min-width: 100% !important; max-width: none !important; }',
            '#package-tables .examine-imports-table th, #package-tables .examine-imports-table td, #package-tables .examine-exports-table th, #package-tables .examine-exports-table td { width: auto !important; }',
            '#package-tables .examine-imports-table th:nth-child(n+2), #package-tables .examine-imports-table td:nth-child(n+2), #package-tables .examine-imports-table th:nth-child(n+2) *, #package-tables .examine-imports-table td:nth-child(n+2) * { white-space: nowrap !important; word-break: normal !important; overflow-wrap: normal !important; }',
            '#package-tables tr.is-reference-target > td { background: rgba(246, 196, 83, .18) !important; box-shadow: inset 4px 0 0 var(--amber); border-top: 1px solid rgba(246, 196, 83, .55); border-bottom: 1px solid rgba(246, 196, 83, .55); }',
            '#package-tables tr.is-reference-target > td.examine-reference-target-cell { background: #6e5616 !important; color: #fff9d8 !important; box-shadow: inset 0 0 0 2px #f6c453, inset 5px 0 0 #f6c453 !important; }',
            '#package-tables tr.is-reference-target > td.examine-reference-target-cell a { color: #fff9d8 !important; text-decoration: underline; }'
        ]);

        packageTables.querySelectorAll('table[data-sortable-table]').forEach(bindSorting);
        removeSelfReferenceLinks(packageTables);
        movePackageRefsToIndexTooltips(packageTables);
        removeSelfReferenceLinks(packageTables);

        document.addEventListener('click', function (event) {
            var reference = event.target.closest('#package-tables a.xref, #package-tables a[data-reference-targets], #package-tables a[data-xref-target]');
            if (!reference) return;
            var targetIds = targetIdsFor(reference);
            if (!targetIds.length || !document.getElementById(targetIds[0])) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            followLocalReference(packageTables, reference);
        }, true);

        document.addEventListener('click', function (event) {
            var tab = event.target.closest('#package-tables [data-tab], #package-tables [data-examine-tab]');
            if (!tab) return;
            var tabName = tab.dataset.tab || tab.dataset.examineTab;
            if (!tabName) return;
            clearReferenceHighlights(packageTables);
            showPanel(packageTables, tabName);
        }, true);

        window.addEventListener('hashchange', function () {
            var id = decodeURIComponent(window.location.hash.replace(/^#/, ''));
            if (!id || id.indexOf('tab-') === 0) return;
            var target = document.getElementById(id);
            if (!target) return;
            clearReferenceHighlights(packageTables);
            showPanelForTarget(packageTables, target);
            var row = target.matches('tr') ? target : target.closest('tr');
            if (row) {
                row.classList.add('is-reference-target');
                var indexCell = row.cells && row.cells[0];
                if (indexCell) indexCell.classList.add('examine-reference-target-cell');
            }
        });
    }

    function initSearchHighlights() {
        var query = new URLSearchParams(window.location.search).get('q') || '';
        query = query.trim();
        if (query.length < 2) return;
        var resultHeading = Array.from(document.querySelectorAll('.card h2')).find(function (heading) {
            return heading.textContent.trim() === 'Results';
        });
        var resultTable = resultHeading && resultHeading.closest('.card').querySelector('table');
        if (!resultTable || resultTable.dataset.searchHighlightApplied === '1') return;
        resultTable.dataset.searchHighlightApplied = '1';

        var expression;
        try {
            expression = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        } catch (error) {
            return;
        }
        addStyle(['.catalog-search-highlight { padding: 0 2px; border-radius: 3px; color: #1a1300; background: #f6c453; font-weight: 800; }']);

        Array.from(resultTable.querySelectorAll('tbody td:nth-child(-n+4)')).forEach(function (cell) {
            var walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, {
                acceptNode: function (node) {
                    return node.parentElement && node.parentElement.closest('mark')
                        ? NodeFilter.FILTER_REJECT
                        : NodeFilter.FILTER_ACCEPT;
                }
            });
            var textNodes = [];
            while (walker.nextNode()) textNodes.push(walker.currentNode);
            textNodes.forEach(function (node) {
                var value = node.nodeValue || '';
                expression.lastIndex = 0;
                if (!expression.test(value)) return;
                expression.lastIndex = 0;
                var parts = value.split(expression);
                var fragment = document.createDocumentFragment();
                parts.forEach(function (part, index) {
                    if (part === '') return;
                    if (index % 2 === 1) {
                        var mark = document.createElement('mark');
                        mark.className = 'catalog-search-highlight';
                        mark.textContent = part;
                        fragment.appendChild(mark);
                    } else {
                        fragment.appendChild(document.createTextNode(part));
                    }
                });
                node.parentNode.replaceChild(fragment, node);
            });
        });
    }

    initAdminNavigation();
    installSortableHeaderStyle();
    document.querySelectorAll('table[data-sortable-table]').forEach(bindSorting);
    initExaminePage();
    initSearchHighlights();
})();

(function () {
    'use strict';

    function countryCodeFromFlagText(value) {
        var text = (value || '').trim();
        if (/^[A-Za-z]{2}$/.test(text)) return text.toUpperCase();
        var symbols = Array.from(text);
        if (symbols.length !== 2) return '';
        var first = symbols[0].codePointAt(0);
        var second = symbols[1].codePointAt(0);
        var base = 0x1F1E6;
        var end = 0x1F1FF;
        if (first < base || first > end || second < base || second > end) return '';
        return String.fromCharCode(65 + first - base, 65 + second - base);
    }

    var flags = document.querySelectorAll('.download-country-flag');
    if (!flags.length) return;

    var path = window.location.pathname || '/';
    var marker = '/catalog/';
    var index = path.indexOf(marker);
    var root = index >= 0 ? path.slice(0, index + marker.length) : '/catalog/';

    var style = document.createElement('style');
    style.textContent = '.download-country-flag-image{display:inline-block;width:24px;height:18px;object-fit:cover;vertical-align:middle;border-radius:2px;box-shadow:0 0 0 1px rgba(255,255,255,.16);cursor:help}';
    document.head.appendChild(style);

    flags.forEach(function (flag) {
        var code = countryCodeFromFlagText(flag.textContent || '');
        if (!code) return;
        var name = flag.getAttribute('title') || flag.getAttribute('aria-label') || code;
        var image = document.createElement('img');
        image.className = 'download-country-flag-image';
        image.src = root + 'country-flag.php?code=' + encodeURIComponent(code);
        image.alt = code;
        image.title = name;
        image.width = 24;
        image.height = 18;
        image.loading = 'lazy';
        image.decoding = 'async';
        flag.replaceWith(image);
    });
})();

(function () {
    'use strict';

    var statCards = document.querySelector('.download-log-cards');
    var table = document.querySelector('table.download-log-table');
    if (!statCards || !table || !table.tHead || !table.tBodies.length) return;

    function codeFromCountryCell(cell) {
        var image = cell.querySelector('img.download-country-flag-image');
        if (image && /^[A-Za-z]{2}$/.test(image.alt || '')) return image.alt.toUpperCase();
        var marker = cell.querySelector('.download-country-flag');
        var text = marker ? (marker.textContent || '').trim() : '';
        if (/^[A-Za-z]{2}$/.test(text)) return text.toUpperCase();
        var symbols = Array.from(text);
        if (symbols.length !== 2) return '';
        var base = 0x1F1E6;
        var first = symbols[0].codePointAt(0);
        var second = symbols[1].codePointAt(0);
        if (first < base || first > 0x1F1FF || second < base || second > 0x1F1FF) return '';
        return String.fromCharCode(65 + first - base, 65 + second - base);
    }

    function countryNameFromCell(cell, code) {
        var image = cell.querySelector('img.download-country-flag-image');
        if (image && image.title) return image.title;
        var marker = cell.querySelector('.download-country-flag');
        return marker && (marker.title || marker.getAttribute('aria-label'))
            ? (marker.title || marker.getAttribute('aria-label'))
            : code;
    }

    var headers = Array.from(table.tHead.rows[0].cells).map(function (cell) {
        return (cell.textContent || '').replace(/[▲▼]/g, '').trim().toLowerCase();
    });
    var ipIndex = headers.indexOf('ip');
    var countryIndex = headers.indexOf('country');
    if (ipIndex < 0 || countryIndex < 0) return;

    var pointsByKey = Object.create(null);
    Array.from(table.tBodies[0].rows).forEach(function (row) {
        var ipCell = row.cells[ipIndex];
        var countryCell = row.cells[countryIndex];
        if (!ipCell || !countryCell) return;
        var ip = (ipCell.textContent || '').trim();
        var code = codeFromCountryCell(countryCell);
        if (!ip || !/^[A-Z]{2}$/.test(code)) return;
        var name = countryNameFromCell(countryCell, code);
        var key = code + '|' + ip;
        if (!pointsByKey[key]) {
            pointsByKey[key] = { ip: ip, code: code, country: name, count: 0 };
        }
        pointsByKey[key].count++;
    });
    var points = Object.keys(pointsByKey).map(function (key) { return pointsByKey[key]; });

    var details = document.createElement('details');
    details.className = 'download-world-map';
    details.setAttribute('data-download-world-map', '');

    var storageKey = 'unrealdb.downloadLogs.worldMapOpen';
    try {
        details.open = window.localStorage.getItem(storageKey) !== '0';
    } catch (error) {
        details.open = true;
    }

    var summary = document.createElement('summary');
    summary.textContent = 'World activity map · ' + points.length + ' unique IP' + (points.length === 1 ? '' : 's') + ' on this page';
    details.appendChild(summary);

    var content = document.createElement('div');
    content.className = 'download-world-map-content';

    var note = document.createElement('div');
    note.className = 'download-world-map-note';
    note.textContent = 'Country-level approximation from the stored GeoIP country snapshot. Only the currently displayed log rows are plotted.';
    content.appendChild(note);

    var stage = document.createElement('div');
    stage.className = 'download-world-map-stage';
    stage.textContent = points.length ? 'Loading world map…' : 'No country-resolved IP addresses are visible on this page.';
    content.appendChild(stage);

    var footer = document.createElement('div');
    footer.className = 'download-world-map-credit';
    footer.appendChild(document.createTextNode('Map: '));
    var credit = document.createElement('a');
    credit.href = 'https://github.com/melenaos/Menelabs.VectorAtlas';
    credit.target = '_blank';
    credit.rel = 'noopener noreferrer';
    credit.textContent = 'VectorAtlas';
    footer.appendChild(credit);
    footer.appendChild(document.createTextNode(' / Natural Earth.'));
    content.appendChild(footer);

    details.appendChild(content);
    statCards.parentNode.insertBefore(details, statCards);

    var style = document.createElement('style');
    style.textContent = [
        '.download-world-map{margin:0 0 14px;border:1px solid var(--line);border-radius:10px;background:rgba(255,255,255,.025);overflow:hidden}',
        '.download-world-map>summary{cursor:pointer;padding:11px 14px;font-weight:800;user-select:none}',
        '.download-world-map[open]>summary{border-bottom:1px solid var(--line)}',
        '.download-world-map-content{padding:12px 14px 10px}',
        '.download-world-map-note,.download-world-map-credit{font-size:.86rem;color:var(--muted)}',
        '.download-world-map-note{margin-bottom:9px}',
        '.download-world-map-credit{margin-top:7px;text-align:right}',
        '.download-world-map-stage{min-height:220px;display:flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:rgba(0,0,0,.12);color:var(--muted)}',
        '.download-world-map-stage svg{display:block;width:100%;height:auto;max-height:430px}',
        '.download-world-map-stage svg path{fill:rgba(148,163,184,.22);stroke:rgba(148,163,184,.48);stroke-width:.9;vector-effect:non-scaling-stroke}',
        '.download-world-map-stage svg path.download-world-map-country-active{fill:rgba(96,165,250,.28);stroke:rgba(147,197,253,.78)}',
        '.download-world-map-stage .download-world-map-dot{fill:var(--amber);stroke:#fff;stroke-width:2.2;vector-effect:non-scaling-stroke;cursor:help}',
        '.download-world-map-stage .download-world-map-dot:focus{outline:none;stroke-width:4}',
        '@media(max-width:700px){.download-world-map-stage{min-height:160px}.download-world-map-content{padding:9px}}'
    ].join('\n');
    document.head.appendChild(style);

    var loaded = false;
    var loading = false;
    var root = (function () {
        var path = window.location.pathname || '/';
        var marker = '/catalog/';
        var index = path.indexOf(marker);
        return index >= 0 ? path.slice(0, index + marker.length) : '/catalog/';
    })();

    function renderMap(svgText) {
        var parsed = new DOMParser().parseFromString(svgText, 'image/svg+xml');
        var sourceSvg = parsed.documentElement;
        if (!sourceSvg || sourceSvg.nodeName.toLowerCase() !== 'svg' || parsed.querySelector('parsererror')) {
            throw new Error('Invalid world map SVG.');
        }

        var namespace = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(namespace, 'svg');
        svg.setAttribute('viewBox', sourceSvg.getAttribute('viewBox') || '0 0 2000 1001');
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'Approximate country locations of IP addresses in the currently displayed download logs');

        sourceSvg.querySelectorAll('path[id][d]').forEach(function (sourcePath) {
            var id = (sourcePath.getAttribute('id') || '').trim().toLowerCase();
            var d = sourcePath.getAttribute('d') || '';
            if (!/^[a-z0-9_-]{2,80}$/.test(id) || d === '' || d.length > 1000000) return;
            var path = document.createElementNS(namespace, 'path');
            path.setAttribute('id', id);
            path.setAttribute('d', d);
            svg.appendChild(path);
        });

        stage.textContent = '';
        stage.appendChild(svg);

        window.requestAnimationFrame(function () {
            var byCountry = Object.create(null);
            points.forEach(function (point) {
                (byCountry[point.code] || (byCountry[point.code] = [])).push(point);
            });

            var mapped = 0;
            Object.keys(byCountry).forEach(function (code) {
                var countryPath = svg.querySelector('#' + code.toLowerCase());
                if (!countryPath) return;
                countryPath.classList.add('download-world-map-country-active');
                var box;
                try {
                    box = countryPath.getBBox();
                } catch (error) {
                    return;
                }
                if (!box || !Number.isFinite(box.x) || !Number.isFinite(box.y)) return;

                var group = byCountry[code];
                var centerX = box.x + box.width / 2;
                var centerY = box.y + box.height / 2;
                group.forEach(function (point, index) {
                    var count = group.length;
                    var angle = count > 1 ? ((Math.PI * 2 * index) / count) - (Math.PI / 2) : 0;
                    var spread = count > 1 ? Math.min(28, 8 + count * 1.4) : 0;
                    var circle = document.createElementNS(namespace, 'circle');
                    circle.classList.add('download-world-map-dot');
                    circle.setAttribute('cx', String(centerX + Math.cos(angle) * spread));
                    circle.setAttribute('cy', String(centerY + Math.sin(angle) * spread));
                    circle.setAttribute('r', '8');
                    circle.setAttribute('tabindex', '0');
                    circle.setAttribute('role', 'img');
                    var tooltip = point.ip + ' · ' + point.country + ' · ' + point.count + ' visible log entr' + (point.count === 1 ? 'y' : 'ies') + ' · country-level approximation';
                    circle.setAttribute('aria-label', tooltip);
                    var title = document.createElementNS(namespace, 'title');
                    title.textContent = tooltip;
                    circle.appendChild(title);
                    svg.appendChild(circle);
                    mapped++;
                });
            });

            note.textContent = 'Country-level approximation from the stored GeoIP country snapshot. '
                + mapped + ' of ' + points.length + ' unique IP' + (points.length === 1 ? '' : 's')
                + ' on the current page mapped.';
        });
    }

    function ensureMap() {
        if (loaded || loading || !points.length) return;
        loading = true;
        fetch(root + 'world-map.php', { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('World map request returned HTTP ' + response.status + '.');
                return response.text();
            })
            .then(function (svgText) {
                renderMap(svgText);
                loaded = true;
            })
            .catch(function (error) {
                stage.textContent = 'World map could not be loaded: ' + error.message;
            })
            .finally(function () {
                loading = false;
            });
    }

    details.addEventListener('toggle', function () {
        try {
            window.localStorage.setItem(storageKey, details.open ? '1' : '0');
        } catch (error) {
            // Storage is optional; map visibility still works for this page view.
        }
        if (details.open) ensureMap();
    });

    if (details.open) ensureMap();
})();

(function () {
    'use strict';

    var details = document.querySelector('[data-download-world-map]');
    if (!details) return;
    var stage = details.querySelector('.download-world-map-stage');
    if (!stage) return;

    var toolbar = document.createElement('div');
    toolbar.className = 'download-world-map-controls';
    toolbar.setAttribute('aria-label', 'World map zoom controls');

    function controlButton(label, title) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button download-world-map-control';
        button.textContent = label;
        button.title = title;
        button.disabled = true;
        return button;
    }

    var zoomOut = controlButton('−', 'Zoom out');
    var zoomLabel = document.createElement('span');
    zoomLabel.className = 'download-world-map-zoom-label mono small';
    zoomLabel.textContent = '100%';
    var zoomIn = controlButton('+', 'Zoom in');
    var reset = controlButton('Reset', 'Reset map to the full world view');
    toolbar.appendChild(zoomOut);
    toolbar.appendChild(zoomLabel);
    toolbar.appendChild(zoomIn);
    toolbar.appendChild(reset);
    stage.parentNode.insertBefore(toolbar, stage);

    var style = document.createElement('style');
    style.textContent = [
        '.download-world-map-controls{display:flex;justify-content:flex-end;align-items:center;gap:7px;margin:0 0 8px}',
        '.download-world-map-control{min-width:38px;padding:5px 10px}',
        '.download-world-map-zoom-label{min-width:48px;text-align:center;color:var(--muted)}',
        '.download-world-map-stage.download-world-map-interactive{cursor:grab;touch-action:none;user-select:none}',
        '.download-world-map-stage.download-world-map-panning{cursor:grabbing}',
        '@media(max-width:700px){.download-world-map-controls{justify-content:center}}'
    ].join('\n');
    document.head.appendChild(style);

    var svg = null;
    var base = null;
    var view = null;
    var dragging = false;
    var dragPointerId = null;
    var lastClientX = 0;
    var lastClientY = 0;
    var minScale = 1;
    var maxScale = 10;

    function parseViewBox(value) {
        var parts = (value || '').trim().split(/\s+/).map(Number);
        if (parts.length !== 4 || parts.some(function (number) { return !Number.isFinite(number); }) || parts[2] <= 0 || parts[3] <= 0) {
            return null;
        }
        return { x: parts[0], y: parts[1], width: parts[2], height: parts[3] };
    }

    function currentScale() {
        return base && view ? base.width / view.width : 1;
    }

    function clampView(candidate) {
        if (!base) return candidate;
        candidate.width = Math.min(base.width, Math.max(base.width / maxScale, candidate.width));
        candidate.height = Math.min(base.height, Math.max(base.height / maxScale, candidate.height));
        var maxX = base.x + base.width - candidate.width;
        var maxY = base.y + base.height - candidate.height;
        candidate.x = Math.min(maxX, Math.max(base.x, candidate.x));
        candidate.y = Math.min(maxY, Math.max(base.y, candidate.y));
        return candidate;
    }

    function applyView() {
        if (!svg || !view || !base) return;
        clampView(view);
        svg.setAttribute('viewBox', [view.x, view.y, view.width, view.height].join(' '));
        var scale = currentScale();
        zoomLabel.textContent = Math.round(scale * 100) + '%';
        zoomOut.disabled = scale <= minScale + 0.001;
        zoomIn.disabled = scale >= maxScale - 0.001;
        reset.disabled = scale <= minScale + 0.001 && Math.abs(view.x - base.x) < 0.01 && Math.abs(view.y - base.y) < 0.01;
    }

    function zoomTo(scale, focusX, focusY) {
        if (!base || !view) return;
        scale = Math.min(maxScale, Math.max(minScale, scale));
        var oldWidth = view.width;
        var oldHeight = view.height;
        var newWidth = base.width / scale;
        var newHeight = base.height / scale;
        var ratioX = oldWidth > 0 ? (focusX - view.x) / oldWidth : 0.5;
        var ratioY = oldHeight > 0 ? (focusY - view.y) / oldHeight : 0.5;
        view = {
            x: focusX - ratioX * newWidth,
            y: focusY - ratioY * newHeight,
            width: newWidth,
            height: newHeight
        };
        applyView();
    }

    function zoomAtCenter(multiplier) {
        if (!view) return;
        zoomTo(currentScale() * multiplier, view.x + view.width / 2, view.y + view.height / 2);
    }

    function svgPointFromClient(clientX, clientY) {
        if (!svg || !view) return null;
        var rect = svg.getBoundingClientRect();
        if (!rect.width || !rect.height) return null;
        return {
            x: view.x + ((clientX - rect.left) / rect.width) * view.width,
            y: view.y + ((clientY - rect.top) / rect.height) * view.height
        };
    }

    function resetView() {
        if (!base) return;
        view = { x: base.x, y: base.y, width: base.width, height: base.height };
        applyView();
    }

    function attachMap(candidate) {
        if (!candidate || candidate === svg) return;
        var parsed = parseViewBox(candidate.getAttribute('viewBox'));
        if (!parsed) return;
        svg = candidate;
        base = parsed;
        view = { x: parsed.x, y: parsed.y, width: parsed.width, height: parsed.height };
        stage.classList.add('download-world-map-interactive');
        applyView();
    }

    zoomIn.addEventListener('click', function () { zoomAtCenter(1.5); });
    zoomOut.addEventListener('click', function () { zoomAtCenter(1 / 1.5); });
    reset.addEventListener('click', resetView);

    stage.addEventListener('wheel', function (event) {
        if (!svg || !view) return;
        var point = svgPointFromClient(event.clientX, event.clientY);
        if (!point) return;
        event.preventDefault();
        zoomTo(currentScale() * (event.deltaY < 0 ? 1.2 : 1 / 1.2), point.x, point.y);
    }, { passive: false });

    stage.addEventListener('dblclick', function (event) {
        if (!svg || !view) return;
        var point = svgPointFromClient(event.clientX, event.clientY);
        if (!point) return;
        event.preventDefault();
        zoomTo(currentScale() * 1.5, point.x, point.y);
    });

    stage.addEventListener('pointerdown', function (event) {
        if (!svg || !view || event.button !== 0 || currentScale() <= 1.001) return;
        if (event.target.closest && event.target.closest('.download-world-map-dot')) return;
        dragging = true;
        dragPointerId = event.pointerId;
        lastClientX = event.clientX;
        lastClientY = event.clientY;
        stage.classList.add('download-world-map-panning');
        stage.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    stage.addEventListener('pointermove', function (event) {
        if (!dragging || event.pointerId !== dragPointerId || !svg || !view) return;
        var rect = svg.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        var dx = event.clientX - lastClientX;
        var dy = event.clientY - lastClientY;
        lastClientX = event.clientX;
        lastClientY = event.clientY;
        view.x -= dx * (view.width / rect.width);
        view.y -= dy * (view.height / rect.height);
        applyView();
    });

    function stopDragging(event) {
        if (!dragging || (event && event.pointerId !== dragPointerId)) return;
        dragging = false;
        stage.classList.remove('download-world-map-panning');
        if (event && stage.hasPointerCapture && stage.hasPointerCapture(event.pointerId)) {
            stage.releasePointerCapture(event.pointerId);
        }
        dragPointerId = null;
    }

    stage.addEventListener('pointerup', stopDragging);
    stage.addEventListener('pointercancel', stopDragging);
    stage.addEventListener('lostpointercapture', stopDragging);

    var existingSvg = stage.querySelector('svg');
    if (existingSvg) attachMap(existingSvg);

    var observer = new MutationObserver(function () {
        var candidate = stage.querySelector('svg');
        if (candidate) attachMap(candidate);
    });
    observer.observe(stage, { childList: true, subtree: false });
})();
