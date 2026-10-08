import React, { useEffect } from 'react';

import { Stack } from '@mui/material';

import { redo, setDragging, undo } from '../documents/editor/EditorContext';

import SidebarPanel, { SIDEBAR_WIDTH } from './SidebarPanel';
import TemplatePanel from './TemplatePanel';

// EDM: upstream's left-hand SamplesDrawer (Waypoint sample gallery) is removed,
// and the right-hand inspector is replaced by a Brevo-style left panel
// (SidebarPanel: block tiles, layouts, email styles, or the selected block's
// settings).

/** Ctrl/Cmd+Z undo, Ctrl/Cmd+Shift+Z or Ctrl+Y redo - outside text fields. */
function useUndoKeys() {
  useEffect(() => {
    const onKey = (ev: KeyboardEvent) => {
      if (!(ev.ctrlKey || ev.metaKey)) {
        return;
      }
      const t = ev.target as HTMLElement | null;
      if (t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) {
        return; // the field's own undo
      }
      const key = ev.key.toLowerCase();
      if (key === 'z' && !ev.shiftKey) {
        ev.preventDefault();
        undo();
      } else if ((key === 'z' && ev.shiftKey) || key === 'y') {
        ev.preventDefault();
        redo();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);
}

/**
 * Drop zones show while something is dragged (EditorContext dragging). A drag
 * that ends anywhere - dropped outside a zone, cancelled with Esc, or its
 * source re-rendered away - must still hide them.
 */
function useDragReset() {
  useEffect(() => {
    const reset = () => setDragging(null);
    const onKey = (ev: KeyboardEvent) => {
      if (ev.key === 'Escape') {
        reset();
      }
    };
    window.addEventListener('dragend', reset);
    window.addEventListener('drop', reset);
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('dragend', reset);
      window.removeEventListener('drop', reset);
      window.removeEventListener('keydown', onKey);
    };
  }, []);
}

export default function App() {
  useUndoKeys();
  useDragReset();

  return (
    <>
      <SidebarPanel />
      <Stack sx={{ marginLeft: `${SIDEBAR_WIDTH}px` }}>
        <TemplatePanel />
      </Stack>
    </>
  );
}
