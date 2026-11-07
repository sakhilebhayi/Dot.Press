// Focus trap helpers shared by Popover and Dialog.
const SEL = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
export const focusables = (root) => [...root.querySelectorAll(SEL)].filter((el) => el.offsetParent !== null || el === document.activeElement);
export function trapTab(e, root) {
  if (e.key !== 'Tab') return;
  const list = focusables(root);
  if (!list.length) { e.preventDefault(); root.focus(); return; }
  const first = list[0]; const last = list[list.length - 1];
  if (e.shiftKey && (document.activeElement === first || document.activeElement === root)) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}
