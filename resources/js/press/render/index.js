// Dot.Press slide renderer: the public surface.
//
// The renderer draws the presentation document (docs/programme/R40_DOCUMENT_SPEC.md) as DOM and inline SVG, never
// canvas, so text stays selectable, accessible and crisp at any size. Other code (the editor, the share viewer,
// the offline package) imports from this file or from the paths named here.
//
// Components
//   SlideView        render one slide. Props:
//                      slide    spec slide ({ id, bg, els, ... })
//                      deck     { w, h, theme }  (theme: spec section 3; a partial theme is completed)
//                      assets   { [assetId]: { url, thumb, w, h, mime, name } }  (spec section 9)
//                      mode     'edit' | 'view' | 'thumb' | 'present'
//                                 edit    every element drawn, hidden ones dimmed, no media is played
//                                 view    read only, links live, native video controls
//                                 thumb   cheap: no video elements, no embeds, no animation, content-visibility auto
//                                 present animation steps apply and media autoplays on `active`
//                      state    animation step visited (-1 initial, 0 entry group played, k clicks). Default: all
//                      active   present mode: this slide is on screen (starts autoplay)
//                      fill     fill the container height and letterbox. Default: width driven, deck aspect ratio
//                      allowEmbeds, eager
//                    Emits: link (slide id from a "#slide:<id>" link), scale (current scale factor).
//                    Expose: root, stage, scale, nodes() -> Map(elementId -> wrapper element).
//                    Every element is `div.p-el[data-eid]` (positioned, rotated) around `div.p-fx` (the animation
//                    layer, never positioned by the renderer) around the element component.
//   TextBox, ElText, ElShape, ElImage, ElVideo, ElAudio, ElIcon, ElChart, ElTable   the element components
//
// Pure helpers (no DOM, node tested)
//   theme.js     resolveColor, colorCss, resolveFont, resolveFill, resolveWeight, themeVars, themeStyle,
//                normalizeTheme, resolveBackground, DEFAULT_THEME
//   doc.js       docToHtml, docToPlain, docIsEmpty, docKey, safeHref, escapeHtml
//   fit.js       fitFactor, fitKey, fitCache
//   geometry.js  shapePath, lineGeometry, imagePlacement, normalizeCrop, maskClip, adjustFilter, svgGradientCoords
//   media.js     resolveSrc, embedInfo, safeUrl, slideAssetIds
//   table.js     tableModel
//   ../icons.js  ICONS, getIcon, iconSvg      ../fonts.js  FONTS, FONT_FAMILIES, ensureFonts
//
// Conventions fixed here where the spec is silent
//   line and arrow shapes run horizontally through the vertical centre of their box; rotate for other directions.
//   an image caption (`cap`) occupies the bottom of the element box, the picture fills the rest.
//   table `rows` is a full grid: every row holds a cell for every column, and a spanning cell's covered
//   neighbours are skipped.
//   transition `dir` is the side the new slide enters from; default "r".
//   charts are drawn by ../charts (chartToSvg with a css font-family stack as `font`, chartToTable, describeChart);
//   the chart CSS is added to the page once.

export { default as SlideView } from './SlideView.vue';
export { default as TextBox } from './TextBox.vue';
export { default as ElText } from './ElText.vue';
export { default as ElShape } from './ElShape.vue';
export { default as ElImage } from './ElImage.vue';
export { default as ElVideo } from './ElVideo.vue';
export { default as ElAudio } from './ElAudio.vue';
export { default as ElIcon } from './ElIcon.vue';
export { default as ElChart } from './ElChart.vue';
export { default as ElTable } from './ElTable.vue';

export * from './theme.js';
export * from './doc.js';
export * from './fit.js';
export * from './geometry.js';
export * from './media.js';
export * from './table.js';
export { reducedMotion, fontEpoch } from './env.js';
export { ICONS, ICON_IDS, getIcon, iconSvg } from '../icons.js';
export { FONTS, FONT_FAMILIES, ensureFonts } from '../fonts.js';
