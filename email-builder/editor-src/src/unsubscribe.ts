import { TEditorBlock } from './documents/editor/core';

/**
 * EDM: the Unsubscribe tile (left panel and the inline + menu). One Html
 * block: a light rule, the reason the reader gets the email and the
 * {{unsubscribe_url}} link, which MessageRenderer fills with the signed
 * per-recipient link (public/unsubscribe.php). With this variable in the
 * design no extra footer is appended at send time. The wording is edited in
 * the block's HTML settings.
 */
export const UNSUBSCRIBE_HTML =
  '<div style="border-top:1px solid #E5E5E5;padding-top:16px;">' +
  'You are receiving this as a member of Alpro Pharmacy.<br>' +
  '<a href="{{unsubscribe_url}}" style="color:#888888;text-decoration:underline;">Unsubscribe</a>' +
  '</div>';

export function unsubscribeBlock(): TEditorBlock {
  return {
    type: 'Html',
    data: {
      props: { contents: UNSUBSCRIBE_HTML },
      style: { fontSize: 13, textAlign: 'center', color: '#888888', padding: { top: 16, bottom: 16, left: 24, right: 24 } },
    },
  } as TEditorBlock;
}
