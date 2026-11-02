/** `ids` with the one at `from` moved to `to`. */
export default function reorder(ids, from, to) {
  const order = ids.slice();
  order.splice(to, 0, order.splice(from, 1)[0]);

  return order;
}
