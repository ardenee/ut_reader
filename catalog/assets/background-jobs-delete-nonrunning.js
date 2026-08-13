(function () {
    'use strict';

    const app = document.getElementById('background-jobs-app');
    const action = document.getElementById('jobs-bulk-action');
    const apply = document.getElementById('jobs-apply-action');
    const summary = document.getElementById('jobs-selection-summary');
    if (!app || !action || !apply || !summary) return;

    function selectedNonRunningExists() {
        const text = String(summary.textContent || '').trim();
        if (!text || text === 'Nothing selected') return false;

        // "Select all matching" is represented by the summary rather than row
        // checkboxes. The bulk API remains authoritative and excludes running
        // jobs from deletion even when a mixed result set is selected.
        if (/matching jobs selected/i.test(text)) return true;

        const checked = Array.from(document.querySelectorAll('.jobs-row-checkbox:checked'));
        return checked.some(function (checkbox) {
            const row = checkbox.closest('tr.jobs-main-row');
            if (!row) return false;
            const badge = row.querySelector('.job-status');
            return !badge || !/\brunning\b/i.test(String(badge.textContent || ''));
        });
    }

    function installDeleteOption() {
        if (!selectedNonRunningExists()) return;
        let option = action.querySelector('option[value="delete"]');
        if (!option) {
            option = document.createElement('option');
            option.value = 'delete';
            action.appendChild(option);
        }
        option.textContent = 'Delete selected/matching non-running jobs';
        action.disabled = false;
        apply.disabled = !action.value;
    }

    const observer = new MutationObserver(function () {
        window.setTimeout(installDeleteOption, 0);
    });
    observer.observe(action, {childList: true});
    observer.observe(summary, {childList: true, characterData: true, subtree: true});
    document.addEventListener('change', function (event) {
        if (event.target && event.target.classList && event.target.classList.contains('jobs-row-checkbox')) {
            window.setTimeout(installDeleteOption, 0);
        }
    });
    document.addEventListener('click', function (event) {
        const id = event.target && event.target.id ? event.target.id : '';
        if (id === 'jobs-select-matching' || id === 'jobs-clear-selection') {
            window.setTimeout(installDeleteOption, 0);
        }
    });
    action.addEventListener('change', function () {
        apply.disabled = !action.value;
    });

    installDeleteOption();
}());
