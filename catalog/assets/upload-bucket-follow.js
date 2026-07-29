(function () {
    'use strict';

    const form = document.getElementById('upload-bucket-form');
    const progress = document.getElementById('bucket-progress');
    const label = document.getElementById('bucket-progress-label');
    const currentBar = document.getElementById('bucket-progress-bar');
    const currentSpeed = document.getElementById('bucket-progress-speed');
    const overallBar = document.getElementById('bucket-overall-progress-bar');
    const overallLabel = document.getElementById('bucket-overall-progress-label');
    const overallCount = document.getElementById('bucket-overall-progress-count');
    if (!progress || !label || !currentBar || !currentSpeed || !overallBar || !overallLabel || !overallCount) return;

    const queueUrl = progress.dataset.processingUrl || 'background-jobs.php?queue=catalog%3Abucket-processing';
    let handled = false;
    let handoffTimer = 0;
    let phaseTimer = 0;
    let phaseStarted = 0;
    let activePhase = '';
    let phaseDetail = '';

    function elapsedText() {
        if (!phaseStarted) return '0s';
        const seconds = Math.max(0, Math.floor((Date.now() - phaseStarted) / 1000));
        if (seconds < 60) return seconds + 's';
        const minutes = Math.floor(seconds / 60);
        const remainder = seconds % 60;
        return minutes + 'm ' + String(remainder).padStart(2, '0') + 's';
    }

    function setIndeterminate(bar) {
        bar.removeAttribute('value');
    }

    function stopPhaseTimer() {
        if (phaseTimer) window.clearInterval(phaseTimer);
        phaseTimer = 0;
    }

    function renderTimedPhase() {
        const elapsed = elapsedText();
        if (activePhase === 'prepare') {
            overallLabel.textContent = 'Phase 1 of 3 — Prepare durable staging and pause processing';
            overallCount.textContent = phaseDetail + ' · ' + elapsed + ' elapsed · no selected-file bytes transferred yet';
            currentSpeed.textContent = elapsed + ' elapsed';
            setIndeterminate(overallBar);
            setIndeterminate(currentBar);
        } else if (activePhase === 'finalize') {
            overallLabel.textContent = 'Phase 3 of 3 — Finalise batch and create processing jobs';
            overallCount.textContent = phaseDetail + ' · ' + elapsed + ' elapsed';
            currentSpeed.textContent = elapsed + ' elapsed';
            setIndeterminate(overallBar);
            setIndeterminate(currentBar);
        }
    }

    function beginTimedPhase(name, detail) {
        if (activePhase !== name) {
            stopPhaseTimer();
            activePhase = name;
            phaseStarted = Date.now();
            phaseTimer = window.setInterval(renderTimedPhase, 1000);
        }
        phaseDetail = detail;
        renderTimedPhase();
    }

    function beginTransferPhase() {
        if (activePhase === 'transfer') {
            overallLabel.textContent = 'Phase 2 of 3 — Check identities and upload files';
            return;
        }
        stopPhaseTimer();
        activePhase = 'transfer';
        phaseStarted = 0;
        phaseDetail = '';
        currentSpeed.textContent = '';
        if (!currentBar.hasAttribute('value')) currentBar.value = 0;
        if (!overallBar.hasAttribute('value')) overallBar.value = 0;
        overallLabel.textContent = 'Phase 2 of 3 — Check identities and upload files';
    }

    function finishPhases(success) {
        stopPhaseTimer();
        activePhase = 'complete';
        phaseStarted = 0;
        phaseDetail = '';
        currentSpeed.textContent = '';
        if (!currentBar.hasAttribute('value')) currentBar.value = success ? 100 : 0;
        if (!overallBar.hasAttribute('value')) overallBar.value = success ? 100 : 0;
        if (success) overallLabel.textContent = 'Phase 3 of 3 complete — processing jobs created';
    }

    function showHandoff() {
        if (handled) return;
        handled = true;

        const panel = document.createElement('div');
        panel.className = 'bucket-next-phase';
        panel.innerHTML = ''
            + '<strong>Upload phase complete.</strong> '
            + '<span data-countdown>Opening the processing queue in 3 seconds…</span> '
            + '<a class="button" data-open href="' + queueUrl.replace(/"/g, '&quot;') + '">Open processing queue now</a> '
            + '<button type="button" class="button secondary" data-stay>Stay on this page</button>';
        progress.appendChild(panel);

        const countdown = panel.querySelector('[data-countdown]');
        const stay = panel.querySelector('[data-stay]');
        let seconds = 3;

        stay.addEventListener('click', function () {
            window.clearInterval(handoffTimer);
            countdown.textContent = 'Automatic handoff cancelled. Processing continues in the background.';
            stay.disabled = true;
        });

        handoffTimer = window.setInterval(function () {
            seconds--;
            if (seconds <= 0) {
                window.clearInterval(handoffTimer);
                countdown.textContent = 'Opening the processing queue…';
                window.location.assign(queueUrl);
                return;
            }
            countdown.textContent = 'Opening the processing queue in ' + seconds + ' second' + (seconds === 1 ? '' : 's') + '…';
        }, 1000);
    }

    function inspect() {
        const text = String(label.textContent || '').trim();

        if (/^Preparing durable upload staging/i.test(text)) {
            beginTimedPhase(
                'prepare',
                'Scanning stale incomplete chunk staging, checking both Upload Bucket workers and requesting a cooperative pause'
            );
            return;
        }
        if (/^Waiting for the current Upload Bucket job/i.test(text)) {
            beginTimedPhase(
                'prepare',
                'The active Upload Bucket job is finishing normally; the worker will pause before this batch starts transferring'
            );
            return;
        }
        if (/^Upload Bucket processing paused\. Starting file checks/i.test(text)
            || /^(?:Calculating MD5|Checking physical duplicates|Preparing upload|Uploading chunk|Resuming chunk|Verifying transferred file)/i.test(text)) {
            beginTransferPhase();
            return;
        }
        if (/^(?:All required files transferred|No files transferred)/i.test(text)) {
            beginTimedPhase(
                'finalize',
                'Rechecking duplicates, consolidating pending work and creating the complete processing queue'
            );
            return;
        }
        if (/^Batch ready:/i.test(text)) {
            finishPhases(true);
            showHandoff();
            return;
        }
        if (/Previously queued Upload Bucket processing was resumed\./i.test(text)) {
            finishPhases(true);
            showHandoff();
            return;
        }
        if (/^(?:Upload batch could not start|All required files were transferred, but batch finalisation failed|No files required transfer, but)/i.test(text)) {
            finishPhases(false);
        }
    }

    if (form) {
        form.addEventListener('submit', function () {
            handled = false;
            const existing = progress.querySelector('.bucket-next-phase');
            if (existing) existing.remove();
            beginTimedPhase(
                'prepare',
                'Starting the server-side staging check and cooperative worker pause'
            );
        });
    }

    new MutationObserver(inspect).observe(label, {childList: true, characterData: true, subtree: true});
    inspect();
}());
