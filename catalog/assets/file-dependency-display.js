(function () {
    'use strict';

    var params = new URLSearchParams(window.location.search);
    var fileId = parseInt(params.get('id') || '0', 10);
    if (!fileId) return;

    function h(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function statusLabel(status) {
        return ({
            missing: 'Missing',
            object_missing: 'Object missing',
            package_only: 'Package only',
            resolved: 'Resolved',
            common: 'Common'
        })[status] || status;
    }

    function sourceLabel(source) {
        return ({
            exact_package: 'exact package',
            exact_package_alias: 'package alias',
            exact_object: 'exact object',
            exact_object_alias: 'alias object',
            common_script: 'common script',
            none: 'none'
        })[source] || source || 'unknown';
    }

    function addStyle() {
        var style = document.createElement('style');
        style.textContent = [
            '.dep.object_missing{border-color:rgba(246,196,83,.85);color:#fde68a;background:rgba(120,83,16,.28)}',
            '.file-dependency-identity{min-width:350px}',
            '.file-dependency-identity span{display:block}',
            '.file-dependency-table th,.file-dependency-table td{vertical-align:top}',
            '.file-dependency-empty{padding:14px 0}',
            '.file-dependency-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}',
            '.file-dependency-tab.is-active{outline:2px solid var(--blue);outline-offset:2px;background:rgba(118,169,255,.13)}',
            '.file-dependency-panel[hidden]{display:none}',
            '.file-pak-source-card table td{vertical-align:top}',
            '.file-pak-source-card .pak-source-actions{white-space:nowrap}'
        ].join('\n');
        document.head.appendChild(style);
    }

    function identityHtml(file) {
        return '<span>GUID: ' + h(file.guid || '') + '</span><span>MD5: ' + h(file.md5 || '') + '</span>';
    }

    function fileTable(files) {
        if (!files.length) return '<p class="muted file-dependency-empty">No files.</p>';
        var rows = files.map(function (file) {
            var href = 'file-examine.php?id=' + encodeURIComponent(file.id);
            return '<tr>'
                + '<td><a href="' + href + '" title="Package identity: ' + h(file.package) + '">' + h(file.file) + '</a></td>'
                + '<td data-sort-value="' + Number(file.size || 0) + '">' + h(file.size_text) + '</td>'
                + '<td class="mono small file-dependency-identity" data-sort-value="' + h((file.guid || '') + ' ' + (file.md5 || '')) + '">' + identityHtml(file) + '</td>'
                + '</tr>';
        }).join('');
        return '<div class="examine-table-region"><table class="file-dependency-table" data-file-dependency-sort><thead><tr><th>File</th><th>Size</th><th>GUID / MD5</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function bindSort(table) {
        var head = table.tHead && table.tHead.rows[0];
        var body = table.tBodies && table.tBodies[0];
        if (!head || !body) return;
        var active = -1;
        var ascending = true;
        Array.from(head.cells).forEach(function (header, index) {
            header.tabIndex = 0;
            header.style.cursor = 'pointer';
            function sort() {
                ascending = active === index ? !ascending : true;
                active = index;
                Array.from(body.rows).sort(function (left, right) {
                    var lv = (left.cells[index].dataset.sortValue || left.cells[index].textContent || '').trim();
                    var rv = (right.cells[index].dataset.sortValue || right.cells[index].textContent || '').trim();
                    var numeric = /^-?\d+(?:\.\d+)?$/;
                    var result = numeric.test(lv) && numeric.test(rv)
                        ? Number(lv) - Number(rv)
                        : lv.localeCompare(rv, undefined, {numeric: true, sensitivity: 'base'});
                    return ascending ? result : -result;
                }).forEach(function (row) { body.appendChild(row); });
            }
            header.addEventListener('click', sort);
            header.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sort();
                }
            });
        });
    }

    function bindNewTables(root) {
        root.querySelectorAll('[data-file-dependency-sort]').forEach(bindSort);
    }

    function installExamineTabs(data) {
        var root = document.getElementById('package-tables');
        if (!root) return;
        var nav = root.querySelector('.examine-tabs');
        var exportsPanel = root.querySelector('[data-panel="exports"]');
        if (!nav || !exportsPanel || nav.querySelector('[data-file-dependency-tab]')) return;

        function tab(name, label, count) {
            var link = document.createElement('a');
            link.className = 'examine-tab';
            link.href = 'file-examine.php?id=' + encodeURIComponent(fileId) + '&tab=' + encodeURIComponent(name) + '#tab-' + encodeURIComponent(name);
            link.dataset.fileDependencyTab = name;
            link.textContent = label + ' ';
            var countNode = document.createElement('span');
            countNode.textContent = String(count);
            link.appendChild(countNode);
            return link;
        }

        var requiresTab = tab('requires', 'Uses', data.requires.length);
        var requiredByTab = tab('required-by', 'Used By', data.required_by.length);
        nav.appendChild(requiresTab);
        nav.appendChild(requiredByTab);

        function panel(name, title, files) {
            var section = document.createElement('section');
            section.id = 'tab-' + name;
            section.dataset.fileDependencyPanel = name;
            section.className = 'examine-tab-panel file-dependency-panel';
            section.hidden = true;
            section.innerHTML = '<h2>' + h(title) + '</h2>' + fileTable(files);
            return section;
        }

        var requiresPanel = panel('requires', 'Uses', data.requires);
        var requiredByPanel = panel('required-by', 'Used By', data.required_by);
        exportsPanel.insertAdjacentElement('afterend', requiredByPanel);
        exportsPanel.insertAdjacentElement('afterend', requiresPanel);
        bindNewTables(root);

        function show(name, updateUrl) {
            root.querySelectorAll('[data-panel], [data-file-dependency-panel]').forEach(function (section) {
                var sectionName = section.dataset.panel || section.dataset.fileDependencyPanel;
                section.hidden = sectionName !== name;
            });
            root.querySelectorAll('[data-tab], [data-file-dependency-tab]').forEach(function (link) {
                var linkName = link.dataset.tab || link.dataset.fileDependencyTab;
                link.classList.toggle('is-active', linkName === name);
            });
            if (updateUrl) {
                var url = new URL(window.location.href);
                url.searchParams.set('tab', name);
                url.searchParams.delete('target');
                url.hash = 'tab-' + name;
                window.history.pushState(null, '', url);
            }
        }

        [requiresTab, requiredByTab].forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                show(link.dataset.fileDependencyTab, true);
                root.scrollIntoView({block: 'start'});
            });
        });
        function hideDependencyPanels() {
            requiresPanel.hidden = true;
            requiredByPanel.hidden = true;
            requiresTab.classList.remove('is-active');
            requiredByTab.classList.remove('is-active');
        }
        nav.querySelectorAll('[data-tab]').forEach(function (link) {
            link.addEventListener('click', hideDependencyPanels);
        });
        window.addEventListener('popstate', function () {
            var selected = new URLSearchParams(window.location.search).get('tab');
            if (selected === 'requires' || selected === 'required-by') show(selected, false);
            else hideDependencyPanels();
        });

        var selected = params.get('tab');
        if (selected === 'requires' || selected === 'required-by') show(selected, false);
    }

    function dependencyTable(rows) {
        if (!rows.length) return '<p class="muted">No dependencies in this status.</p>';
        return '<table data-file-dependency-sort><thead><tr><th>Status</th><th>Source</th><th>Confidence</th><th>Required object</th><th>Resolved package</th></tr></thead><tbody>'
            + rows.map(function (row) {
                var resolved = row.resolved_file
                    ? '<a href="file-info.php?id=' + encodeURIComponent(row.resolved_file.id) + '">' + h(row.resolved_file.package || row.resolved_file.file) + '</a>'
                    : '<span class="muted">not resolved</span>';
                return '<tr>'
                    + '<td><span class="dep ' + h(row.status) + '">' + h(statusLabel(row.status)) + '</span></td>'
                    + '<td><span class="dep resolution-source">' + h(sourceLabel(row.source)) + '</span></td>'
                    + '<td class="mono">' + h(row.confidence) + '</td>'
                    + '<td class="mono path">' + h(row.required_object) + '</td>'
                    + '<td>' + resolved + '</td>'
                    + '</tr>';
            }).join('') + '</tbody></table>';
    }

    function installFileInfoDependencies(data) {
        var card = document.getElementById('dependencies');
        if (!card) return;
        var statuses = [
            ['missing', 'Missing'],
            ['object_missing', 'Object missing'],
            ['package_only', 'Package only'],
            ['resolved', 'Resolved'],
            ['common', 'Common']
        ];
        if (!data.dependencies.length) {
            card.innerHTML = '<h2>Dependencies</h2><p class="muted">No dependencies were recorded for this file.</p>';
            return;
        }

        var initial = data.dependency_counts.missing ? 'missing'
            : (data.dependency_counts.object_missing ? 'object_missing' : 'all');
        var tabs = '<nav class="file-dependency-tabs" role="tablist">'
            + '<a class="dep file-dependency-tab' + (initial === 'all' ? ' is-active' : '') + '" href="#dependency-all" data-fd-info-tab="all">All: ' + data.dependencies.length + '</a>'
            + statuses.map(function (item) {
                var status = item[0];
                return '<a class="dep ' + status + ' file-dependency-tab' + (initial === status ? ' is-active' : '') + '" href="#dependency-' + status + '" data-fd-info-tab="' + status + '">' + h(item[1]) + ': ' + Number(data.dependency_counts[status] || 0) + '</a>';
            }).join('') + '</nav>';

        var panels = '<section class="file-dependency-panel" data-fd-info-panel="all"' + (initial === 'all' ? '' : ' hidden') + '>' + dependencyTable(data.dependencies) + '</section>'
            + statuses.map(function (item) {
                var status = item[0];
                var rows = data.dependencies.filter(function (row) { return row.status === status; });
                return '<section class="file-dependency-panel" data-fd-info-panel="' + status + '"' + (initial === status ? '' : ' hidden') + '>' + dependencyTable(rows) + '</section>';
            }).join('');
        card.innerHTML = '<h2>Dependencies</h2>' + tabs + panels;
        bindNewTables(card);

        card.querySelectorAll('[data-fd-info-tab]').forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                var selected = tab.dataset.fdInfoTab;
                card.querySelectorAll('[data-fd-info-panel]').forEach(function (panel) {
                    panel.hidden = panel.dataset.fdInfoPanel !== selected;
                });
                card.querySelectorAll('[data-fd-info-tab]').forEach(function (other) {
                    other.classList.toggle('is-active', other.dataset.fdInfoTab === selected);
                });
                window.history.replaceState(null, '', '#dependency-' + selected);
            });
        });
    }

    function installPakSources(data) {
        if (!data || !Array.isArray(data.paks) || !data.paks.length || document.querySelector('.file-pak-source-card')) return;
        var rows = data.paks.map(function (pak) {
            return '<tr>'
                + '<td><a href="pak-info.php?id=' + encodeURIComponent(pak.id) + '"><strong>' + h(pak.name) + '</strong></a><br><span class="mono small">' + h(pak.mount_point) + '</span></td>'
                + '<td class="mono path">' + h(pak.entry_path) + '<br><span class="small muted">entry #' + Number(pak.entry_index) + '</span></td>'
                + '<td>' + h(pak.import_status) + '</td>'
                + '<td>' + h(pak.size_text) + '</td>'
                + '<td><span class="mono small">MD5 ' + h(pak.md5) + '</span><br><span class="mono small">SHA256 ' + h(pak.sha256) + '</span></td>'
                + '<td class="pak-source-actions"><a class="button" href="pak-info.php?id=' + encodeURIComponent(pak.id) + '">View PAK</a> <a class="button" href="pak-download.php?id=' + encodeURIComponent(pak.id) + '">Download original PAK</a></td>'
                + '</tr>';
        }).join('');
        var card = document.createElement('div');
        card.className = 'card file-pak-source-card';
        card.innerHTML = '<h2>Source PAK archive' + (data.paks.length === 1 ? '' : 's') + '</h2>'
            + '<p class="muted">This package was extracted from the original self-contained PAK archive shown below.</p>'
            + '<div class="ui-table-region"><table><thead><tr><th>Original PAK</th><th>Entry path</th><th>Import result</th><th>PAK size</th><th>Identity</th><th>Actions</th></tr></thead><tbody>' + rows + '</tbody></table></div>';

        var packageTables = document.getElementById('package-tables');
        if (packageTables && packageTables.parentNode) {
            packageTables.parentNode.insertBefore(card, packageTables);
            return;
        }
        var firstCard = document.querySelector('main > .card, main .card');
        if (firstCard) {
            firstCard.insertAdjacentElement('afterend', card);
        }
    }

    addStyle();
    fetch('file-dependency-files.php?id=' + encodeURIComponent(fileId), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'Accept': 'application/json'}
    }).then(function (response) {
        return response.json().then(function (payload) {
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Could not load file dependencies.');
            return payload;
        });
    }).then(function (data) {
        installExamineTabs(data);
        installFileInfoDependencies(data);
    }).catch(function (error) {
        console.error('[UnrealDB file dependencies]', error);
    });

    fetch('file-pak-sources.php?id=' + encodeURIComponent(fileId), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'Accept': 'application/json'}
    }).then(function (response) {
        return response.json().then(function (payload) {
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Could not load source PAK references.');
            return payload;
        });
    }).then(installPakSources).catch(function (error) {
        console.error('[UnrealDB source PAK references]', error);
    });
})();
