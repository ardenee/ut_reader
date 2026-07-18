(function () {
    'use strict';

    function installStyle() {
        if (document.getElementById('unverified-files-compact-layout-style')) return;

        var style = document.createElement('style');
        style.id = 'unverified-files-compact-layout-style';
        style.textContent = [
            '.uv-id-list > a { display:block; }',
            '.uv-id-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; white-space:nowrap; }',
            '.uv-id-head strong { min-width:0; overflow:hidden; text-overflow:ellipsis; }',
            '.uv-id-head small { flex:0 0 auto; margin:0; text-align:right; }',
            '.uv-id-list > a > span { display:block; }',
            '.uv-match-count { display:flex !important; align-items:center; justify-content:flex-end; min-width:68px; }',
            '.uv-match-compact-count { display:inline-block; white-space:nowrap; font-weight:700; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function compactDatabaseCounts(table) {
        Array.prototype.slice.call(table.querySelectorAll('.uv-database-counts')).forEach(function (counts) {
            counts.textContent = counts.textContent.replace(/\s*N\s*\/\s*I\s*\/\s*E\s*$/i, '').trim();
            counts.title = 'Names / Imports / Exports';
        });
    }

    function compactIdentityMatches(table) {
        Array.prototype.slice.call(table.querySelectorAll('.uv-id-list > a')).forEach(function (link) {
            if (link.querySelector(':scope > .uv-id-head')) return;

            var game = link.querySelector(':scope > strong');
            var engine = link.querySelector(':scope > small');
            if (!game || !engine) return;

            var head = document.createElement('div');
            head.className = 'uv-id-head';
            link.insertBefore(head, link.firstChild);
            head.appendChild(game);
            head.appendChild(engine);
        });
    }

    function numberFromText(value) {
        var match = String(value || '').match(/(\d+)/);
        return match ? parseInt(match[1], 10) || 0 : 0;
    }

    function compactAndSortTargets(table) {
        Array.prototype.slice.call(table.querySelectorAll('.uv-match-list')).forEach(function (list) {
            var cards = Array.prototype.slice.call(list.querySelectorAll(':scope > .uv-match'));

            cards.forEach(function (card) {
                var countCell = card.querySelector('.uv-match-count');
                if (!countCell) return;

                var packageReferences = numberFromText(countCell.querySelector('strong') ? countCell.querySelector('strong').textContent : '');
                var usedBy = numberFromText(countCell.querySelector('small') ? countCell.querySelector('small').textContent : '');
                countCell.innerHTML = '';

                var compact = document.createElement('span');
                compact.className = 'uv-match-compact-count';
                compact.textContent = packageReferences + ' / ' + usedBy;
                compact.title = 'Package References / Used By';
                countCell.appendChild(compact);
            });

            cards.sort(function (left, right) {
                var leftName = left.querySelector('strong');
                var rightName = right.querySelector('strong');
                return String(leftName ? leftName.textContent : '').localeCompare(
                    String(rightName ? rightName.textContent : ''),
                    undefined,
                    { sensitivity: 'base', numeric: true }
                );
            });

            cards.forEach(function (card) { list.appendChild(card); });
        });
    }

    function applyLayout() {
        var table = document.querySelector('#unverified-bulk-form .uv-table');
        if (!table) return;

        installStyle();
        compactDatabaseCounts(table);
        compactIdentityMatches(table);
        compactAndSortTargets(table);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyLayout, { once: true });
    } else {
        applyLayout();
    }
})();
