(function () {
    'use strict';

    const folderInput = document.getElementById('profiled-upload-folder');
    const progress = document.getElementById('profiled-upload-progress');
    const overallBar = document.getElementById('overall-progress-bar');
    const overallLabel = document.getElementById('overall-progress-label');
    const overallCount = document.getElementById('overall-progress-count');
    const currentBar = document.getElementById('upload-progress-bar');
    const currentLabel = document.getElementById('upload-progress-label');
    if (!folderInput || !progress || !overallBar || !overallLabel || !overallCount || !currentBar || !currentLabel) {
        loadCore();
        return;
    }

    let scanActive = false;
    let stopRequested = false;
    let folderButton = null;
    let folderSummary = null;
    let stopButton = null;

    function yieldToBrowser() {
        return new Promise(function (resolve) { window.setTimeout(resolve, 0); });
    }

    function installPicker() {
        const row = folderInput.closest('p');
        if (!row) return;

        row.textContent = '';
        const heading = document.createElement('strong');
        heading.textContent = 'Choose folder / subfolders';
        row.appendChild(heading);
        row.appendChild(document.createElement('br'));

        folderButton = document.createElement('button');
        folderButton.type = 'button';
        folderButton.className = 'secondary';
        folderButton.id = 'profiled-upload-folder-button';
        folderButton.textContent = 'Choose folder';
        row.appendChild(folderButton);

        folderSummary = document.createElement('span');
        folderSummary.id = 'profiled-upload-folder-summary';
        folderSummary.className = 'muted';
        folderSummary.style.marginLeft = '8px';
        folderSummary.textContent = 'No folder selected';
        row.appendChild(folderSummary);

        stopButton = document.createElement('button');
        stopButton.type = 'button';
        stopButton.className = 'secondary';
        stopButton.style.marginLeft = '8px';
        stopButton.textContent = 'Stop discovery';
        stopButton.hidden = true;
        stopButton.addEventListener('click', function () {
            stopRequested = true;
            stopButton.disabled = true;
            currentLabel.textContent = 'Stopping folder discovery...';
        });
        row.appendChild(stopButton);

        const fallback = document.createElement('details');
        fallback.style.margin = '6px 0 12px';
        const summary = document.createElement('summary');
        summary.textContent = 'Fallback folder selector for browsers without direct folder access';
        fallback.appendChild(summary);
        const line = document.createElement('p');
        line.appendChild(folderInput);
        fallback.appendChild(line);
        const note = document.createElement('p');
        note.className = 'muted';
        note.textContent = 'Chrome users should use the button above. The fallback browser control may pause while Chrome constructs a very large FileList.';
        fallback.appendChild(note);
        row.insertAdjacentElement('afterend', fallback);

        folderInput.addEventListener('change', function () {
            const count = Number(folderInput.files ? folderInput.files.length : 0);
            folderSummary.textContent = count ? count.toLocaleString() + ' fallback folder files' : 'No folder selected';
        });

        folderButton.addEventListener('click', chooseDirectory);
    }

    async function* walkDirectory(directoryHandle, prefix) {
        for await (const entry of directoryHandle.values()) {
            if (stopRequested) {
                const error = new Error('Folder discovery stopped.');
                error.name = 'AbortError';
                throw error;
            }
            const relativePath = prefix ? prefix + '/' + entry.name : entry.name;
            if (entry.kind === 'file') {
                yield {handle: entry, relativePath: relativePath};
            } else if (entry.kind === 'directory') {
                yield* walkDirectory(entry, relativePath);
            }
        }
    }

    function attachRelativePath(file, relativePath) {
        try {
            Object.defineProperty(file, 'webkitRelativePath', {
                value: relativePath,
                configurable: true
            });
        } catch (error) {
            // Chrome normally allows an own property to shadow the readonly prototype value.
        }
        return file;
    }

    async function chooseDirectory() {
        if (scanActive) return;
        if (typeof window.showDirectoryPicker !== 'function' || typeof window.DataTransfer !== 'function') {
            folderInput.click();
            return;
        }

        scanActive = true;
        stopRequested = false;
        folderButton.disabled = true;
        stopButton.hidden = false;
        stopButton.disabled = false;
        progress.hidden = false;
        overallBar.removeAttribute('value');
        currentBar.removeAttribute('value');
        overallLabel.textContent = 'Discovering folder';
        overallCount.textContent = '0 files found';
        currentLabel.textContent = 'Choose a folder in the browser prompt.';

        try {
            const rootHandle = await window.showDirectoryPicker({mode: 'read'});
            const transfer = new DataTransfer();
            const relativePaths = [];
            let count = 0;
            for await (const entry of walkDirectory(rootHandle, rootHandle.name)) {
                const file = attachRelativePath(await entry.handle.getFile(), entry.relativePath);
                transfer.items.add(file);
                relativePaths.push(entry.relativePath);
                count++;
                if (count % 100 === 0) {
                    overallCount.textContent = count.toLocaleString() + ' files found';
                    currentLabel.textContent = 'Scanning ' + rootHandle.name + ': ' + count.toLocaleString() + ' files found.';
                    await yieldToBrowser();
                }
            }

            folderInput.files = transfer.files;
            // Re-apply relative paths to the FileList objects in case Chrome cloned a File.
            for (let index = 0; index < folderInput.files.length && index < relativePaths.length; index++) {
                attachRelativePath(folderInput.files[index], relativePaths[index]);
            }

            overallBar.value = 0;
            currentBar.value = 0;
            overallLabel.textContent = 'Folder ready';
            overallCount.textContent = count.toLocaleString() + ' files selected';
            currentLabel.textContent = 'Folder discovery complete. Press Upload and queue to start.';
            folderSummary.textContent = rootHandle.name + ' · ' + count.toLocaleString() + ' files';
        } catch (error) {
            overallBar.value = 0;
            currentBar.value = 0;
            if (stopRequested) {
                overallLabel.textContent = 'Folder discovery stopped';
                overallCount.textContent = '';
                currentLabel.textContent = 'Folder discovery was stopped.';
            } else if (error && error.name === 'AbortError') {
                overallLabel.textContent = 'Folder selection cancelled';
                overallCount.textContent = '';
                currentLabel.textContent = 'No folder was selected.';
            } else {
                overallLabel.textContent = 'Folder discovery failed';
                overallCount.textContent = '';
                currentLabel.textContent = error && error.message ? error.message : 'The folder could not be read.';
            }
        } finally {
            scanActive = false;
            stopRequested = false;
            folderButton.disabled = false;
            stopButton.hidden = true;
            stopButton.disabled = false;
        }
    }

    function loadCore() {
        const script = document.createElement('script');
        script.src = 'assets/profiled-upload-jobs-core.js?v=20260826-1';
        script.async = false;
        script.onerror = function () {
            if (currentLabel) currentLabel.textContent = 'The profiled upload client could not be loaded.';
        };
        document.head.appendChild(script);
    }

    installPicker();
    loadCore();
}());
