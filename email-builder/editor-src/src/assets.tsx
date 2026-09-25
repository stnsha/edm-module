import React, { useState } from 'react';
import { create } from 'zustand';

import { Box, TextField, Tooltip, Typography } from '@mui/material';

/**
 * EDM: images from the Files library (edm_assets), offered in the Image
 * block's panel so a user picks an uploaded image instead of pasting a URL.
 *
 * The list is pushed in by the parent page with the 'load' message (see
 * bridge.ts); email-builder/index.php renders it from the Files table,
 * newest first. The grid shows the newest SHOW_LIMIT; the search box filters
 * the whole list by name so older images stay reachable.
 */
const SHOW_LIMIT = 30;
export type TAsset = { id: number; name: string; url: string };

const assetsStore = create<{ assets: TAsset[] }>(() => ({ assets: [] }));

export function setAssets(assets: TAsset[]) {
  assetsStore.setState({ assets });
}

type Props = {
  selectedUrl: string | null | undefined;
  onPick: (asset: TAsset) => void;
};

export function AssetPicker({ selectedUrl, onPick }: Props) {
  const assets = assetsStore((s) => s.assets);
  const [query, setQuery] = useState('');

  const q = query.trim().toLowerCase();
  const matches = q === '' ? assets : assets.filter((a) => a.name.toLowerCase().includes(q));
  const shown = matches.slice(0, SHOW_LIMIT);

  return (
    <Box>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
        Files library - click an image to use it
      </Typography>
      {assets.length > 0 && (
        <TextField
          size="small"
          fullWidth
          placeholder="Search images by name"
          value={query}
          onChange={(ev) => setQuery(ev.target.value)}
          inputProps={{ 'aria-label': 'Search images by name' }}
          sx={{ mb: 1 }}
        />
      )}
      {assets.length === 0 ? (
        <Box sx={{ p: 1.5, border: '1px dashed', borderColor: 'divider', borderRadius: 1 }}>
          <Typography variant="caption" color="text.secondary">
            No images yet. Upload them under Files, then reopen this newsletter.
          </Typography>
        </Box>
      ) : shown.length === 0 ? (
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
          No images match "{query.trim()}".
        </Typography>
      ) : (
        <Box
          sx={{
            display: 'grid',
            gridTemplateColumns: 'repeat(3, 1fr)',
            gap: 1,
            maxHeight: 232,
            overflowY: 'auto',
            pr: 0.5,
          }}
        >
          {shown.map((a) => {
            const selected = !!selectedUrl && selectedUrl === a.url;
            return (
              <Tooltip key={a.id} title={a.name}>
                <Box
                  component="button"
                  type="button"
                  onClick={() => onPick(a)}
                  aria-label={'Use ' + a.name}
                  aria-pressed={selected}
                  sx={{
                    p: 0,
                    height: 72,
                    cursor: 'pointer',
                    borderRadius: 1,
                    overflow: 'hidden',
                    bgcolor: '#f5f5f5',
                    border: '2px solid',
                    borderColor: selected ? 'primary.main' : 'divider',
                    '&:hover': { borderColor: 'primary.light' },
                  }}
                >
                  <Box
                    component="img"
                    src={a.url}
                    alt=""
                    loading="lazy"
                    sx={{ width: '100%', height: '100%', objectFit: 'contain', display: 'block' }}
                  />
                </Box>
              </Tooltip>
            );
          })}
        </Box>
      )}
      {matches.length > shown.length && (
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
          Showing {shown.length} of {matches.length}
          {q === '' ? ' (newest first) - search to find older images.' : ' - refine the search to narrow it down.'}
        </Typography>
      )}
    </Box>
  );
}
