import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

// Libraries move rarely and the application changes with every release, so they get their own files: a browser that has
// the framework cached downloads only the application after a deploy (R-40 performance, docs/programme/R40_PERFORMANCE.md).
// The rich text editor is its own group because only the text editing code loads it, and only when text is edited.
const vendorGroups = [
    ['tiptap', /node_modules\/(@tiptap|prosemirror-[^/]+|linkifyjs|orderedmap|rope-sequence|w3c-keyname|crelt|markdown-it|@remirror|entities|mdurl|uc\.micro|linkify-it)\//],
    ['vue', /node_modules\/(vue|@vue)\//],
    ['inertia', /node_modules\/(@inertiajs|axios|ziggy-js|lodash[^/]*|qs|nprogress|deepmerge|side-channel[^/]*|object-inspect|call-bind[^/]*|get-intrinsic|es-[^/]+|function-bind|has-[^/]+|gopd|hasown|math-intrinsics|dunder-proto|follow-redirects|form-data|proxy-from-env|combined-stream|mime-[^/]+|asynckit|delayed-stream|set-function-length|define-data-property)\//],
];

export default defineConfig({
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    for (const [name, pattern] of vendorGroups) if (pattern.test(id)) return name;
                    return undefined;
                },
            },
        },
    },
    plugins: [
        tailwindcss(),
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
