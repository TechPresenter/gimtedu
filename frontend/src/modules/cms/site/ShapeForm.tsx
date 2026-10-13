import { useMemo, useState, type KeyboardEvent } from 'react';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, ChevronDown, Copy, GripVertical, Plus, Search, Trash2, X } from 'lucide-react';
import { Button, Combobox, Field, IconButton, Input, Modal, RichText, Select, Textarea, Toggle } from '@/components/ui';
import { formatNumber } from '@/lib/format';
import type { Option } from '@/lib/types';
import { emptyItem, hasErrorsUnder, whenMatches } from './api';
import { CmsIcon, ICONS } from './icons';
import { ImageField } from './ImagePicker';
import { moveItem, useSortable } from './sortable';
import type { CmsSchema, Data, LinkValue, PreviewData, ShapeField } from './types';

const colClass: Record<number, string> = {
  12: 'sm:col-span-12', 9: 'sm:col-span-9', 8: 'sm:col-span-8', 7: 'sm:col-span-7', 6: 'sm:col-span-6', 5: 'sm:col-span-5', 4: 'sm:col-span-4', 3: 'sm:col-span-3', 2: 'sm:col-span-2',
};

export interface ShapeFormProps {
  fields: ShapeField[];
  value: Data;
  onChange: (next: Data) => void;
  /** errors keyed by dotted path */
  errors?: Record<string, string>;
  /** prefix of this object's paths in `errors` (e.g. "blocks.2.data" or "content") */
  path: string;
  schema: CmsSchema;
  preview?: PreviewData;
  disabled?: boolean;
  idPrefix?: string;
}

/** Form generated from field definitions (app/services/cms.php). Handles nested repeaters, links, images, icons and conditions. */
export function ShapeForm({ fields, value, onChange, errors = {}, path, schema, preview, disabled, idPrefix = 'f' }: ShapeFormProps) {
  const v = value ?? {};
  const set = (name: string, next: unknown) => onChange({ ...v, [name]: next });
  return (
    <div className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-12">
      {fields.filter((f) => whenMatches(f, v)).map((f) => {
        const p = `${path}.${f.name}`;
        const id = `${idPrefix}-${p.replace(/[^a-z0-9]+/gi, '-')}`;
        const col = f.col ?? (['textarea', 'richtext', 'repeater', 'list', 'multiselect', 'programs'].includes(f.type) ? 12 : f.type === 'link' ? 6 : 12);
        if (f.type === 'toggle') {
          return (
            <div key={f.name} className={clsx('col-span-1 flex items-end pb-1', colClass[col] ?? 'sm:col-span-12')}>
              <Toggle id={id} checked={!!v[f.name]} onChange={(c) => set(f.name, c)} label={f.label} description={f.help} disabled={disabled} />
            </div>
          );
        }
        if (f.type === 'repeater') {
          return (
            <div key={f.name} className="col-span-1 sm:col-span-12">
              <Repeater field={f} items={Array.isArray(v[f.name]) ? v[f.name] : []} onChange={(items) => set(f.name, items)} errors={errors} path={p} schema={schema} preview={preview} disabled={disabled} idPrefix={idPrefix} />
            </div>
          );
        }
        const err = errors[p] ?? (f.type === 'link' ? errors[`${p}.label`] ?? errors[`${p}.url`] : undefined);
        const str = typeof v[f.name] === 'string' ? (v[f.name] as string) : '';
        const counter = f.max && ['text', 'textarea'].includes(f.type) && str.length > f.max * 0.7 ? `${str.length}/${f.max}` : undefined;
        return (
          <Field key={f.name} className={clsx('col-span-1', colClass[col] ?? 'sm:col-span-12')} label={f.label} required={f.required} htmlFor={id} error={err} hint={f.help} aside={counter}>
            <FieldControl field={f} id={id} value={v[f.name]} onChange={(nv) => set(f.name, nv)} invalid={!!err} schema={schema} preview={preview} disabled={disabled} />
          </Field>
        );
      })}
    </div>
  );
}

function FieldControl({ field: f, id, value, onChange, invalid, schema, preview, disabled }: {
  field: ShapeField; id: string; value: unknown; onChange: (v: unknown) => void; invalid: boolean; schema: CmsSchema; preview?: PreviewData; disabled?: boolean;
}) {
  const str = value === null || value === undefined ? '' : String(value);
  switch (f.type) {
    case 'textarea':
      return <Textarea id={id} value={str} rows={f.rows ?? 3} maxLength={f.max} placeholder={f.placeholder} invalid={invalid} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
    case 'richtext':
      return disabled
        ? <div id={id} className="max-h-60 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300 [&_li]:ml-5 [&_ul]:list-disc" dangerouslySetInnerHTML={{ __html: str }} />
        : <RichText id={id} value={str} onChange={onChange} invalid={invalid} minHeight={160} />;
    case 'image':
      return <ImageField id={id} value={str} onChange={onChange} invalid={invalid} disabled={disabled} />;
    case 'icon':
      return <IconPicker id={id} value={str} onChange={onChange} icons={schema.icons} invalid={invalid} disabled={disabled} />;
    case 'link':
      return <LinkInput id={id} value={(value as LinkValue) ?? { label: '', url: '' }} onChange={onChange} invalid={invalid} disabled={disabled} />;
    case 'select': {
      const sourceField = f.name === 'source' && (f.options ?? []).some((o) => o.value === 'programs' || o.value === 'students');
      const opts: Option[] = sourceField
        ? (f.options ?? []).map((o) => {
            const live = schema.stat_sources.find((s) => s.value === o.value)?.live;
            return live !== undefined && live !== null ? { ...o, label: `${o.label} — now ${formatNumber(live, Number.isInteger(live) ? 0 : 1)}` } : o;
          })
        : f.options ?? [];
      const hasEmpty = opts.some((o) => o.value === '');
      return (
        <Select id={id} value={str || (f.default !== undefined ? String(f.default) : '')} options={opts} invalid={invalid} disabled={disabled}
          placeholder={hasEmpty ? undefined : f.required ? `Select ${f.label.toLowerCase()}` : '— Select —'} onChange={(e) => onChange(e.target.value)} />
      );
    }
    case 'multiselect': {
      const arr = Array.isArray(value) ? (value as string[]) : [];
      return (
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={f.label} id={id}>
          {(f.options ?? []).map((o) => {
            const on = arr.includes(String(o.value));
            return (
              <button
                key={String(o.value)}
                type="button"
                aria-pressed={on}
                disabled={disabled}
                onClick={() => onChange(on ? arr.filter((x) => x !== String(o.value)) : [...arr, String(o.value)])}
                className={clsx('rounded-full border px-3 py-1 text-xs font-medium transition', on ? 'border-brand-700 bg-brand-700 text-white dark:border-brand-400 dark:bg-brand-500' : 'border-slate-200 text-slate-600 hover:border-brand-300 hover:text-brand-700 dark:border-slate-700 dark:text-slate-300')}
              >
                {o.label}
              </button>
            );
          })}
        </div>
      );
    }
    case 'number':
      return <Input id={id} type="number" inputMode="decimal" value={str} min={f.min} max={f.max} step={f.step ?? 1} invalid={invalid} disabled={disabled} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} />;
    case 'list':
      return <ListInput id={id} value={Array.isArray(value) ? (value as string[]) : []} onChange={onChange} max={f.max_items} itemMax={f.item_max} placeholder={f.placeholder} invalid={invalid} />;
    case 'programs': {
      const opts: Option[] = (preview?.programs ?? []).map((p) => ({ value: p.id, label: `${p.short_name} — ${p.name}`, sub: p.level }));
      return <Combobox id={id} multiple value={Array.isArray(value) ? (value as number[]) : []} onChange={(nv) => onChange(nv.map(Number))} options={opts} placeholder="Choose programs…" invalid={invalid} disabled={disabled} />;
    }
    case 'department':
      return (
        <Select id={id} value={str} invalid={invalid} disabled={disabled} placeholder={f.required ? 'Select department' : 'All departments'}
          options={(preview?.departments ?? []).map((d) => ({ value: d.id, label: d.name }))} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)} />
      );
    case 'gallery':
      return (
        <Select id={id} value={str} invalid={invalid} disabled={disabled} placeholder="Select an album"
          options={(preview?.galleries ?? []).map((g) => ({ value: g.id, label: `${g.title} (${g.items} photos)` }))} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)} />
      );
    case 'url':
      return <Input id={id} value={str} maxLength={f.max ?? 255} placeholder={f.placeholder ?? '/page or https://…'} invalid={invalid} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
    default:
      return <Input id={id} value={str} maxLength={f.max} placeholder={f.placeholder} invalid={invalid} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
  }
}

/** Button text + URL pair. */
export function LinkInput({ id, value, onChange, invalid, disabled }: { id: string; value: LinkValue; onChange: (v: LinkValue) => void; invalid?: boolean; disabled?: boolean }) {
  const v = value ?? { label: '', url: '' };
  return (
    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
      <Input id={id} value={v.label ?? ''} maxLength={60} placeholder="Button text" invalid={invalid && !v.label} disabled={disabled} onChange={(e) => onChange({ ...v, label: e.target.value })} aria-label="Button text" />
      <Input value={v.url ?? ''} maxLength={255} placeholder="/apply or https://…" invalid={invalid && !!v.label} disabled={disabled} onChange={(e) => onChange({ ...v, url: e.target.value })} aria-label="Button link" />
    </div>
  );
}

/** Chips input for a short list of strings. */
function ListInput({ id, value, onChange, max, itemMax = 60, placeholder, invalid }: { id: string; value: string[]; onChange: (v: string[]) => void; max?: number; itemMax?: number; placeholder?: string; invalid?: boolean }) {
  const [draft, setDraft] = useState('');
  const add = () => {
    const t = draft.trim();
    if (!t || (max && value.length >= max)) return;
    onChange([...value, t.slice(0, itemMax)]);
    setDraft('');
  };
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      add();
    } else if (e.key === 'Backspace' && !draft && value.length) {
      onChange(value.slice(0, -1));
    }
  };
  return (
    <div className={clsx('flex min-h-[42px] flex-wrap items-center gap-1.5 rounded-xl border bg-white px-2 py-1.5 dark:bg-slate-900', invalid ? 'border-red-300' : 'border-slate-200 dark:border-slate-700')}>
      {value.map((t, i) => (
        <span key={`${t}${i}`} className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-800 dark:bg-brand-500/15 dark:text-brand-100">
          {t}
          <button type="button" onClick={() => onChange(value.filter((_, j) => j !== i))} className="rounded-full p-0.5 hover:bg-brand-100 dark:hover:bg-brand-500/30" aria-label={`Remove ${t}`}>
            <X className="h-3 w-3" />
          </button>
        </span>
      ))}
      {(!max || value.length < max) && (
        <input id={id} value={draft} maxLength={itemMax} onChange={(e) => setDraft(e.target.value)} onKeyDown={onKey} onBlur={add}
          placeholder={value.length ? 'Add…' : placeholder ? `e.g. ${placeholder} — press Enter` : 'Type and press Enter'} className="min-w-[8rem] flex-1 border-0 bg-transparent px-1 py-1 text-sm focus:outline-none focus:ring-0 dark:text-white" />
      )}
    </div>
  );
}

/** Icon chooser (curated website icon set). */
export function IconPicker({ id, value, onChange, icons, invalid, disabled }: { id: string; value: string; onChange: (v: string) => void; icons: string[]; invalid?: boolean; disabled?: boolean }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const list = useMemo(() => icons.filter((n) => ICONS[n] && n.includes(q.trim().toLowerCase())), [icons, q]);
  return (
    <>
      <div className="flex items-center gap-2">
        <button id={id} type="button" onClick={() => setOpen(true)} disabled={disabled}
          className={clsx('flex h-[42px] min-w-0 flex-1 items-center gap-2 rounded-xl border bg-white px-3 text-left text-sm transition hover:border-brand-300 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-slate-900', invalid ? 'border-red-300' : 'border-slate-200 dark:border-slate-700')}>
          <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-[#D03E16] dark:bg-orange-500/15">
            <CmsIcon name={value || 'sparkles'} className="h-4 w-4" />
          </span>
          <span className={clsx('truncate', value ? 'text-slate-800 dark:text-slate-100' : 'text-slate-400')}>{value || 'Choose icon'}</span>
          <ChevronDown className="ml-auto h-4 w-4 shrink-0 text-slate-400" />
        </button>
        {value && !disabled && <IconButton icon={X} label="Clear icon" size="sm" onClick={() => onChange('')} />}
      </div>
      <Modal open={open} onClose={() => setOpen(false)} size="lg" title="Choose an icon" description="Icons available on the website.">
        <div className="relative mb-3">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search icons…" className="pl-9" autoFocus aria-label="Search icons" />
        </div>
        <div className="grid grid-cols-4 gap-2 sm:grid-cols-6">
          {list.map((n) => (
            <button key={n} type="button" title={n}
              onClick={() => {
                onChange(n);
                setOpen(false);
              }}
              className={clsx('flex flex-col items-center gap-1.5 rounded-xl border p-2.5 text-[10px] text-slate-500 transition hover:-translate-y-0.5 hover:border-brand-300 hover:text-brand-700 dark:border-slate-700 dark:text-slate-400',
                value === n ? 'border-brand-600 bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-white' : 'border-slate-200')}>
              <CmsIcon name={n} className="h-5 w-5" />
              <span className="w-full truncate text-center">{n}</span>
            </button>
          ))}
          {!list.length && <p className="col-span-full py-6 text-center text-sm text-slate-500">No icon matches “{q}”.</p>}
        </div>
      </Modal>
    </>
  );
}

/** List of objects (stats, steps, cards …) with add / duplicate / remove / reorder (drag or buttons). */
function Repeater({ field: f, items, onChange, errors, path, schema, preview, disabled, idPrefix }: {
  field: ShapeField; items: Data[]; onChange: (items: Data[]) => void; errors: Record<string, string>; path: string; schema: CmsSchema; preview?: PreviewData; disabled?: boolean; idPrefix: string;
}) {
  const [open, setOpen] = useState<number | null>(items.length ? null : null);
  const max = f.max_items ?? 50;
  const move = (from: number, to: number) => {
    onChange(moveItem(items, from, to));
    if (open === from) setOpen(to);
  };
  const sortable = useSortable(move, `rep-${path}`);
  const ownError = errors[path];
  const label = (it: Data, i: number) => {
    const k = f.item_label ?? 'title';
    const t = it?.[k];
    return typeof t === 'string' && t.trim() ? t : `${f.label.replace(/s$/, '')} ${i + 1}`;
  };
  return (
    <div>
      <div className="mb-2 flex items-center justify-between gap-2">
        <p className="form-label !mb-0">
          {f.label}
          {f.required && <span className="ml-0.5 text-red-500" aria-hidden>*</span>}
          <span className="ml-2 text-xs font-normal text-slate-400">
            {items.length}
            {f.max_items ? ` / ${f.max_items}` : ''}
          </span>
        </p>
        <Button size="xs" variant="secondary" icon={Plus} disabled={disabled || items.length >= max}
          onClick={() => {
            onChange([...items, emptyItem(f.fields)]);
            setOpen(items.length);
          }}>
          Add
        </Button>
      </div>
      {f.help && <p className="form-hint !mt-0 mb-2">{f.help}</p>}
      {ownError && <p className="form-error mb-2" role="alert">{ownError}</p>}
      <ol className="space-y-2">
        {items.map((it, i) => {
          const ip = `${path}.${i}`;
          const bad = hasErrorsUnder(errors, ip);
          const isOpen = open === i || bad;
          const icon = typeof it?.icon === 'string' && it.icon ? it.icon : null;
          return (
            <li key={i} {...sortable.dropProps(i)}
              className={clsx('rounded-xl border bg-white transition dark:bg-slate-900/60', bad ? 'border-red-300 dark:border-red-500/40' : 'border-slate-200 dark:border-slate-700',
                sortable.overIndex === i && sortable.dragIndex !== i && 'ring-2 ring-brand-400', sortable.dragIndex === i && 'opacity-50')}>
              <div className="flex items-center gap-1.5 px-2 py-1.5" {...(disabled ? {} : sortable.dragProps(i))}>
                {!disabled && <span className="cursor-grab text-slate-300 active:cursor-grabbing dark:text-slate-600" aria-hidden><GripVertical className="h-4 w-4" /></span>}
                <button type="button" onClick={() => setOpen(isOpen && !bad ? null : i)} className="flex min-w-0 flex-1 items-center gap-2 py-1 text-left text-sm" aria-expanded={isOpen}>
                  {icon && <CmsIcon name={icon} className="h-4 w-4 shrink-0 text-[#D03E16]" />}
                  <span className={clsx('truncate font-medium', bad ? 'text-red-600' : 'text-slate-800 dark:text-slate-100')}>{label(it, i)}</span>
                  <ChevronDown className={clsx('ml-auto h-4 w-4 shrink-0 text-slate-400 transition', isOpen && 'rotate-180')} />
                </button>
                {!disabled && (
                  <>
                    <IconButton size="sm" icon={ArrowUp} label="Move up" disabled={i === 0} onClick={() => move(i, i - 1)} />
                    <IconButton size="sm" icon={ArrowDown} label="Move down" disabled={i === items.length - 1} onClick={() => move(i, i + 1)} />
                    <IconButton size="sm" icon={Copy} label="Duplicate" disabled={items.length >= max} onClick={() => {
                      const next = items.slice();
                      next.splice(i + 1, 0, JSON.parse(JSON.stringify(it)));
                      onChange(next);
                      setOpen(i + 1);
                    }} />
                    <IconButton size="sm" icon={Trash2} label="Remove" tone="danger" onClick={() => {
                      onChange(items.filter((_, j) => j !== i));
                      setOpen(null);
                    }} />
                  </>
                )}
              </div>
              {isOpen && (
                <div className="border-t border-slate-100 p-3 dark:border-slate-800">
                  <ShapeForm fields={f.fields ?? []} value={it} onChange={(nv) => onChange(items.map((x, j) => (j === i ? nv : x)))} errors={errors} path={ip} schema={schema} preview={preview} disabled={disabled} idPrefix={idPrefix} />
                </div>
              )}
            </li>
          );
        })}
      </ol>
      {!items.length && (
        <button type="button" onClick={() => { onChange([emptyItem(f.fields)]); setOpen(0); }} disabled={disabled}
          className="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 py-4 text-sm font-medium text-slate-500 transition hover:border-brand-300 hover:text-brand-700 dark:border-slate-700">
          <Plus className="h-4 w-4" /> Add the first {f.label.toLowerCase().replace(/s$/, '')}
        </button>
      )}
    </div>
  );
}
