import React, { useState } from 'react';

import { AddOutlined, ArrowDownwardOutlined, ArrowUpwardOutlined, DeleteOutlined } from '@mui/icons-material';
import { Box, Button, IconButton, Menu, MenuItem, Stack, TextField, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import { HtmlProps } from '@usewaypoint/block-html';

import {
  buildSocialHtml,
  parseSocialHtml,
  SOCIAL_PLATFORMS,
  SOCIAL_SIZES,
  socialItemLabel,
  socialPlatform,
  TSocialConfig,
  TSocialItem,
} from '../../../../social';

import BaseSidebarPanel from './helpers/BaseSidebarPanel';
import MultiStylePropertyPanel from './helpers/style-inputs/MultiStylePropertyPanel';

type SocialSidebarPanelProps = {
  data: HtmlProps;
  setData: (v: HtmlProps) => void;
};

/**
 * EDM: settings of the Social block (an Html block, see social.ts): show
 * as logos or text, with or without a "|" between, add platforms (each once) and Other links (as many as
 * needed, each with a name), give each its own link, reorder, remove, pick
 * the icon size. Every change rebuilds the block's HTML.
 */
export default function SocialSidebarPanel({ data, setData }: SocialSidebarPanelProps) {
  const [config, setConfig] = useState<TSocialConfig>(() => parseSocialHtml(data.props?.contents ?? ''));
  const [menuEl, setMenuEl] = useState<HTMLElement | null>(null);

  const save = (next: TSocialConfig) => {
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
  const remove = (i: number) => save({ ...config, items: config.items.filter((_, n) => n !== i) });
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
                <img src={p.icon} alt="" width={20} height={20} />
                <Typography variant="body2" sx={{ fontWeight: 600, flex: 1 }}>
                  {name}
                </Typography>
                <IconButton size="small" disabled={i === 0} onClick={() => move(i, -1)} aria-label={'Move ' + name + ' up'}>
                  <ArrowUpwardOutlined fontSize="small" />
                </IconButton>
                <IconButton
                  size="small"
                  disabled={i === config.items.length - 1}
                  onClick={() => move(i, 1)}
                  aria-label={'Move ' + name + ' down'}
                >
                  <ArrowDownwardOutlined fontSize="small" />
                </IconButton>
                <IconButton size="small" onClick={() => remove(i)} aria-label={'Remove ' + name}>
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
