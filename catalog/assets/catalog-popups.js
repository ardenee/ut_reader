function catalogPopup(url, name, width, height) {
    const w = width || 980;
    const h = height || 760;
    const left = Math.max(0, Math.floor((window.screen.width - w) / 2));
    const top = Math.max(0, Math.floor((window.screen.height - h) / 2));
    const features = 'popup=yes,width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes,noopener=yes';
    const win = window.open(url, name || 'catalogPopup', features);
    if (win) {
        win.focus();
    }
    return false;
}
