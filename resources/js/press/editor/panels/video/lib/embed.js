/**
 * Video sources for the video panel. Pure.
 *
 *   parseEmbed(input)      {provider: 'youtube'|'vimeo', id, start} for a YouTube or Vimeo address, else null
 *   classifyVideoUrl(s)    {kind: 'embed', embed: {provider, id}, start}
 *                          | {kind: 'file', url}     an https link to a video file (mp4, webm, ogv, mov, m4v)
 *                          | {kind: 'error', message}   plain language
 *   parseStart(s)          seconds from '90', '1m30s', '1:30', '1h2m3s'; 0 when absent or unreadable
 *   formatStart(sec)       '1:30' for the start time readout
 *   captureProblem(...)    why a poster cannot be captured, or null
 *
 * Accepted YouTube forms (host youtube.com, www., m., music., youtube-nocookie.com, youtu.be): watch?v=ID, /embed/ID, /shorts/ID,
 * /live/ID, /v/ID, youtu.be/ID, with any extra query. Vimeo (vimeo.com, www., player.vimeo.com): /ID, /channels/x/ID,
 * /groups/x/videos/ID, /album/n/video/ID, /video/ID, /ID/hash. Only https (or no scheme) is read; http, javascript, data, ftp and
 * look alike hosts (youtube.com.evil.test, evilyoutube.com, user@host tricks) are rejected.
 */
const YT_ID = /^[A-Za-z0-9_-]{11}$/;
const VIMEO_ID = /^\d{4,12}$/;
const YT_HOSTS = new Set(['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com']);
const VIMEO_HOSTS = new Set(['vimeo.com', 'www.vimeo.com', 'player.vimeo.com']);
const FILE_EXT = /\.(mp4|webm|ogv|mov|m4v)$/i;

export function parseStart(s) {
  if (s == null) return 0;
  const t = String(s).trim().toLowerCase();
  if (!t) return 0;
  if (/^\d+$/.test(t)) return Math.min(86400, parseInt(t, 10));
  if (/^\d{1,2}(:\d{1,2}){1,2}$/.test(t)) {
    const parts = t.split(':').map(Number);
    return Math.min(86400, parts.reduce((a, n) => a * 60 + n, 0));
  }
  const m = /^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/.exec(t);
  if (m && (m[1] || m[2] || m[3])) return Math.min(86400, (+m[1] || 0) * 3600 + (+m[2] || 0) * 60 + (+m[3] || 0));
  return 0;
}

export function formatStart(sec) {
  const n = Math.max(0, Math.floor(+sec || 0));
  const h = Math.floor(n / 3600);
  const m = Math.floor((n % 3600) / 60);
  const s = n % 60;
  const pad = (v) => String(v).padStart(2, '0');
  return h ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

function toUrl(input) {
  if (typeof input !== 'string') return null;
  let t = input.trim();
  if (!t || t.length > 2048 || /[\u0000-\u001f\u007f\s<>"'`\\]/.test(t)) return null;
  if (/^[a-z][a-z0-9+.-]*:/i.test(t) && !/^https:\/\//i.test(t)) {
    // "youtube.com:8080/x" has a colon too, but a scheme is letters followed by two slashes or a known word
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(t) || /^(javascript|data|vbscript|file|blob|mailto):/i.test(t)) return null;
  }
  if (!/^https:\/\//i.test(t)) t = `https://${t}`;
  try {
    const u = new URL(t);
    if (u.protocol !== 'https:' || u.username || u.password || u.port) return null;
    return u;
  } catch {
    return null;
  }
}

export function parseEmbed(input) {
  const u = toUrl(input);
  if (!u) return null;
  const host = u.hostname.toLowerCase();
  const seg = u.pathname.split('/').filter(Boolean);
  const startOf = () => parseStart(u.searchParams.get('t') ?? u.searchParams.get('start') ?? (u.hash.startsWith('#t=') ? u.hash.slice(3) : ''));
  if (host === 'youtu.be') {
    const id = seg[0] || '';
    return YT_ID.test(id) && seg.length === 1 ? { provider: 'youtube', id, start: startOf() } : null;
  }
  if (YT_HOSTS.has(host)) {
    let id = '';
    if (seg[0] === 'watch' && seg.length === 1) id = u.searchParams.get('v') || '';
    else if (['embed', 'shorts', 'live', 'v'].includes(seg[0]) && seg.length === 2) id = seg[1];
    return YT_ID.test(id) ? { provider: 'youtube', id, start: startOf() } : null;
  }
  if (VIMEO_HOSTS.has(host)) {
    let id = '';
    if (host === 'player.vimeo.com') {
      if (seg[0] === 'video' && seg.length === 2) id = seg[1];
    } else if (seg.length === 1 || (seg.length === 2 && /^[0-9a-f]{6,}$/i.test(seg[1]))) {
      id = seg[0];
    } else if (seg[0] === 'channels' && seg.length === 3) id = seg[2];
    else if (seg[0] === 'groups' && seg[2] === 'videos' && seg.length === 4) id = seg[3];
    else if (seg[0] === 'album' && seg[2] === 'video' && seg.length === 4) id = seg[3];
    return VIMEO_ID.test(id) ? { provider: 'vimeo', id, start: startOf() } : null;
  }
  return null;
}

export function classifyVideoUrl(input) {
  const raw = typeof input === 'string' ? input.trim() : '';
  if (!raw) return { kind: 'error', message: 'Paste a YouTube or Vimeo address, or a link to a video file.' };
  if (/^http:\/\//i.test(raw)) return { kind: 'error', message: 'Only secure https addresses can be used.' };
  const e = parseEmbed(raw);
  if (e) return { kind: 'embed', embed: { provider: e.provider, id: e.id }, start: e.start };
  const u = toUrl(raw);
  if (!u) return { kind: 'error', message: 'That is not an address this can use. Use an https link.' };
  if (FILE_EXT.test(u.pathname)) return { kind: 'file', url: u.href };
  return { kind: 'error', message: 'Only YouTube, Vimeo or a direct link to a video file (mp4, webm, mov) can be used. A web page link will not play.' };
}

export function captureProblem({ hasAssetSource, ready, videoWidth }) {
  if (!hasAssetSource) return 'Only an uploaded video can give a frame.';
  if (!ready || !videoWidth) return 'Wait for the video to load, then pause on the frame you want.';
  return null;
}
