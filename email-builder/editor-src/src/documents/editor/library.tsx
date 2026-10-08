import React from 'react';

import {
  AccountCircleOutlined,
  Crop32Outlined,
  HorizontalRuleOutlined,
  HtmlOutlined,
  ImageOutlined,
  LibraryAddOutlined,
  NotesOutlined,
  ShareOutlined,
  SmartButtonOutlined,
  StarOutlineOutlined,
  TitleOutlined,
  ViewColumnOutlined,
} from '@mui/icons-material';

import { TEditorBlock } from './core';
import { newBlockId } from './tree';

/**
 * EDM: what the left panel offers (Brevo-style). BLOCKS are single content
 * blocks; LAYOUTS are ready-made rows - a Container or ColumnsContainer with
 * blocks already inside. build() returns the new root block id plus every
 * block to add (root and descendants), all with fresh ids.
 */
export type TBuilt = { rootId: string; blocks: Record<string, TEditorBlock> };
export type TLibraryItem = { key: string; label: string; icon: JSX.Element; build: () => TBuilt };

const PAD = { top: 16, bottom: 16, left: 24, right: 24 };
const SAMPLE_IMAGE = 'https://assets.usewaypoint.com/sample-image.jpg';

function one(block: TEditorBlock): TBuilt {
  const rootId = newBlockId();
  return { rootId, blocks: { [rootId]: block } };
}

const heading = (text: string, textAlign: 'left' | 'center' = 'left'): TEditorBlock => ({
  type: 'Heading',
  data: { props: { text, level: 'h2' }, style: { padding: { ...PAD, bottom: 8 }, textAlign } },
});
const text = (value: string, textAlign: 'left' | 'center' = 'left'): TEditorBlock => ({
  type: 'Text',
  data: { props: { text: value }, style: { padding: { ...PAD, top: 8 }, textAlign, fontWeight: 'normal' } },
});
const button = (label: string, buttonAlign: 'left' | 'center' = 'left'): TEditorBlock =>
  ({
    type: 'Button',
    data: {
      props: { text: label, url: 'https://www.example.com', buttonBackgroundColor: '#0D6EFD', fullWidth: false },
      style: { padding: PAD, textAlign: buttonAlign },
    },
  }) as TEditorBlock;
const image = (alt: string, width?: number): TEditorBlock => ({
  type: 'Image',
  data: {
    props: { url: SAMPLE_IMAGE, alt, contentAlignment: 'middle', linkHref: null, ...(width ? { width } : {}) },
    style: { padding: PAD, textAlign: 'center' },
  },
});

export const BLOCKS: TLibraryItem[] = [
  { key: 'title', label: 'Title', icon: <TitleOutlined />, build: () => one(heading('Your title here')) },
  { key: 'text', label: 'Text', icon: <NotesOutlined />, build: () => one(text('Write your message here.')) },
  { key: 'image', label: 'Image', icon: <ImageOutlined />, build: () => one(image('Image')) },
  { key: 'button', label: 'Button', icon: <SmartButtonOutlined />, build: () => one(button('Shop now')) },
  { key: 'logo', label: 'Logo', icon: <StarOutlineOutlined />, build: () => one(image('Logo', 140)) },
  {
    key: 'social',
    label: 'Social',
    icon: <ShareOutlined />,
    build: () =>
      one({
        type: 'Html',
        data: {
          props: {
            contents:
              '<a href="https://www.facebook.com/">Facebook</a> &nbsp;|&nbsp; <a href="https://www.instagram.com/">Instagram</a> &nbsp;|&nbsp; <a href="https://www.tiktok.com/">TikTok</a>',
          },
          style: { fontSize: 14, textAlign: 'center', padding: PAD },
        },
      }),
  },
  {
    key: 'divider',
    label: 'Divider',
    icon: <HorizontalRuleOutlined />,
    build: () =>
      one({ type: 'Divider', data: { style: { padding: { top: 16, right: 0, bottom: 16, left: 0 } }, props: { lineColor: '#CCCCCC' } } }),
  },
  { key: 'spacer', label: 'Spacer', icon: <Crop32Outlined />, build: () => one({ type: 'Spacer', data: {} }) },
  {
    key: 'html',
    label: 'HTML',
    icon: <HtmlOutlined />,
    build: () =>
      one({
        type: 'Html',
        data: { props: { contents: '<strong>Your HTML</strong>' }, style: { fontSize: 16, textAlign: null, padding: PAD } },
      }),
  },
  {
    key: 'avatar',
    label: 'Avatar',
    icon: <AccountCircleOutlined />,
    build: () =>
      one({ type: 'Avatar', data: { props: { imageUrl: 'https://ui-avatars.com/api/?size=128', shape: 'circle' }, style: { padding: PAD } } }),
  },
  { key: 'columns', label: 'Columns', icon: <ViewColumnOutlined />, build: () => columns(2, [[], []]) },
  {
    key: 'container',
    label: 'Container',
    icon: <LibraryAddOutlined />,
    build: () => container([]),
  },
];

function container(children: TEditorBlock[], backgroundColor?: string): TBuilt {
  const rootId = newBlockId();
  const blocks: Record<string, TEditorBlock> = {};
  const ids = children.map((b) => {
    const id = newBlockId();
    blocks[id] = b;
    return id;
  });
  blocks[rootId] = {
    type: 'Container',
    data: { style: { padding: { top: 8, bottom: 8, left: 0, right: 0 }, ...(backgroundColor ? { backgroundColor } : {}) }, props: { childrenIds: ids } },
  };
  return { rootId, blocks };
}

function columns(count: 2 | 3, cols: TEditorBlock[][]): TBuilt {
  const rootId = newBlockId();
  const blocks: Record<string, TEditorBlock> = {};
  const lists = [0, 1, 2].map((i) =>
    (cols[i] ?? []).map((b) => {
      const id = newBlockId();
      blocks[id] = b;
      return id;
    })
  );
  blocks[rootId] = {
    type: 'ColumnsContainer',
    data: {
      style: { padding: { top: 16, bottom: 16, left: 24, right: 24 } },
      props: {
        columnsCount: count,
        columnsGap: 16,
        columns: [{ childrenIds: lists[0] }, { childrenIds: lists[1] }, { childrenIds: lists[2] }],
      },
    },
  } as TEditorBlock;
  return { rootId, blocks };
}

const smallPad = (b: TEditorBlock): TEditorBlock =>
  ({ ...b, data: { ...b.data, style: { ...(b.data as { style?: object }).style, padding: { top: 8, bottom: 8, left: 0, right: 0 } } } }) as TEditorBlock;

/** Small two-tone sketches of each layout for its tile. */
function sketch(kind: string) {
  const box = (w: string, h: number, dark = false) => (
    <span style={{ display: 'block', width: w, height: h, borderRadius: 2, background: dark ? '#9fb6d6' : '#d6e2f0' }} />
  );
  const row = (children: React.ReactNode) => <span style={{ display: 'flex', gap: 3, width: '100%' }}>{children}</span>;
  const col = (children: React.ReactNode) => (
    <span style={{ display: 'flex', flexDirection: 'column', gap: 3, flex: 1, alignItems: 'center' }}>{children}</span>
  );
  switch (kind) {
    case 'hero':
      return col(<>{box('100%', 16, true)}{box('70%', 4)}{box('85%', 3)}{box('35%', 6, true)}</>);
    case 'text-button':
      return col(<>{box('60%', 5)}{box('90%', 3)}{box('90%', 3)}{box('35%', 6, true)}</>);
    case 'image-text':
      return row(<>{col(box('100%', 22, true))}{col(<>{box('90%', 4)}{box('90%', 3)}{box('60%', 5, true)}</>)}</>);
    case 'text-image':
      return row(<>{col(<>{box('90%', 4)}{box('90%', 3)}{box('60%', 5, true)}</>)}{col(box('100%', 22, true))}</>);
    case 'cols-2':
      return row(<>{col(box('100%', 22))}{col(box('100%', 22))}</>);
    case 'products':
      return row(<>{[0, 1, 2].map((i) => <React.Fragment key={i}>{col(<>{box('100%', 14, true)}{box('80%', 3)}</>)}</React.Fragment>)}</>);
    case 'footer':
      return col(<>{box('100%', 1)}{box('70%', 3)}{box('50%', 3)}{box('30%', 3, true)}</>);
    default:
      return null;
  }
}

export const LAYOUTS: (TLibraryItem & { sketch: JSX.Element | null })[] = [
  {
    key: 'hero',
    label: 'Hero banner',
    icon: <ImageOutlined />,
    sketch: sketch('hero'),
    build: () =>
      container([image('Banner'), heading('Big news for you', 'center'), text('Tell readers what this email is about.', 'center'), button('Discover more', 'center')]),
  },
  {
    key: 'text-button',
    label: 'Text + button',
    icon: <NotesOutlined />,
    sketch: sketch('text-button'),
    build: () => container([heading('A short headline'), text('Add a few lines of text that lead to the button.'), button('Learn more')]),
  },
  {
    key: 'image-text',
    label: 'Image left, text right',
    icon: <ViewColumnOutlined />,
    sketch: sketch('image-text'),
    build: () =>
      columns(2, [[smallPad(image('Image'))], [smallPad(heading('Feature title')), smallPad(text('Describe it in a sentence or two.')), smallPad(button('Read more'))]]),
  },
  {
    key: 'text-image',
    label: 'Text left, image right',
    icon: <ViewColumnOutlined />,
    sketch: sketch('text-image'),
    build: () =>
      columns(2, [[smallPad(heading('Feature title')), smallPad(text('Describe it in a sentence or two.')), smallPad(button('Read more'))], [smallPad(image('Image'))]]),
  },
  {
    key: 'cols-2',
    label: 'Two columns',
    icon: <ViewColumnOutlined />,
    sketch: sketch('cols-2'),
    build: () => columns(2, [[], []]),
  },
  {
    key: 'products',
    label: 'Three products',
    icon: <ViewColumnOutlined />,
    sketch: sketch('products'),
    build: () =>
      columns(3, [0, 1, 2].map((i) => [smallPad(image('Product ' + (i + 1))), smallPad(text('Product name - RM 0.00', 'center'))])),
  },
  {
    key: 'footer',
    label: 'Footer',
    icon: <HorizontalRuleOutlined />,
    sketch: sketch('footer'),
    build: () =>
      container([
        { type: 'Divider', data: { style: { padding: { top: 16, right: 24, bottom: 8, left: 24 } }, props: { lineColor: '#DDDDDD' } } },
        {
          type: 'Html',
          data: {
            props: {
              contents:
                'Company name &middot; Address line &middot; City<br>You received this email because you are a member. <a href="{{unsubscribe_url}}">Unsubscribe</a>',
            },
            style: { fontSize: 12, textAlign: 'center', color: '#888888', padding: PAD },
          },
        },
      ]),
  },
];

export function findItem(kind: 'block' | 'layout', key: string): TLibraryItem | undefined {
  return (kind === 'block' ? BLOCKS : LAYOUTS).find((i) => i.key === key);
}
