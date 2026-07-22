(function () {
    'use strict';

    if (window.__unrealDbCatalogIdentitiesLoaded) return;
    window.__unrealDbCatalogIdentitiesLoaded = true;

    var script = document.currentScript;
    var endpoint = script && script.src
        ? new URL('../api/v1/file-identities.php', script.src).toString()
        : 'api/v1/file-identities.php';
    var identityCache = new Map();
    var pendingTargets = new Map();
    var requestScheduled = false;

    function normalize(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function cleanValue(value) {
        value = String(value || '').trim();
        return value === '' || value === '—' || value === '-' ? '' : value;
    }

    function headerKind(label) {
        var text = normalize(label).replace(/[():]/g, ' ');
        if (text.indexOf('identity') !== -1) return 'identity';
        if (/\bguid\b/.test(text)) return 'guid';
        if (/\bmd5\b/.test(text)) return 'md5';
        if (/\bsha(?:1|256)?\b/.test(text)) return 'sha';
        return '';
    }

    function emptyIdentity() {
        return { guid: '', md5: '', sha: '', shaRank: 0 };
    }

    function mergeIdentity(target, source) {
        if (source.guid) target.guid = source.guid;
        if (source.md5) target.md5 = source.md5;
        if (source.sha && (source.shaRank || 1) >= (target.shaRank || 0)) {
            target.sha = source.sha;
            target.shaRank = source.shaRank || 1;
        }
        return target;
    }

    function stripLabel(value, kind) {
        var expression = kind === 'guid'
            ? /^\s*guid\s*[:=-]?\s*/i
            : kind === 'md5'
                ? /^\s*md5\s*[:=-]?\s*/i
                : /^\s*sha(?:1|256)?\s*[:=-]?\s*/i;
        return cleanValue(String(value || '').replace(expression, ''));
    }

    function parseIdentityText(text, fallbackKind) {
        var result = emptyIdentity();
        var lines = String(text || '').split(/[\r\n]+/).map(function (line) {
            return line.replace(/\s+/g, ' ').trim();
        }).filter(Boolean);

        lines.forEach(function (line) {
            var match = line.match(/^GUID\s*[:=-]?\s*(.*)$/i);
            if (match) {
                result.guid = cleanValue(match[1]);
                return;
            }
            match = line.match(/^MD5\s*[:=-]?\s*(.*)$/i);
            if (match) {
                result.md5 = cleanValue(match[1]);
                return;
            }
            match = line.match(/^SHA(256|1)?\s*[:=-]?\s*(.*)$/i);
            if (match) {
                result.sha = cleanValue(match[2]);
                result.shaRank = match[1] === '256' ? 3 : (match[1] === '1' ? 2 : 1);
                return;
            }

            if (fallbackKind === 'guid' && !result.guid) result.guid = stripLabel(line, 'guid');
            if (fallbackKind === 'md5' && !result.md5) result.md5 = stripLabel(line, 'md5');
            if (fallbackKind === 'sha' && !result.sha) {
                result.sha = stripLabel(line, 'sha');
                result.shaRank = 1;
            }
            if (fallbackKind === 'identity' && !result.guid && /^[A-Fa-f0-9-]{16,64}$/.test(line)) {
                result.guid = line;
            }
        });

        if (lines.length === 1 && fallbackKind && fallbackKind !== 'identity') {
            var value = stripLabel(lines[0], fallbackKind);
            if (fallbackKind === 'guid') result.guid = value;
            if (fallbackKind === 'md5') result.md5 = value;
            if (fallbackKind === 'sha') {
                result.sha = value;
                result.shaRank = 1;
            }
        }
        return result;
    }

    function identityLine(label, value) {
        var line = document.createElement('span');
        line.className = 'catalog-identity__line';
        line.style.display = 'block';
        line.style.whiteSpace = 'nowrap';
        line.style.overflowWrap = 'normal';
        line.style.wordBreak = 'normal';

        var strong = document.createElement('strong');
        strong.textContent = label;
        line.appendChild(strong);
        line.appendChild(document.createTextNode(' ' + (cleanValue(value) || '—')));
        return line;
    }

    function renderIdentity(cell, identity) {
        if (!cell) return;
        cell.textContent = '';
        cell.classList.add('catalog-identity-cell');
        cell.style.whiteSpace = 'nowrap';
        cell.style.overflowWrap = 'normal';
        cell.style.wordBreak = 'normal';

        var block = document.createElement('span');
        block.className = 'catalog-identity mono small';
        block.style.display = 'inline-flex';
        block.style.flexDirection = 'column';
        block.style.alignItems = 'flex-start';
        block.style.whiteSpace = 'nowrap';
        block.style.overflowWrap = 'normal';
        block.style.wordBreak = 'normal';
        block.appendChild(identityLine('GUID', identity.guid));
        block.appendChild(identityLine('MD5', identity.md5));
        block.appendChild(identityLine('SHA', identity.sha));
        cell.appendChild(block);
    }

    function fileIdFromHref(href) {
        if (!href) return 0;
        try {
            var url = new URL(href, window.location.href);
            if (!/(?:file-info|file-examine|download-info|download|file-maintenance)\.php$/i.test(url.pathname)) return 0;
            var id = parseInt(url.searchParams.get('file_id') || url.searchParams.get('id') || '0', 10);
            return id > 0 ? id : 0;
        } catch (error) {
            return 0;
        }
    }

    function fileIdFromScope(scope) {
        var explicit = parseInt(scope && scope.dataset ? (scope.dataset.fileId || '0') : '0', 10);
        if (explicit > 0) return explicit;

        var hidden = scope ? scope.querySelector('input[name="file_id"][value]') : null;
        if (hidden) {
            var hiddenId = parseInt(hidden.value || '0', 10);
            if (hiddenId > 0) return hiddenId;
        }

        var links = scope ? scope.querySelectorAll('a[href]') : [];
        for (var i = 0; i < links.length; i++) {
            var id = fileIdFromHref(links[i].getAttribute('href'));
            if (id > 0) return id;
        }
        return 0;
    }

    function currentFileId() {
        if (!/(?:file-info|file-examine)\.php$/i.test(window.location.pathname)) return 0;
        var id = parseInt(new URLSearchParams(window.location.search).get('id') || '0', 10);
        return id > 0 ? id : 0;
    }

    function queueIdentityFetch(fileId, cell, fallback) {
        if (!fileId || !cell) return;
        if (identityCache.has(fileId)) {
            renderIdentity(cell, identityCache.get(fileId));
            return;
        }
        if (!pendingTargets.has(fileId)) pendingTargets.set(fileId, []);
        pendingTargets.get(fileId).push({ cell: cell, fallback: fallback });
        if (!requestScheduled) {
            requestScheduled = true;
            window.setTimeout(flushIdentityRequests, 0);
        }
    }

    async function flushIdentityRequests() {
        requestScheduled = false;
        var ids = Array.from(pendingTargets.keys()).slice(0, 500);
        if (!ids.length) return;
        var targets = new Map();
        ids.forEach(function (id) {
            targets.set(id, pendingTargets.get(id) || []);
            pendingTargets.delete(id);
        });

        try {
            var url = new URL(endpoint, window.location.href);
            url.searchParams.set('ids', ids.join(','));
            var response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' });
            var body = await response.json();
            if (!response.ok) throw new Error('Identity request failed.');
            var files = body && body.data && body.data.files ? body.data.files : {};
            ids.forEach(function (id) {
                var raw = files[String(id)] || null;
                var identity = raw
                    ? { guid: cleanValue(raw.guid), md5: cleanValue(raw.md5), sha: cleanValue(raw.sha), shaRank: 2 }
                    : null;
                if (identity) identityCache.set(id, identity);
                (targets.get(id) || []).forEach(function (target) {
                    renderIdentity(target.cell, identity || target.fallback || emptyIdentity());
                });
            });
        } catch (error) {
            ids.forEach(function (id) {
                (targets.get(id) || []).forEach(function (target) {
                    renderIdentity(target.cell, target.fallback || emptyIdentity());
                });
            });
        }

        if (pendingTargets.size > 0 && !requestScheduled) {
            requestScheduled = true;
            window.setTimeout(flushIdentityRequests, 0);
        }
    }

    function identityColumns(table) {
        if (!table.tHead || !table.tHead.rows.length) return [];
        return Array.from(table.tHead.rows[0].cells).map(function (header, index) {
            return { header: header, index: index, kind: headerKind(header.textContent) };
        }).filter(function (column) { return column.kind !== ''; });
    }

    function processColumnTable(table) {
        if (table.dataset.catalogIdentityColumns === '1') return;
        var columns = identityColumns(table);
        if (!columns.length) return;
        table.dataset.catalogIdentityColumns = '1';

        var primary = columns.find(function (column) { return column.kind === 'identity'; }) || columns[0];
        primary.header.textContent = 'Identity';
        primary.header.style.whiteSpace = 'nowrap';
        columns.forEach(function (column) {
            if (column !== primary) column.header.hidden = true;
        });

        Array.from(table.tBodies).forEach(function (body) {
            Array.from(body.rows).forEach(function (row) {
                if (!row.cells.length || !row.cells[primary.index]) return;
                var identity = emptyIdentity();
                columns.forEach(function (column) {
                    var cell = row.cells[column.index];
                    if (!cell) return;
                    mergeIdentity(identity, parseIdentityText(cell.innerText || cell.textContent, column.kind));
                    if (column !== primary) cell.hidden = true;
                });

                var primaryCell = row.cells[primary.index];
                renderIdentity(primaryCell, identity);
                var fileId = fileIdFromScope(row);
                if (fileId > 0) queueIdentityFetch(fileId, primaryCell, identity);
            });
        });
    }

    function processKeyValueTable(table) {
        if (table.dataset.catalogIdentityRows === '1') return;
        var matches = [];
        Array.from(table.rows).forEach(function (row) {
            if (row.cells.length < 2 || row.cells[0].tagName !== 'TH') return;
            var kind = headerKind(row.cells[0].textContent);
            if (kind) matches.push({ row: row, kind: kind });
        });
        if (!matches.length) return;
        table.dataset.catalogIdentityRows = '1';

        var identity = emptyIdentity();
        matches.forEach(function (match) {
            var parsed = parseIdentityText(match.row.cells[1].innerText || match.row.cells[1].textContent, match.kind);
            if (match.kind === 'sha') {
                var key = normalize(match.row.cells[0].textContent);
                parsed.shaRank = key.indexOf('256') !== -1 ? 3 : (key.indexOf('1') !== -1 ? 2 : 1);
            }
            mergeIdentity(identity, parsed);
        });

        var primary = matches[0].row;
        primary.cells[0].textContent = 'Identity';
        primary.cells[0].style.whiteSpace = 'nowrap';
        renderIdentity(primary.cells[1], identity);
        matches.slice(1).forEach(function (match) { match.row.remove(); });

        var fileId = fileIdFromScope(table) || currentFileId();
        if (fileId > 0) queueIdentityFetch(fileId, primary.cells[1], identity);
    }

    function processTables(root) {
        var tables = [];
        if (root instanceof HTMLTableElement) tables.push(root);
        if (root && root.querySelectorAll) {
            root.querySelectorAll('table').forEach(function (table) { tables.push(table); });
        }
        tables.forEach(function (table) {
            processColumnTable(table);
            processKeyValueTable(table);
        });
    }

    function installStyle() {
        var style = document.createElement('style');
        style.textContent = [
            '.catalog-identity-cell, .catalog-identity-cell * { white-space: nowrap !important; overflow-wrap: normal !important; word-break: normal !important; }',
            '.catalog-identity strong { font-weight: 750; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    installStyle();
    processTables(document);

    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === Node.ELEMENT_NODE) processTables(node);
            });
        });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
