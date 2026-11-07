// Media helpers: URL validation, asset lookup and the embed facades. Pure, no DOM.

/** https only; returns the normalised URL or null. */
export function safeUrl(u) {
    if (typeof u !== 'string' || u.length > 2048 || /[\u0000-\u001f\u007f\s<>"'`\\]/.test(u)) return null;
    try {
        const url = new URL(u);
        return url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}

/** A same origin or signed asset URL handed to us in the assets map is trusted only if it is http(s), relative or a data: image/media URI. */
export function safeAssetUrl(u) {
    if (typeof u !== 'string' || u.length > 8 * 1024 * 1024) return null;
    if (/^data:(image|video|audio)\/[a-z0-9.+-]+;base64,[a-z0-9+/=]+$/i.test(u)) return u;
    if (/^data:/i.test(u)) return null;
    if (/[\u0000-\u001f\u007f\s<>"'`\\]/.test(u)) return null;
    if (u.startsWith('/') && !u.startsWith('//')) return u;
    return safeUrl(u);
}

/**
 * Resolve an element `src` ({asset} or {url}) against the assets map.
 * @returns {{ url: string, thumb: string, w: number|null, h: number|null, mime: string|null, name: string|null }|null}
 */
export function resolveSrc(src, assets) {
    if (!src || typeof src !== 'object') return null;
    if (src.asset != null) {
        const a = assets && assets[src.asset];
        if (!a) return null;
        const url = safeAssetUrl(a.url);
        if (!url) return null;
        return { url, thumb: safeAssetUrl(a.thumb) || url, w: +a.w || null, h: +a.h || null, mime: a.mime || null, name: a.name || null };
    }
    if (src.url) {
        const url = safeUrl(src.url);
        return url ? { url, thumb: url, w: null, h: null, mime: null, name: null } : null;
    }
    return null;
}

const YT = /^[A-Za-z0-9_-]{6,20}$/;
const VIMEO = /^\d{4,12}$/;

/**
 * Embed description for the click-to-load facade. The iframe URL uses the privacy friendly hosts and is only
 * ever turned into an iframe on a user click or when presenting with embeds allowed.
 */
export function embedInfo(embed, start = 0) {
    if (!embed || typeof embed !== 'object') return null;
    const id = String(embed.id ?? '');
    const t = Math.max(0, Math.floor(+start || 0));
    if (embed.provider === 'youtube' && YT.test(id)) {
        return {
            provider: 'youtube',
            label: 'YouTube',
            host: 'youtube.com',
            id,
            page: `https://www.youtube.com/watch?v=${id}${t ? `&t=${t}s` : ''}`,
            src: `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&playsinline=1${t ? `&start=${t}` : ''}`,
        };
    }
    if (embed.provider === 'vimeo' && VIMEO.test(id)) {
        return {
            provider: 'vimeo',
            label: 'Vimeo',
            host: 'vimeo.com',
            id,
            page: `https://vimeo.com/${id}`,
            src: `https://player.vimeo.com/video/${id}?autoplay=1&dnt=1${t ? `#t=${t}s` : ''}`,
        };
    }
    return null;
}

/** All asset ids a slide uses (images, video, poster, audio, bg), for preloading and the offline package. */
export function slideAssetIds(slide) {
    const ids = new Set();
    const add = (src) => {
        if (src && typeof src === 'object' && src.asset != null) ids.add(String(src.asset));
    };
    for (const el of slide?.els || []) {
        if (!el || typeof el !== 'object') continue;
        add(el.src);
        add(el.poster);
    }
    const bg = slide?.bg;
    if (bg && typeof bg === 'object' && bg.img) add(bg.img);
    return [...ids];
}
