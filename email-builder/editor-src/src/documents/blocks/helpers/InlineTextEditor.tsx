import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';

import { clearActiveInput, setActiveInput } from '../../../variables';
import { useCurrentBlockId } from '../../editor/EditorBlock';
import { setDocument, useDocument, useSelectedBlockId } from '../../editor/EditorContext';

/**
 * EDM: type straight onto the canvas (Text / Heading / Button blocks).
 *
 * Upstream EmailBuilder.js only edits text in the Inspect panel. This wraps a
 * block's normal render and, while the block is selected, turns its text
 * element (found by `selector` inside the rendered block) contentEditable.
 * Nothing is written to the document while typing - that would re-render the
 * block under the caret - the text is committed on blur, on deselect, or on
 * Enter for single-line blocks. The block is then remounted (key bump) so
 * React never reconciles against DOM the browser edited.
 *
 * Markdown Text blocks are left alone: the canvas shows rendered HTML there,
 * so editing it in place would lose the markdown source.
 */
type Props = {
  children: JSX.Element;
  selector: string; // text element inside the rendered block
  multiline: boolean; // Enter inserts a line break (Text) instead of committing
};

type TTextBlock = { type: string; data: { props?: { text?: string | null; markdown?: boolean | null } | null } };

export default function InlineTextEditor({ children, selector, multiline }: Props) {
  const blockId = useCurrentBlockId();
  const selectedBlockId = useSelectedBlockId();
  const document = useDocument();
  const block = document[blockId] as unknown as TTextBlock;
  const ref = useRef<HTMLDivElement>(null);
  const [version, setVersion] = useState(0);

  const markdown = !!block?.data?.props?.markdown;
  const editing = selectedBlockId === blockId && !markdown;

  // Latest values for the DOM listeners below.
  const blockRef = useRef(block);
  blockRef.current = block;

  function target(): HTMLElement | null {
    return ref.current ? (ref.current.querySelector(selector) as HTMLElement | null) : null;
  }

  function commit() {
    const el = target();
    if (!el || el.contentEditable !== 'true') {
      return;
    }
    let text = el.innerText.replace(/ /g, ' ');
    if (!multiline) {
      text = text.replace(/\s*\n\s*/g, ' ').trim();
    } else {
      text = text.replace(/\n$/, '');
    }
    el.contentEditable = 'false';
    const current = blockRef.current;
    if (text !== (current.data.props?.text ?? '')) {
      setDocument({
        [blockId]: {
          ...current,
          data: { ...current.data, props: { ...current.data.props, text } },
        },
      } as never);
    }
    setVersion((v) => v + 1);
  }

  useLayoutEffect(() => {
    const el = target();
    if (!el || !editing) {
      return undefined;
    }
    el.contentEditable = 'true';
    el.style.outline = 'none';
    el.style.cursor = 'text';

    const handle = {
      insert: (token: string) => {
        el.focus();
        window.document.execCommand('insertText', false, token);
      },
    };

    const onFocus = () => setActiveInput(handle);
    const onBlur = () => commit();
    const onKeyDown = (ev: KeyboardEvent) => {
      if (ev.key === 'Enter' && !multiline) {
        ev.preventDefault();
        el.blur();
      } else if (ev.key === 'Escape') {
        el.blur();
      }
      // Keep editor shortcuts (e.g. block delete) from firing while typing.
      ev.stopPropagation();
    };
    // Paste as plain text so no foreign markup lands in the email.
    const onPaste = (ev: ClipboardEvent) => {
      ev.preventDefault();
      const text = ev.clipboardData?.getData('text/plain') ?? '';
      window.document.execCommand('insertText', false, multiline ? text : text.replace(/\s*\n\s*/g, ' '));
    };

    el.addEventListener('focus', onFocus);
    el.addEventListener('blur', onBlur);
    el.addEventListener('keydown', onKeyDown);
    el.addEventListener('paste', onPaste);
    return () => {
      el.removeEventListener('focus', onFocus);
      el.removeEventListener('blur', onBlur);
      el.removeEventListener('keydown', onKeyDown);
      el.removeEventListener('paste', onPaste);
      clearActiveInput(handle);
      // Deselected while focused: blur already committed; otherwise commit now.
      if (el.contentEditable === 'true') {
        commit();
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editing, version, selector, multiline]);

  // A second click on an already selected block places the caret.
  useEffect(() => {
    if (!editing) {
      return;
    }
    const el = target();
    if (el && window.document.activeElement !== el) {
      el.focus();
      const range = window.document.createRange();
      range.selectNodeContents(el);
      range.collapse(false);
      const sel = window.getSelection();
      sel?.removeAllRanges();
      sel?.addRange(range);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editing]);

  return (
    <div ref={ref} key={version}>
      {children}
    </div>
  );
}
