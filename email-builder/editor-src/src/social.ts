/**
 * EDM: the Social block. It is an ordinary Html block (so the stock
 * renderer sends it), whose contents carry data attributes the editor reads
 * back: the wrapper `data-edm-social="<icon size>"` + `data-edm-social-mode`
 * (logo | text) + `data-edm-social-sep` (bar | none), and one link per item `data-edm-social-key="<key>"` (an
 * Other link also `data-edm-social-label`). The Social settings panel
 * (SocialSidebarPanel) edits the list - which platforms, their links, the
 * order, logo or text, the icon size - and rebuilds the HTML.
 *
 * Icons are the brands' own full-colour logos as PNG (Gmail drops SVG)
 * from the Icons8 CDN. Icons8 has no colour RedNote or Lazada logo: RedNote
 * is its red tile in brand red, Lazada the coloured heart-box. Other links
 * use a plain link icon.
 */
export type TSocialPlatform = { key: string; label: string; icon: string; placeholder: string; multiple?: boolean };
export type TSocialItem = { key: string; url: string; label?: string };
export type TSocialMode = 'logo' | 'text';
export type TSocialSeparator = 'bar' | 'none';
export type TSocialConfig = { size: number; mode: TSocialMode; separator: TSocialSeparator; items: TSocialItem[] };

const ICONS8 = 'https://img.icons8.com/';

export const SOCIAL_PLATFORMS: TSocialPlatform[] = [
  { key: 'facebook', label: 'Facebook', icon: ICONS8 + 'color/100/facebook-new.png', placeholder: 'https://www.facebook.com/yourpage' },
  { key: 'instagram', label: 'Instagram', icon: ICONS8 + 'color/100/instagram-new.png', placeholder: 'https://www.instagram.com/yourprofile' },
  { key: 'tiktok', label: 'TikTok', icon: ICONS8 + 'color/100/tiktok--v1.png', placeholder: 'https://www.tiktok.com/@yourprofile' },
  { key: 'youtube', label: 'YouTube', icon: ICONS8 + 'color/100/youtube-play.png', placeholder: 'https://www.youtube.com/@yourchannel' },
  { key: 'rednote', label: 'RedNote', icon: ICONS8 + 'ios-filled/100/FF2442/xiao-hong-shu.png', placeholder: 'https://www.xiaohongshu.com/user/profile/...' },
  { key: 'shopee', label: 'Shopee', icon: ICONS8 + 'color/100/shopee.png', placeholder: 'https://shopee.com.my/yourshop' },
  { key: 'lazada', label: 'Lazada', icon: ICONS8 + 'plasticine/100/lazada.png', placeholder: 'https://www.lazada.com.my/shop/yourshop' },
  { key: 'website', label: 'Website', icon: ICONS8 + 'color/100/domain--v1.png', placeholder: 'https://www.yourwebsite.com' },
  // Any other link: added as often as needed, with its own name.
  { key: 'other', label: 'Other link', icon: ICONS8 + 'fluency/100/link.png', placeholder: 'https://...', multiple: true },
];

export const SOCIAL_SIZES = [24, 32, 40, 48];
const DEFAULT_SIZE = 32;
// The "|" between items: light grey, vertically centred next to logos.
const SEPARATOR = '<span style="display:inline-block;margin:0 4px;vertical-align:middle;color:#C4C8CE;">|</span>';

export function socialPlatform(key: string): TSocialPlatform | undefined {
  return SOCIAL_PLATFORMS.find((p) => p.key === key);
}

/** What an item is called: the name typed for an Other link, else the platform. */
export function socialItemLabel(item: TSocialItem): string {
  const p = socialPlatform(item.key);
  if (p?.multiple) {
    return (item.label ?? '').trim() || p.label;
  }
  return p?.label ?? item.key;
}

/** A new Social block: Facebook, Instagram and TikTok logos with placeholder links. */
export function defaultSocialConfig(): TSocialConfig {
  return {
    size: DEFAULT_SIZE,
    mode: 'logo',
    separator: 'bar',
    items: ['facebook', 'instagram', 'tiktok'].map((key) => ({ key, url: socialPlatform(key)!.placeholder })),
  };
}

function escAttr(v: string): string {
  return v.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * The block's HTML: one link per item, in order - linked logos or text
 * links (text colour and size come from the block's style), with a light
 * "|" between them unless the separator is off.
 */
export function buildSocialHtml(config: TSocialConfig): string {
  const size = SOCIAL_SIZES.includes(config.size) ? config.size : DEFAULT_SIZE;
  const mode: TSocialMode = config.mode === 'text' ? 'text' : 'logo';
  const separator: TSocialSeparator = config.separator === 'none' ? 'none' : 'bar';
  const links = config.items
    .map((item) => {
      const p = socialPlatform(item.key);
      if (!p) {
        return '';
      }
      const label = socialItemLabel(item);
      const attrs =
        'data-edm-social-key="' + p.key + '"' +
        (p.multiple ? ' data-edm-social-label="' + escAttr((item.label ?? '').trim()) + '"' : '') +
        ' href="' + escAttr(item.url.trim() || '#') + '" target="_blank"';
      if (mode === 'text') {
        return '<a ' + attrs + ' style="color:inherit;text-decoration:underline;">' + escAttr(label) + '</a>';
      }
      return (
        '<a ' + attrs + ' style="display:inline-block;margin:0 6px;vertical-align:middle;text-decoration:none;">' +
        '<img src="' + p.icon + '" alt="' + escAttr(label) + '" width="' + size + '" height="' + size + '" ' +
        'style="display:block;width:' + size + 'px;height:' + size + 'px;border:0;outline:none;" /></a>'
      );
    })
    .filter((l) => l !== '')
    .join(separator === 'bar' ? SEPARATOR : mode === 'text' ? ' &nbsp; ' : '');

  return (
    '<div data-edm-social="' + size + '" data-edm-social-mode="' + mode + '" data-edm-social-sep="' + separator + '">' +
    links +
    '</div>'
  );
}

/** True when an Html block's contents are a Social block. */
export function isSocialHtml(contents: string | null | undefined): boolean {
  return typeof contents === 'string' && contents.indexOf('data-edm-social=') !== -1;
}

/** Reads a Social block's settings back from its HTML (browser only). */
export function parseSocialHtml(contents: string): TSocialConfig {
  const doc = new DOMParser().parseFromString(contents, 'text/html');
  const wrap = doc.querySelector('[data-edm-social]');
  const size = parseInt(wrap?.getAttribute('data-edm-social') ?? '', 10);
  const mode: TSocialMode = wrap?.getAttribute('data-edm-social-mode') === 'text' ? 'text' : 'logo';
  // Blocks made before the separator option had none.
  const separator: TSocialSeparator = wrap?.getAttribute('data-edm-social-sep') === 'bar' ? 'bar' : 'none';
  const items: TSocialItem[] = [];
  doc.querySelectorAll('a[data-edm-social-key]').forEach((a) => {
    const key = a.getAttribute('data-edm-social-key') ?? '';
    const href = a.getAttribute('href') ?? '';
    const p = socialPlatform(key);
    if (!p || (!p.multiple && items.some((i) => i.key === key))) {
      return;
    }
    const item: TSocialItem = { key, url: href === '#' ? '' : href };
    if (p.multiple) {
      item.label = a.getAttribute('data-edm-social-label') ?? '';
    }
    items.push(item);
  });

  return { size: SOCIAL_SIZES.includes(size) ? size : DEFAULT_SIZE, mode, separator, items };
}
