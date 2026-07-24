(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    function gameIdForRow(row) {
        var input = row.querySelector('input[name="game_id"]');
        if (input && /^\d+$/.test(input.value || '')) {
            return input.value;
        }
        var link = row.querySelector('a[href*="game-manager.php?game_id="]');
        if (!link) return '';
        try {
            return new URL(link.href, window.location.href).searchParams.get('game_id') || '';
        } catch (error) {
            return '';
        }
    }

    function gamesTable() {
        var cards = Array.from(document.querySelectorAll('.card'));
        var card = cards.find(function (candidate) {
            var title = candidate.querySelector('h2');
            return title && (title.textContent || '').trim() === 'Games';
        });
        return card ? card.querySelector('table') : null;
    }

    function insertHeader(row, index) {
        var header = document.createElement('th');
        header.textContent = 'Missing dependencies';
        header.setAttribute('title', 'Missing dependency object rows for this game');
        row.insertBefore(header, row.cells[index + 1] || null);
    }

    function insertCountCell(row, index, gameId) {
        var cell = document.createElement('td');
        cell.dataset.gameMissingCount = gameId;
        cell.innerHTML = '<span class="muted">…</span>';
        row.insertBefore(cell, row.cells[index + 1] || null);
        return cell;
    }

    function showCount(cell, count) {
        var link = document.createElement('a');
        link.href = 'missing.php';
        link.title = 'Open missing dependency report';
        var pill = document.createElement('span');
        pill.className = 'pill ' + (count > 0 ? 'amber' : 'good-pill');
        pill.textContent = Number(count || 0).toLocaleString();
        link.appendChild(pill);
        cell.replaceChildren(link);
    }

    function failCells(cells) {
        cells.forEach(function (cell) {
            cell.innerHTML = '<span class="muted" title="Missing dependency count unavailable">unavailable</span>';
        });
    }

    ready(function () {
        var table = gamesTable();
        if (!table || table.dataset.gameMissingCountsBound === '1' || !table.rows.length) return;

        var headerRow = table.rows[0];
        var headers = Array.from(headerRow.cells);
        var fileIndex = headers.findIndex(function (cell) {
            return (cell.textContent || '').trim() === 'Files';
        });
        if (fileIndex < 0) return;

        table.dataset.gameMissingCountsBound = '1';
        insertHeader(headerRow, fileIndex);

        var cells = new Map();
        Array.from(table.rows).slice(1).forEach(function (row) {
            var gameId = gameIdForRow(row);
            if (gameId === '') return;
            cells.set(gameId, insertCountCell(row, fileIndex, gameId));
        });
        if (!cells.size) return;

        fetch('api/v1/game-missing-counts.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json || json.ok !== true) {
                    throw new Error((json && json.error) || 'Count request failed');
                }
                return json;
            });
        }).then(function (result) {
            cells.forEach(function (cell, gameId) {
                showCount(cell, Number((result.counts || {})[gameId] || 0));
            });
        }).catch(function () {
            failCells(cells);
        });
    });
})();
