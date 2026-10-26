<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import Konva from 'konva';
import { Editor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { TextStyle } from '@tiptap/extension-text-style';
import Color from '@tiptap/extension-color';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import AppLayout from '@/Layouts/AppLayout.vue';

const STAGE_WIDTH = 1280;
const STAGE_HEIGHT = 720;
const SNAP_THRESHOLD = 8;

const ICON_LIBRARY = ['★', '❤', '✔', '⚡', '☁', '✈', '♫', '⬢'];
const STYLE_PRESETS = [
    { key: 'clean-blue', label: 'Clean Blue', fill: '#dbeafe', stroke: '#1d4ed8', shadowColor: '#93c5fd', shadowBlur: 0, opacity: 1 },
    { key: 'warm-sand', label: 'Warm Sand', fill: '#fef3c7', stroke: '#d97706', shadowColor: '#f59e0b', shadowBlur: 4, opacity: 1 },
    { key: 'mint-soft', label: 'Mint Soft', fill: '#dcfce7', stroke: '#15803d', shadowColor: '#86efac', shadowBlur: 8, opacity: 0.95 },
    { key: 'ink-outline', label: 'Ink Outline', fill: '#ffffff', stroke: '#0f172a', shadowColor: '#000000', shadowBlur: 0, opacity: 1 },
];

const props = defineProps({
    deck: {
        type: Object,
        required: true,
    },
    slides: {
        type: Array,
        required: true,
    },
    slide: {
        type: Object,
        required: true,
    },
    // Read-only members (the viewer role) get the canvas to look at, not to change.
    // This only hides and disables the controls; the API refuses their writes.
    canEdit: {
        type: Boolean,
        default: true,
    },
});

const stageRef = ref(null);
const transformerRef = ref(null);
const nodeRefs = new Map();

const canvasViewportRef = ref(null);
const stageScale = ref(1);
const stageViewportWidth = ref(STAGE_WIDTH);
const stageViewportHeight = ref(STAGE_HEIGHT);

// Deck rename
const deckTitle = ref(props.deck.title);
const isRenamingDeck = ref(false);

async function commitRenameDeck() {
    if (!props.canEdit) {
        return;
    }

    isRenamingDeck.value = false;
    const newTitle = deckTitle.value.trim();
    if (!newTitle) { deckTitle.value = props.deck.title; return; }
    if (newTitle === props.deck.title) return;
    try {
        await axios.patch(`/api/decks/${props.deck.id}`, { title: newTitle });
    } catch {
        deckTitle.value = props.deck.title;
    }
}

function startRenameDeck() {
    if (!props.canEdit) {
        return;
    }

    isRenamingDeck.value = true;
    nextTick(() => {
        const el = document.querySelector('.deck-title-input');
        el?.focus();
        el?.select();
    });
}

const elements = ref([]);
const selectedIds = ref([]);
const isSaving = ref(false);
const isDirty = ref(false);
const errorMessage = ref('');
const editingTextElementId = ref(null);
const textEditor = ref(null);
const textEditorDirty = ref(false);

const textColor = ref('#111827');
const fontSizeValue = ref(34);
const lineHeightValue = ref(1.3);
const indentValue = ref(0);
const imageFitValue = ref('contain');
const iconValue = ref(ICON_LIBRARY[0]);
const iconColorValue = ref('#111827');
const iconSizeValue = ref(72);
const videoUrlValue = ref('');
const videoAutoplayValue = ref(false);
const videoMutedValue = ref(true);
const videoLoopValue = ref(false);

const imageUploadInputRef = ref(null);
const videoUploadInputRef = ref(null);
const isUploadingImage = ref(false);
const isUploadingVideo = ref(false);
const isLoadingAssets = ref(false);
const isGeneratingWithAi = ref(false);
const isRewritingWithAi = ref(false);
const aiPrompt = ref('');
const aiRewriteTone = ref('Professional');
const aiRewriteMode = ref('shorten');
const aiUsage = ref({
    used_today: 0,
    daily_quota: 0,
    remaining_today: 0,
});
const slideRevision = ref(Number(props.slide.revision ?? props.slide.canvas_state?.meta?.revision ?? 0));
const collaborators = ref([]);
const cursorPosition = ref(null);
let collabHeartbeatTimer = null;
let collabParticipantsTimer = null;
const projectImageAssets = ref([]);
const projectVideoAssets = ref([]);
const loadedImageNodes = reactive({});

const guideX = ref(null);
const guideY = ref(null);

const selection = reactive({
    visible: false,
    x: 0,
    y: 0,
    width: 0,
    height: 0,
});

const history = ref([]);
const historyIndex = ref(-1);

const slideTitle = computed(() => props.slide.title ?? `Slide ${props.slide.sort_order + 1}`);
const selectedElement = computed(() => elements.value.find((element) => element.id === selectedIds.value[0]) ?? null);
const selectedTextElement = computed(() => selectedElement.value?.type === 'text' ? selectedElement.value : null);
const selectedImageElement = computed(() => selectedElement.value?.type === 'image' ? selectedElement.value : null);
const selectedIconElement = computed(() => selectedElement.value?.type === 'icon' ? selectedElement.value : null);
const selectedVideoElement = computed(() => selectedElement.value?.type === 'video' ? selectedElement.value : null);
const editingTextElement = computed(() => elements.value.find((element) => element.id === editingTextElementId.value) ?? null);
const isEditingText = computed(() => editingTextElementId.value !== null);
const canvasOuterStyle = computed(() => ({
    width: `${stageViewportWidth.value + 16}px`,
    minWidth: 'fit-content',
}));
const canvasFrameStyle = computed(() => ({
    width: `${stageViewportWidth.value}px`,
    height: `${stageViewportHeight.value}px`,
}));
const canvasScaleStyle = computed(() => ({
    width: `${STAGE_WIDTH}px`,
    height: `${STAGE_HEIGHT}px`,
    transform: `scale(${stageScale.value})`,
    transformOrigin: 'top left',
}));
const selectedTextValue = computed(() => {
    if (!selectedTextElement.value) {
        return '';
    }

    return textRenderValue(selectedTextElement.value).trim();
});

const textEditorOverlayStyle = computed(() => {
    const element = editingTextElement.value;

    if (!element) {
        return {};
    }

    return {
        left: `${element.x}px`,
        top: `${element.y}px`,
        width: `${Math.max(120, element.width)}px`,
        minHeight: `${Math.max(48, element.height)}px`,
        transform: `rotate(${element.rotation ?? 0}deg)`,
        transformOrigin: 'top left',
        color: element.fill ?? '#111827',
        fontSize: `${element.fontSize ?? 34}px`,
        lineHeight: `${element.lineHeight ?? 1.3}`,
        textAlign: element.textAlign ?? 'left',
        textIndent: `${element.textIndent ?? 0}px`,
    };
});

const normalizeRect = (rect) => {
    const x = Math.min(rect.x, rect.x + rect.width);
    const y = Math.min(rect.y, rect.y + rect.height);

    return {
        x,
        y,
        width: Math.abs(rect.width),
        height: Math.abs(rect.height),
    };
};

const deepClone = (value) => JSON.parse(JSON.stringify(value));

const buildDefaultRichDoc = (text = 'Add your headline') => {
    if (!text || !text.trim()) {
        return {
            type: 'doc',
            content: [{ type: 'paragraph' }],
        };
    }

    return {
        type: 'doc',
        content: [
            {
                type: 'paragraph',
                content: [
                    {
                        type: 'text',
                        text,
                    },
                ],
            },
        ],
    };
};

const nodeToText = (node, listContext = null) => {
    if (!node) {
        return '';
    }

    if (node.type === 'text') {
        return node.text ?? '';
    }

    if (node.type === 'hardBreak') {
        return '\n';
    }

    if (node.type === 'bulletList') {
        return (node.content ?? [])
            .map((item) => `• ${nodeToText(item, { ordered: false }).trim()}`)
            .join('\n');
    }

    if (node.type === 'orderedList') {
        return (node.content ?? [])
            .map((item, index) => `${index + 1}. ${nodeToText(item, { ordered: true }).trim()}`)
            .join('\n');
    }

    if (node.type === 'listItem') {
        return (node.content ?? []).map((child) => nodeToText(child, listContext)).join('\n');
    }

    if (node.type === 'paragraph' || node.type === 'heading') {
        return (node.content ?? []).map((child) => nodeToText(child, listContext)).join('');
    }

    if (node.type === 'doc') {
        return (node.content ?? [])
            .map((child) => nodeToText(child, listContext))
            .filter((line) => line !== '')
            .join('\n');
    }

    return (node.content ?? []).map((child) => nodeToText(child, listContext)).join('');
};

const richDocToPlainText = (doc) => nodeToText(doc).trim();

const textRenderValue = (element) => {
    if (!element) {
        return '';
    }

    if (element.richContent) {
        const converted = richDocToPlainText(element.richContent);

        if (converted.length > 0) {
            return converted;
        }
    }

    return element.text ?? '';
};

const isTextElementId = (id) => {
    const element = elements.value.find((item) => item.id === id);

    return element?.type === 'text';
};

const isImageElementId = (id) => {
    const element = elements.value.find((item) => item.id === id);

    return element?.type === 'image';
};

const imageNodeFor = (elementId) => loadedImageNodes[elementId]?.node ?? null;

const ensureImageNode = (elementId, sourceUrl) => {
    if (!sourceUrl) {
        return;
    }

    if (loadedImageNodes[elementId]?.sourceUrl === sourceUrl) {
        return;
    }

    const image = new window.Image();

    image.onload = () => {
        loadedImageNodes[elementId] = {
            sourceUrl,
            node: image,
        };
    };

    image.onerror = () => {
        loadedImageNodes[elementId] = {
            sourceUrl,
            node: null,
        };
    };

    image.src = sourceUrl;
};

const imageRenderConfig = (element) => {
    const imageNode = imageNodeFor(element.id);

    if (!imageNode) {
        return {
            x: 0,
            y: 0,
            width: element.width,
            height: element.height,
            image: null,
        };
    }

    const boxWidth = Math.max(10, element.width ?? 10);
    const boxHeight = Math.max(10, element.height ?? 10);
    const fit = element.objectFit ?? 'contain';

    if (fit === 'fill') {
        return {
            x: 0,
            y: 0,
            width: boxWidth,
            height: boxHeight,
            image: imageNode,
        };
    }

    const imageWidth = Math.max(1, imageNode.width || 1);
    const imageHeight = Math.max(1, imageNode.height || 1);

    if (fit === 'cover') {
        const scale = Math.max(boxWidth / imageWidth, boxHeight / imageHeight);
        const renderWidth = imageWidth * scale;
        const renderHeight = imageHeight * scale;

        return {
            x: (boxWidth - renderWidth) / 2,
            y: (boxHeight - renderHeight) / 2,
            width: renderWidth,
            height: renderHeight,
            image: imageNode,
        };
    }

    const scale = Math.min(boxWidth / imageWidth, boxHeight / imageHeight);
    const renderWidth = imageWidth * scale;
    const renderHeight = imageHeight * scale;

    return {
        x: (boxWidth - renderWidth) / 2,
        y: (boxHeight - renderHeight) / 2,
        width: renderWidth,
        height: renderHeight,
        image: imageNode,
    };
};

const applyAssetToImageElement = async (elementId, asset) => {
    updateElement(elementId, {
        assetId: asset.id,
        assetUrl: asset.delivery_url,
        objectFit: 'contain',
    });

    imageFitValue.value = 'contain';
    ensureImageNode(elementId, asset.delivery_url);
    commitHistory();
};

const applyAssetToVideoElement = async (elementId, asset) => {
    updateElement(elementId, {
        assetId: asset.id,
        assetUrl: asset.delivery_url,
        videoUrl: asset.delivery_url,
        videoType: 'file',
    });

    videoUrlValue.value = asset.delivery_url;
    commitHistory();
};

const fetchProjectAssets = async () => {
    isLoadingAssets.value = true;

    try {
        const response = await axios.get('/api/assets', {
            params: {
                project_id: props.deck.project.id,
            },
        });

        const assets = response.data?.data ?? [];
        projectImageAssets.value = assets.filter((asset) => String(asset.mime_type ?? '').startsWith('image/'));
        projectVideoAssets.value = assets.filter((asset) => String(asset.mime_type ?? '').startsWith('video/'));
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load project assets.';
    } finally {
        isLoadingAssets.value = false;
    }
};

const openImageUploadDialog = () => {
    if (!props.canEdit) {
        return;
    }

    imageUploadInputRef.value?.click();
};

const onImageUploadChange = async (event) => {
    const file = event.target.files?.[0];
    const target = selectedImageElement.value;

    if (!file || !target) {
        return;
    }

    isUploadingImage.value = true;

    try {
        const formData = new FormData();
        formData.append('project_id', String(props.deck.project.id));
        formData.append('file', file);

        const response = await axios.post('/api/assets', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        await applyAssetToImageElement(target.id, {
            id: response.data.asset.id,
            delivery_url: response.data.delivery_url,
        });

        await fetchProjectAssets();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Image upload failed.';
    } finally {
        isUploadingImage.value = false;
        event.target.value = '';
    }
};

const openVideoUploadDialog = () => {
    if (!props.canEdit) {
        return;
    }

    videoUploadInputRef.value?.click();
};

const onVideoUploadChange = async (event) => {
    const file = event.target.files?.[0];
    const target = selectedVideoElement.value;

    if (!file || !target) {
        return;
    }

    isUploadingVideo.value = true;

    try {
        const formData = new FormData();
        formData.append('project_id', String(props.deck.project.id));
        formData.append('file', file);

        const response = await axios.post('/api/assets', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        await applyAssetToVideoElement(target.id, {
            id: response.data.asset.id,
            delivery_url: response.data.delivery_url,
        });

        await fetchProjectAssets();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Video upload failed.';
    } finally {
        isUploadingVideo.value = false;
        event.target.value = '';
    }
};

const useExistingAsset = async (asset) => {
    const target = selectedImageElement.value;

    if (!target) {
        return;
    }

    await applyAssetToImageElement(target.id, asset);
};

const useExistingVideoAsset = async (asset) => {
    const target = selectedVideoElement.value;

    if (!target) {
        return;
    }

    await applyAssetToVideoElement(target.id, asset);
};

const setImageFitMode = () => {
    const target = selectedImageElement.value;

    if (!target) {
        return;
    }

    updateElement(target.id, {
        objectFit: imageFitValue.value,
    });

    commitHistory();
};

const detectVideoType = (url) => {
    const normalized = String(url ?? '').trim().toLowerCase();

    if (normalized.includes('youtube.com') || normalized.includes('youtu.be')) {
        return 'youtube';
    }

    if (normalized.includes('vimeo.com')) {
        return 'vimeo';
    }

    return 'direct';
};

const setVideoSource = () => {
    const target = selectedVideoElement.value;

    if (!target) {
        return;
    }

    updateElement(target.id, {
        videoUrl: videoUrlValue.value,
        videoType: detectVideoType(videoUrlValue.value),
    });

    commitHistory();
};

const setVideoPlayback = () => {
    const target = selectedVideoElement.value;

    if (!target) {
        return;
    }

    updateElement(target.id, {
        videoAutoplay: Boolean(videoAutoplayValue.value),
        videoMuted: Boolean(videoMutedValue.value),
        videoLoop: Boolean(videoLoopValue.value),
    });

    commitHistory();
};

const setIconGlyph = (glyph) => {
    const target = selectedIconElement.value;

    if (!target) {
        return;
    }

    iconValue.value = glyph;
    updateElement(target.id, { icon: glyph });
    commitHistory();
};

const setIconAppearance = () => {
    const target = selectedIconElement.value;

    if (!target) {
        return;
    }

    updateElement(target.id, {
        icon: iconValue.value,
        fill: iconColorValue.value,
        fontSize: Number(iconSizeValue.value),
    });

    commitHistory();
};

const applyStylePreset = (preset) => {
    const target = selectedElement.value;

    if (!target) {
        return;
    }

    updateElement(target.id, {
        fill: preset.fill,
        stroke: preset.stroke,
        shadowColor: preset.shadowColor,
        shadowBlur: preset.shadowBlur,
        opacity: preset.opacity,
    });

    commitHistory();
};

const elementVisualConfig = (element, defaults = {}) => ({
    fill: element.fill ?? defaults.fill,
    stroke: element.stroke ?? defaults.stroke,
    strokeWidth: element.strokeWidth ?? defaults.strokeWidth ?? 2,
    opacity: element.opacity ?? 1,
    shadowColor: element.shadowColor,
    shadowBlur: element.shadowBlur ?? 0,
    shadowOpacity: element.shadowColor ? 0.5 : 0,
});

const refreshImageDeliveryUrls = async () => {
    const unresolved = elements.value.filter((element) => element.type === 'image' && element.assetId && !element.assetUrl);

    for (const element of unresolved) {
        try {
            const response = await axios.get(`/api/assets/${element.assetId}`);
            const deliveryUrl = response.data?.delivery_url;

            if (deliveryUrl) {
                updateElement(element.id, { assetUrl: deliveryUrl });
                ensureImageNode(element.id, deliveryUrl);
            }
        } catch {
            // Ignore expired/missing assets and keep placeholder rendering.
        }
    }
};

const fetchAiUsage = async () => {
    try {
        const response = await axios.get('/api/ai/usage');
        aiUsage.value = {
            used_today: Number(response.data?.used_today ?? 0),
            daily_quota: Number(response.data?.daily_quota ?? 0),
            remaining_today: Number(response.data?.remaining_today ?? 0),
        };
    } catch {
        // Leave fallback values to avoid blocking the editor.
    }
};

const fetchCollaborators = async () => {
    try {
        const response = await axios.get(`/api/collab/slides/${props.slide.id}/participants`);
        collaborators.value = Array.isArray(response.data?.participants) ? response.data.participants : [];
    } catch {
        // Ignore transient collaboration polling failures.
    }
};

const sendCollaborationHeartbeat = async () => {
    try {
        const response = await axios.post(`/api/collab/slides/${props.slide.id}/heartbeat`, {
            cursor: cursorPosition.value,
            selected_ids: selectedIds.value,
        });

        collaborators.value = Array.isArray(response.data?.participants) ? response.data.participants : collaborators.value;
    } catch {
        // Ignore heartbeat errors to avoid interrupting editing.
    }
};

const startCollaborationSync = () => {
    stopCollaborationSync();

    collabHeartbeatTimer = window.setInterval(() => {
        sendCollaborationHeartbeat();
    }, 2000);

    collabParticipantsTimer = window.setInterval(() => {
        fetchCollaborators();
    }, 4000);

    sendCollaborationHeartbeat();
};

const stopCollaborationSync = () => {
    if (collabHeartbeatTimer) {
        window.clearInterval(collabHeartbeatTimer);
        collabHeartbeatTimer = null;
    }

    if (collabParticipantsTimer) {
        window.clearInterval(collabParticipantsTimer);
        collabParticipantsTimer = null;
    }
};

// Presence (above) only ever polls who's looking at a slide. This is the
// part that makes edits actually show up live: SlideController::update
// broadcasts SlideElementsUpdated (as 'slide.elements-updated') to
// 'slide.{id}' with ->toOthers(), so every other tab with this slide open
// applies the change without waiting for a poll or hitting a save conflict.
let slideChannelName = null;

const applyRemoteCanvasUpdate = (payload) => {
    if (!payload || Number(payload.slide_id) !== props.slide.id) {
        return;
    }

    if (isDirty.value) {
        // Don't clobber in-progress local edits. The existing
        // revision-conflict check on save will surface this collaborator's
        // change (and reload the latest state) the moment this user saves.
        errorMessage.value = `${payload.edited_by?.name ?? 'A collaborator'} made changes elsewhere. Save to pick them up.`;
        return;
    }

    const incomingElements = Array.isArray(payload.canvas_state?.elements) ? deepClone(payload.canvas_state.elements) : [];

    elements.value = incomingElements;
    slideRevision.value = Number(payload.revision ?? slideRevision.value);
    history.value = [deepClone(incomingElements)];
    historyIndex.value = 0;
    selectedIds.value = [];
    errorMessage.value = '';
};

const subscribeToRealtimeUpdates = () => {
    if (!window.Echo) {
        // BROADCAST_CONNECTION isn't set to reverb (see .env.example) --
        // presence polling above still works, live-updates just don't.
        return;
    }

    slideChannelName = `slide.${props.slide.id}`;
    window.Echo.private(slideChannelName).listen('.slide.elements-updated', applyRemoteCanvasUpdate);
};

const unsubscribeFromRealtimeUpdates = () => {
    if (!window.Echo || !slideChannelName) {
        return;
    }

    window.Echo.leave(slideChannelName);
    slideChannelName = null;
};

const generateSlideWithAi = async () => {
    if (!props.canEdit) {
        return;
    }

    if (!aiPrompt.value.trim()) {
        errorMessage.value = 'Enter a prompt before generating a slide.';
        return;
    }

    isGeneratingWithAi.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.post(`/api/ai/decks/${props.deck.id}/generate-slide`, {
            prompt: aiPrompt.value,
        });

        aiPrompt.value = '';
        const createdSlideId = response.data?.slide?.id;

        if (createdSlideId) {
            router.visit(route('editor.slides.show', [props.deck.id, createdSlideId]));
            return;
        }

        await fetchAiUsage();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to generate slide with AI.';
    } finally {
        isGeneratingWithAi.value = false;
    }
};

const rewriteSelectedTextWithAi = async () => {
    if (!props.canEdit) {
        return;
    }

    if (!selectedTextElement.value) {
        errorMessage.value = 'Select a text element first.';
        return;
    }

    const currentText = selectedTextValue.value;

    if (!currentText) {
        errorMessage.value = 'Selected text is empty.';
        return;
    }

    isRewritingWithAi.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.post(`/api/ai/slides/${props.slide.id}/rewrite-text`, {
            text: currentText,
            mode: aiRewriteMode.value,
            tone: aiRewriteMode.value === 'tone' ? aiRewriteTone.value : null,
        });

        const rewrittenText = String(response.data?.text ?? '').trim();

        if (!rewrittenText) {
            throw new Error('AI returned empty text.');
        }

        updateElement(selectedTextElement.value.id, {
            text: rewrittenText,
            richContent: buildDefaultRichDoc(rewrittenText),
        });
        commitHistory();
        await fetchAiUsage();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? error.message ?? 'Unable to rewrite text with AI.';
    } finally {
        isRewritingWithAi.value = false;
    }
};

const hydrateCanvasState = () => {
    const state = props.slide.canvas_state ?? {};
    const incomingElements = Array.isArray(state.elements) ? deepClone(state.elements) : [];

    elements.value = incomingElements;
    selectedIds.value = [];
    editingTextElementId.value = null;
    slideRevision.value = Number(props.slide.revision ?? state.meta?.revision ?? 0);
    isDirty.value = false;

    history.value = [deepClone(incomingElements)];
    historyIndex.value = 0;
};

const commitHistory = () => {
    if (!props.canEdit) {
        return;
    }

    const snapshot = deepClone(elements.value);
    history.value = history.value.slice(0, historyIndex.value + 1);
    history.value.push(snapshot);
    historyIndex.value = history.value.length - 1;
    isDirty.value = true;
};

const setNodeRef = (componentRef, id) => {
    if (!componentRef || !componentRef.getNode) {
        nodeRefs.delete(id);
        return;
    }

    nodeRefs.set(id, componentRef.getNode());
};

const syncTransformer = () => {
    const transformer = transformerRef.value?.getNode();

    if (!transformer) {
        return;
    }

    if (isEditingText.value) {
        transformer.nodes([]);
        transformer.getLayer()?.batchDraw();
        return;
    }

    const nodes = selectedIds.value
        .map((id) => nodeRefs.get(id))
        .filter((node) => Boolean(node));

    transformer.nodes(nodes);
    transformer.getLayer()?.batchDraw();
};

const addElement = (type) => {
    if (!props.canEdit) {
        return;
    }

    const id = `el-${Math.random().toString(36).slice(2, 10)}`;

    const common = {
        id,
        type,
        x: 160 + (elements.value.length % 6) * 24,
        y: 120 + (elements.value.length % 6) * 24,
        width: 220,
        height: 120,
        rotation: 0,
    };

    let element = common;

    if (type === 'rect') {
        element = { ...common, fill: '#dbeafe', stroke: '#2563eb', strokeWidth: 2, radius: 8 };
    }

    if (type === 'text') {
        element = {
            ...common,
            width: 280,
            height: 84,
            text: 'Add your headline',
            fontSize: 34,
            fill: '#111827',
            fontStyle: 'bold',
            textAlign: 'left',
            lineHeight: 1.3,
            textIndent: 0,
            richContent: buildDefaultRichDoc('Add your headline'),
        };
    }

    if (type === 'image') {
        element = {
            ...common,
            width: 320,
            height: 220,
            fill: '#f8fafc',
            stroke: '#475569',
            strokeWidth: 2,
            dash: [8, 4],
            objectFit: 'contain',
            assetId: null,
            assetUrl: null,
        };
    }

    if (type === 'group') {
        element = { ...common, width: 320, height: 180, fill: '#eef2ff', stroke: '#4338ca', strokeWidth: 2, dash: [10, 5] };
    }

    if (type === 'circle') {
        element = { ...common, width: 180, height: 180, fill: '#fee2e2', stroke: '#dc2626', strokeWidth: 2 };
    }

    if (type === 'line') {
        element = { ...common, width: 260, height: 18, stroke: '#0f172a', strokeWidth: 4 };
    }

    if (type === 'arrow') {
        element = { ...common, width: 260, height: 30, stroke: '#1d4ed8', strokeWidth: 4 };
    }

    if (type === 'polygon') {
        element = { ...common, width: 220, height: 180, fill: '#dcfce7', stroke: '#15803d', strokeWidth: 2 };
    }

    if (type === 'icon') {
        element = {
            ...common,
            width: 96,
            height: 96,
            icon: ICON_LIBRARY[0],
            fill: '#111827',
            fontSize: 72,
            textAlign: 'center',
        };
    }

    if (type === 'video') {
        element = {
            ...common,
            width: 420,
            height: 240,
            fill: '#111827',
            stroke: '#334155',
            strokeWidth: 2,
            videoUrl: '',
            videoType: 'direct',
            videoAutoplay: false,
            videoMuted: true,
            videoLoop: false,
            assetId: null,
            assetUrl: null,
        };
    }

    elements.value.push(element);
    selectedIds.value = [id];
    commitHistory();
};

const updateElement = (id, patch) => {
    if (!props.canEdit) {
        return;
    }

    elements.value = elements.value.map((element) => {
        if (element.id !== id) {
            return element;
        }

        return { ...element, ...patch };
    });
};

const syncTextToolbarFromElement = (element) => {
    if (!element) {
        return;
    }

    textColor.value = element.fill ?? '#111827';
    fontSizeValue.value = Number(element.fontSize ?? 34);
    lineHeightValue.value = Number(element.lineHeight ?? 1.3);
    indentValue.value = Number(element.textIndent ?? 0);
};

const destroyTextEditor = () => {
    if (textEditor.value) {
        textEditor.value.destroy();
        textEditor.value = null;
    }
};

const commitTextEditorBuffer = () => {
    if (!editingTextElementId.value || !textEditor.value) {
        return;
    }

    const richContent = textEditor.value.getJSON();
    const plainText = richDocToPlainText(richContent);

    updateElement(editingTextElementId.value, {
        richContent,
        text: plainText || ' ',
    });

    textEditorDirty.value = true;
    isDirty.value = true;
};

const startTextEditMode = async (id) => {
    if (!props.canEdit) {
        return;
    }

    if (!isTextElementId(id)) {
        return;
    }

    if (editingTextElementId.value && editingTextElementId.value !== id) {
        stopTextEditMode(true);
    }

    selectedIds.value = [id];
    editingTextElementId.value = id;

    const element = elements.value.find((item) => item.id === id);

    if (!element) {
        return;
    }

    syncTextToolbarFromElement(element);
    destroyTextEditor();

    textEditor.value = new Editor({
        content: element.richContent ?? buildDefaultRichDoc(element.text ?? ''),
        extensions: [
            StarterKit,
            TextStyle,
            Color,
            Underline,
            TextAlign.configure({
                types: ['heading', 'paragraph'],
            }),
        ],
        editorProps: {
            attributes: {
                class: 'rich-text-prosemirror',
            },
        },
        onUpdate: () => {
            commitTextEditorBuffer();
        },
    });

    textEditorDirty.value = false;

    await nextTick();
    textEditor.value.commands.focus('end');
};

const stopTextEditMode = (commit = true) => {
    if (!editingTextElementId.value) {
        return;
    }

    if (commit && textEditor.value) {
        commitTextEditorBuffer();
    }

    if (commit && textEditorDirty.value) {
        commitHistory();
    }

    textEditorDirty.value = false;
    editingTextElementId.value = null;
    destroyTextEditor();
};

const applyElementTextStyle = (patch) => {
    const element = selectedTextElement.value;

    if (!element) {
        return;
    }

    updateElement(element.id, patch);
    commitHistory();
};

const setFontSize = () => {
    const fontSize = Number(fontSizeValue.value);

    applyElementTextStyle({ fontSize });

    if (textEditor.value) {
        textEditor.value
            .chain()
            .focus()
            .setMark('textStyle', { fontSize: `${fontSize}px` })
            .run();
    }
};

const setTextColor = () => {
    const color = textColor.value;

    applyElementTextStyle({ fill: color });

    if (textEditor.value) {
        textEditor.value.chain().focus().setColor(color).run();
    }
};

const setLineHeight = () => {
    applyElementTextStyle({ lineHeight: Number(lineHeightValue.value) });
};

const setIndent = () => {
    applyElementTextStyle({ textIndent: Number(indentValue.value) });
};

const setTextAlign = (alignment) => {
    applyElementTextStyle({ textAlign: alignment });

    if (textEditor.value) {
        textEditor.value.chain().focus().setTextAlign(alignment).run();
    }
};

const applyEditorCommand = (command) => {
    if (!textEditor.value) {
        return;
    }

    command(textEditor.value.chain().focus()).run();
};

const removeSelected = () => {
    if (!props.canEdit) {
        return;
    }

    if (selectedIds.value.length === 0) {
        return;
    }

    const removeSet = new Set(selectedIds.value);
    elements.value = elements.value.filter((element) => !removeSet.has(element.id));
    selectedIds.value = [];
    commitHistory();
};

const bringForward = () => {
    if (selectedIds.value.length !== 1) {
        return;
    }

    const id = selectedIds.value[0];
    const index = elements.value.findIndex((element) => element.id === id);

    if (index < 0 || index >= elements.value.length - 1) {
        return;
    }

    const reordered = [...elements.value];
    const [item] = reordered.splice(index, 1);
    reordered.splice(index + 1, 0, item);
    elements.value = reordered;
    commitHistory();
};

const sendBackward = () => {
    if (selectedIds.value.length !== 1) {
        return;
    }

    const id = selectedIds.value[0];
    const index = elements.value.findIndex((element) => element.id === id);

    if (index <= 0) {
        return;
    }

    const reordered = [...elements.value];
    const [item] = reordered.splice(index, 1);
    reordered.splice(index - 1, 0, item);
    elements.value = reordered;
    commitHistory();
};

const undo = () => {
    if (!props.canEdit) {
        return;
    }

    if (historyIndex.value <= 0) {
        return;
    }

    historyIndex.value -= 1;
    elements.value = deepClone(history.value[historyIndex.value]);
    selectedIds.value = [];
    isDirty.value = true;
};

const redo = () => {
    if (!props.canEdit) {
        return;
    }

    if (historyIndex.value >= history.value.length - 1) {
        return;
    }

    historyIndex.value += 1;
    elements.value = deepClone(history.value[historyIndex.value]);
    selectedIds.value = [];
    isDirty.value = true;
};

const clearGuides = () => {
    guideX.value = null;
    guideY.value = null;
};

const snapPosition = (node) => {
    let x = node.x();
    let y = node.y();

    const width = node.width() * node.scaleX();
    const height = node.height() * node.scaleY();

    const centerX = x + width / 2;
    const centerY = y + height / 2;

    clearGuides();

    if (Math.abs(x) <= SNAP_THRESHOLD) {
        x = 0;
        guideX.value = 0;
    }

    if (Math.abs(y) <= SNAP_THRESHOLD) {
        y = 0;
        guideY.value = 0;
    }

    if (Math.abs(STAGE_WIDTH - (x + width)) <= SNAP_THRESHOLD) {
        x = STAGE_WIDTH - width;
        guideX.value = STAGE_WIDTH;
    }

    if (Math.abs(STAGE_HEIGHT - (y + height)) <= SNAP_THRESHOLD) {
        y = STAGE_HEIGHT - height;
        guideY.value = STAGE_HEIGHT;
    }

    if (Math.abs(centerX - STAGE_WIDTH / 2) <= SNAP_THRESHOLD) {
        x = STAGE_WIDTH / 2 - width / 2;
        guideX.value = STAGE_WIDTH / 2;
    }

    if (Math.abs(centerY - STAGE_HEIGHT / 2) <= SNAP_THRESHOLD) {
        y = STAGE_HEIGHT / 2 - height / 2;
        guideY.value = STAGE_HEIGHT / 2;
    }

    node.position({ x, y });

    return { x, y };
};

const onElementClick = (event, id) => {
    if (isEditingText.value && editingTextElementId.value !== id) {
        stopTextEditMode(true);
    }

    const isMulti = event.evt.shiftKey || event.evt.metaKey || event.evt.ctrlKey;

    if (isMulti) {
        if (selectedIds.value.includes(id)) {
            selectedIds.value = selectedIds.value.filter((currentId) => currentId !== id);
        } else {
            selectedIds.value = [...selectedIds.value, id];
        }

        return;
    }

    selectedIds.value = [id];
};

const onElementDragMove = (event, id) => {
    const nextPosition = snapPosition(event.target);
    updateElement(id, nextPosition);
};

const onElementDragEnd = (event, id) => {
    clearGuides();

    updateElement(id, {
        x: event.target.x(),
        y: event.target.y(),
    });

    commitHistory();
};

const onElementTransformEnd = (event, id) => {
    clearGuides();

    const node = event.target;
    const width = Math.max(30, node.width() * node.scaleX());
    const height = Math.max(20, node.height() * node.scaleY());

    node.scaleX(1);
    node.scaleY(1);

    updateElement(id, {
        x: node.x(),
        y: node.y(),
        width,
        height,
        rotation: node.rotation(),
    });

    commitHistory();
};

const onStageMouseDown = (event) => {
    const stage = stageRef.value?.getNode();

    if (!stage) {
        return;
    }

    const clickedOnEmpty = event.target === stage;

    if (!clickedOnEmpty) {
        return;
    }

    if (isEditingText.value) {
        stopTextEditMode(true);
    }

    const pointer = stage.getPointerPosition();

    selectedIds.value = [];

    if (!pointer) {
        return;
    }

    selection.visible = true;
    selection.x = pointer.x;
    selection.y = pointer.y;
    selection.width = 0;
    selection.height = 0;
};

const onStageMouseMove = () => {
    const stage = stageRef.value?.getNode();
    const pointer = stage?.getPointerPosition();

    if (pointer) {
        cursorPosition.value = {
            x: Math.max(0, Math.min(STAGE_WIDTH, Math.round(pointer.x))),
            y: Math.max(0, Math.min(STAGE_HEIGHT, Math.round(pointer.y))),
        };
    }

    if (!selection.visible) {
        return;
    }

    if (!pointer) {
        return;
    }

    selection.width = pointer.x - selection.x;
    selection.height = pointer.y - selection.y;
};

const onStageMouseUp = () => {
    if (!selection.visible) {
        return;
    }

    const box = normalizeRect(selection);

    selection.visible = false;

    if (box.width < 4 || box.height < 4) {
        return;
    }

    const matchedIds = elements.value
        .filter((element) => {
            const node = nodeRefs.get(element.id);

            if (!node) {
                return false;
            }

            return Konva.Util.haveIntersection(box, node.getClientRect());
        })
        .map((element) => element.id);

    selectedIds.value = matchedIds;
};

const saveCanvas = async () => {
    if (!props.canEdit) {
        return;
    }

    if (isEditingText.value) {
        stopTextEditMode(true);
    }

    isSaving.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.put(`/api/slides/${props.slide.id}`, {
            canvas_state: {
                elements: elements.value,
                viewport: {
                    width: STAGE_WIDTH,
                    height: STAGE_HEIGHT,
                },
                meta: {
                    version: 1,
                },
            },
            expected_revision: slideRevision.value,
        });

        slideRevision.value = Number(response.data?.revision ?? slideRevision.value + 1);

        isDirty.value = false;
    } catch (error) {
        if (error.response?.status === 409 && error.response?.data?.conflict) {
            const serverCanvasState = error.response?.data?.server_canvas_state ?? { elements: [] };

            elements.value = Array.isArray(serverCanvasState.elements) ? deepClone(serverCanvasState.elements) : [];
            slideRevision.value = Number(error.response?.data?.server_revision ?? slideRevision.value);
            history.value = [deepClone(elements.value)];
            historyIndex.value = 0;
            selectedIds.value = [];
            isDirty.value = false;
            errorMessage.value = 'Another collaborator changed this slide. Latest version has been loaded.';
            return;
        }

        errorMessage.value = error.response?.data?.message ?? 'Unable to save the slide.';
    } finally {
        isSaving.value = false;
    }
};

const createSlide = async () => {
    if (!props.canEdit) {
        return;
    }

    if (isEditingText.value) {
        stopTextEditMode(true);
    }

    isSaving.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.post('/api/slides', {
            deck_id: props.deck.id,
            title: `Slide ${props.slides.length + 1}`,
            canvas_state: {
                elements: [],
            },
        });

        router.visit(route('editor.slides.show', [props.deck.id, response.data.id]));
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to create a new slide.';
    } finally {
        isSaving.value = false;
    }
};

const onKeyDown = (event) => {
    if (isEditingText.value) {
        if (event.key === 'Escape') {
            event.preventDefault();
            stopTextEditMode(true);
        }

        return;
    }

    const targetTag = event.target?.tagName?.toLowerCase();
    const isInputTarget = ['input', 'textarea'].includes(targetTag);

    if (isInputTarget) {
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && !event.shiftKey) {
        event.preventDefault();
        undo();
        return;
    }

    if (((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') || ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === 'z')) {
        event.preventDefault();
        redo();
        return;
    }

    if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        removeSelected();
        return;
    }

    if (event.key === 'Enter' && selectedIds.value.length === 1 && isTextElementId(selectedIds.value[0])) {
        event.preventDefault();
        startTextEditMode(selectedIds.value[0]);
    }
};

watch([selectedIds, elements], async () => {
    await nextTick();
    syncTransformer();
}, { deep: true });

watch(() => props.slide.id, () => {
    stopTextEditMode(false);
    hydrateCanvasState();
    refreshImageDeliveryUrls();
    startCollaborationSync();
});

watch(selectedTextElement, (nextElement) => {
    if (nextElement) {
        syncTextToolbarFromElement(nextElement);
    }
});

watch(selectedImageElement, (nextElement) => {
    if (nextElement) {
        imageFitValue.value = nextElement.objectFit ?? 'contain';
    }
});

watch(selectedIconElement, (nextElement) => {
    if (nextElement) {
        iconValue.value = nextElement.icon ?? ICON_LIBRARY[0];
        iconColorValue.value = nextElement.fill ?? '#111827';
        iconSizeValue.value = Number(nextElement.fontSize ?? 72);
    }
});

watch(selectedVideoElement, (nextElement) => {
    if (nextElement) {
        videoUrlValue.value = nextElement.videoUrl ?? '';
        videoAutoplayValue.value = Boolean(nextElement.videoAutoplay);
        videoMutedValue.value = Boolean(nextElement.videoMuted ?? true);
        videoLoopValue.value = Boolean(nextElement.videoLoop);
    }
});

watch(elements, (nextElements) => {
    nextElements
        .filter((element) => element.type === 'image' && element.assetUrl)
        .forEach((element) => {
            ensureImageNode(element.id, element.assetUrl);
        });
}, { deep: true });

const updateCanvasViewport = () => {
    const viewport = canvasViewportRef.value;

    if (!viewport || typeof window === 'undefined') {
        return;
    }

    if (window.innerWidth < 768) {
        stageScale.value = 1;
        stageViewportWidth.value = STAGE_WIDTH;
        stageViewportHeight.value = STAGE_HEIGHT;

        return;
    }

    const rect = viewport.getBoundingClientRect();
    const maxWidth = Math.max(320, rect.width - 16);
    const maxHeight = Math.max(220, window.innerHeight - rect.top - 24);
    const scale = Math.min(maxWidth / STAGE_WIDTH, maxHeight / STAGE_HEIGHT, 1);

    stageScale.value = scale;
    stageViewportWidth.value = Math.round(STAGE_WIDTH * scale);
    stageViewportHeight.value = Math.round(STAGE_HEIGHT * scale);
};

onMounted(() => {
    hydrateCanvasState();
    fetchProjectAssets();
    refreshImageDeliveryUrls();
    fetchAiUsage();
    startCollaborationSync();
    subscribeToRealtimeUpdates();
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('resize', updateCanvasViewport);
    nextTick(() => {
        updateCanvasViewport();
    });
});

onBeforeUnmount(() => {
    stopTextEditMode(false);
    stopCollaborationSync();
    unsubscribeFromRealtimeUpdates();
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('resize', updateCanvasViewport);
});
</script>

<template>
    <AppLayout title="Canvas Editor">
        <Head :title="`Editor - ${slideTitle}`" />

        <template #header>
            <div class="editor-header flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-widest text-white/40">{{ deck.project.name }}</p>
                    <div class="flex items-center gap-2">
                        <!-- Inline rename input -->
                        <input
                            v-if="isRenamingDeck"
                            class="deck-title-input bg-transparent border-b border-amber-400/60 font-semibold text-xl text-white leading-tight outline-none focus:border-amber-400 w-64 py-0.5"
                            v-model="deckTitle"
                            @keydown.enter="commitRenameDeck"
                            @keydown.escape="isRenamingDeck = false; deckTitle = deck.title"
                            @blur="commitRenameDeck"
                        />
                        <template v-else>
                            <h2 class="font-semibold text-xl text-white leading-tight">{{ deckTitle }} · {{ slideTitle }}</h2>
                            <button
                                v-if="canEdit"
                                type="button"
                                title="Rename presentation"
                                class="text-white/30 hover:text-amber-400 transition"
                                @click="startRenameDeck"
                            >
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                </svg>
                            </button>
                        </template>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="route('presentation.slides.show', [deck.id, slide.id])"
                        class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                    >
                        Present
                    </Link>
                    <a
                        :href="route('export.decks.pdf', [deck.id])"
                        class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                    >
                        Export PDF
                    </a>
                    <a
                        :href="route('export.decks.pptx', [deck.id])"
                        class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                    >
                        Export PPTX
                    </a>
                    <button
                        v-if="canEdit"
                        class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                        type="button"
                        @click="undo"
                    >
                        Undo
                    </button>
                    <button
                        v-if="canEdit"
                        class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                        type="button"
                        @click="redo"
                    >
                        Redo
                    </button>
                    <span v-if="!canEdit" class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white/60">View only</span>
                    <button
                        v-if="canEdit"
                        class="rounded-xl bg-gradient-to-r from-amber-400 to-orange-500 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-amber-500/25 transition hover:brightness-110 disabled:opacity-60"
                        :disabled="isSaving"
                        type="button"
                        @click="saveCanvas"
                    >
                        {{ isSaving ? 'Saving...' : 'Save Slide' }}
                    </button>
                </div>
            </div>
        </template>

        <div class="editor-page py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
                    <aside class="editor-panel editor-sidebar rounded-2xl border border-white/10 bg-white/[0.03] p-4 shadow-2xl lg:sticky lg:top-24 lg:max-h-[calc(100vh-7.5rem)] lg:overflow-y-auto">
                        <p class="text-xs font-semibold uppercase tracking-wider text-white/40">Slides</p>
                        <div class="mt-3 space-y-2">
                            <Link
                                v-for="item in slides"
                                :key="item.id"
                                :href="route('editor.slides.show', [deck.id, item.id])"
                                class="block rounded-xl border px-3 py-2 text-sm transition"
                                :class="item.id === slide.id ? 'border-amber-400/40 bg-amber-400/10 text-amber-300' : 'border-white/10 bg-white/5 text-white/70 hover:border-white/30 hover:text-white'"
                            >
                                {{ item.title || `Slide ${item.sort_order + 1}` }}
                            </Link>
                        </div>

                        <button
                            v-if="canEdit"
                            class="mt-4 w-full rounded-xl border border-dashed border-white/25 px-3 py-2 text-sm font-medium text-white/80 transition hover:bg-white/10"
                            type="button"
                            @click="createSlide"
                        >
                            + New Slide
                        </button>

                        <div class="mt-6 border-t border-white/10 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-white/40">Collaboration</p>
                            <p class="mt-2 text-xs text-white/60">Revision {{ slideRevision }}</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span
                                    v-for="person in collaborators"
                                    :key="person.user_id"
                                    class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px]"
                                    :style="{ borderColor: person.color, color: person.color }"
                                >
                                    <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: person.color }" />
                                    {{ person.name }}
                                </span>
                                <p v-if="collaborators.length === 0" class="text-xs text-white/40">No active collaborators on this slide.</p>
                            </div>
                        </div>

                        <template v-if="canEdit">
                        <p class="mt-6 text-xs font-semibold uppercase tracking-wider text-white/40">Elements</p>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button class="editor-element-btn" type="button" @click="addElement('rect')">Rect</button>
                            <button class="editor-element-btn" type="button" @click="addElement('text')">Text</button>
                            <button class="editor-element-btn" type="button" @click="addElement('image')">Image Box</button>
                            <button class="editor-element-btn" type="button" @click="addElement('video')">Video</button>
                            <button class="editor-element-btn" type="button" @click="addElement('group')">Group Box</button>
                            <button class="editor-element-btn" type="button" @click="addElement('icon')">Icon</button>
                            <button class="editor-element-btn" type="button" @click="addElement('circle')">Circle</button>
                            <button class="editor-element-btn" type="button" @click="addElement('polygon')">Polygon</button>
                            <button class="editor-element-btn" type="button" @click="addElement('line')">Line</button>
                            <button class="editor-element-btn" type="button" @click="addElement('arrow')">Arrow</button>
                        </div>

                        <div class="mt-6 border-t border-white/10 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-white/40">Ask AI</p>
                            <label class="mt-3 block text-xs text-white/60">Generate new slide from prompt</label>
                            <textarea
                                v-model="aiPrompt"
                                class="mt-1 w-full rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/30"
                                rows="4"
                                placeholder="Create a product launch overview slide with title, three bullets, and a callout box."
                            />
                            <button
                                class="mt-2 w-full rounded-xl bg-gradient-to-r from-amber-400 to-orange-500 px-3 py-2 text-xs font-semibold text-white shadow-lg shadow-amber-500/25 transition hover:brightness-110 disabled:opacity-60"
                                type="button"
                                :disabled="isGeneratingWithAi"
                                @click="generateSlideWithAi"
                            >
                                {{ isGeneratingWithAi ? 'Generating...' : 'Generate AI Slide' }}
                            </button>

                            <div class="mt-4 border-t border-white/10 pt-3">
                                <p class="text-xs text-white/60">Rewrite selected text</p>
                                <select v-model="aiRewriteMode" class="mt-1 w-full rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white">
                                    <option value="shorten">Shorten</option>
                                    <option value="expand">Expand</option>
                                    <option value="rephrase">Rephrase</option>
                                    <option value="tone">Change tone</option>
                                </select>
                                <input
                                    v-if="aiRewriteMode === 'tone'"
                                    v-model="aiRewriteTone"
                                    class="mt-2 w-full rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/30"
                                    type="text"
                                    placeholder="Professional"
                                >
                                <button
                                    class="mt-2 w-full rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-xs font-medium text-white/80 transition hover:bg-white/10 disabled:opacity-60"
                                    type="button"
                                    :disabled="isRewritingWithAi || !selectedTextElement"
                                    @click="rewriteSelectedTextWithAi"
                                >
                                    {{ isRewritingWithAi ? 'Rewriting...' : 'Rewrite Selected Text' }}
                                </button>
                            </div>

                            <p class="mt-3 text-xs text-white/40">
                                AI usage: {{ aiUsage.used_today }} / {{ aiUsage.daily_quota }} today ({{ aiUsage.remaining_today }} left)
                            </p>
                        </div>

                        <div v-if="selectedImageElement" class="mt-6 border-t border-white/10 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-white/40">Image</p>
                            <input ref="imageUploadInputRef" class="hidden" type="file" accept="image/*" @change="onImageUploadChange">

                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <button
                                    class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10 disabled:opacity-50"
                                    type="button"
                                    :disabled="isUploadingImage"
                                    @click="openImageUploadDialog"
                                >
                                    {{ isUploadingImage ? 'Uploading...' : 'Upload' }}
                                </button>
                                <button
                                    class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10"
                                    type="button"
                                    @click="openImageUploadDialog"
                                >
                                    Replace
                                </button>
                            </div>

                            <label class="mt-3 block text-xs text-white/60">Fit mode</label>
                            <select v-model="imageFitValue" class="mt-1 w-full rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white" @change="setImageFitMode">
                                <option value="contain">Contain</option>
                                <option value="cover">Cover</option>
                                <option value="fill">Fill</option>
                            </select>

                            <p class="mt-3 text-xs font-semibold uppercase tracking-wider text-white/40">Asset Library</p>
                            <p v-if="isLoadingAssets" class="mt-2 text-xs text-white/40">Loading assets...</p>
                            <div v-else class="mt-2 max-h-36 space-y-1 overflow-y-auto rounded-xl border border-white/10 bg-black/10 p-2">
                                <button
                                    v-for="asset in projectImageAssets"
                                    :key="asset.id"
                                    class="block w-full truncate rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-left text-xs text-white/70 transition hover:border-white/30 hover:text-white"
                                    type="button"
                                    @click="useExistingAsset(asset)"
                                >
                                    {{ asset.original_name }}
                                </button>
                                <p v-if="projectImageAssets.length === 0" class="text-xs text-white/40">No image assets uploaded yet.</p>
                            </div>
                        </div>

                        <div v-if="selectedVideoElement" class="mt-6 border-t border-white/10 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-white/40">Video</p>
                            <input ref="videoUploadInputRef" class="hidden" type="file" accept="video/*" @change="onVideoUploadChange">

                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <button
                                    class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10 disabled:opacity-50"
                                    type="button"
                                    :disabled="isUploadingVideo"
                                    @click="openVideoUploadDialog"
                                >
                                    {{ isUploadingVideo ? 'Uploading...' : 'Upload' }}
                                </button>
                                <button
                                    class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10"
                                    type="button"
                                    @click="openVideoUploadDialog"
                                >
                                    Replace
                                </button>
                            </div>

                            <label class="mt-3 block text-xs text-white/60">Embed URL</label>
                            <input v-model="videoUrlValue" class="mt-1 w-full rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/30" type="url" placeholder="https://..." @change="setVideoSource">

                            <div class="mt-3 grid grid-cols-3 gap-2 text-xs text-white/70">
                                <label class="flex items-center gap-1"><input v-model="videoAutoplayValue" type="checkbox" @change="setVideoPlayback">Autoplay</label>
                                <label class="flex items-center gap-1"><input v-model="videoMutedValue" type="checkbox" @change="setVideoPlayback">Muted</label>
                                <label class="flex items-center gap-1"><input v-model="videoLoopValue" type="checkbox" @change="setVideoPlayback">Loop</label>
                            </div>

                            <p class="mt-3 text-xs font-semibold uppercase tracking-wider text-white/40">Video Assets</p>
                            <div class="mt-2 max-h-28 space-y-1 overflow-y-auto rounded-xl border border-white/10 bg-black/10 p-2">
                                <button
                                    v-for="asset in projectVideoAssets"
                                    :key="asset.id"
                                    class="block w-full truncate rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-left text-xs text-white/70 transition hover:border-white/30 hover:text-white"
                                    type="button"
                                    @click="useExistingVideoAsset(asset)"
                                >
                                    {{ asset.original_name }}
                                </button>
                                <p v-if="projectVideoAssets.length === 0" class="text-xs text-white/40">No video assets uploaded yet.</p>
                            </div>

                            <div v-if="videoUrlValue" class="mt-3 rounded-xl border border-white/10 bg-black/10 p-2">
                                <video
                                    v-if="selectedVideoElement.videoType === 'direct'"
                                    class="h-24 w-full rounded object-cover"
                                    :src="videoUrlValue"
                                    controls
                                    muted
                                />
                                <iframe
                                    v-else
                                    title="Video preview"
                                    class="h-24 w-full rounded"
                                    :src="videoUrlValue"
                                    allowfullscreen
                                />
                            </div>
                        </div>

                        <div v-if="selectedIconElement" class="mt-6 border-t border-white/10 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-white/40">Icon Picker</p>
                            <div class="mt-2 grid grid-cols-4 gap-1">
                                <button
                                    v-for="glyph in ICON_LIBRARY"
                                    :key="glyph"
                                    class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-lg text-white transition hover:bg-white/10"
                                    type="button"
                                    @click="setIconGlyph(glyph)"
                                >
                                    {{ glyph }}
                                </button>
                            </div>

                            <label class="mt-3 block text-xs text-white/60">Icon color</label>
                            <input v-model="iconColorValue" class="mt-1 h-8 w-full rounded-xl border border-white/15 bg-white/5" type="color" @change="setIconAppearance">

                            <label class="mt-3 block text-xs text-white/60">Icon size</label>
                            <input v-model.number="iconSizeValue" class="mt-1 w-full" type="range" min="24" max="160" step="2" @change="setIconAppearance">
                        </div>

                        <div v-if="selectedElement" class="mt-6 border-t border-white/10 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-white/40">Style Presets</p>
                            <div class="mt-2 space-y-1">
                                <button
                                    v-for="preset in STYLE_PRESETS"
                                    :key="preset.key"
                                    class="w-full rounded-xl border border-white/10 bg-white/5 px-2 py-1 text-left text-xs text-white/70 transition hover:border-white/30 hover:text-white"
                                    type="button"
                                    @click="applyStylePreset(preset)"
                                >
                                    {{ preset.label }}
                                </button>
                            </div>
                        </div>

                        <p class="mt-6 text-xs font-semibold uppercase tracking-wider text-white/40">Layers</p>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white/80 transition hover:bg-white/10" type="button" @click="bringForward">Bring +</button>
                            <button class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm text-white/80 transition hover:bg-white/10" type="button" @click="sendBackward">Send -</button>
                            <button class="col-span-2 rounded-xl border border-red-400/30 bg-red-500/10 px-3 py-2 text-sm text-red-300 transition hover:bg-red-500/20" type="button" @click="removeSelected">Delete Selected</button>
                        </div>

                        <div v-if="selectedTextElement" class="mt-6 border-t border-white/10 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-white/40">Rich Text</p>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <button class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10" type="button" @click="startTextEditMode(selectedTextElement.id)">
                                    {{ isEditingText ? 'Editing' : 'Edit Text' }}
                                </button>
                                <button class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10" type="button" @click="setTextAlign('left')">Left</button>
                                <button class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10" type="button" @click="setTextAlign('center')">Center</button>
                                <button class="rounded-xl border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10" type="button" @click="setTextAlign('right')">Right</button>
                            </div>

                            <label class="mt-3 block text-xs text-white/60">Font size</label>
                            <input v-model.number="fontSizeValue" class="mt-1 w-full" type="range" min="14" max="96" step="1" @change="setFontSize">

                            <label class="mt-3 block text-xs text-white/60">Text color</label>
                            <input v-model="textColor" class="mt-1 h-8 w-full rounded-xl border border-white/15 bg-white/5" type="color" @change="setTextColor">

                            <label class="mt-3 block text-xs text-white/60">Line spacing</label>
                            <input v-model.number="lineHeightValue" class="mt-1 w-full" type="range" min="1" max="2" step="0.1" @change="setLineHeight">

                            <label class="mt-3 block text-xs text-white/60">Indentation</label>
                            <input v-model.number="indentValue" class="mt-1 w-full" type="range" min="0" max="80" step="2" @change="setIndent">
                        </div>
                        </template>

                        <p v-if="!canEdit" class="mt-6 text-xs text-white/50">You have view-only access to this deck. Ask an editor or administrator if something needs changing.</p>
                        <p v-else class="mt-4 text-xs text-white/40">Tip: Shift+click for multi-select. Drag on empty space for marquee select.</p>
                        <p v-if="isDirty" class="mt-2 text-xs font-medium text-amber-300">Unsaved changes</p>
                        <p v-if="errorMessage" class="mt-2 text-xs font-medium text-red-300">{{ errorMessage }}</p>
                    </aside>

                    <section class="editor-canvas-shell rounded-2xl border border-white/10 bg-white/[0.03] p-4 shadow-2xl overflow-x-auto md:overflow-hidden">
                        <div ref="canvasViewportRef" class="relative mx-auto w-full">
                            <div class="relative mx-auto rounded-xl border border-white/20 bg-[#0f1323] p-2" :style="canvasOuterStyle">
                                <div class="relative overflow-hidden rounded-lg border border-white/10 bg-white" :style="canvasFrameStyle">
                                    <div class="absolute left-0 top-0" :style="canvasScaleStyle">
                            <v-stage
                                ref="stageRef"
                                :config="{ width: STAGE_WIDTH, height: STAGE_HEIGHT }"
                                @mousedown="onStageMouseDown"
                                @mousemove="onStageMouseMove"
                                @mouseup="onStageMouseUp"
                            >
                                <v-layer>
                                    <v-rect :config="{ x: 0, y: 0, width: STAGE_WIDTH, height: STAGE_HEIGHT, fill: '#ffffff' }" />

                                    <template v-for="element in elements" :key="element.id">
                                        <v-text
                                            v-if="element.type === 'text'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{
                                                id: element.id,
                                                x: element.x,
                                                y: element.y,
                                                width: element.width,
                                                height: element.height,
                                                text: textRenderValue(element),
                                                fontSize: element.fontSize ?? 32,
                                                fill: element.fill ?? '#111827',
                                                draggable: canEdit && editingTextElementId !== element.id,
                                                rotation: element.rotation ?? 0,
                                                fontStyle: element.fontStyle ?? 'normal',
                                                lineHeight: element.lineHeight ?? 1.3,
                                                align: element.textAlign ?? 'left',
                                                padding: element.textIndent ?? 0,
                                            }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @dblclick="() => startTextEditMode(element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dbltap="() => startTextEditMode(element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        />

                                        <v-group
                                            v-else-if="element.type === 'image'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{
                                                id: element.id,
                                                x: element.x,
                                                y: element.y,
                                                rotation: element.rotation ?? 0,
                                                draggable: canEdit,
                                            }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        >
                                            <v-rect :config="{ width: element.width, height: element.height, ...elementVisualConfig(element, { fill: '#f8fafc', stroke: '#475569', strokeWidth: 2 }), dash: element.dash ?? [8, 4], cornerRadius: 8 }" />
                                            <v-image
                                                v-if="imageNodeFor(element.id)"
                                                :config="{ ...imageRenderConfig(element), image: imageNodeFor(element.id) }"
                                            />
                                            <v-text
                                                v-else
                                                :config="{ text: 'Upload image', width: element.width, height: element.height, align: 'center', verticalAlign: 'middle', fill: '#334155', fontSize: 20, fontStyle: 'bold' }"
                                            />
                                        </v-group>

                                        <v-group
                                            v-else-if="element.type === 'group'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{
                                                id: element.id,
                                                x: element.x,
                                                y: element.y,
                                                rotation: element.rotation ?? 0,
                                                draggable: canEdit,
                                            }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        >
                                            <v-rect :config="{ width: element.width, height: element.height, fill: element.fill ?? '#eef2ff', stroke: element.stroke ?? '#4338ca', strokeWidth: element.strokeWidth ?? 2, dash: element.dash ?? [10, 5], cornerRadius: 10 }" />
                                            <v-text :config="{ text: 'Group Container', width: element.width, align: 'center', y: 8, fill: '#3730a3', fontSize: 16, fontStyle: 'bold' }" />
                                        </v-group>

                                        <v-group
                                            v-else-if="element.type === 'video'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{ id: element.id, x: element.x, y: element.y, rotation: element.rotation ?? 0, draggable: canEdit }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        >
                                            <v-rect :config="{ width: element.width, height: element.height, cornerRadius: 10, ...elementVisualConfig(element, { fill: '#111827', stroke: '#334155', strokeWidth: 2 }) }" />
                                            <v-text :config="{ text: element.videoUrl ? 'Video linked' : 'Add video URL or upload', width: element.width, height: element.height, align: 'center', verticalAlign: 'middle', fill: '#e2e8f0', fontSize: 18, fontStyle: 'bold' }" />
                                        </v-group>

                                        <v-text
                                            v-else-if="element.type === 'icon'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{
                                                id: element.id,
                                                x: element.x,
                                                y: element.y,
                                                width: element.width,
                                                height: element.height,
                                                text: element.icon ?? '★',
                                                align: 'center',
                                                verticalAlign: 'middle',
                                                fontSize: element.fontSize ?? 72,
                                                fill: element.fill ?? '#111827',
                                                draggable: canEdit,
                                                rotation: element.rotation ?? 0,
                                                opacity: element.opacity ?? 1,
                                                shadowColor: element.shadowColor,
                                                shadowBlur: element.shadowBlur ?? 0,
                                                shadowOpacity: element.shadowColor ? 0.5 : 0,
                                            }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        />

                                        <v-rect
                                            v-else-if="element.type === 'rect'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{
                                                id: element.id,
                                                x: element.x,
                                                y: element.y,
                                                width: element.width,
                                                height: element.height,
                                                ...elementVisualConfig(element, { fill: '#dbeafe', stroke: '#2563eb', strokeWidth: 2 }),
                                                cornerRadius: element.radius ?? 8,
                                                draggable: canEdit,
                                                rotation: element.rotation ?? 0,
                                            }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        />

                                        <v-group
                                            v-else-if="element.type === 'circle'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{
                                                id: element.id,
                                                x: element.x,
                                                y: element.y,
                                                draggable: canEdit,
                                                rotation: element.rotation ?? 0,
                                            }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        >
                                            <v-ellipse :config="{ x: element.width / 2, y: element.height / 2, radiusX: element.width / 2, radiusY: element.height / 2, ...elementVisualConfig(element, { fill: '#fee2e2', stroke: '#dc2626', strokeWidth: 2 }) }" />
                                        </v-group>

                                        <v-group
                                            v-else-if="element.type === 'line'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{ id: element.id, x: element.x, y: element.y, rotation: element.rotation ?? 0, draggable: canEdit }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        >
                                            <v-line :config="{ points: [0, element.height / 2, element.width, element.height / 2], ...elementVisualConfig(element, { fill: undefined, stroke: '#0f172a', strokeWidth: 4 }) }" />
                                        </v-group>

                                        <v-group
                                            v-else-if="element.type === 'arrow'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{ id: element.id, x: element.x, y: element.y, rotation: element.rotation ?? 0, draggable: canEdit }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        >
                                            <v-arrow :config="{ points: [0, element.height / 2, element.width, element.height / 2], ...elementVisualConfig(element, { fill: element.stroke ?? '#1d4ed8', stroke: '#1d4ed8', strokeWidth: 4 }), pointerLength: 16, pointerWidth: 16 }" />
                                        </v-group>

                                        <v-group
                                            v-else-if="element.type === 'polygon'"
                                            :ref="(el) => setNodeRef(el, element.id)"
                                            :config="{ id: element.id, x: element.x, y: element.y, rotation: element.rotation ?? 0, draggable: canEdit }"
                                            @click="(event) => onElementClick(event, element.id)"
                                            @tap="(event) => onElementClick(event, element.id)"
                                            @dragmove="(event) => onElementDragMove(event, element.id)"
                                            @dragend="(event) => onElementDragEnd(event, element.id)"
                                            @transformend="(event) => onElementTransformEnd(event, element.id)"
                                        >
                                            <v-line :config="{ points: [element.width / 2, 0, element.width, element.height, 0, element.height], closed: true, ...elementVisualConfig(element, { fill: '#dcfce7', stroke: '#15803d', strokeWidth: 2 }) }" />
                                        </v-group>
                                    </template>

                                    <v-rect
                                        v-if="selection.visible"
                                        :config="{
                                            x: selection.x,
                                            y: selection.y,
                                            width: selection.width,
                                            height: selection.height,
                                            fill: 'rgba(59,130,246,0.1)',
                                            stroke: '#3b82f6',
                                            strokeWidth: 1,
                                            dash: [4, 4],
                                        }"
                                    />

                                    <v-line
                                        v-if="guideX !== null"
                                        :config="{
                                            points: [guideX, 0, guideX, STAGE_HEIGHT],
                                            stroke: '#3b82f6',
                                            strokeWidth: 1,
                                            dash: [4, 4],
                                            listening: false,
                                        }"
                                    />

                                    <v-line
                                        v-if="guideY !== null"
                                        :config="{
                                            points: [0, guideY, STAGE_WIDTH, guideY],
                                            stroke: '#3b82f6',
                                            strokeWidth: 1,
                                            dash: [4, 4],
                                            listening: false,
                                        }"
                                    />

                                    <v-transformer
                                        ref="transformerRef"
                                        :config="{
                                            rotateEnabled: true,
                                            keepRatio: false,
                                            padding: 4,
                                            borderStroke: '#111827',
                                            anchorStroke: '#111827',
                                            anchorFill: '#ffffff',
                                            anchorSize: 8,
                                        }"
                                    />
                                </v-layer>
                            </v-stage>

                            <template v-for="person in collaborators" :key="`cursor-${person.user_id}`">
                                <div
                                    v-if="person.cursor"
                                    class="pointer-events-none absolute z-20"
                                    :style="{ left: `${person.cursor.x}px`, top: `${person.cursor.y}px` }"
                                >
                                    <div class="h-3 w-3 -translate-x-1 -translate-y-1 rounded-full border-2 border-white" :style="{ backgroundColor: person.color }" />
                                    <div class="mt-1 rounded px-1 py-0.5 text-[10px] font-medium text-white" :style="{ backgroundColor: person.color }">
                                        {{ person.name }}
                                    </div>
                                </div>
                            </template>

                            <div
                                v-if="isEditingText && textEditor"
                                class="absolute rounded-xl border-2 border-amber-400 bg-[#111827]/95 p-2 shadow-2xl"
                                :style="textEditorOverlayStyle"
                            >
                                <div class="mb-2 flex flex-wrap items-center gap-1 border-b border-white/10 pb-2">
                                    <button class="toolbar-btn" type="button" @click="applyEditorCommand((chain) => chain.toggleBold())">B</button>
                                    <button class="toolbar-btn italic" type="button" @click="applyEditorCommand((chain) => chain.toggleItalic())">I</button>
                                    <button class="toolbar-btn underline" type="button" @click="applyEditorCommand((chain) => chain.toggleUnderline())">U</button>
                                    <button class="toolbar-btn" type="button" @click="applyEditorCommand((chain) => chain.toggleBulletList())">• List</button>
                                    <button class="toolbar-btn" type="button" @click="applyEditorCommand((chain) => chain.toggleOrderedList())">1. List</button>
                                    <button class="toolbar-btn" type="button" @click="applyEditorCommand((chain) => chain.sinkListItem('listItem'))">Indent +</button>
                                    <button class="toolbar-btn" type="button" @click="applyEditorCommand((chain) => chain.liftListItem('listItem'))">Indent -</button>
                                    <button class="toolbar-btn" type="button" @click="stopTextEditMode(true)">Done</button>
                                </div>
                                <EditorContent :editor="textEditor" />
                                <p class="mt-2 text-[11px] text-white/50">Press Esc to exit text edit mode.</p>
                            </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.editor-page {
    background: radial-gradient(ellipse at top left, rgba(251, 191, 36, 0.08), transparent 42%), radial-gradient(ellipse at bottom right, rgba(249, 115, 22, 0.08), transparent 46%);
}

.editor-sidebar {
    scrollbar-width: thin;
    scrollbar-color: rgba(251, 191, 36, 0.4) rgba(255, 255, 255, 0.06);
}

.editor-element-btn {
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 0.75rem;
    background: rgba(255, 255, 255, 0.05);
    color: rgba(255, 255, 255, 0.86);
    padding: 0.55rem 0.7rem;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 120ms ease;
}

.editor-element-btn:hover {
    border-color: rgba(251, 191, 36, 0.45);
    background: linear-gradient(90deg, rgba(251, 191, 36, 0.16), rgba(249, 115, 22, 0.16));
    color: white;
}

.editor-element-btn:focus-visible {
    outline: 2px solid rgba(251, 191, 36, 0.7);
    outline-offset: 2px;
}

:deep(.rich-text-prosemirror) {
    min-height: 28px;
    outline: none;
    white-space: pre-wrap;
    word-break: break-word;
    color: rgb(243 244 246);
}

:deep(.rich-text-prosemirror p) {
    margin: 0;
}

:deep(.rich-text-prosemirror ul),
:deep(.rich-text-prosemirror ol) {
    margin: 0.25rem 0;
    padding-left: 1.1rem;
}

.toolbar-btn {
    border: 1px solid rgb(255 255 255 / 0.2);
    border-radius: 0.6rem;
    font-size: 0.75rem;
    line-height: 1rem;
    padding: 0.22rem 0.55rem;
    background: rgb(255 255 255 / 0.06);
    color: rgb(243 244 246);
}

.toolbar-btn:hover {
    background: rgb(255 255 255 / 0.14);
}

:deep(.editor-canvas-shell input[type='range']) {
    accent-color: #f59e0b;
}

:deep(.editor-canvas-shell input[type='checkbox']) {
    accent-color: #f59e0b;
}
</style>
