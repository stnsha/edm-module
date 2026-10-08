import React, { Fragment } from 'react';

import { TEditorBlock } from '../../../editor/core';
import EditorBlock from '../../../editor/EditorBlock';
import { useDragging } from '../../../editor/EditorContext';
import { newBlockId, TSlot } from '../../../editor/tree';

import AddBlockButton from './AddBlockMenu';
import DropZone from './DropZone';

export type EditorChildrenChange = {
  blockId: string;
  block: TEditorBlock;
  childrenIds: string[];
};

export type EditorChildrenIdsProps = {
  childrenIds: string[] | null | undefined;
  onChange: (val: EditorChildrenChange) => void;
  // EDM: which children list this is, for drag and drop (DropZone).
  slot: TSlot;
};
export default function EditorChildrenIds({ childrenIds, onChange, slot }: EditorChildrenIdsProps) {
  const dragging = useDragging();

  const appendBlock = (block: TEditorBlock) => {
    const blockId = newBlockId();
    return onChange({
      blockId,
      block,
      childrenIds: [...(childrenIds || []), blockId],
    });
  };

  const insertBlock = (block: TEditorBlock, index: number) => {
    const blockId = newBlockId();
    const newChildrenIds = [...(childrenIds || [])];
    newChildrenIds.splice(index, 0, blockId);
    return onChange({
      blockId,
      block,
      childrenIds: newChildrenIds,
    });
  };

  // While something is dragged, drop zones replace the "+" buttons.
  if (!childrenIds || childrenIds.length === 0) {
    return dragging ? <DropZone slot={slot} index={0} empty /> : <AddBlockButton placeholder onSelect={appendBlock} />;
  }

  return (
    <>
      {childrenIds.map((childId, i) => (
        <Fragment key={childId}>
          {dragging ? <DropZone slot={slot} index={i} /> : <AddBlockButton onSelect={(block) => insertBlock(block, i)} />}
          <EditorBlock id={childId} />
        </Fragment>
      ))}
      {dragging ? <DropZone slot={slot} index={childrenIds.length} /> : <AddBlockButton onSelect={appendBlock} />}
    </>
  );
}
