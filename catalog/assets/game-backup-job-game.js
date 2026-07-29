(function () {
    'use strict';

    function clean(value) {
        return String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
    }

    function findRecentJobsTable() {
        var heading = Array.from(document.querySelectorAll('.card h2')).find(function (node) {
            return clean(node.textContent) === 'Recent backup jobs';
        });
        var card = heading ? heading.closest('.card') : null;
        return card ? card.querySelector('table') : null;
    }

    function gameNamesById() {
        var names = new Map();
        document.querySelectorAll('select[name="game_id"] option[value]').forEach(function (option) {
            var id = Number(option.value || 0);
            var name = clean(option.textContent);
            if (id > 0 && name !== '' && !names.has(id)) names.set(id, name);
        });
        return names;
    }

    function requestError(body, status) {
        if (body && body.error && body.error.message) return String(body.error.message);
        if (body && typeof body.error === 'string') return body.error;
        return 'Could not load the stored job details (HTTP ' + status + ').';
    }

    async function loadJob(jobId) {
        var url = 'api/v1/job-status.php?' + new URLSearchParams({job_id: String(jobId)}).toString();
        var response = await fetch(url, {cache: 'no-store', credentials: 'same-origin'});
        var body;
        try {
            body = await response.json();
        } catch (error) {
            throw new Error('The stored job details returned invalid JSON (HTTP ' + response.status + ').');
        }
        if (!response.ok) throw new Error(requestError(body, response.status));
        var jobs = body && body.data && Array.isArray(body.data.jobs) ? body.data.jobs : [];
        if (!jobs.length) throw new Error('The stored backup job could not be found.');
        return jobs[0];
    }

    function renderGame(cell, job, operation, names) {
        var result = job && job.result && typeof job.result === 'object' ? job.result : {};
        var payload = job && job.payload && typeof job.payload === 'object' ? job.payload : {};
        var gameId = Number(payload.game_id || result.target_game_id || result.game_id || 0);
        var isImport = operation === 'import';
        var gameName = clean(isImport ? result.target_game_name : result.game_name);
        if (gameName === '' && gameId > 0) gameName = names.get(gameId) || ('Game #' + gameId);
        if (gameName === '') gameName = 'Unknown game';

        cell.textContent = '';
        cell.className = 'nowrap';
        var name = document.createElement('strong');
        name.textContent = gameName;
        cell.appendChild(name);
        var role = document.createElement('span');
        role.className = 'small muted';
        role.style.display = 'block';
        role.textContent = isImport ? 'restore target' : 'backup source';
        cell.appendChild(role);
    }

    function init() {
        if (!/\/game-backups\.php$/i.test(window.location.pathname)) return;
        var table = findRecentJobsTable();
        if (!table || table.dataset.backupGameColumnBound === '1') return;
        table.dataset.backupGameColumnBound = '1';

        var header = table.rows[0];
        if (!header || Array.from(header.cells).some(function (cell) { return clean(cell.textContent) === 'Game'; })) return;

        var gameHeader = document.createElement('th');
        gameHeader.textContent = 'Game';
        header.insertBefore(gameHeader, header.lastElementChild);

        var names = gameNamesById();
        Array.from(table.rows).slice(1).forEach(function (row) {
            if (!row.cells || row.cells.length < 5) return;
            var operation = clean(row.cells[1].textContent).toLowerCase();
            if (operation !== 'import' && operation !== 'export') return;

            var gameCell = document.createElement('td');
            gameCell.className = 'nowrap small muted';
            gameCell.textContent = 'Loading…';
            row.insertBefore(gameCell, row.lastElementChild);

            var link = row.cells[0].querySelector('a');
            var match = link ? clean(link.textContent).match(/^#(\d+)$/) : null;
            if (!match) {
                gameCell.textContent = 'Unknown game';
                return;
            }

            loadJob(Number(match[1])).then(function (job) {
                renderGame(gameCell, job, operation, names);
            }).catch(function (error) {
                gameCell.textContent = error && error.message ? error.message : 'Could not load game.';
                gameCell.className = 'small';
                gameCell.title = gameCell.textContent;
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
