<?php
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, private');
?>
(function () {
    'use strict';

    function token() {
        var data = new Uint8Array(18);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(data);
            return Array.from(data).map(function (v) { return v.toString(16).padStart(2, '0'); }).join('');
        }
        return Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    function overlay(label) {
        var node = document.createElement('div');
        node.id = 'catalog-maintenance-live-overlay';
        node.style.cssText = 'position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:20px;background:rgba(3,8,18,.72)';
        node.innerHTML = '<div style="width:min(520px,100%);padding:24px;border:1px solid #3a4c6d;border-radius:14px;background:#111b2d;box-shadow:0 24px 70px rgba(0,0,0,.5)"><h2 style="margin:0 0 8px">Catalog maintenance</h2><p id="catalog-maintenance-live-message" style="margin:0 0 16px">' + label + '</p><progress id="catalog-maintenance-live-bar" value="0" max="100" style="width:100%;height:16px"></progress><p id="catalog-maintenance-live-count" style="margin:9px 0 0;color:#b6c6e4">Waiting for server…</p></div>';
        document.body.appendChild(node);
        return node;
    }

    function update(node, state) {
        var bar = node.querySelector('#catalog-maintenance-live-bar');
        var message = node.querySelector('#catalog-maintenance-live-message');
        var count = node.querySelector('#catalog-maintenance-live-count');
        var done = Number(state.done || 0);
        var total = Number(state.total || 0);
        var percent = Math.max(0, Math.min(100, Number(state.percent || 0)));
        bar.value = percent;
        message.textContent = state.message || 'Working…';
        count.textContent = total > 0 ? done + ' of ' + total + ' imports processed (' + Math.round(percent) + '%)' : 'Waiting for server…';
    }

    function monitor(id, node) {
        var active = true;
        var timer;
        function tick() {
            if (!active) return;
            fetch('file-maintenance.php?progress=' + encodeURIComponent(id), {credentials:'same-origin', cache:'no-store'})
                .then(function (response) { return response.json(); })
                .then(function (response) { if (response.ok && response.progress) update(node, response.progress); })
                .catch(function () {})
                .finally(function () { if (active) timer = setTimeout(tick, 450); });
        }
        tick();
        return function () { active = false; if (timer) clearTimeout(timer); };
    }

    document.querySelectorAll('.game-files-admin-actions form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var row = form.closest('tr');
            var label = row && row.cells[1] ? row.cells[1].textContent.trim().replace(/\s+/g, ' ') : 'Selected package';
            var id = token();
            var node = overlay(label);
            var stop = monitor(id, node);
            var data = new FormData(form);
            data.set('progress_token', id);
            document.querySelectorAll('.game-files-admin-actions button').forEach(function (button) { button.disabled = true; });
            fetch(form.action, {method:'POST', credentials:'same-origin', headers:{Accept:'application/json'}, body:data})
                .then(function (response) { return response.json(); })
                .then(function (response) {
                    stop();
                    if (!response.ok) throw new Error(response.error || 'Maintenance failed.');
                    update(node, {done:1,total:1,percent:100,message:response.message || 'Maintenance complete.'});
                    node.querySelector('#catalog-maintenance-live-count').textContent = 'Maintenance complete. Loading updated file list…';
                    setTimeout(function () { window.location.assign(response.return_url || window.location.href); }, 80);
                })
                .catch(function (error) {
                    stop();
                    node.remove();
                    document.querySelectorAll('.game-files-admin-actions button').forEach(function (button) { button.disabled = false; });
                    window.alert(error.message || 'Maintenance failed.');
                });
        });
    });
})();
