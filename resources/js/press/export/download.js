// Saving files from the browser. The server routes answer with JSON when something is wrong (not ready, expired,
// bundle missing), so a download goes through fetch first: an error becomes a plain message instead of a page of JSON.
//
//   filenameFromDisposition(header, fallback)
//   formatBytes(n)
//   downloadUrl(url, {fetchFn, fallbackName})  -> {name, size, warnings}  (throws an Error with .status and a plain message)
//   saveBlob(blob, name)

export function filenameFromDisposition(header, fallback = 'download') {
    const h = String(header || '');
    const star = /filename\*\s*=\s*(?:UTF-8|utf-8)''([^;]+)/.exec(h);
    if (star) {
        try { return decodeURIComponent(star[1].trim()); } catch { /* fall through */ }
    }
    const quoted = /filename\s*=\s*"((?:[^"\\]|\\.)*)"/.exec(h);
    if (quoted) return quoted[1].replace(/\\(.)/g, '$1');
    const bare = /filename\s*=\s*([^;]+)/.exec(h);
    return bare ? bare[1].trim() : fallback;
}

export function formatBytes(n) {
    const v = Number(n) || 0;
    if (v < 1024) return `${v} B`;
    if (v < 1024 * 1024) return `${(v / 1024).toFixed(v < 10 * 1024 ? 1 : 0)} KB`;
    return `${(v / 1048576).toFixed(v < 10 * 1048576 ? 1 : 0)} MB`;
}

export function saveBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
}

export async function downloadUrl(url, { fetchFn = (...a) => fetch(...a), fallbackName = 'download' } = {}) {
    let res;
    try {
        res = await fetchFn(url, { credentials: 'same-origin', headers: { Accept: '*/*', 'X-Requested-With': 'XMLHttpRequest' } });
    } catch {
        const e = new Error('The network is not reachable. Check your connection and try again.');
        e.network = true;
        throw e;
    }
    if (!res.ok) {
        let message = '';
        try { message = (await res.json())?.message || ''; } catch { /* not json */ }
        const e = new Error(message || `The server answered ${res.status}.`);
        e.status = res.status;
        throw e;
    }
    const name = filenameFromDisposition(res.headers.get('content-disposition'), fallbackName);
    const blob = await res.blob();
    const warnings = Number(res.headers.get('x-press-warnings')) || 0;
    saveBlob(blob, name);
    return { name, size: blob.size, warnings };
}
