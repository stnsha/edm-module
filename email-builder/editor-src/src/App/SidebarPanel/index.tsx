import React, { useState } from 'react';

import { ArrowBackOutlined } from '@mui/icons-material';
import { Box, Button, Drawer, Tab, Tabs, Tooltip, Typography } from '@mui/material';

import { addFromPanel } from '../../documents/editor/actions';
import {
  setDragging,
  setSelectedBlockId,
  useDocument,
  useSelectedBlockId,
} from '../../documents/editor/EditorContext';
import { BLOCKS, LAYOUTS } from '../../documents/editor/library';
import { VariablesBox } from '../../variables';
import ConfigurationPanel from '../InspectorDrawer/ConfigurationPanel';
import StylesPanel from '../InspectorDrawer/StylesPanel';

/**
 * EDM: Brevo-style left panel (replaces upstream's right-hand inspector).
 *
 *   nothing selected  tabs Content (block tiles), Layouts (ready-made rows),
 *                     Email (global email styles); drag a tile onto the
 *                     email, or click it to add it below the selected block
 *                     / at the end
 *   block selected    that block's settings, with Done to go back
 */
export const SIDEBAR_WIDTH = 300;

// Blocks whose text fields accept personalisation variables.
const VARIABLE_BLOCKS = ['Text', 'Heading', 'Button', 'Html'];

const TYPE_LABELS: Record<string, string> = {
  Heading: 'Title',
  Text: 'Text',
  Image: 'Image',
  Button: 'Button',
  Html: 'HTML',
  Divider: 'Divider',
  Spacer: 'Spacer',
  Avatar: 'Avatar',
  Container: 'Container',
  ColumnsContainer: 'Columns',
};

function dragProps(kind: 'block' | 'layout', key: string) {
  return {
    draggable: true,
    onDragStart: (ev: React.DragEvent) => {
      ev.dataTransfer.setData('text/plain', 'edm-' + kind);
      ev.dataTransfer.effectAllowed = 'copy';
      // After the drag image is taken: showing the drop zones re-renders the canvas.
      setTimeout(() => setDragging({ kind, key }), 0);
    },
    onDragEnd: () => setDragging(null),
  };
}

export default function SidebarPanel() {
  const [tab, setTab] = useState<'content' | 'layouts' | 'email'>('content');
  const document = useDocument();
  const selectedBlockId = useSelectedBlockId();
  const selected = selectedBlockId && selectedBlockId !== 'root' ? document[selectedBlockId] : undefined;

  const tileSx = {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 0.5,
    height: 76,
    p: 1,
    border: '1px solid',
    borderColor: 'divider',
    borderRadius: 1,
    bgcolor: 'background.paper',
    cursor: 'grab',
    userSelect: 'none',
    fontSize: 12,
    color: 'text.primary',
    '&:hover': { borderColor: 'primary.main', color: 'primary.main', boxShadow: 1 },
    '& svg': { fontSize: 26 },
  } as const;

  const renderBlockSettings = () => (
    <>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 1.5, height: 49, borderBottom: 1, borderColor: 'divider' }}>
        <Button size="small" startIcon={<ArrowBackOutlined fontSize="small" />} onClick={() => setSelectedBlockId(null)}>
          Done
        </Button>
        <Typography variant="subtitle2" sx={{ ml: 'auto', color: 'text.secondary' }}>
          {TYPE_LABELS[selected?.type ?? ''] ?? selected?.type} settings
        </Typography>
      </Box>
      <Box sx={{ height: 'calc(100% - 49px)', overflow: 'auto' }}>
        {VARIABLE_BLOCKS.includes(selected?.type ?? '') && <VariablesBox canInsert />}
        <ConfigurationPanel />
      </Box>
    </>
  );

  const renderLibrary = () => (
    <>
      <Box sx={{ px: 1, height: 49, borderBottom: 1, borderColor: 'divider' }}>
        <Tabs value={tab} onChange={(_, v) => setTab(v)} variant="fullWidth">
          <Tab value="content" label="Content" />
          <Tab value="layouts" label="Layouts" />
          <Tab value="email" label="Email" />
        </Tabs>
      </Box>
      <Box sx={{ height: 'calc(100% - 49px)', overflow: 'auto' }}>
        {tab === 'content' && (
          <Box sx={{ p: 2 }}>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              Drag a block onto the email, or click it to add it at the end.
            </Typography>
            <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 1 }}>
              {BLOCKS.map((b) => (
                <Tooltip key={b.key} title={'Drag or click to add ' + b.label.toLowerCase()} placement="top" enterDelay={600}>
                  <Box
                    component="button"
                    type="button"
                    {...dragProps('block', b.key)}
                    onClick={() => addFromPanel('block', b.key, selectedBlockId)}
                    sx={tileSx}
                  >
                    {b.icon}
                    {b.label}
                  </Box>
                </Tooltip>
              ))}
            </Box>
            <Box sx={{ mt: 2 }}>
              <VariablesBox canInsert={false} />
            </Box>
          </Box>
        )}
        {tab === 'layouts' && (
          <Box sx={{ p: 2 }}>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
              Ready-made rows with content already in place. Drag one onto the email, then edit it.
            </Typography>
            <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 1 }}>
              {LAYOUTS.map((l) => (
                <Box
                  key={l.key}
                  component="button"
                  type="button"
                  {...dragProps('layout', l.key)}
                  onClick={() => addFromPanel('layout', l.key, selectedBlockId)}
                  sx={{ ...tileSx, height: 96, gap: 1 }}
                >
                  <Box sx={{ width: '100%', px: 0.5, display: 'flex', justifyContent: 'center' }}>{l.sketch}</Box>
                  <span>{l.label}</span>
                </Box>
              ))}
            </Box>
          </Box>
        )}
        {tab === 'email' && <StylesPanel />}
      </Box>
    </>
  );

  return (
    <Drawer
      variant="permanent"
      anchor="left"
      sx={{ width: SIDEBAR_WIDTH, '& .MuiDrawer-paper': { width: SIDEBAR_WIDTH, boxSizing: 'border-box', bgcolor: '#fafbfc' } }}
    >
      {selected ? renderBlockSettings() : renderLibrary()}
    </Drawer>
  );
}
