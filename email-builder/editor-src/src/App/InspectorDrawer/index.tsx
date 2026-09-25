import React from 'react';

import { Box, Drawer, Tab, Tabs } from '@mui/material';

import {
  setSidebarTab,
  useDocument,
  useInspectorDrawerOpen,
  useSelectedBlockId,
  useSelectedSidebarTab,
} from '../../documents/editor/EditorContext';
import { VariablesBox } from '../../variables';

import ConfigurationPanel from './ConfigurationPanel';
import StylesPanel from './StylesPanel';

export const INSPECTOR_DRAWER_WIDTH = 320;

// EDM: blocks whose text fields accept personalisation variables.
const VARIABLE_BLOCKS = ['Text', 'Heading', 'Button', 'Html'];

export default function InspectorDrawer() {
  const selectedSidebarTab = useSelectedSidebarTab();
  const inspectorDrawerOpen = useInspectorDrawerOpen();
  const document = useDocument();
  const selectedBlockId = useSelectedBlockId();
  const selectedType = selectedBlockId ? document[selectedBlockId]?.type : undefined;
  const canInsert = !!selectedType && VARIABLE_BLOCKS.includes(selectedType);

  const renderCurrentSidebarPanel = () => {
    switch (selectedSidebarTab) {
      case 'block-configuration':
        return <ConfigurationPanel />;
      case 'styles':
        return <StylesPanel />;
    }
  };

  return (
    <Drawer
      variant="persistent"
      anchor="right"
      open={inspectorDrawerOpen}
      sx={{
        width: inspectorDrawerOpen ? INSPECTOR_DRAWER_WIDTH : 0,
      }}
    >
      <Box sx={{ width: INSPECTOR_DRAWER_WIDTH, height: 49, borderBottom: 1, borderColor: 'divider' }}>
        <Box px={2}>
          <Tabs value={selectedSidebarTab} onChange={(_, v) => setSidebarTab(v)}>
            <Tab value="styles" label="Styles" />
            <Tab value="block-configuration" label="Inspect" />
          </Tabs>
        </Box>
      </Box>
      <Box sx={{ width: INSPECTOR_DRAWER_WIDTH, height: 'calc(100% - 49px)', overflow: 'auto' }}>
        {/* EDM: always visible so users can find their custom fields. */}
        <VariablesBox canInsert={canInsert} />
        {renderCurrentSidebarPanel()}
      </Box>
    </Drawer>
  );
}
