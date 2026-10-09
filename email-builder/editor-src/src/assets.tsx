import React, { useRef, useState } from 'react';
import { create } from 'zustand';

import { CloseOutlined, CloudUploadOutlined } from '@mui/icons-material';
import { Alert, Box, Button, IconButton, LinearProgress, TextField, Tooltip, Typography } from '@mui/material';

/**
 * EDM: images from the Files library (edm_assets), offered in the Image
 * block's panel so a user picks an uploaded image instead of pasting a URL.
 *
 * The list is pushed in by the parent page with the 'load' message (see
 * bridge.ts); email-builder/index.php renders it from the Files table,
 * newest first. The grid shows the newest SHOW_LIMIT; the search box filters
 * the whole list by name so older images stay reachable.
 *
 * Images can also be uploaded right here (Upload image, or drop files on the
 * box): they go to the Files library through assets/api.php assets_upload
 * (same origin, the odb session), appear first in the grid and - for a
 * single file - are applied to the selected Image block straight away.
 *
 * Each tile has a delete button (assets/api.php assets_delete). It asks
 * first with the parent page's edmConfirm (js/edm-confirm.js - never a native
 * confirm()); the server keeps the stored file when a newsletter or template
 * already uses it.
 */
const SHOW_LIMIT = 30;
const MAX_BYTES = 5 * 1024 * 1024; // AssetController::MAX_BYTES
const ACCEPT = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
// editor/index.html -> edm/assets/api.php
const UPLOAD_URL = new URL('../../assets/api.php?action=assets_upload', window.location.href).toString();
const DELETE_URL = new URL('../../assets/api.php?action=assets_delete', window.location.href).toString();

type TConfirm = (message: string, onConfirm: () => void, options?: { title?: string; variant?: string; confirmLabel?: string }) => void;

export type TAsset = { id: number; name: string; url: string };

const assetsStore = create<{ assets: TAsset[] }>(() => ({ assets: [] }));

export function setAssets(assets: TAsset[]) {
  assetsStore.setState({ assets });
}

function addAssets(added: TAsset[]) {
  assetsStore.setState((s) => ({ assets: [...added, ...s.assets.filter((a) => !added.some((n) => n.id === a.id))] }));
}

function removeAsset(id: number) {
  assetsStore.setState((s) => ({ assets: s.assets.filter((a) => a.id !== id) }));
}

/** The parent page's edmConfirm (same origin), or null when it is not loaded. */
function parentConfirm(): TConfirm | null {
  try {
    const fn = (window.parent as unknown as { edmConfirm?: TConfirm }).edmConfirm;
    return typeof fn === 'function' ? fn : null;
  } catch {
    return null;
  }
}

/** Removes an asset from the Files library; rejects with a message. */
function deleteOne(asset: TAsset): Promise<void> {
  return fetch(DELETE_URL + '&id=' + encodeURIComponent(String(asset.id)), {
    method: 'POST',
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  })
    .then((r) => r.json())
    .then((res) => {
      if (!res || !res.success) {
        throw new Error(asset.name + ': ' + ((res && res.message) || 'could not be deleted.'));
      }
    });
}

/** One file to the Files library; resolves with the new asset or rejects with a message. */
function uploadOne(file: File): Promise<TAsset> {
  if (!ACCEPT.includes(file.type)) {
    return Promise.reject(new Error(file.name + ': only JPG, PNG, GIF or WebP images can be uploaded.'));
  }
  if (file.size > MAX_BYTES) {
    return Promise.reject(new Error(file.name + ': the image must not be larger than 5 MB.'));
  }
  const body = new FormData();
  body.append('file', file);
  return fetch(UPLOAD_URL, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then((r) => r.json())
    .then((res) => {
      if (!res || !res.success) {
        const first = res && res.errors ? (Object.values(res.errors)[0] as string[] | undefined)?.[0] : null;
        throw new Error(file.name + ': ' + (first || (res && res.message) || 'the upload failed.'));
      }
      return { id: Number(res.data.id), name: String(res.data.name), url: String(res.data.url) };
    });
}

type Props = {
  selectedUrl: string | null | undefined;
  onPick: (asset: TAsset) => void;
};

export function AssetPicker({ selectedUrl, onPick }: Props) {
  const assets = assetsStore((s) => s.assets);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState<{ done: number; total: number } | null>(null);
  const [errors, setErrors] = useState<string[]>([]);
  const [dragOver, setDragOver] = useState(false);
  const [deleting, setDeleting] = useState<number | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);

  // Delete needs the parent page's confirm dialog; without it there is no button.
  const canDelete = parentConfirm() !== null;
  const q = query.trim().toLowerCase();
  const matches = q === '' ? assets : assets.filter((a) => a.name.toLowerCase().includes(q));
  const shown = matches.slice(0, SHOW_LIMIT);

  // Uploads one after the other (progress counts them); every success is
  // added to the grid as it lands, a single upload is also used right away.
  const upload = async (files: File[]) => {
    if (files.length === 0 || busy) {
      return;
    }
    setErrors([]);
    setQuery('');
    const failed: string[] = [];
    const added: TAsset[] = [];
    setBusy({ done: 0, total: files.length });
    for (let i = 0; i < files.length; i++) {
      try {
        const asset = await uploadOne(files[i]);
        added.push(asset);
        addAssets([asset]);
      } catch (e) {
        failed.push(e instanceof Error ? e.message : String(e));
      }
      setBusy({ done: i + 1, total: files.length });
    }
    setBusy(null);
    setErrors(failed);
    if (added.length === 1) {
      onPick(added[0]);
    }
  };

  // Delete: confirm in the parent page's dialog, then remove from the grid.
  const askDelete = (a: TAsset) => {
    const confirm = parentConfirm();
    if (!confirm || deleting !== null) {
      return;
    }
    const inUse = !!selectedUrl && selectedUrl === a.url;
    confirm(
      'Delete "' + a.name + '" from the Files library? It disappears from the library for everyone. ' +
        'Newsletters and templates that already use it keep showing it.' +
        (inUse ? ' This Image block still uses it - choose another image if you do not want it here.' : ''),
      () => {
        setErrors([]);
        setDeleting(a.id);
        deleteOne(a)
          .then(() => removeAsset(a.id))
          .catch((e) => setErrors([e instanceof Error ? e.message : String(e)]))
          .finally(() => setDeleting(null));
      },
      { title: 'Delete image', variant: 'danger', confirmLabel: 'Delete' }
    );
  };

  return (
    <Box
      onDragOver={(ev) => {
        if (ev.dataTransfer.types.includes('Files')) {
          ev.preventDefault();
          setDragOver(true);
        }
      }}
      onDragLeave={() => setDragOver(false)}
      onDrop={(ev) => {
        if (ev.dataTransfer.files.length > 0) {
          ev.preventDefault();
          setDragOver(false);
          upload(Array.from(ev.dataTransfer.files));
        }
      }}
      sx={{
        p: dragOver ? 1 : 0,
        borderRadius: 1,
        outline: dragOver ? '2px dashed' : 'none',
        outlineColor: 'primary.main',
        bgcolor: dragOver ? 'action.hover' : 'transparent',
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1, mb: 1 }}>
        <Typography variant="caption" color="text.secondary">
          Files library - click an image to use it
        </Typography>
        <Button
          size="small"
          variant="outlined"
          startIcon={<CloudUploadOutlined fontSize="small" />}
          onClick={() => fileInput.current?.click()}
          disabled={!!busy}
          sx={{ flexShrink: 0, py: 0.25 }}
        >
          Upload image
        </Button>
        <input
          ref={fileInput}
          type="file"
          accept={ACCEPT.join(',')}
          multiple
          hidden
          onChange={(ev) => {
            const files = Array.from(ev.target.files ?? []);
            ev.target.value = '';
            upload(files);
          }}
        />
      </Box>
      {busy && (
        <Box sx={{ mb: 1 }}>
          <Typography variant="caption" color="text.secondary">
            Uploading {busy.done + 1 > busy.total ? busy.total : busy.done + 1} of {busy.total}...
          </Typography>
          <LinearProgress variant="determinate" value={(busy.done / busy.total) * 100} />
        </Box>
      )}
      {errors.length > 0 && (
        <Alert severity="error" onClose={() => setErrors([])} sx={{ mb: 1, py: 0, '& .MuiAlert-message': { fontSize: 12 } }}>
          {errors.map((m) => (
            <div key={m}>{m}</div>
          ))}
        </Alert>
      )}
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
        <Box
          component="button"
          type="button"
          onClick={() => fileInput.current?.click()}
          sx={{
            width: '100%',
            p: 1.5,
            border: '1px dashed',
            borderColor: 'divider',
            borderRadius: 1,
            bgcolor: 'transparent',
            cursor: 'pointer',
            textAlign: 'center',
          }}
        >
          <Typography variant="caption" color="text.secondary">
            No images yet. Click Upload image, or drop images here - they are added to Files.
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
              <Box
                key={a.id}
                sx={{
                  position: 'relative',
                  opacity: deleting === a.id ? 0.4 : 1,
                }}
              >
                <Tooltip title={a.name}>
                  <Box
                    component="button"
                    type="button"
                    onClick={() => onPick(a)}
                    aria-label={'Use ' + a.name}
                    aria-pressed={selected}
                    sx={{
                      p: 0,
                      width: '100%',
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
                {canDelete && (
                  <Tooltip title="Delete image">
                    <IconButton
                      size="small"
                      aria-label={'Delete ' + a.name}
                      disabled={deleting !== null}
                      onClick={() => askDelete(a)}
                      sx={{
                        position: 'absolute',
                        top: 4,
                        right: 4,
                        p: 0.25,
                        bgcolor: 'rgba(255,255,255,0.9)',
                        border: '1px solid',
                        borderColor: 'divider',
                        '&:hover': { bgcolor: 'error.main', color: '#fff', borderColor: 'error.main' },
                      }}
                    >
                      <CloseOutlined sx={{ fontSize: 14 }} />
                    </IconButton>
                  </Tooltip>
                )}
              </Box>
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
