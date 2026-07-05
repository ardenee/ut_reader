<?php
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, private');
?>
(function () {
    'use strict';

    function injectStyle() {
        var style = document.createElement('style');
        style.textContent = [
            '.catalog-maintenance-overlay { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 20px; background: rgba(3, 8, 18, .72); backdrop-filter: blur(3px); }',
            '.catalog-maintenance-dialog { width: min(520px, 100%); padding: 24px; border: 1px solid var(--line2); border-radius: 14px; background: #111b2d; box-shadow: 0 24px 70px rgba(0,0,0,.5); }',
            '.catalog-maintenance-dialog h2 { margin: 0 0 8px; }',
            '.catalog-maintenance-dialog p { margin: 0 0 16px; }',
            '.catalog-maintenance-progress { height: 12px; overflow: hidden; border: 1px solid var(--line2); border-radius: 999px; background: rgba(255,255,255,.05); }',
            '.catalog-maintenance-progress > span { display: block; width: 42%; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #76a9ff, #9dc2ff, #76a9ff); animation: catalog-maintenance-progress 1.25s ease-in-out infinite; }',
            '.catalog-maintenance-steps { margin: 16px 0 0; padding: 0; list-style: none; }',
            '.catalog-maintenance-steps li { margin: 7px 0; color: var(--muted); }',
            '.catalog-maintenance-steps li.is-active { color: var(--text); font-weight: 700; }',
            '.catalog-maintenance-steps li.is-active::before { content: "› "; color: var(--blue); }',
            '.catalog-maintenance-notice { margin: 0 0 14px; padding: 12px 14px; border: 1px solid rgba(50,213,131,.55); border-radius: 10px; color: #dcffea; background: rgba(50,213,131,.12); }',
            '@keyframes catalog-maintenance-progress { 0% { transform: translateX(-110%); } 55% { transform: translateX(150%); } 100% { transform: translateX(150%); } }'
        ].join('\n');
        document.head.appendChild(style);
    }

    function stagesFor(operation) {
        return operation === 'remove'
            ? ['Preparing removal', 'Staging stored package', 'Removing catalog records', 'Rebuilding dependency links', 'Finalising cleanup']
            : ['Preparing dependency rebuild', 'Scanning verified packages', 'Resolving import and export links', 'Saving dependency results', 'Refreshing file list'];
    }

    function showOverlay(operation, label) {
        if (document.querySelector('.catalog-maintenance-overlay')) return;
        var stages = stagesFor(operation);
        var overlay = document.createElement('div');
        overlay.className = 'catalog-maintenance-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'assertive');

        var stepsHtml = stages.map(function (stage, index) {
            return '<li' + (index === 0 ? ' class="is-active"' : '') + '>' + stage + '</li>';
        }).join('');
        overlay.innerHTML = '<div class="catalog-maintenance-dialog">'
            + '<h2>' + (operation === 'remove' ? 'Removing package' : 'Rebuilding dependencies') + '</h2>'
            + '<p>' + label + '</p>'
            + '<div class="catalog-maintenance-progress"><span></span></div>'
            + '<ul class="catalog-maintenance-steps">' + stepsHtml + '</ul>'
            + '</div>';
        document.body.appendChild(overlay);

        var stepItems = Array.from(overlay.querySelectorAll('.catalog-maintenance-steps li'));
        var active = 0;
        window.setInterval(function () {
            if (active < stepItems.length - 1) {
                stepItems[active].classList.remove('is-active');
                active += 1;
                stepItems[active].classList.add('is-active');
            }
        }, 1250);
    }

    function showCompletionNotice() {
        var params = new URLSearchParams(window.location.search);
        var state = params.get('maintenance');
        if (!state) return;

        var message = state === 'removed'
            ? 'Package removal completed. The game dependency links were rebuilt.'
            : 'Dependency rebuild completed for this game.';
        var section = document.querySelector('.ui-section');
        if (!section) return;
        var notice = document.createElement('div');
        notice.className = 'catalog-maintenance-notice';
        notice.textContent = message;
        section.insertBefore(notice, section.firstChild);

        params.delete('maintenance');
        var query = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
    }

    function bindForms() {
        document.querySelectorAll('.game-files-admin-actions form').forEach(function (form) {
            var operationInput = form.querySelector('input[name="operation"]');
            if (!operationInput) return;
            var operation = operationInput.value;
            var row = form.closest('tr');
            var fileName = row && row.cells.length > 1 ? row.cells[1].textContent.trim().replace(/\s+/g, ' ') : 'Selected package';
            form.removeAttribute('onsubmit');

            form.addEventListener('submit', function (event) {
                if (operation === 'remove' && !window.confirm('Remove ' + fileName + ' from storage and the catalog? This cannot be undone. Dependency links will be rebuilt afterwards.')) {
                    event.preventDefault();
                    return;
                }
                showOverlay(operation, fileName);
            });
        });
    }

    injectStyle();
    showCompletionNotice();
    bindForms();
})();
