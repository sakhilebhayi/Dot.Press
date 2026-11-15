// The shared header's account menu has Light / Dark / System buttons (dot-ui, js/dot-nav.js). They set data-theme on <html> and
// announce `dot:theme`. Press keeps its own saved choice (`press:theme`, read before first paint by the page shell), so this
// bridge copies the header's choice into it: Light and Dark are saved, System forgets the choice and follows the device.
export function installThemeBridge(doc = document, storage = null) {
    const store = storage || (() => { try { return window.localStorage; } catch { return null; } })();
    const onTheme = () => {
        const root = doc.documentElement;
        const mode = root.getAttribute('data-theme');
        const saved = mode === 'light' || mode === 'dark' ? mode : null;
        try {
            const s = typeof store === 'function' ? store() : store;
            if (saved) s?.setItem('press:theme', saved); else s?.removeItem('press:theme');
        } catch { /* storage can be blocked: the choice lasts for this page */ }
        // the page shell set an inline color-scheme before first paint; keep it in step with the choice
        root.style.colorScheme = saved || '';
    };
    doc.addEventListener('dot:theme', onTheme);
    return () => doc.removeEventListener('dot:theme', onTheme);
}
