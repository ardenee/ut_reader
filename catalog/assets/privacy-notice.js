(function () {
    'use strict';
    const NAME='UEDB_PRIVACY_CHOICE';
    const MAX_AGE=15552000; // 180 days
    function current() {
        const match=document.cookie.match(/(?:^|;\s*)UEDB_PRIVACY_CHOICE=(analytics|essential)(?:;|$)/);
        return match?match[1]:null;
    }
    function write(value) {
        document.cookie=NAME+'='+value+'; Path=/catalog/; Max-Age='+MAX_AGE
            +'; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');
        window.dispatchEvent(new CustomEvent('unrealdb:privacy-choice',{detail:{analytics:value==='analytics'}}));
    }
    function show() {
        const existing=document.getElementById('uedb-cookie-notice');
        if(existing){existing.hidden=false;return;}
        const panel=document.createElement('aside');
        panel.id='uedb-cookie-notice';
        panel.setAttribute('role','dialog');
        panel.setAttribute('aria-label','Privacy and cookie choices');
        panel.style.cssText='position:fixed;bottom:16px;right:16px;left:16px;max-width:620px;margin:auto;z-index:99990;background:#172431;color:#f5f8fb;box-shadow:0 12px 44px #0009;border:1px solid #63718a;border-radius:10px;padding:18px;font:14px/1.5 system-ui,sans-serif';
        panel.innerHTML='<strong style="display:block;font-size:17px;margin-bottom:6px">Privacy and cookies</strong>'
           +'<p style="margin:0 0 12px">UnrealDB is view-only for visitors and does not require an account. Essential cookies support normal browsing and protection against automated scraping; security access records are also processed. Optional site-improvement analytics are disabled unless you agree. We do not sell personal data or share it for advertising. CrowdSec may receive attacking IP addresses and security alert details for community threat protection. <a style="color:#8ed0ff" href="/catalog/privacy.html">Read our privacy notice</a>.</p>'
           +'<div style="display:flex;gap:10px;flex-wrap:wrap">'
           +'<button type="button" data-uedb-consent="essential" style="padding:10px 14px;cursor:pointer">Necessary only</button>'
           +'<button type="button" data-uedb-consent="analytics" style="padding:10px 14px;cursor:pointer">Allow site-improvement analytics</button>'
           +'</div>';
        document.body.appendChild(panel);
        panel.querySelectorAll('[data-uedb-consent]').forEach(button=>button.addEventListener('click',()=>{
            write(button.dataset.uedbConsent);
            panel.hidden=true;
        }));
    }
    function privacyButton() {
        if(document.getElementById('uedb-privacy-settings'))return;
        const control=document.createElement('button');
        control.type='button';
        control.id='uedb-privacy-settings';
        control.textContent='Privacy settings';
        control.style.cssText='position:fixed;bottom:0;left:0;z-index:99989;padding:5px 10px;background:#1b2937;color:#d1e2ed;border:1px solid #4b5e70;border-radius:0 7px 0 0;cursor:pointer;font:11px system-ui,sans-serif';
        control.addEventListener('click',show);
        document.body.appendChild(control);
    }
    function init() {
        privacyButton();
        if(!current())show();
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
    else init();
})();
