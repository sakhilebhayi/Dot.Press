// Files dropped, pasted or picked: upload to the asset library, show a placeholder with progress, then add (or replace) the element.
//
//   const uploads = useUploads({editor, store, slide, deck, toast, announce, canEdit})
//   uploads.items          ref of [{id, name, kind, box, progress}] for the placeholders (slide units)
//   uploads.addFiles(files, {point, replaceId})   point is in slide units
//   uploads.addExisting({kind: 'asset', id, asset?}, {point})   an asset dragged out of the asset panel
//
// The upload goes through `editor.uploadAsset(file, {projectId, onProgress})` when the shell provides one, otherwise it POSTs
// multipart to /api/assets (field `file`, optional `project_id`) with axios, which handles the CSRF cookie. The project id
// is `editor.deck.project_id` when the document carries it; without it the server uses the team's workspace project.
import { ref, unref } from 'vue';
import axios from 'axios';
import { newImage, newVideo, newAudio, newId } from '../core/doc.js';
import {
    classifyFile, fileProblem, placeMedia, replaceTarget, replacePatch, assetToElementProps, toAssetEntry, uploadMessage, nameOf,
} from './lib/drop.js';

const U = unref;

function readNatural(file, kind) {
    return new Promise((resolve) => {
        if (kind === 'audio') { resolve(null); return; }
        const url = URL.createObjectURL(file);
        const done = (v) => { URL.revokeObjectURL(url); resolve(v); };
        const timer = setTimeout(() => done(null), 4000);
        if (kind === 'image') {
            const img = new Image();
            img.onload = () => { clearTimeout(timer); done(img.naturalWidth ? { w: img.naturalWidth, h: img.naturalHeight } : null); };
            img.onerror = () => { clearTimeout(timer); done(null); };
            img.src = url;
        } else {
            const v = document.createElement('video');
            v.preload = 'metadata';
            v.onloadedmetadata = () => { clearTimeout(timer); done(v.videoWidth ? { w: v.videoWidth, h: v.videoHeight } : null); };
            v.onerror = () => { clearTimeout(timer); done(null); };
            v.src = url;
        }
    });
}

export function useUploads({
    editor, store, slide, deck, toast, announce, canEdit,
}) {
    const items = ref([]);
    let counter = 0;

    const projectId = () => U(editor.deck)?.project_id ?? U(editor.projectId) ?? null;

    async function uploadFile(file, onProgress) {
        if (typeof editor.uploadAsset === 'function') return editor.uploadAsset(file, { projectId: projectId(), onProgress });
        const form = new FormData();
        form.append('file', file);
        if (projectId() != null) form.append('project_id', String(projectId()));
        const res = await axios.post('/api/assets', form, {
            headers: { Accept: 'application/json' },
            onUploadProgress: (ev) => { if (ev.total) onProgress(ev.loaded / ev.total); },
        });
        return res.data.asset;
    }

    function register(asset) {
        const entry = toAssetEntry(asset);
        if (typeof editor.registerAsset === 'function') { editor.registerAsset(asset.id, entry); return; }
        const map = U(editor.assets);
        if (map && typeof map === 'object') map[asset.id] = entry;
    }

    function addElement(slideId, kind, asset, point, natural) {
        const s = store.getState().doc.slides.find((x) => x.id === slideId);
        if (!s) return null;
        const d = store.getState().doc.deck;
        const taken = s.els.map((e) => e.id);
        const props = assetToElementProps(kind, asset);
        const nat = natural || props.natural;
        const box = placeMedia(d, kind, nat, point);
        const base = kind === 'image' ? newImage(d, { src: props.src, natural: nat || undefined, taken })
            : kind === 'video' ? newVideo(d, { src: props.src, taken })
                : newAudio(d, { src: props.src, taken });
        const el = { ...base, ...box, id: base.id || newId(taken, 'e') };
        if (asset.alt) el.alt = asset.alt;
        store.dispatch([{ op: 'el.add', slide: slideId, el }], { label: kind === 'image' ? 'Add picture' : `Add ${kind}` });
        if (store.getState().slideId === slideId) store.select([el.id]);
        return el.id;
    }

    async function addFiles(files, { point = null, replaceId = null } = {}) {
        if (!canEdit()) return;
        const slideId = slide().id;
        for (const file of files) {
            const problem = fileProblem(file);
            if (problem) { toast(problem, 'warn'); continue; }
            const kind = classifyFile(file);
            const natural = await readNatural(file, kind);
            let target = replaceId;
            if (!target && point) target = replaceTarget(slide(), point, kind);
            const old = target ? slide().els.find((e) => e.id === target) : null;
            const box = old ? { x: old.x, y: old.y, w: old.w, h: old.h } : placeMedia(deck(), kind, natural, point);
            const id = `up${++counter}`;
            items.value = [...items.value, {
                id, name: nameOf(file), kind, box, progress: 0,
            }];
            const setProgress = (p) => { items.value = items.value.map((u) => (u.id === id ? { ...u, progress: Math.min(0.97, p) } : u)); };
            try {
                const asset = await uploadFile(file, setProgress);
                register(asset);
                if (old) {
                    const cur = store.getState().doc.slides.find((s) => s.id === slideId)?.els.find((e) => e.id === old.id);
                    if (cur) {
                        store.dispatch([{ op: 'el.set', slide: slideId, id: old.id, p: replacePatch(cur, { asset: asset.id }) }], { label: 'Replace picture' });
                        announce(`Replaced ${old.type}`);
                    } else addElement(slideId, kind, asset, point, natural);
                } else {
                    addElement(slideId, kind, asset, point, natural);
                    announce(`Added ${kind}`);
                }
            } catch (err) {
                toast(uploadMessage(err), 'error');
            } finally {
                items.value = items.value.filter((u) => u.id !== id);
            }
        }
    }

    function addExisting(drag, { point = null } = {}) {
        if (!canEdit()) return;
        const id = drag.id;
        const known = U(editor.assets)?.[id];
        const raw = drag.asset || {};
        const mime = raw.mime || raw.mime_type || known?.mime || '';
        const kind = raw.kind === 'video' || mime.startsWith('video/') ? 'video' : raw.kind === 'audio' || mime.startsWith('audio/') ? 'audio' : 'image';
        const asset = {
            id: Number.isFinite(+id) ? +id : id,
            width: raw.width ?? known?.w ?? null,
            height: raw.height ?? known?.h ?? null,
            alt: raw.alt || null,
            url: raw.url || known?.url,
            thumb: raw.thumb || known?.thumb,
            web: raw.web || known?.url,
            mime,
            name: raw.name || known?.name || '',
            kind,
        };
        if (!known && (asset.url)) register(asset);
        const target = replaceTarget(slide(), point, kind);
        const slideId = slide().id;
        if (target) {
            const cur = slide().els.find((e) => e.id === target);
            store.dispatch([{ op: 'el.set', slide: slideId, id: target, p: replacePatch(cur, { asset: asset.id }) }], { label: 'Replace picture' });
            announce(`Replaced ${cur.type}`);
            return;
        }
        addElement(slideId, kind, asset, point, asset.width && asset.height ? { w: asset.width, h: asset.height } : null);
        announce(`Added ${kind}`);
    }

    return { items, addFiles, addExisting };
}
