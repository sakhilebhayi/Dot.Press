// Vue glue around the upload queue: POST /api/assets (multipart, field `file`) through editor.api so the CSRF header is handled, with
// progress from axios. Used by the Media tab of Insert and by the asset library.
//
//   const up = useAssetUploads(editor, { onDone(item) })
//   up.items          ref of queue items (see uploadQueue.js)
//   up.add(files)     up.cancel(id) up.retry(id) up.remove(id) up.clearFinished()
//   up.busy           computed: files still queued or uploading
import { computed, onBeforeUnmount, ref, unref } from 'vue';
import { classifyFile, fileProblem } from '../../stage/lib/drop.js';
import { createUploadQueue } from './uploadQueue.js';

export function useAssetUploads(editor, { onDone = () => {} } = {}) {
  const items = ref([]);
  const projectId = () => unref(editor.deck)?.project_id ?? unref(editor.projectId) ?? null;

  async function upload(file, { onProgress, signal }) {
    const form = new FormData();
    form.append('file', file);
    const pid = projectId();
    if (pid != null) form.append('project_id', String(pid));
    const res = await editor.api.post('/api/assets', form, {
      signal,
      timeout: 0,
      onUploadProgress: (ev) => { if (ev.total) onProgress(ev.loaded / ev.total); },
    });
    return { asset: res.asset, deduplicated: !!res.deduplicated };
  }

  const queue = createUploadQueue({ upload, validate: fileProblem, onDone: (item) => onDone(item) });
  const off = queue.subscribe((list) => { items.value = list; });
  onBeforeUnmount(() => { off(); for (const i of queue.items()) if (i.state === 'uploading' || i.state === 'queued') queue.cancel(i.id); });

  return {
    items,
    busy: computed(() => items.value.some((i) => i.state === 'queued' || i.state === 'uploading')),
    add(files) {
      const list = [...files];
      return queue.add(list);
    },
    classify: classifyFile,
    cancel: (id) => queue.cancel(id),
    retry: (id) => queue.retry(id),
    remove: (id) => queue.remove(id),
    clearFinished: () => queue.clearFinished(),
  };
}
