/**
 * The style clipboard: one remembered look, shared by every Arrange panel in the page (it survives moving between slides but not a
 * reload, on purpose: it is a convenience, not content).
 */
import { shallowRef } from 'vue';

export const styleClip = shallowRef(null);
export const setStyleClip = (style) => { styleClip.value = style; };
