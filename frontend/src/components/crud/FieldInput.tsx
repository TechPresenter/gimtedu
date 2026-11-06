import { useState } from 'react';
import { Combobox, FileUpload, Input, PhoneInput, RadioGroup, RichText, Select, Textarea, Toggle } from '@/components/ui';
import type { Option } from '@/lib/types';
import type { CrudField } from './types';

interface FieldInputProps {
  field: CrudField;
  value: unknown;
  onChange: (v: unknown) => void;
  invalid?: boolean;
  /** all current form values (dependent selects) */
  values: Record<string, unknown>;
  labels?: Option[];
  file?: File | null;
  onFile?: (f: File | null) => void;
  onRemoveCurrent?: () => void;
  removed?: boolean;
  disabled?: boolean;
  onError?: (msg: string) => void;
}

/* ---- email: same normalisation as app/services/phone.php email_normalize() + typo hints for common providers ---- */

const EMAIL_DOMAINS = ['gmail.com', 'yahoo.com', 'yahoo.co.in', 'yahoo.in', 'hotmail.com', 'outlook.com', 'outlook.in', 'live.com', 'icloud.com', 'rediffmail.com', 'ymail.com', 'aol.com', 'msn.com', 'protonmail.com', 'zoho.com'];
const TLD_FIX: Record<string, string> = { con: 'com', cmo: 'com', ocm: 'com', vom: 'com', xom: 'com', comm: 'com', coom: 'com', cpm: 'com', 'co.inn': 'co.in', 'con.in': 'co.in' };

/** Trim, drop spaces / "mailto:", lowercase, IDN domain -> ASCII (punycode via the URL parser). */
function normalizeEmail(v: string): string {
  const e = v.replace(/\s+/g, '').replace(/^mailto:/i, '');
  const at = e.lastIndexOf('@');
  if (at < 0) return e.toLowerCase();
  let domain = e
    .slice(at + 1)
    .toLowerCase()
    .replace(/\.+$/, '');
  if (/[^\x20-\x7e]/.test(domain)) {
    try {
      domain = new URL(`http://${domain}/`).hostname || domain;
    } catch {
      /* keep */
    }
  }
  return `${e.slice(0, at).toLowerCase()}@${domain}`;
}

function editDistance(a: string, b: string): number {
  const d = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)] as number[]);
  for (let j = 1; j <= b.length; j++) d[0][j] = j;
  for (let i = 1; i <= a.length; i++)
    for (let j = 1; j <= b.length; j++) {
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
    }
  return d[a.length][b.length];
}

/** "name@gmial.com" -> "name@gmail.com"; null when nothing looks mistyped. */
function suggestEmail(v: string): string | null {
  const at = v.lastIndexOf('@');
  if (at < 1) return null;
  const domain = v.slice(at + 1);
  if (!domain || EMAIL_DOMAINS.includes(domain)) return null;
  let best: string | null = null;
  let score = 3;
  const name = domain.split('.')[0];
  for (const d of EMAIL_DOMAINS) {
    // same provider name with another ending = a regional domain ("yahoo.co.uk"), not a typo
    if (d.split('.')[0] === name) continue;
    const s = editDistance(domain, d);
    if (s < score && s <= (d.length > 9 ? 2 : 1)) {
      best = d;
      score = s;
    }
  }
  // provider name with a missing / mistyped ending ("gmail", "gmail.co", "yahoo.om")
  const provider = !best ? EMAIL_DOMAINS.find((d) => d.split('.')[0] === name) : undefined;
  if (provider && (domain === name || ['co', 'om', 'cm', 'ni', ...Object.keys(TLD_FIX)].includes(domain.slice(name.length + 1)))) best = provider;
  if (!best) {
    const dot = domain.indexOf('.');
    const tld = dot > 0 ? domain.slice(dot + 1) : '';
    if (TLD_FIX[tld]) best = `${domain.slice(0, dot)}.${TLD_FIX[tld]}`;
  }
  return best && best !== domain ? `${v.slice(0, at)}@${best}` : null;
}

function EmailField({ id, value, onChange, invalid, disabled, placeholder, maxLength }: { id: string; value: string; onChange: (v: unknown) => void; invalid?: boolean; disabled?: boolean; placeholder?: string; maxLength?: number }) {
  const [hint, setHint] = useState<string | null>(null);
  return (
    <>
      <Input
        id={id}
        type="email"
        inputMode="email"
        autoCapitalize="off"
        spellCheck={false}
        value={value}
        placeholder={placeholder}
        maxLength={maxLength}
        invalid={invalid}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        onBlur={(e) => {
          const norm = normalizeEmail(e.target.value);
          if (norm !== e.target.value) onChange(norm);
          setHint(suggestEmail(norm));
        }}
      />
      {hint && (
        <p className="form-hint" aria-live="polite">
          Did you mean{' '}
          <button
            type="button"
            className="font-semibold text-brand-700 underline underline-offset-2 dark:text-brand-300"
            onClick={() => {
              onChange(hint);
              setHint(null);
            }}
          >
            {hint}
          </button>
          ?
        </p>
      )}
    </>
  );
}

/** Renders the control for a CRUD field definition. */
export function FieldInput({ field: f, value, onChange, invalid, values, labels, file, onFile, onRemoveCurrent, removed, disabled, onError }: FieldInputProps) {
  const id = `f-${f.name}`;
  const str = value === null || value === undefined ? '' : String(value);
  const dependParams: Record<string, string> = {};
  let dependsMissing = false;
  Object.entries(f.depends ?? {}).forEach(([param, field]) => {
    const v = values[field];
    if (v === null || v === undefined || v === '') dependsMissing = true;
    else dependParams[param] = String(v);
  });

  switch (f.type) {
    case 'textarea':
    case 'json':
      return <Textarea id={id} value={str} rows={f.rows ?? (f.type === 'json' ? 8 : 3)} placeholder={f.placeholder} invalid={invalid} disabled={disabled} maxLength={f.maxlength} className={f.type === 'json' ? 'font-mono text-xs' : undefined} onChange={(e) => onChange(e.target.value)} />;
    case 'richtext':
      return <RichText id={id} value={str} onChange={onChange} placeholder={f.placeholder} invalid={invalid} />;
    case 'boolean':
    case 'toggle':
    case 'checkbox':
      return <Toggle id={id} checked={!!value && value !== '0'} onChange={(v) => onChange(v)} label={f.placeholder ?? (value ? 'Yes' : 'No')} disabled={disabled} />;
    case 'radio':
      return <RadioGroup name={f.name} value={str} onChange={onChange} options={f.options ?? []} />;
    case 'image':
    case 'file':
      return (
        <FileUpload
          id={id}
          file={file ?? null}
          onFile={(fl) => onFile?.(fl)}
          currentPath={removed ? null : (str || null)}
          onRemoveCurrent={onRemoveCurrent}
          accept={f.accept}
          maxSize={f.max_size}
          image={f.type === 'image'}
          invalid={invalid}
          onError={onError}
          privateFile={f.private}
        />
      );
    case 'multiselect':
      return (
        <Combobox
          id={id}
          multiple
          value={Array.isArray(value) ? (value as (string | number)[]) : str ? str.split(',') : []}
          onChange={(v) => onChange(v)}
          options={f.options}
          optionsUrl={f.options_url}
          params={dependParams}
          placeholder={f.placeholder ?? `Select ${f.label.toLowerCase()}…`}
          invalid={invalid}
          disabled={disabled || dependsMissing}
          initialOptions={labels}
        />
      );
    case 'select':
    case 'combobox': {
      const useNative = f.type === 'select' && !f.options_url && (f.options?.length ?? 0) <= 12;
      if (useNative) {
        return <Select id={id} value={str} options={f.options ?? []} placeholder={f.placeholder ?? (f.required ? `Select ${f.label.toLowerCase()}` : '— None —')} invalid={invalid} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
      }
      return (
        <Combobox
          id={id}
          value={str === '' ? null : str}
          onChange={(v) => onChange(v ?? '')}
          options={f.options_url ? undefined : f.options}
          optionsUrl={f.options_url}
          params={dependParams}
          placeholder={dependsMissing ? `Select ${Object.values(f.depends ?? {}).map((d) => d.replace(/_id$/, '')).join(', ')} first` : f.placeholder ?? `Search ${f.label.toLowerCase()}…`}
          invalid={invalid}
          disabled={disabled || dependsMissing}
          initialOptions={labels}
        />
      );
    }
    case 'number':
    case 'decimal':
    case 'money':
      return (
        <Input
          id={id}
          type="number"
          inputMode="decimal"
          value={str}
          min={f.min}
          max={f.max}
          step={f.step ?? (f.type === 'number' ? 1 : 0.01)}
          placeholder={f.placeholder}
          invalid={invalid}
          disabled={disabled}
          prefix={f.type === 'money' ? '₹' : f.prefix}
          suffix={f.suffix}
          onChange={(e) => onChange(e.target.value)}
        />
      );
    case 'date':
    case 'datetime':
    case 'time':
      return <Input id={id} type={f.type === 'datetime' ? 'datetime-local' : f.type} value={str} min={f.min as unknown as string} max={f.max as unknown as string} invalid={invalid} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
    case 'color':
      return (
        <div className="flex items-center gap-2">
          <input type="color" value={str || '#0B2A5B'} onChange={(e) => onChange(e.target.value)} className="h-10 w-12 cursor-pointer rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-700" aria-label={f.label} />
          <Input id={id} value={str} onChange={(e) => onChange(e.target.value)} placeholder="#0B2A5B" invalid={invalid} className="font-mono" />
        </div>
      );
    case 'tel':
      // international number with a country-code selector; the server stores E.164 (app/crud.php)
      return (
        <PhoneInput
          id={id}
          value={str}
          onChange={(v) => onChange(v)}
          defaultCountry={((f as { country?: string }).country || 'IN').toUpperCase()}
          invalid={invalid}
          disabled={disabled}
          placeholder={f.placeholder && !f.placeholder.includes('+') ? f.placeholder : undefined}
        />
      );
    case 'email':
      return <EmailField id={id} value={str} onChange={onChange} invalid={invalid} disabled={disabled} placeholder={f.placeholder} maxLength={f.maxlength} />;
    case 'password':
      return <Input id={id} type="password" autoComplete="new-password" value={str} placeholder={f.placeholder ?? '••••••••'} invalid={invalid} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
    default:
      return (
        <Input
          id={id}
          type={f.type === 'url' ? 'url' : 'text'}
          value={str}
          placeholder={f.placeholder}
          maxLength={f.maxlength}
          pattern={f.pattern}
          invalid={invalid}
          disabled={disabled}
          prefix={f.prefix}
          suffix={f.suffix}
          onChange={(e) => onChange(f.type === 'slug' ? e.target.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-') : e.target.value)}
        />
      );
  }
}
