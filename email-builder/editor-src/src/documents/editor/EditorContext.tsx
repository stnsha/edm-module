import { create } from 'zustand';

import getConfiguration from '../../getConfiguration';

import { TEditorConfiguration } from './core';

/**
 * EDM: what is being dragged in the editor - a new block or layout from the
 * left panel (src/App/SidebarPanel) or an existing block by its drag handle
 * (TuneMenu). Drop zones (EditorChildrenIds/DropZone) only show while set.
 */
export type TDragPayload =
  | { kind: 'block'; key: string }
  | { kind: 'layout'; key: string }
  | { kind: 'move'; blockId: string };

type TValue = {
  document: TEditorConfiguration;

  selectedBlockId: string | null;
  selectedSidebarTab: 'block-configuration' | 'styles';
  selectedMainTab: 'editor' | 'preview' | 'json' | 'html';
  selectedScreenSize: 'desktop' | 'mobile';

  inspectorDrawerOpen: boolean;

  // EDM: Brevo-style editing
  dragging: TDragPayload | null;
  canUndo: boolean;
  canRedo: boolean;
};

const editorStateStore = create<TValue>(() => ({
  document: getConfiguration(),
  selectedBlockId: null,
  selectedSidebarTab: 'styles',
  selectedMainTab: 'editor',
  selectedScreenSize: 'desktop',

  inspectorDrawerOpen: true,

  dragging: null,
  canUndo: false,
  canRedo: false,
}));

/**
 * EDM: undo / redo. Every edit (setDocument / resetDocument) pushes the
 * previous document; edits to the same block(s) within COALESCE_MS - typing,
 * dragging a slider - merge into one step. loadDocument() (a design opened by
 * the parent page) starts a fresh history.
 */
const HISTORY_LIMIT = 100;
const COALESCE_MS = 800;
let past: TEditorConfiguration[] = [];
let future: TEditorConfiguration[] = [];
let lastKey = '';
let lastAt = 0;

function commit(document: TEditorConfiguration, coalesceKey: string, extra: Partial<TValue> = {}) {
  const now = Date.now();
  const merge = coalesceKey !== '' && coalesceKey === lastKey && now - lastAt < COALESCE_MS;
  if (!merge) {
    past.push(editorStateStore.getState().document);
    if (past.length > HISTORY_LIMIT) {
      past.shift();
    }
  }
  lastKey = coalesceKey;
  lastAt = now;
  future = [];
  editorStateStore.setState({ document, canUndo: past.length > 0, canRedo: false, ...extra });
}

function travel(from: TEditorConfiguration[], to: TEditorConfiguration[]) {
  const target = from.pop();
  if (!target) {
    return;
  }
  const state = editorStateStore.getState();
  to.push(state.document);
  lastKey = '';
  const selected = state.selectedBlockId && target[state.selectedBlockId] ? state.selectedBlockId : null;
  editorStateStore.setState({
    document: target,
    selectedBlockId: selected,
    selectedSidebarTab: selected ? 'block-configuration' : 'styles',
    canUndo: past.length > 0,
    canRedo: future.length > 0,
  });
}

export function undo() {
  travel(past, future);
}

export function redo() {
  travel(future, past);
}

export function useCanUndo() {
  return editorStateStore((s) => s.canUndo);
}

export function useCanRedo() {
  return editorStateStore((s) => s.canRedo);
}

/**
 * EDM: fires on every document change (edits and resetDocument alike). Used by
 * src/bridge.ts to tell the parent page there are unsaved changes.
 */
export function subscribeDocument(listener: (document: TEditorConfiguration) => void) {
  return editorStateStore.subscribe((state, prev) => {
    if (state.document !== prev.document) {
      listener(state.document);
    }
  });
}

export function getDocument() {
  return editorStateStore.getState().document;
}

export function useDocument() {
  return editorStateStore((s) => s.document);
}

export function useSelectedBlockId() {
  return editorStateStore((s) => s.selectedBlockId);
}

export function useSelectedScreenSize() {
  return editorStateStore((s) => s.selectedScreenSize);
}

export function useSelectedMainTab() {
  return editorStateStore((s) => s.selectedMainTab);
}

export function setSelectedMainTab(selectedMainTab: TValue['selectedMainTab']) {
  return editorStateStore.setState({ selectedMainTab });
}

export function useSelectedSidebarTab() {
  return editorStateStore((s) => s.selectedSidebarTab);
}

export function useInspectorDrawerOpen() {
  return editorStateStore((s) => s.inspectorDrawerOpen);
}

export function setSelectedBlockId(selectedBlockId: TValue['selectedBlockId']) {
  const selectedSidebarTab = selectedBlockId === null ? 'styles' : 'block-configuration';
  const options: Partial<TValue> = {};
  if (selectedBlockId !== null) {
    options.inspectorDrawerOpen = true;
  }
  return editorStateStore.setState({
    selectedBlockId,
    selectedSidebarTab,
    ...options,
  });
}

export function setSidebarTab(selectedSidebarTab: TValue['selectedSidebarTab']) {
  return editorStateStore.setState({ selectedSidebarTab });
}

/** A structural edit (move, delete, duplicate, drop): one undo step. */
export function resetDocument(document: TValue['document']) {
  return commit(document, '', {
    selectedSidebarTab: 'styles',
    selectedBlockId: null,
  });
}

/** EDM: a design opened from the parent page - not an edit, new history. */
export function loadDocument(document: TValue['document']) {
  past = [];
  future = [];
  lastKey = '';
  return editorStateStore.setState({
    document,
    selectedSidebarTab: 'styles',
    selectedBlockId: null,
    canUndo: false,
    canRedo: false,
  });
}

/** Merge changed blocks in; repeated edits of the same blocks coalesce. */
export function setDocument(document: TValue['document']) {
  const originalDocument = editorStateStore.getState().document;
  return commit(
    {
      ...originalDocument,
      ...document,
    },
    'set:' + Object.keys(document).sort().join(',')
  );
}

export function toggleInspectorDrawerOpen() {
  const inspectorDrawerOpen = !editorStateStore.getState().inspectorDrawerOpen;
  return editorStateStore.setState({ inspectorDrawerOpen });
}

export function setSelectedScreenSize(selectedScreenSize: TValue['selectedScreenSize']) {
  return editorStateStore.setState({ selectedScreenSize });
}

export function useDragging() {
  return editorStateStore((s) => s.dragging);
}

export function getDragging() {
  return editorStateStore.getState().dragging;
}

export function setDragging(dragging: TDragPayload | null) {
  return editorStateStore.setState({ dragging });
}
