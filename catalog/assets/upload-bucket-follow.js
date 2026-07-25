(function () {
    'use strict';

    const progress = document.getElementById('bucket-progress');
    const label = document.getElementById('bucket-progress-label');
    if (!progress || !label) return;

    const queueUrl = progress.dataset.processingUrl || 'background-jobs.php?queue=catalog%3Abucket-processing';
    let handled = false;
    let timer = 0;

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
            window.clearInterval(timer);
            countdown.textContent = 'Automatic handoff cancelled. Processing continues in the background.';
            stay.disabled = true;
        });

        timer = window.setInterval(function () {
            seconds--;
            if (seconds <= 0) {
                window.clearInterval(timer);
                countdown.textContent = 'Opening the processing queue…';
                window.location.assign(queueUrl);
                return;
            }
            countdown.textContent = 'Opening the processing queue in ' + seconds + ' second' + (seconds === 1 ? '' : 's') + '…';
        }, 1000);
    }

    function inspect() {
        const text = String(label.textContent || '');
        if (/^Batch ready:/i.test(text)) {
            showHandoff();
            return;
        }
        if (/Previously queued Upload Bucket processing was resumed\./i.test(text)) showHandoff();
    }

    new MutationObserver(inspect).observe(label, {childList: true, characterData: true, subtree: true});
    inspect();
}());
