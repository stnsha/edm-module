import { renderToStaticMarkup } from '@usewaypoint/email-builder';

import { EditorConfigurationSchema, TEditorConfiguration } from './documents/editor/core';
import { getDocument, resetDocument, subscribeDocument } from './documents/editor/EditorContext';
import EMPTY_EMAIL_MESSAGE from './getConfiguration/sample/empty-email-message';
import { setAssets, TAsset } from './assets';
import { setVariables, TVariable } from './variables';

/**
 * EDM: postMessage bridge to the parent page (email-builder/index.php +
 * email-builder.js), which owns loading and saving via email-builder/api.php.
 *
 * Every message is { source: 'edm-email-creator', type, ... } and is only
 * accepted from / sent to the same origin.
 *
 * editor -> parent
 *   ready                         editor mounted, send me a document
 *   change                        the document was edited (unsaved changes)
 *   export  { requestId, document, html }   reply to an export request
 *
 * parent -> editor
 *   load    { document?, html?, variables?, assets? }
 *                                 open a saved design; a legacy HTML-only body
 *                                 (no editor JSON) is wrapped in one Html block.
 *                                 variables = [{ token, label }] for the
 *                                 Personalisation box (see variables.tsx);
 *                                 assets = [{ id, name, url }] images from the
 *                                 Files library for the Image block (assets.tsx)
 *   export  { requestId }         ask for the current document + rendered HTML
 */
const SOURCE = 'edm-email-creator';

type TInbound =
  | {
      source: typeof SOURCE;
      type: 'load';
      document?: unknown;
      html?: string | null;
      variables?: TVariable[];
      assets?: TAsset[];
    }
  | { source: typeof SOURCE; type: 'export'; requestId: string };

function post(message: Record<string, unknown>) {
  if (window.parent && window.parent !== window) {
    window.parent.postMessage({ source: SOURCE, ...message }, window.location.origin);
  }
}

function fromLegacyHtml(html: string): TEditorConfiguration {
  return {
    root: {
      type: 'EmailLayout',
      data: {
        ...EMPTY_EMAIL_MESSAGE.root.data,
        childrenIds: ['legacy-html'],
      },
    },
    'legacy-html': {
      type: 'Html',
      data: {
        style: { padding: { top: 16, bottom: 16, left: 24, right: 24 } },
        props: { contents: html },
      },
    },
  } as TEditorConfiguration;
}

/**
 * The saved document round-trips through PHP (json_decode assoc) and Laravel's
 * array cast, both of which turn an empty object {} into an empty array [].
 * zod then rejects [] where it expects an object. The only array-typed keys in
 * the block schemas are childrenIds (may be empty) and the fixed 3-tuples
 * columns / fixedWidths (never empty), so every other [] was originally {}.
 */
function repairEmptyObjects(value: unknown, key?: string): unknown {
  if (Array.isArray(value)) {
    if (value.length === 0 && key !== 'childrenIds') {
      return {};
    }
    return value.map((v) => repairEmptyObjects(v));
  }
  if (value && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    Object.entries(value as Record<string, unknown>).forEach(([k, v]) => {
      out[k] = repairEmptyObjects(v, k);
    });
    return out;
  }
  return value;
}

function toDocument(document: unknown, html: string | null | undefined): TEditorConfiguration {
  if (document && typeof document === 'object') {
    const parsed = EditorConfigurationSchema.safeParse(repairEmptyObjects(document));
    if (parsed.success && parsed.data.root) {
      return parsed.data;
    }
  }
  if (html && html.trim() !== '') {
    return fromLegacyHtml(html);
  }
  return EMPTY_EMAIL_MESSAGE;
}

export function startBridge() {
  // resetDocument() from a parent 'load' must not count as an unsaved edit.
  let loading = false;

  subscribeDocument(() => {
    if (!loading) {
      post({ type: 'change' });
    }
  });

  window.addEventListener('message', (event: MessageEvent) => {
    if (event.origin !== window.location.origin || event.source !== window.parent) {
      return;
    }
    const data = event.data as TInbound;
    if (!data || data.source !== SOURCE) {
      return;
    }

    if (data.type === 'load') {
      if (Array.isArray(data.variables)) {
        setVariables(data.variables);
      }
      if (Array.isArray(data.assets)) {
        setAssets(data.assets);
      }
      loading = true;
      resetDocument(toDocument(data.document, data.html));
      loading = false;
      return;
    }

    if (data.type === 'export') {
      const document = getDocument();
      post({
        type: 'export',
        requestId: data.requestId,
        document,
        html: renderToStaticMarkup(document, { rootBlockId: 'root' }),
      });
    }
  });

  post({ type: 'ready' });
}
