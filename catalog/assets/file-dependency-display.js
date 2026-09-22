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
        var nativePanel = root.querySelector('[data-file-examine-native-panel]');
        if (!nav || !nativePanel || nav.querySelector('[data-file-dependency-tab]')) return;

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
        nativePanel.insertAdjacentElement('afterend', requiredByPanel);
        nativePanel.insertAdjacentElement('afterend', requiresPanel);
        bindNewTables(root);

        function show(name, updateUrl) {
            nativePanel.hidden = true;
            root.querySelectorAll('[data-file-dependency-panel]').forEach(function (section) {
                section.hidden = section.dataset.fileDependencyPanel !== name;
            });
            nav.querySelectorAll('.examine-tab').forEach(function (link) {
                var linkName = link.dataset.fileDependencyTab || '';
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
            nativePanel.hidden = false;
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

    function showDependencyLoadError(error) {
        var message = error && error.message ? error.message : 'Could not load file dependency relationships.';
        var root = document.getElementById('package-tables');
        if (root && !root.querySelector('.file-dependency-load-error')) {
            var notice = document.createElement('div');
            notice.className = 'file-dependency-load-error';
            notice.innerHTML = '<p class="dep missing"><strong>Uses / Used By unavailable:</strong> ' + h(message) + '</p>';
            root.insertBefore(notice, root.firstChild);
        }
        var card = document.getElementById('dependencies');
        if (card && !card.querySelector('.file-dependency-load-error')) {
            card.insertAdjacentHTML(
                'beforeend',
                '<p class="file-dependency-load-error dep missing"><strong>Dependency relationships unavailable:</strong> ' + h(message) + '</p>'
            );
        }
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

    function examinerHref(target) {
        var pageSize = parseInt(params.get('page_size') || '250', 10) || 250;
        var match = /^(name|import|export)-(\\d+)$/.exec(target || '');
        if (!match) return '#';
        var table = match[1] === 'name' ? 'names' : (match[1] + 's');
        var index = parseInt(match[2], 10) || 0;
        var page = Math.floor(index / pageSize) + 1;
        return 'file-examine.php?id=' + encodeURIComponent(fileId)
            + '&tab=' + encodeURIComponent(table)
            + '&page=' + encodeURIComponent(page)
            + '&page_size=' + encodeURIComponent(pageSize)
            + '&target=' + encodeURIComponent(target)
            + '#' + encodeURIComponent(target);
    }

    function installExaminerEnrichment(data) {
        Object.keys(data.name_usage || {}).forEach(function (index) {
            var cell = document.querySelector('[data-examine-name-usage="' + CSS.escape(index) + '"]');
            if (!cell) return;
            var usage = data.name_usage[index] || {};
            var parts = [];
            if (Number(usage.imports_count || 0) > 0 && usage.imports_target) {
                parts.push('<a class="xref" href="' + h(examinerHref(usage.imports_target)) + '">Imports: ' + Number(usage.imports_count) + '</a>');
            }
            if (Number(usage.exports_count || 0) > 0 && usage.exports_target) {
                parts.push('<a class="xref" href="' + h(examinerHref(usage.exports_target)) + '">Exports: ' + Number(usage.exports_count) + '</a>');
            }
            cell.innerHTML = parts.length ? parts.join(' <span class="muted">·</span> ') : '<span class="muted">none</span>';
        });

        Object.keys(data.name_links || {}).forEach(function (rowIndex) {
            var links = data.name_links[rowIndex] || {};
            Object.keys(links).forEach(function (column) {
                var selector = '[data-examine-name-link="' + CSS.escape(column) + '"][data-examine-row="' + CSS.escape(rowIndex) + '"]';
                var cell = document.querySelector(selector);
                if (!cell) return;
                var value = (cell.textContent || '').trim();
                var target = 'name-' + Number(links[column]);
                cell.innerHTML = '<a class="xref mono path" href="' + h(examinerHref(target)) + '" title="Open name table entry">' + h(value) + '</a>';
            });
        });

        document.querySelectorAll('[data-examine-dependency]').forEach(function (cell) {
            var index = cell.dataset.examineDependency;
            var dependency = (data.dependencies || {})[index];
            if (!dependency) {
                cell.innerHTML = '<span class="muted">not built</span>';
                return;
            }
            var status = String(dependency.status || 'unknown');
            var title = String(dependency.required_object_path || '').trim();
            cell.innerHTML = '<span class="dep ' + h(status) + '"' + (title ? ' title="' + h(title) + '"' : '') + '>' + h(status) + '</span>';
        });
    }

    function installRawHeader(payload) {
        var card = document.getElementById('raw-package-header');
        if (!card) return;
        var inspection = payload && payload.inspection;
        if (!inspection || !inspection.ok) {
            card.innerHTML = '<h2>Raw package header</h2><p class="muted">' + h(inspection ? inspection.error : 'Header inspection unavailable.') + '</p>';
            return;
        }
        var summary = inspection.summary || {};
        var left = ['GUID','Version','Licensee Version','Signature','Name Offset','Import Offset','Export Offset','Total Header Size'];
        var right = ['Flags','Build','Heritage','Counts','Catalog Counts','Generations','Folder Name'];
        function table(labels) {
            return '<table>' + labels.filter(function (label) {
                return Object.prototype.hasOwnProperty.call(summary, label);
            }).map(function (label) {
                return '<tr><th>' + h(label) + '</th><td class="mono path">' + h(summary[label]) + '</td></tr>';
            }).join('') + '</table>';
        }
        var rows = Array.isArray(inspection.rows) ? inspection.rows.slice(0, 500) : [];
        var details = '';
        if (rows.length) {
            details = '<details><summary>Raw fields (' + Number(inspection.rows.length) + ')</summary><div class="examine-table-region"><table><thead><tr><th>Offset</th><th>Size</th><th>Field</th><th>Type</th><th>Value</th><th>Raw hex</th><th>Note</th></tr></thead><tbody>'
                + rows.map(function (row) {
                    return '<tr><td class="mono">' + Number(row.offset || 0) + '</td><td class="mono">' + Number(row.size || 0) + '</td><td class="mono">' + h(row.field) + '</td><td class="mono">' + h(row.type) + '</td><td class="mono path">' + h(row.value) + '</td><td class="mono path">' + h(row.hex) + '</td><td>' + h(row.note) + '</td></tr>';
                }).join('') + '</tbody></table></div></details>';
        }
        card.innerHTML = '<h2>Raw package header</h2><div class="two-col">' + table(left) + table(right) + '</div>' + details;
    }

    function loadRawHeader() {
        if (!document.getElementById('raw-package-header')) return;
        fetch('file-examine-header.php?id=' + encodeURIComponent(fileId), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'Could not inspect stored package header.');
                return payload;
            });
        }).then(installRawHeader).catch(function (error) {
            console.error('[UnrealDB raw package header]', error);
            var status = document.querySelector('[data-file-examine-header-status]');
            if (status) status.textContent = 'Raw package header unavailable: ' + (error.message || 'unknown error');
        });
    }

    function loadExaminerEnrichment() {
        var root = document.getElementById('package-tables');
        if (!root) return;
        var table = params.get('tab') || 'names';
        if (table !== 'names' && table !== 'imports' && table !== 'exports') return;
        var page = parseInt(params.get('page') || '1', 10) || 1;
        var pageSize = parseInt(params.get('page_size') || '250', 10) || 250;
        fetch('file-examine-enrichment.php?id=' + encodeURIComponent(fileId)
            + '&table=' + encodeURIComponent(table)
            + '&page=' + encodeURIComponent(page)
            + '&page_size=' + encodeURIComponent(pageSize), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'Could not enrich package table.');
                return payload;
            });
        }).then(installExaminerEnrichment).catch(function (error) {
            console.error('[UnrealDB file examine enrichment]', error);
            root.querySelectorAll('[data-examine-name-usage],[data-examine-dependency]').forEach(function (cell) {
                cell.innerHTML = '<span class="muted">unavailable</span>';
            });
        });
    }

    addStyle();
    var examineRoot = document.getElementById('package-tables');
    if (examineRoot) {
        // Keep the examiner interactive after first paint. Expensive physical-header
        // and dependency relationship work must not start automatically here.
        // Those features are reattached lazily once their bounded endpoints are in place.
        return;
    }
    loadRawHeader();
    loadExaminerEnrichment();
    var dependencyEndpoint = 'file-dependency-files.php?id=' + encodeURIComponent(fileId);
    fetch(dependencyEndpoint, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'Accept': 'application/json'}
    }).then(function (response) {
        return response.json().then(function (payload) {
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Could not load file dependencies.');
            return payload;
        });
    }).then(function (data) {
        if (examineRoot) {
            installExamineTabs(data);
        } else {
            installFileInfoDependencies(data);
        }
    }).catch(function (error) {
        console.error('[UnrealDB file dependencies]', error);
        showDependencyLoadError(error);
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
