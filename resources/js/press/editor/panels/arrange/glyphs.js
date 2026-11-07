/**
 * Glyphs the Text, Shape and Arrange panels need and the editor glyph set does not have (alignment, lists, marks, layer order).
 * Added to the shared GLYPHS table at import time under a `p-` prefix so the usual <Ico name="p-alignL"> works and nothing in
 * glyphs.js has to change. Same grid as the rest: 24 x 24, stroke only, round caps.
 */
import { GLYPHS } from '../../ui/glyphs.js';

const lines = (...ys) => ys.map(([y, x1, x2]) => `M${x1} ${y}H${x2}`);

export const PANEL_GLYPHS = {
  // horizontal alignment of text
  'p-alignL': lines([6, 4, 20], [10, 4, 14], [14, 4, 20], [18, 4, 14]),
  'p-alignC': lines([6, 4, 20], [10, 7, 17], [14, 4, 20], [18, 7, 17]),
  'p-alignR': lines([6, 4, 20], [10, 10, 20], [14, 4, 20], [18, 10, 20]),
  'p-alignJ': lines([6, 4, 20], [10, 4, 20], [14, 4, 20], [18, 4, 14]),
  // vertical alignment of text in its box
  'p-vTop': ['M4 4.5H20', 'M9 9H15', 'M9 13H15'],
  'p-vMid': ['M4 12H20', 'M9 7H15', 'M9 17H15'],
  'p-vBot': ['M4 19.5H20', 'M9 11H15', 'M9 15H15'],
  // lists
  'p-bullets': ['F:M5.6 7a1.3 1.3 0 1 0 .01 0Z', 'F:M5.6 12a1.3 1.3 0 1 0 .01 0Z', 'F:M5.6 17a1.3 1.3 0 1 0 .01 0Z', 'M10 7H20', 'M10 12H20', 'M10 17H20'],
  'p-numbers': ['M4.5 5.5l1.2-.8V9.5', 'M4.2 14h2.6l-2.6 3.5h2.8', 'M10 7H20', 'M10 12H20', 'M10 17H20'],
  'p-indent': ['M4 5H20', 'M12 9.5H20', 'M12 14.5H20', 'M4 19H20', 'M4 9.5l3.5 2.5L4 14.5Z'],
  'p-outdent': ['M4 5H20', 'M12 9.5H20', 'M12 14.5H20', 'M4 19H20', 'M8 9.5L4.5 12 8 14.5Z'],
  // marks
  'p-bold': ['M7 4.5h6a3.4 3.4 0 0 1 0 6.8H7Z', 'M7 11.3h7a3.6 3.6 0 0 1 0 7.2H7Z'],
  'p-italic': ['M10 4.5H18', 'M6 19.5H14', 'M15 4.5L9 19.5'],
  'p-underline': ['M7 4.5V11a5 5 0 0 0 10 0V4.5', 'M5 20H19'],
  'p-strike': ['M16.5 8A4.5 3 0 0 0 12 5.5c-2.5 0-4.5 1.2-4.5 3S10 11 12 12', 'M12 12c2 .5 4.5 1.2 4.5 3.5S14.5 19 12 19a5 3 0 0 1-4.7-2.5', 'M4 12H20'],
  'p-link': ['M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1', 'M14 10a4 4 0 0 0-5.7 0l-3 3A4 4 0 0 0 11 18.7l1-1'],
  'p-unlink': ['M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1', 'M14 10a4 4 0 0 0-5.7 0l-3 3A4 4 0 0 0 11 18.7l1-1', 'M4 4l16 16'],
  'p-fx-outline': ['M7 19.5V8a5 5 0 0 1 10 0v11.5', 'M7 14H17'],
  'p-fx-mark': ['M4 16.5l9-9 4 4-9 9H4Z', 'M12 8.5l3.5 3.5', 'M14 20H20'],
  'p-fit': ['M5 9V5h4', 'M19 9V5h-4', 'M5 15v4h4', 'M19 15v4h-4', 'M9.5 15L12 8.5 14.5 15', 'M10.3 13h3.4'],
  // arrange: align (to a line)
  'p-aL': ['M4 3.5V20.5', 'M8 6.5H20', 'M8 13.5H15'],
  'p-aCh': ['M12 3.5V20.5', 'M6 6.5H18', 'M8.5 13.5H15.5'],
  'p-aR': ['M20 3.5V20.5', 'M4 6.5H16', 'M9 13.5H16'],
  'p-aT': ['M3.5 4H20.5', 'M6.5 8V20', 'M13.5 8V15'],
  'p-aMv': ['M3.5 12H20.5', 'M6.5 6V18', 'M13.5 8.5V15.5'],
  'p-aB': ['M3.5 20H20.5', 'M6.5 4V16', 'M13.5 9V16'],
  'p-distH': ['M4 4V20', 'M20 4V20', 'M9.5 7.5h5v9h-5Z'],
  'p-distV': ['M4 4H20', 'M4 20H20', 'M7.5 9.5v5h9v-5Z'],
  'p-distC': ['M4 4V20', 'M20 4V20', 'M12 6V18'],
  'p-matchW': ['M4 6V18', 'M20 6V18', 'M7 12H17', 'M9.5 9.5L7 12l2.5 2.5', 'M14.5 9.5L17 12l-2.5 2.5'],
  'p-matchH': ['M6 4H18', 'M6 20H18', 'M12 7V17', 'M9.5 9.5L12 7l2.5 2.5', 'M9.5 14.5L12 17l2.5-2.5'],
  'p-group': ['M3.5 3.5h6v6h-6Z', 'M14.5 14.5h6v6h-6Z', 'M7 14.5H4a.5.5 0 0 0-.5.5v5.5', 'M17 9.5h3.5'],
  'p-ungroup': ['M3.5 3.5h7v7h-7Z', 'M13.5 13.5h7v7h-7Z'],
  'p-lock': ['M6 11h12v9H6Z', 'M8.5 11V8a3.5 3.5 0 0 1 7 0v3'],
  'p-unlock': ['M6 11h12v9H6Z', 'M8.5 11V8a3.5 3.5 0 0 1 6.8-1.2'],
  'p-flipH': ['M12 4V20', 'M9 7L3.5 18H9Z', 'M15 7l5.5 11H15Z'],
  'p-flipV': ['M4 12H20', 'M7 9L18 3.5V9Z', 'M7 15l11 5.5V15Z'],
  'p-front': ['M8.5 8.5h12v12h-12Z', 'M3.5 3.5h8v5', 'M3.5 3.5v8h5'],
  'p-forward': ['M9 9h11v11H9Z', 'M4 14V4h10'],
  'p-backward': ['M4 4h11v11H4Z', 'M20 10v10H10'],
  'p-back': ['M3.5 3.5h12v12h-12Z', 'M20.5 12.5v8h-8v-5', 'M20.5 20.5h-5'],
  'p-copyStyle': ['M9 9h11v11H9Z', 'M5 15V5h10', 'M12.5 14.5h4', 'M12.5 17.5h2.5'],
  'p-pasteStyle': ['M7 5h10v3H7Z', 'M5 7.5v13h14v-13', 'M9 13h6', 'M9 16.5h4'],
  'p-rename': ['M4 20h4l11-11-4-4L4 16Z', 'M13 7l4 4'],
  'p-chev': ['M9 5l7 7-7 7'],
  // line ends and dashes
  'p-endNone': ['M4 12H20'],
  'p-endArrow': ['M4 12H20', 'M14 6l6 6-6 6'],
  'p-startArrow': ['M20 12H4', 'M10 6l-6 6 6 6'],
  'p-startDot': ['M9 12H20', 'F:M8.5 12a3 3 0 1 0 -6 0a3 3 0 1 0 6 0Z'],
  'p-endDot': ['M4 12H15', 'F:M15.5 12a3 3 0 1 0 .01 0Z'],
  'p-dashSolid': ['M3 12H21'],
  'p-dashDash': ['M3 12H8', 'M10 12H14', 'M16 12H21'],
  'p-dashDot': ['M3 12H4', 'M8 12H9', 'M13 12H14', 'M18 12H19', 'M21 12H22'],
};

Object.assign(GLYPHS, PANEL_GLYPHS);
export default PANEL_GLYPHS;
