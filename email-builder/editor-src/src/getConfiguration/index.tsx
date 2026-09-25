import EMPTY_EMAIL_MESSAGE from './sample/empty-email-message';

// EDM: the upstream sample gallery and #code/ share links are removed. The
// document is always pushed in by the parent page (see src/bridge.ts); this is
// only the blank canvas shown until that arrives.
export default function getConfiguration() {
  return EMPTY_EMAIL_MESSAGE;
}
