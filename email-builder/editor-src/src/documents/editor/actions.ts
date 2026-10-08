import { getDocument, resetDocument, setSelectedBlockId, TDragPayload } from './EditorContext';
import { findItem } from './library';
import { findSlot, insertBlocks, isWithin, moveBlock, TSlot } from './tree';

/**
 * EDM: a drop on a drop zone (EditorChildrenIds/DropZone) - a new block or
 * layout from the left panel, or an existing block moved by its handle. One
 * undo step; the dropped block ends up selected.
 */
export function applyDrop(payload: TDragPayload, slot: TSlot, index: number) {
  const doc = getDocument();
  if (payload.kind === 'move') {
    if (isWithin(doc, payload.blockId, slot.parentId)) {
      return; // a container cannot go inside itself
    }
    const next = moveBlock(doc, payload.blockId, slot, index);
    if (next !== doc) {
      resetDocument(next);
    }
    setSelectedBlockId(payload.blockId);
    return;
  }
  const item = findItem(payload.kind, payload.key);
  if (!item) {
    return;
  }
  const built = item.build();
  resetDocument(insertBlocks(doc, slot, index, built.rootId, built.blocks));
  setSelectedBlockId(built.rootId);
}

/**
 * A click on a left-panel tile: adds it below the selected block (in the
 * same slot), or at the end of the email when nothing is selected.
 */
export function addFromPanel(kind: 'block' | 'layout', key: string, selectedBlockId: string | null) {
  const doc = getDocument();
  const at = selectedBlockId && selectedBlockId !== 'root' ? findSlot(doc, selectedBlockId) : null;
  const slot: TSlot = at ? at.slot : { parentId: 'root' };
  const index = at ? at.index + 1 : Number.MAX_SAFE_INTEGER;
  applyDrop({ kind, key }, slot, index);
}
