(function () {
    'use strict';

    const form = document.getElementById('upload-bucket-form');
    const fileInput = document.getElementById('upload-bucket-files');
    const folderInput = document.getElementById('upload-bucket-folder');
    if (!form || !fileInput || !window.DataTransfer) return;

    let configured = [];
    try {
        configured = JSON.parse(form.dataset.allowedExtensions || '[]');
    } catch (error) {
        configured = [];
    }

    const allowed = new Set(configured.map(function (extension) {
        return String(extension || '').trim().toLowerCase().replace(/^\.+/, '');
    }).filter(Boolean));
    ['uz', 'uz2', 'uz3'].forEach(function (extension) { allowed.add(extension); });

    function displayName(file) {
        return file.webkitRelativePath || file.name || 'Unnamed file';
    }

    function extensionOf(file) {
        const name = String(file && file.name ? file.name : '').replace(/\\/g, '/').split('/').pop();
        const position = name.lastIndexOf('.');
        return position >= 0 ? name.slice(position + 1).trim().toLowerCase() : '';
    }

    function filterInput(input) {
        if (!input || !input.files || !input.files.length) return [];

        const transfer = new window.DataTransfer();
        const skipped = [];
        Array.from(input.files).forEach(function (file) {
            const extension = extensionOf(file);
            if (extension !== '' && allowed.has(extension)) {
                transfer.items.add(file);
                return;
            }
            skipped.push({file: file, extension: extension});
        });
        input.files = transfer.files;
        return skipped;
    }

    function appendSkipped(skipped) {
        const progressBox = document.getElementById('bucket-progress');
        const log = document.getElementById('bucket-log');
        if (!log) return;
        if (progressBox) progressBox.hidden = false;

        skipped.forEach(function (entry) {
            const row = document.createElement('div');
            row.className = 'bucket-result bucket-result-skipped';

            const badge = document.createElement('span');
            badge.className = 'bucket-result-badge';
            badge.textContent = 'skipped';
            row.appendChild(badge);

            const file = document.createElement('span');
            file.className = 'bucket-result-file';
            file.textContent = displayName(entry.file);
            row.appendChild(file);

            const message = document.createElement('span');
            message.className = 'bucket-result-message';
            message.textContent = 'Extension .' + (entry.extension || '(none)')
                + ' is not allowed by any active game profile. Skipped before hashing or upload; no retry was attempted.';
            row.appendChild(message);

            log.appendChild(row);
        });
        log.scrollTop = log.scrollHeight;
    }

    form.addEventListener('submit', function (event) {
        const skipped = filterInput(fileInput).concat(filterInput(folderInput));
        if (!skipped.length) return;

        window.setTimeout(function () { appendSkipped(skipped); }, 0);

        const remaining = Number(fileInput.files ? fileInput.files.length : 0)
            + Number(folderInput && folderInput.files ? folderInput.files.length : 0);
        if (remaining > 0) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const progressBox = document.getElementById('bucket-progress');
        const currentBar = document.getElementById('bucket-progress-bar');
        const overallBar = document.getElementById('bucket-overall-progress-bar');
        const currentLabel = document.getElementById('bucket-progress-label');
        const overallLabel = document.getElementById('bucket-overall-progress-label');
        const overallCount = document.getElementById('bucket-overall-progress-count');
        const button = document.getElementById('upload-bucket-button');

        if (progressBox) progressBox.hidden = false;
        if (currentBar) currentBar.value = 100;
        if (overallBar) overallBar.value = 100;
        if (currentLabel) currentLabel.textContent = 'No selected files use an allowed package extension.';
        if (overallLabel) overallLabel.textContent = 'Extension check complete (100%)';
        if (overallCount) overallCount.textContent = skipped.length + ' unsupported file(s) skipped';
        if (button) button.disabled = false;
    }, true);
}());
