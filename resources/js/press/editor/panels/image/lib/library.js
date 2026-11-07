/**
 * Asset library helpers shared by the image, video and audio panels. No DOM except where a function says so.
 *
 *   listParams({kind, q, cursor, projectId, limit})   query for GET /api/assets (empty values are left out)
 *   apiProblem(err)                                   {kind: 'forbidden'|'limited'|'offline'|'server', message, retryAfter}
 *                                                     in plain language for the 403, 429, network and other failures
 *   assetKind(file)                                   'image'|'video'|'audio'|null from a File (mime, then extension)
 *   uploadProblem(file, kind)                         a sentence when the file is the wrong kind, else null
 *   humanSize(bytes)                                  '2.4 MB'
 *   registerAsset(editor, asset)                      puts the asset (and its poster) in the editor's asset map, returns the id
 */
import { toAssetEntry } from '../../../stage/lib/drop.js';

export const PAGE = 24;

export function listParams({ kind, q, cursor, projectId, limit = PAGE } = {}) {
  const p = { limit };
  if (kind) p.kind = kind;
  const term = String(q ?? '').trim().slice(0, 100);
  if (term) p.q = term;
  if (cursor) p.cursor = cursor;
  if (projectId != null && projectId !== '') p.project_id = projectId;
  return p;
}

export function apiProblem(err) {
  const status = err?.status ?? err?.response?.status;
  const retryAfter = Number(err?.retryAfter) || null;
  if (status === 403) return { kind: 'forbidden', message: 'You can view this deck but not use the asset library here.', retryAfter: null };
  if (status === 429) return { kind: 'limited', message: `Too many requests. Wait ${retryAfter ? `${retryAfter} seconds` : 'a moment'} and try again.`, retryAfter };
  if (err?.network || (!status && err?.message)) return { kind: 'offline', message: 'The library could not be reached. Check your connection.', retryAfter: null };
  if (status === 404) return { kind: 'server', message: 'That file is no longer in the library.', retryAfter: null };
  if (status === 413) return { kind: 'server', message: 'That file is too large for this server.', retryAfter: null };
  if (status === 422) return { kind: 'server', message: String(err?.message || 'That file was not accepted.'), retryAfter: null };
  return { kind: 'server', message: String(err?.message || 'Something went wrong. Try again.'), retryAfter: null };
}

const EXT = {
  image: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'bmp'],
  video: ['mp4', 'webm', 'mov', 'm4v', 'ogv'],
  audio: ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'oga'],
};

export function assetKind(file) {
  if (!file) return null;
  const mime = String(file.type || '').toLowerCase();
  for (const k of Object.keys(EXT)) if (mime.startsWith(`${k}/`)) return k;
  const ext = String(file.name || '').split('.').pop().toLowerCase();
  for (const k of Object.keys(EXT)) if (EXT[k].includes(ext)) return k;
  return null;
}

export function uploadProblem(file, kind) {
  if (!file) return 'Choose a file first.';
  if (file.size === 0) return 'That file is empty.';
  const got = assetKind(file);
  if (got !== kind) return `That is not ${kind === 'image' ? 'a picture' : kind === 'audio' ? 'an audio file' : 'a video file'}.`;
  return null;
}

export function humanSize(bytes) {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 0) return '';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / 1048576).toFixed(n < 10 * 1048576 ? 1 : 0)} MB`;
}

/** Same rule the stage uses for uploads: editor.registerAsset when the shell has one, else write the map. */
export function registerAsset(editor, asset) {
  if (!asset || asset.id == null) return null;
  const origin = typeof location !== 'undefined' ? location.origin : null;
  const put = (a) => {
    const entry = toAssetEntry(a, origin);
    if (typeof editor.registerAsset === 'function') editor.registerAsset(a.id, entry);
    else if (editor.assets && typeof editor.assets === 'object') editor.assets[a.id] = entry;
  };
  put(asset);
  if (asset.poster && asset.poster.id != null) put({ ...asset.poster, kind: 'poster', name: `${asset.name || 'Video'} poster` });
  return asset.id;
}
