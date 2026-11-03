import React, { useRef, useState } from 'react';

import { AddOutlined, ArrowDownwardOutlined, ArrowUpwardOutlined, DeleteOutlined, UploadOutlined } from '@mui/icons-material';
import { Box, Button, IconButton, Menu, MenuItem, Stack, TextField, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import { HtmlProps } from '@usewaypoint/block-html';

import {
  buildSocialHtml,
  parseSocialHtml,
  SOCIAL_PLATFORMS,
  SOCIAL_SIZES,
  socialItemIcon,
  socialItemLabel,
  socialPlatform,
  TSocialConfig,
  TSocialItem,
} from '../../../../social';
import { uploadImage } from '../../../../assets';

import BaseSidebarPanel from './helpers/BaseSidebarPanel';
import MultiStylePropertyPanel from './helpers/style-inputs/MultiStylePropertyPanel';

/** Side of the square PNG an uploaded icon is turned into (2x the largest icon size, sharp on retina). */
const ICON_PX = 96;

/**
 * Fits an image into an ICON_PX square PNG (aspect ratio kept, transparent
 * padding), so an uploaded icon of any shape shows at the block's icon size
 * exactly like the platform logos, without stretching.
 */
function squareIcon(file: File): Promise<File> {
  return new Promise((resolve, reject) => {
    const src = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(src);
      const canvas = document.createElement('canvas');
      canvas.width = ICON_PX;
      canvas.height = ICON_PX;
      const ctx = canvas.getContext('2d');
      if (!ctx || !img.naturalWidth || !img.naturalHeight) {
        reject(new Error(file.name + ': the image could not be read.'));
        return;
      }
      const scale = Math.min(ICON_PX / img.naturalWidth, ICON_PX / img.naturalHeight);
      const w = Math.round(img.naturalWidth * scale);
      const h = Math.round(img.naturalHeight * scale);
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(img, Math.round((ICON_PX - w) / 2), Math.round((ICON_PX - h) / 2), w, h);
      canvas.toBlob((blob) => {
        if (!blob) {
          reject(new Error(file.name + ': the image could not be converted.'));
          return;
        }
        const base = file.name.replace(/\.[^.]+$/, '') || 'icon';
        resolve(new File([blob], base + '-icon.png', { type: 'image/png' }));
      }, 'image/png');
    };
    img.onerror = () => {
      URL.revokeObjectURL(src);
      reject(new Error(file.name + ': only JPG, PNG, GIF or WebP images can be uploaded.'));
    };
    img.src = src;
  });
}

type SocialSidebarPanelProps = {
  data: HtmlProps;
  setData: (v: HtmlProps) => void;
};

/**
 * EDM: settings of the Social block (an Html block, see social.ts): show
 * as logos or text, with or without a "|" between, add platforms (each once) and Other links (as many as
 * needed, each with a name and optionally its own uploaded icon), give each its own link, reorder, remove, pick
 * the icon size (uploaded icons follow it too). Every change rebuilds the block's HTML.
 */
export default function SocialSidebarPanel({ data, setData }: SocialSidebarPanelProps) {
  const [config, setConfig] = useState<TSocialConfig>(() => parseSocialHtml(data.props?.contents ?? ''));
  const [menuEl, setMenuEl] = useState<HTMLElement | null>(null);
  // Other link icon upload: which item is uploading, and the last error per item.
  const [uploading, setUploading] = useState<number | null>(null);
  const [iconError, setIconError] = useState<Record<number, string>>({});
  const fileInput = useRef<HTMLInputElement | null>(null);
  const uploadFor = useRef<number | null>(null);
  // Latest config for the async upload callback (the user may edit meanwhile).
  const configRef = useRef(config);
  configRef.current = config;

  const save = (next: TSocialConfig) => {
    configRef.current = next;
    setConfig(next);
    setData({ ...data, props: { ...data.props, contents: buildSocialHtml(next) } });
  };
  const setItem = (i: number, patch: Partial<TSocialItem>) =>
    save({ ...config, items: config.items.map((it, n) => (n === i ? { ...it, ...patch } : it)) });
  const move = (i: number, by: number) => {
    const items = config.items.slice();
    const [it] = items.splice(i, 1);
    items.splice(i + by, 0, it);
    save({ ...config, items });
  };
  const remove = (i: number) => {
    setIconError({});
    save({ ...config, items: config.items.filter((_, n) => n !== i) });
  };
  const pickIcon = (i: number) => {
    uploadFor.current = i;
    fileInput.current?.click();
  };
  const onIconFile = (file: File | undefined) => {
    const i = uploadFor.current;
    uploadFor.current = null;
    if (!file || i === null) {
      return;
    }
    setUploading(i);
    setIconError((e) => ({ ...e, [i]: '' }));
    squareIcon(file)
      .then(uploadImage)
      .then((asset) => {
        const cur = configRef.current;
        save({ ...cur, items: cur.items.map((it, n) => (n === i ? { ...it, icon: asset.url } : it)) });
      })
      .catch((err: Error) => setIconError((e) => ({ ...e, [i]: err.message })))
      .finally(() => setUploading(null));
  };
  const add = (key: string) => {
    setMenuEl(null);
    const item: TSocialItem = socialPlatform(key)?.multiple ? { key, url: '', label: '' } : { key, url: '' };
    save({ ...config, items: [...config.items, item] });
  };
  // Each platform once; Other links as often as needed.
  const addable = SOCIAL_PLATFORMS.filter((p) => p.multiple || !config.items.some((it) => it.key === p.key));
  const isText = config.mode === 'text';

  return (
    <BaseSidebarPanel title="Social links">
      <Box>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
          Show as
        </Typography>
        <ToggleButtonGroup
          exclusive
          size="small"
          value={config.mode}
          onChange={(_, v) => {
            if (v !== null) {
              save({ ...config, mode: v });
            }
          }}
        >
          <ToggleButton value="logo">Logo</ToggleButton>
          <ToggleButton value="text">Text</ToggleButton>
        </ToggleButtonGroup>
      </Box>
      <Box>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
          Separator
        </Typography>
        <ToggleButtonGroup
          exclusive
          size="small"
          value={config.separator}
          onChange={(_, v) => {
            if (v !== null) {
              save({ ...config, separator: v });
            }
          }}
        >
          <ToggleButton value="bar">|</ToggleButton>
          <ToggleButton value="none">None</ToggleButton>
        </ToggleButtonGroup>
      </Box>
      <Stack spacing={2}>
        {config.items.length === 0 && (
          <Typography variant="body2" color="text.secondary">
            No links yet. Add a platform below.
          </Typography>
        )}
        {config.items.map((it, i) => {
          const p = socialPlatform(it.key);
          if (!p) {
            return null;
          }
          const name = socialItemLabel(it);
          return (
            <Box key={i}>
              <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 0.5 }}>
                <img src={socialItemIcon(it)} alt="" width={20} height={20} style={{ objectFit: 'contain' }} />
                <Typography variant="body2" sx={{ fontWeight: 600, flex: 1 }}>
                  {name}
                </Typography>
                <IconButton size="small" disabled={i === 0 || uploading !== null} onClick={() => move(i, -1)} aria-label={'Move ' + name + ' up'}>
                  <ArrowUpwardOutlined fontSize="small" />
                </IconButton>
                <IconButton
                  size="small"
                  disabled={i === config.items.length - 1 || uploading !== null}
                  onClick={() => move(i, 1)}
                  aria-label={'Move ' + name + ' down'}
                >
                  <ArrowDownwardOutlined fontSize="small" />
                </IconButton>
                <IconButton size="small" disabled={uploading !== null} onClick={() => remove(i)} aria-label={'Remove ' + name}>
                  <DeleteOutlined fontSize="small" />
                </IconButton>
              </Stack>
              <Stack spacing={1}>
                {p.multiple && (
                  <TextField
                    fullWidth
                    size="small"
                    variant="outlined"
                    placeholder="Name, e.g. WhatsApp or Online store"
                    value={it.label ?? ''}
                    helperText={isText ? 'Shown as the link text' : 'Used as the image description'}
                    onChange={(ev) => setItem(i, { label: ev.target.value })}
                  />
                )}
                {p.multiple && !isText && (
                  <Box>
                    <Stack direction="row" spacing={1}>
                      <Button
                        size="small"
                        variant="outlined"
                        startIcon={<UploadOutlined />}
                        disabled={uploading !== null}
                        onClick={() => pickIcon(i)}
                      >
                        {uploading === i ? 'Uploading...' : it.icon ? 'Change icon' : 'Upload icon'}
                      </Button>
                      {it.icon && (
                        <Button size="small" disabled={uploading !== null} onClick={() => setItem(i, { icon: undefined })}>
                          Use default
                        </Button>
                      )}
                    </Stack>
                    <Typography
                      variant="caption"
                      color={iconError[i] ? 'error' : 'text.secondary'}
                      sx={{ display: 'block', mt: 0.5 }}
                    >
                      {iconError[i] || 'Shown at the icon size below, like the other logos.'}
                    </Typography>
                  </Box>
                )}
                <TextField
                  fullWidth
                  size="small"
                  variant="outlined"
                  placeholder={p.placeholder}
                  value={it.url}
                  error={it.url.trim() === ''}
                  helperText={it.url.trim() === '' ? 'Enter the link' : undefined}
                  onChange={(ev) => setItem(i, { url: ev.target.value })}
                />
              </Stack>
            </Box>
          );
        })}
        <input
          ref={fileInput}
          type="file"
          accept="image/jpeg,image/png,image/gif,image/webp"
          hidden
          onChange={(ev) => {
            onIconFile(ev.target.files?.[0]);
            ev.target.value = '';
          }}
        />
        <Box>
          <Button size="small" variant="outlined" startIcon={<AddOutlined />} onClick={(ev) => setMenuEl(ev.currentTarget)}>
            Add platform
          </Button>
          <Menu anchorEl={menuEl} open={menuEl !== null} onClose={() => setMenuEl(null)}>
            {addable.map((p) => (
              <MenuItem key={p.key} onClick={() => add(p.key)}>
                <img src={p.icon} alt="" width={18} height={18} style={{ marginRight: 10 }} />
                {p.label}
              </MenuItem>
            ))}
          </Menu>
        </Box>
      </Stack>
      {!isText && (
        <Box>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
            Icon size
          </Typography>
          <ToggleButtonGroup
            exclusive
            size="small"
            value={config.size}
            onChange={(_, v) => {
              if (v !== null) {
                save({ ...config, size: v });
              }
            }}
          >
            {SOCIAL_SIZES.map((s) => (
              <ToggleButton key={s} value={s}>
                {s}px
              </ToggleButton>
            ))}
          </ToggleButtonGroup>
        </Box>
      )}
      <MultiStylePropertyPanel
        names={isText ? ['color', 'fontSize', 'backgroundColor', 'textAlign', 'padding'] : ['backgroundColor', 'textAlign', 'padding']}
        value={data.style}
        onChange={(style) => setData({ ...data, style })}
      />
    </BaseSidebarPanel>
  );
}
