import React from 'react';
import { create } from 'zustand';

import { Box, Chip, Stack, Typography } from '@mui/material';

/**
 * EDM: personalisation variables (merge tags) resolved at send time.
 *
 * The list is pushed in by the parent page with the 'load' message (see
 * bridge.ts), so it is defined once in email-builder.js. A chip can be dragged
 * into any text field (native text drop), or clicked to insert at the caret of
 * the field that last had focus.
 */
export type TVariable = { token: string; label: string };

const variablesStore = create<{ variables: TVariable[] }>(() => ({ variables: [] }));

export function setVariables(variables: TVariable[]) {
  variablesStore.setState({ variables });
}

// The text field that last had focus; set by TextInput.
type TActiveInput = { insert: (token: string) => void };
let activeInput: TActiveInput | null = null;

export function setActiveInput(input: TActiveInput | null) {
  activeInput = input;
}

export function clearActiveInput(input: TActiveInput) {
  if (activeInput === input) {
    activeInput = null;
  }
}

// canInsert: a Text / Heading / Button / Html block is selected, so there is
// text (on the canvas or in the Inspect panel) to drag into or click-insert into.
export function VariablesBox({ canInsert }: { canInsert: boolean }) {
  const variables = variablesStore((s) => s.variables);
  if (variables.length === 0) {
    return (
      <Box sx={{ mx: 2, mt: 2, p: 1.5, border: '1px dashed', borderColor: 'divider', borderRadius: 1 }}>
        <Typography variant="overline" color="text.secondary" sx={{ display: 'block', lineHeight: 1.6 }}>
          Personalisation
        </Typography>
        <Typography variant="caption" color="text.secondary">
          No variables yet. Add fields under Contacts &gt; Custom fields.
        </Typography>
      </Box>
    );
  }
  return (
    <Box sx={{ mx: 2, mt: 2, p: 1.5, border: '1px dashed', borderColor: 'divider', borderRadius: 1 }}>
      <Typography variant="overline" color="text.secondary" sx={{ display: 'block', lineHeight: 1.6 }}>
        Personalisation
      </Typography>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
        {canInsert
          ? 'Drag a variable into the text, or click in the text and then click a variable.'
          : 'Select a Text, Heading, Button or Html block, then drag a variable into its text or click to insert.'}
      </Typography>
      <Stack direction="row" flexWrap="wrap" gap={0.75}>
        {variables.map((v) => (
          <Chip
            key={v.token}
            label={v.label}
            title={v.token}
            size="small"
            variant="outlined"
            color="primary"
            draggable
            onDragStart={(ev) => {
              ev.dataTransfer.setData('text/plain', v.token);
              ev.dataTransfer.effectAllowed = 'copy';
            }}
            // Keep focus (and caret) in the text field while clicking the chip.
            onMouseDown={(ev) => ev.preventDefault()}
            onClick={() => activeInput?.insert(v.token)}
            sx={{ cursor: 'grab' }}
          />
        ))}
      </Stack>
    </Box>
  );
}
