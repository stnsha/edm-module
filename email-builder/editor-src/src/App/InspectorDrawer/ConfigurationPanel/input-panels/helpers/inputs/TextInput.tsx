import React, { useEffect, useRef, useState } from 'react';

import { InputProps, TextField } from '@mui/material';

import { clearActiveInput, setActiveInput } from '../../../../../../variables';

type Props = {
  label: string;
  rows?: number;
  placeholder?: string;
  helperText?: string | JSX.Element;
  InputProps?: InputProps;
  defaultValue: string;
  onChange: (v: string) => void;
};
export default function TextInput({ helperText, label, placeholder, rows, InputProps, defaultValue, onChange }: Props) {
  const [value, setValue] = useState(defaultValue);
  const isMultiline = typeof rows === 'number' && rows > 1;

  // EDM: lets the Personalisation box insert a variable at the caret.
  const inputRef = useRef<HTMLInputElement | HTMLTextAreaElement | null>(null);
  const valueRef = useRef(value);
  valueRef.current = value;
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;
  const handle = useRef({
    insert: (token: string) => {
      const el = inputRef.current;
      const current = valueRef.current;
      const start = el?.selectionStart ?? current.length;
      const end = el?.selectionEnd ?? current.length;
      const next = current.slice(0, start) + token + current.slice(end);
      setValue(next);
      onChangeRef.current(next);
      requestAnimationFrame(() => {
        if (el) {
          el.focus();
          el.setSelectionRange(start + token.length, start + token.length);
        }
      });
    },
  }).current;
  useEffect(() => () => clearActiveInput(handle), [handle]);

  // EDM: follow edits made elsewhere (typing on the canvas) while this panel
  // stays mounted; own keystrokes round-trip to the same value, so no loop.
  useEffect(() => {
    if (defaultValue !== valueRef.current) {
      setValue(defaultValue);
    }
  }, [defaultValue]);

  return (
    <TextField
      fullWidth
      multiline={isMultiline}
      minRows={rows}
      variant={isMultiline ? 'outlined' : 'standard'}
      label={label}
      placeholder={placeholder}
      helperText={helperText}
      InputProps={InputProps}
      inputRef={inputRef}
      onFocus={() => setActiveInput(handle)}
      value={value}
      onChange={(ev) => {
        const v = ev.target.value;
        setValue(v);
        onChange(v);
      }}
    />
  );
}
