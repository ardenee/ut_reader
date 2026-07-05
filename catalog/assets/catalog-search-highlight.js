(function () {
    'use strict';

    function escapeRegExp(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function findResultsTable() {
        var headings = Array.from(document.querySelectorAll('.card h2'));
        var heading = headings.find(function (candidate) {
            return candidate.textContent.trim() === 'Results';
        });
        return heading ? heading.closest('.card').querySelector('table') : null;
    }

    function highlightTextNode(node, expression) {
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
    }

    function highlightSearchResults() {
        var params = new URLSearchParams(window.location.search);
        var query = (params.get('q') || '').trim();
        if (query.length < 2) return;

        var table = findResultsTable();
        if (!table || table.dataset.searchHighlightApplied === '1') return;
        table.dataset.searchHighlightApplied = '1';

        var style = document.createElement('style');
        style.textContent = '.catalog-search-highlight{padding:0 2px;border-radius:3px;color:#1a1300;background:#f6c453;font-weight:800}';
        document.head.appendChild(style);

        var expression;
        try {
            expression = new RegExp('(' + escapeRegExp(query) + ')', 'gi');
        } catch (error) {
            return;
        }

        Array.from(table.querySelectorAll('tbody td:nth-child(1), tbody td:nth-child(2), tbody td:nth-child(3), tbody td:nth-child(4)')).forEach(function (cell) {
            var walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, {
                acceptNode: function (node) {
                    return node.parentElement && node.parentElement.closest('a, mark')
                        ? NodeFilter.FILTER_REJECT
                        : NodeFilter.FILTER_ACCEPT;
                }
            });
            var nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            nodes.forEach(function (node) { highlightTextNode(node, expression); });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', highlightSearchResults);
    } else {
        highlightSearchResults();
    }
})();
