/**
 * Dropping and pasting files. Pure.
 *
 *   classifyFile(file)                    'image' | 'video' | 'audio' | null (by mime, then by extension)
 *   fileProblem(file)                     a plain sentence when the file cannot be used here, else null
 *   fitInside(nat, maxW, maxH)            {w, h} keeping the ratio
 *   placeMedia(deck, kind, nat, pt?)      {x, y, w, h} for a new image, video or audio element
 *   replaceTarget(slide, pt, kind, pad?)  id of the picture (or video) under the point that a file should replace
 *   replacePatch(el, srcRef)              patch that swaps the source and keeps frame, crop, mask and adjustments
 *   assetToElementProps(kind, asset)      {src, natural}
 *   uploadMessage(err)                    a sentence for a failed upload (HTTP 422 text, 413, network)
 *   toAssetEntry(asset, origin?)          the `editor.assets` map entry for an uploaded asset payload (same origin URLs made relative)
 *   relativeIfSameOrigin(url, origin)
 *   nameOf(file)                          a safe display name
 */
import { hitOrder } from './hit.js';
import { round2 } from '../../core/util.js';

const EXT = {
  image: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'bmp'],
  video: ['mp4', 'webm', 'mov', 'm4v', 'ogv'],
  audio: ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'oga'],
};

export function classifyFile(file) {
  if (!file) return null;
  const mime = String(file.type || '').toLowerCase();
  if (mime.startsWith('image/')) return 'image';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  const ext = String(file.name || '').split('.').pop().toLowerCase();
  for (const k of Object.keys(EXT)) if (EXT[k].includes(ext)) return k;
  return null;
}

export function fileProblem(file) {
  if (!file) return 'Nothing to add.';
  if (!classifyFile(file)) return `${nameOf(file)} is not a picture, video or audio file.`;
  if (file.size === 0) return `${nameOf(file)} is empty.`;
  return null;
}

export const nameOf = (file) => String(file?.name || 'file').replace(/[\u0000-\u001f]/g, '').slice(0, 80);

export function fitInside(nat, maxW, maxH) {
  const nw = nat?.w > 0 ? nat.w : 4;
  const nh = nat?.h > 0 ? nat.h : 3;
  const s = Math.min(maxW / nw, maxH / nh);
  return { w: round2(nw * s), h: round2(nh * s) };
}

export function placeMedia(deck, kind, nat, pt = null) {
  const u = (deck.w || 1920) / 1920;
  let size;
  if (kind === 'audio') size = { w: round2(96 * u), h: round2(96 * u) };
  else if (kind === 'video') size = fitInside(nat || { w: 16, h: 9 }, deck.w * 0.7, deck.h * 0.7);
  else size = fitInside(nat, deck.w * 0.6, deck.h * 0.7);
  let x = pt ? pt.x - size.w / 2 : (deck.w - size.w) / 2;
  let y = pt ? pt.y - size.h / 2 : (deck.h - size.h) / 2;
  x = Math.min(Math.max(x, 0), Math.max(0, deck.w - size.w));
  y = Math.min(Math.max(y, 0), Math.max(0, deck.h - size.h));
  return { x: round2(x), y: round2(y), w: size.w, h: size.h };
}

export function replaceTarget(slide, pt, kind, pad = 0) {
  if (!slide || (kind !== 'image' && kind !== 'video')) return null;
  const want = kind === 'image' ? 'image' : 'video';
  for (const id of hitOrder(slide, pt, { pad })) {
    const el = slide.els.find((e) => e.id === id);
    if (el.type === want) return id;
    // a picture hidden behind a text box is not the target: the topmost thing under the pointer decides
    return null;
  }
  return null;
}

export function replacePatch(el, srcRef) {
  if (el.type === 'image') return { src: srcRef };
  if (el.type === 'video') return { src: srcRef, embed: null, poster: null };
  return {};
}

export function assetToElementProps(kind, asset) {
  const ref = { asset: asset.id };
  const w = asset.width ?? asset.w ?? null;
  const h = asset.height ?? asset.h ?? null;
  return { src: ref, natural: w && h ? { w, h } : null, kind };
}

/** A same origin absolute URL becomes a path, so the renderer's rule (https or relative) also holds on a plain http dev host. */
export function relativeIfSameOrigin(url, origin) {
  if (typeof url !== 'string' || !origin) return url;
  return url.startsWith(`${origin}/`) ? url.slice(origin.length) : url;
}

export function toAssetEntry(asset, origin = null) {
  return {
    url: relativeIfSameOrigin(asset.web || asset.url || asset.delivery_url, origin),
    thumb: relativeIfSameOrigin(asset.thumb || asset.url, origin),
    w: asset.width ?? null,
    h: asset.height ?? null,
    mime: asset.mime || asset.mime_type || '',
    name: asset.name || '',
    kind: asset.kind || null,
  };
}

export function uploadMessage(err) {
  const status = err?.response?.status ?? err?.status;
  const data = err?.response?.data;
  if (status === 422) {
    const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
    return String(first || data?.message || 'That file was not accepted.');
  }
  if (status === 413) return 'That file is too large for this server.';
  if (status === 403) return 'You can view this deck but not add files to it.';
  if (status === 429) return 'Too many uploads at once. Wait a moment and try again.';
  if (err?.code === 'ERR_NETWORK' || err?.network || !status) return 'The upload could not reach the server. Check your connection.';
  return 'The upload failed. Try again.';
}
