import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { KeyRound, Pencil, X } from 'lucide-react';
import { Checkbox, FileUpload, Input, Select, Textarea, Toggle } from '@/components/ui';
import { appUrl } from '@/lib/config';
import type { Option } from '@/lib/types';
import { PasswordInput } from '../components';

export interface SettingField {
  key: string;
  label: string;
  type?: 'text' | 'email' | 'url' | 'tel' | 'number' | 'select' | 'toggle' | 'color' | 'textarea' | 'password' | 'image' | 'checkboxes';
  options?: Option[];
  help?: string;
  placeholder?: string;
  col?: number;
  secret?: boolean;
  required?: boolean;
  suffix?: string;
  prefix?: string;
  accept?: string;
  max_size?: number;
  default_preview?: string;
  dark?: boolean;
  danger?: boolean;
}

export const colSpan: Record<number, string> = {
  12: 'sm:col-span-12', 8: 'sm:col-span-8', 6: 'sm:col-span-6', 5: 'sm:col-span-5', 4: 'sm:col-span-4', 3: 'sm:col-span-3',
};

interface Props {
  field: SettingField;
  value: string;
  onChange: (v: string) => void;
  invalid?: boolean;
  disabled?: boolean;
  /** secrets */
  secretStored?: boolean;
  secretEditing?: boolean;
  secretCleared?: boolean;
  onSecretEdit?: (editing: boolean) => void;
  onSecretClear?: () => void;
  /** images */
  file?: File | null;
  onFile?: (f: File | null) => void;
  removed?: boolean;
  onRemove?: () => void;
  onError?: (m: string) => void;
}

/** One settings control, rendered by field type. */
export function SettingInput({ field: f, value, onChange, invalid, disabled, secretStored, secretEditing, secretCleared, onSecretEdit, onSecretClear, file, onFile, removed, onRemove, onError }: Props) {
  const id = `s-${f.key}`;
  switch (f.type) {
    case 'select':
      return <Select id={id} value={value} onChange={(e) => onChange(e.target.value)} options={f.options ?? []} invalid={invalid} disabled={disabled} placeholder={f.required ? undefined : '— None —'} />;
    case 'toggle':
      return (
        <div className={clsx('rounded-xl border px-3.5 py-3 transition', value === '1' && f.danger ? 'border-red-200 bg-red-50/60 dark:border-red-500/30 dark:bg-red-500/10' : 'border-slate-200 dark:border-slate-700')}>
          <Toggle id={id} checked={value === '1'} onChange={(v) => onChange(v ? '1' : '0')} label={f.label} description={f.help} disabled={disabled} />
        </div>
      );
    case 'textarea':
      return <Textarea id={id} value={value} onChange={(e) => onChange(e.target.value)} invalid={invalid} disabled={disabled} rows={3} placeholder={f.placeholder} />;
    case 'color':
      return (
        <div className="flex items-center gap-2">
          <label className="relative inline-flex h-[42px] w-12 shrink-0 cursor-pointer overflow-hidden rounded-xl border border-slate-200 shadow-sm transition hover:scale-105 dark:border-slate-700" style={{ background: /^#[0-9a-f]{6}$/i.test(value) ? value : '#ffffff' }}>
            <span className="sr-only">Pick {f.label}</span>
            <input type="color" value={/^#[0-9a-f]{6}$/i.test(value) ? value : '#000000'} onChange={(e) => onChange(e.target.value.toUpperCase())} disabled={disabled} className="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label={`${f.label} colour picker`} />
          </label>
          <Input id={id} value={value} onChange={(e) => onChange(e.target.value)} invalid={invalid} disabled={disabled} maxLength={7} className="font-mono uppercase" placeholder="#0B2A5B" />
        </div>
      );
    case 'password': {
      if (secretStored && !secretEditing && !secretCleared) {
        return (
          <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/50">
            <KeyRound className="h-4 w-4 shrink-0 text-emerald-600" />
            <span className="flex-1 truncate text-sm text-slate-600 dark:text-slate-300">
              <span className="font-mono tracking-widest">••••••••</span> <span className="text-xs text-slate-500">stored securely</span>
            </span>
            {!disabled && (
              <>
                <button type="button" onClick={() => onSecretEdit?.(true)} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">
                  <Pencil className="h-3 w-3" /> Change
                </button>
                <button type="button" onClick={onSecretClear} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
                  <X className="h-3 w-3" /> Clear
                </button>
              </>
            )}
          </div>
        );
      }
      return (
        <div>
          <PasswordInput id={id} value={value} onChange={onChange} invalid={invalid} generator={false} meter={false} autoComplete="off" placeholder={secretCleared ? 'Will be cleared on save' : secretStored ? 'Enter a new value' : 'Not set'} />
          {(secretEditing || secretCleared) && (
            <button type="button" onClick={() => onSecretEdit?.(false)} className="mt-1 text-xs font-medium text-slate-500 hover:text-slate-800 dark:hover:text-white">
              Keep the stored value
            </button>
          )}
        </div>
      );
    }
    case 'checkboxes': {
      const set = new Set(value.split(',').filter(Boolean));
      return (
        <div className={clsx('grid grid-cols-2 gap-2 rounded-xl border p-3 sm:grid-cols-3 lg:grid-cols-4', invalid ? 'border-red-300' : 'border-slate-200 dark:border-slate-700')}>
          {(f.options ?? []).map((o) => (
            <Checkbox
              key={String(o.value)}
              label={o.label}
              checked={set.has(String(o.value))}
              disabled={disabled}
              onChange={(e) => {
                const next = new Set(set);
                if (e.target.checked) next.add(String(o.value));
                else next.delete(String(o.value));
                onChange((f.options ?? []).map((x) => String(x.value)).filter((v) => next.has(v)).join(','));
              }}
            />
          ))}
        </div>
      );
    }
    case 'image': {
      const current = removed ? null : value || null;
      const preview = file ? null : current ? appUrl(current) : f.default_preview ? appUrl(f.default_preview) : null;
      return (
        <div className="space-y-2">
          <div className={clsx('flex h-24 items-center justify-center rounded-xl border p-3', f.dark ? 'border-brand-900 bg-brand-900' : 'border-slate-200 bg-white dark:border-slate-700')}>
            {file ? (
              <FilePreview file={file} />
            ) : preview ? (
              <img src={preview} alt={`${f.label} preview`} className="max-h-full max-w-full object-contain" />
            ) : (
              <span className="text-xs text-slate-400">No image</span>
            )}
          </div>
          {!current && !file && f.default_preview && <p className="text-[11px] text-slate-500">Showing the built-in default.</p>}
          {!disabled && (
            <FileUpload file={file ?? null} onFile={(fl) => onFile?.(fl)} currentPath={current} onRemoveCurrent={onRemove} accept={f.accept} maxSize={f.max_size} image compact invalid={invalid} onError={onError} />
          )}
        </div>
      );
    }
    default:
      return (
        <Input
          id={id}
          type={f.type === 'number' ? 'number' : f.type === 'email' ? 'email' : f.type === 'tel' ? 'tel' : f.type === 'url' ? 'url' : 'text'}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          invalid={invalid}
          disabled={disabled}
          placeholder={f.placeholder}
          suffix={f.suffix}
          prefix={f.prefix}
          step={f.type === 'number' ? 'any' : undefined}
        />
      );
  }
}

function FilePreview({ file }: { file: File }) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    const u = URL.createObjectURL(file);
    setUrl(u);
    return () => URL.revokeObjectURL(u);
  }, [file]);
  return url ? <img src={url} alt="New upload preview" className="max-h-full max-w-full object-contain" /> : null;
}
