(function () {
    'use strict';

    const info = document.querySelector('meta[name="catalog-admin-tls-expiry"]');
    if (!info) return;

    const days = Number(info.dataset.days);
    const expires = String(info.dataset.expires || '');
    const fingerprint = String(info.dataset.cert || '');
    const adminId = String(info.dataset.admin || '');
    if (!Number.isInteger(days) || !expires || !/^[a-f0-9]{64}$/.test(fingerprint)
        || !/^\d+$/.test(adminId) || adminId === '0') return;

    // Acknowledgement is scoped to one admin, certificate and local day.
    // Warn again on a later date if renewal is still outstanding.
    const today = new Date().toLocaleDateString('en-CA');
    const storageKey = 'unrealdb.tlsExpiry.dismissed.' + adminId + '.' + fingerprint;
    try {
        if (window.localStorage.getItem(storageKey) === today) return;
    } catch (e) {
        // Storage may be disabled; the warning must still be available.
    }

    const style = document.createElement('style');
    style.textContent = [
        '.catalog-tls-notice-overlay{position:fixed;inset:0;z-index:20000;background:rgba(4,9,20,.78);display:flex;align-items:center;justify-content:center;padding:18px}',
        '.catalog-tls-notice{width:min(560px,100%);padding:24px;border:1px solid var(--line2,#40516b);border-radius:14px;background:var(--panel,#111b2d);box-shadow:0 24px 70px rgba(0,0,0,.55);color:var(--text,#fff)}',
        '.catalog-tls-notice h2{margin:0 0 12px;font-size:1.3rem}',
        '.catalog-tls-notice p{margin:0 0 14px;line-height:1.5}',
        '.catalog-tls-notice-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}',
        '.catalog-tls-notice-actions button{min-height:38px}'
    ].join('\n');
    document.head.appendChild(style);

    const overlay = document.createElement('div');
    overlay.className = 'catalog-tls-notice-overlay';
    const dialog = document.createElement('section');
    dialog.className = 'catalog-tls-notice';
    dialog.setAttribute('role', 'alertdialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'catalog-tls-notice-title');
    dialog.setAttribute('aria-describedby', 'catalog-tls-notice-description');

    const heading = document.createElement('h2');
    heading.id = 'catalog-tls-notice-title';
    heading.textContent = days < 0 ? 'UnrealDB HTTPS certificate has expired'
        : 'UnrealDB HTTPS certificate expires soon';

    const message = document.createElement('p');
    message.id = 'catalog-tls-notice-description';
    message.textContent = days < 0
        ? 'The active unrealdb.com TLS certificate expired on ' + expires + '. Renew it immediately to restore valid HTTPS.'
        : 'The active unrealdb.com TLS certificate expires on ' + expires
            + ' (' + (days === 0 ? 'less than 24 hours' : days + (days === 1 ? ' day' : ' days')) + ' remaining).'
            + ' Automatic renewal is configured, but check that it succeeds before expiry.';

    const actions = document.createElement('div');
    actions.className = 'catalog-tls-notice-actions';
    const acknowledge = document.createElement('button');
    acknowledge.type = 'button';
    acknowledge.className = 'secondary';
    acknowledge.textContent = 'Remind me tomorrow';
    acknowledge.addEventListener('click', function () {
        try { window.localStorage.setItem(storageKey, today); } catch (e) {}
        overlay.remove();
    });
    actions.appendChild(acknowledge);
    dialog.appendChild(heading);
    dialog.appendChild(message);
    dialog.appendChild(actions);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
    acknowledge.focus();
}());
