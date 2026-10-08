import { TEditorBlock, TEditorConfiguration } from './core';

/**
 * EDM: where blocks live in the document tree, for drag and drop and the
 * left-panel "add" actions. A slot is a children list: the email body
 * (EmailLayout childrenIds), a Container's props.childrenIds, or one column
 * of a ColumnsContainer (props.columns[column].childrenIds).
 */
export type TSlot = { parentId: string; column?: number };

let seq = 0;
export function newBlockId() {
  seq += 1;
  return `block-${Date.now()}-${seq}-${Math.floor(Math.random() * 1000)}`;
}

export function slotChildren(doc: TEditorConfiguration, slot: TSlot): string[] {
  const parent = doc[slot.parentId];
  if (!parent) {
    return [];
  }
  switch (parent.type) {
    case 'EmailLayout':
      return parent.data.childrenIds ?? [];
    case 'Container':
      return parent.data.props?.childrenIds ?? [];
    case 'ColumnsContainer':
      return parent.data.props?.columns?.[slot.column ?? 0]?.childrenIds ?? [];
    default:
      return [];
  }
}

function withSlotChildren(doc: TEditorConfiguration, slot: TSlot, ids: string[]): TEditorConfiguration {
  const parent = doc[slot.parentId];
  let next: TEditorBlock;
  switch (parent.type) {
    case 'EmailLayout':
      next = { ...parent, data: { ...parent.data, childrenIds: ids } };
      break;
    case 'Container':
      next = { ...parent, data: { ...parent.data, props: { ...parent.data.props, childrenIds: ids } } };
      break;
    case 'ColumnsContainer': {
      const current = parent.data.props?.columns ?? [{ childrenIds: [] }, { childrenIds: [] }, { childrenIds: [] }];
      const columns = current.map((c, i) =>
        i === (slot.column ?? 0) ? { childrenIds: ids } : { childrenIds: [...c.childrenIds] }
      );
      // columns is a fixed 3-tuple in the schema; map() widens it to an array.
      next = { ...parent, data: { ...parent.data, props: { ...parent.data.props, columns } } } as unknown as TEditorBlock;
      break;
    }
    default:
      return doc;
  }
  return { ...doc, [slot.parentId]: next };
}

/** Every slot of a block (one per column for ColumnsContainer). */
function slotsOf(doc: TEditorConfiguration, id: string): TSlot[] {
  const b = doc[id];
  if (!b) {
    return [];
  }
  if (b.type === 'ColumnsContainer') {
    return [0, 1, 2].map((column) => ({ parentId: id, column }));
  }
  if (b.type === 'Container' || b.type === 'EmailLayout') {
    return [{ parentId: id }];
  }
  return [];
}

/** The slot holding blockId and its index there, or null. */
export function findSlot(doc: TEditorConfiguration, blockId: string): { slot: TSlot; index: number } | null {
  for (const id of Object.keys(doc)) {
    for (const slot of slotsOf(doc, id)) {
      const index = slotChildren(doc, slot).indexOf(blockId);
      if (index !== -1) {
        return { slot, index };
      }
    }
  }
  return null;
}

/** True when id is ancestorId itself or anywhere inside it. */
export function isWithin(doc: TEditorConfiguration, ancestorId: string, id: string): boolean {
  if (ancestorId === id) {
    return true;
  }
  return slotsOf(doc, ancestorId).some((slot) => slotChildren(doc, slot).some((child) => isWithin(doc, child, id)));
}

/** Adds blocks (rootId first in the slot, the rest are its descendants). */
export function insertBlocks(
  doc: TEditorConfiguration,
  slot: TSlot,
  index: number,
  rootId: string,
  blocks: Record<string, TEditorBlock>
): TEditorConfiguration {
  const withBlocks = { ...doc, ...blocks } as TEditorConfiguration;
  const ids = [...slotChildren(withBlocks, slot)];
  ids.splice(Math.max(0, Math.min(index, ids.length)), 0, rootId);
  return withSlotChildren(withBlocks, slot, ids);
}

/** Moves an existing block to slot / index (index counted before removal). */
export function moveBlock(doc: TEditorConfiguration, blockId: string, slot: TSlot, index: number): TEditorConfiguration {
  const from = findSlot(doc, blockId);
  if (!from || isWithin(doc, blockId, slot.parentId)) {
    return doc;
  }
  let target = index;
  const sameSlot = from.slot.parentId === slot.parentId && (from.slot.column ?? 0) === (slot.column ?? 0);
  if (sameSlot && from.index < index) {
    target -= 1;
  }
  if (sameSlot && target === from.index) {
    return doc;
  }
  const removed = slotChildren(doc, from.slot).filter((id) => id !== blockId);
  const without = withSlotChildren(doc, from.slot, removed);
  const ids = [...slotChildren(without, slot)];
  ids.splice(Math.max(0, Math.min(target, ids.length)), 0, blockId);
  return withSlotChildren(without, slot, ids);
}
