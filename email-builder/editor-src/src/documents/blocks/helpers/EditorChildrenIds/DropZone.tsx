import React, { useState } from 'react';

import { Box, Typography } from '@mui/material';

import { applyDrop } from '../../../editor/actions';
import { getDragging, setDragging } from '../../../editor/EditorContext';
import { TSlot } from '../../../editor/tree';

type Props = {
  slot: TSlot;
  index: number;
  // An empty slot: a large "Drop content here" area instead of a thin line.
  empty?: boolean;
};

/**
 * EDM: where a dragged block / layout lands (Brevo-style). Rendered between
 * blocks while something is being dragged (EditorChildrenIds); highlights
 * with a blue line while the pointer is over it.
 */
export default function DropZone({ slot, index, empty }: Props) {
  const [over, setOver] = useState(false);

  return (
    <Box
      onDragOver={(ev) => {
        if (!getDragging()) {
          return;
        }
        ev.preventDefault();
        ev.stopPropagation();
        ev.dataTransfer.dropEffect = getDragging()?.kind === 'move' ? 'move' : 'copy';
        if (!over) {
          setOver(true);
        }
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(ev) => {
        const payload = getDragging();
        ev.preventDefault();
        ev.stopPropagation();
        setOver(false);
        setDragging(null);
        if (payload) {
          applyDrop(payload, slot, index);
        }
      }}
      sx={
        empty
          ? {
              m: 1,
              py: 3,
              textAlign: 'center',
              border: '2px dashed',
              borderColor: over ? 'primary.main' : 'rgba(0,121,204,0.35)',
              borderRadius: 1,
              bgcolor: over ? 'rgba(0,121,204,0.08)' : 'rgba(0,121,204,0.03)',
            }
          : {
              position: 'relative',
              height: 14,
              my: '-7px',
              zIndex: 2,
              '&::after': {
                content: '""',
                position: 'absolute',
                left: 8,
                right: 8,
                top: 6,
                height: over ? 3 : 2,
                borderRadius: 2,
                bgcolor: over ? 'primary.main' : 'rgba(0,121,204,0.25)',
              },
            }
      }
    >
      {empty && (
        <Typography variant="caption" color={over ? 'primary.main' : 'text.secondary'}>
          Drop content here
        </Typography>
      )}
    </Box>
  );
}
