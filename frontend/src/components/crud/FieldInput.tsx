import { Combobox, FileUpload, Input, RadioGroup, RichText, Select, Textarea, Toggle } from '@/components/ui';
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
    case 'password':
      return <Input id={id} type="password" autoComplete="new-password" value={str} placeholder={f.placeholder ?? '••••••••'} invalid={invalid} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
    default:
      return (
        <Input
          id={id}
          type={f.type === 'email' ? 'email' : f.type === 'tel' ? 'tel' : f.type === 'url' ? 'url' : 'text'}
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
