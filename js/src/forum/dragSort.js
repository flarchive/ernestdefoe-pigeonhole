import reorder from './reorder';

/**
 * Drag to reorder, by mouse or finger, without moving any element Mithril
 * owns: the dragged row and the rows it passes are shifted with transforms,
 * and the new order is handed to `onsort` once it is let go.
 *
 * Call from the row's (or its handle's) pointerdown.
 *
 * @param {PointerEvent} e
 * @param {{ row: Element, rows: Element[], ids: string[], onsort: (ids: string[]) => void, axis?: 'x' | 'y', mouseOnly?: boolean }} options
 */
export default function dragSort(e, { row, rows, ids, onsort, axis = 'y', mouseOnly = false }) {
  if (e.button !== 0 || (mouseOnly && e.pointerType !== 'mouse')) return;

  const from = rows.indexOf(row);
  if (from === -1 || rows.length < 2) return;

  const x = axis === 'x';
  const start = x ? e.clientX : e.clientY;
  const begin = (rect) => (x ? rect.left : rect.top);
  const size = (rect) => (x ? rect.width : rect.height);
  const target = e.currentTarget;
  const rects = rows.map((el) => el.getBoundingClientRect());
  const length = size(rects[from]);
  let to = from;
  let dragging = false;

  const move = (event) => {
    const delta = (x ? event.clientX : event.clientY) - start;

    if (!dragging) {
      if (Math.abs(delta) < 5) return;
      dragging = true;
      row.classList.add('is-dragging');
    }

    event.preventDefault();

    const middle = begin(rects[from]) + size(rects[from]) / 2 + delta;
    to = rects.filter((rect, index) => index !== from && begin(rect) + size(rect) / 2 < middle).length;

    const shift = (px) => (x ? `translateX(${px}px)` : `translateY(${px}px)`);

    rows.forEach((el, index) => {
      if (index === from) el.style.transform = shift(delta);
      else if (from < to && index > from && index <= to) el.style.transform = shift(-length);
      else if (from > to && index >= to && index < from) el.style.transform = shift(length);
      else el.style.transform = '';
    });
  };

  const end = () => {
    target.removeEventListener('pointermove', move);
    target.removeEventListener('pointerup', end);
    target.removeEventListener('pointercancel', end);
    rows.forEach((el) => (el.style.transform = ''));
    row.classList.remove('is-dragging');

    if (!dragging) return;

    // A drag is not a click on what it started on. The click, if the
    // browser sends one, comes before any timer does.
    const swallow = (event) => {
      event.stopPropagation();
      event.preventDefault();
    };
    window.addEventListener('click', swallow, { capture: true, once: true });
    setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 0);

    if (to !== from) onsort(reorder(ids, from, to));
  };

  target.setPointerCapture?.(e.pointerId);
  target.addEventListener('pointermove', move);
  target.addEventListener('pointerup', end);
  target.addEventListener('pointercancel', end);
}
