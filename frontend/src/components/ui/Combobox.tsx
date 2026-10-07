import { useEffect, useMemo, useState } from 'react';
import { Combobox as HCombobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import clsx from 'clsx';
import { Check, ChevronsUpDown, X } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { api, type Query } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import type { Option } from '@/lib/types';
import { Spinner } from './Spinner';

type Value = string | number | null;

interface BaseProps {
  /** Static options (filtered client-side) */
  options?: Option[];
  /** Named lookup source (GET /api/lookup/{source}) */
  source?: string;
  /** Any API path returning Option[] (e.g. crud/students/options/program_id) */
  optionsUrl?: string;
  /** Extra query params (dependent selects) */
  params?: Query;
  placeholder?: string;
  disabled?: boolean;
  invalid?: boolean;
  /** Labels for the current value(s) when options are loaded asynchronously */
  initialOptions?: Option[];
  id?: string;
  size?: 'sm' | 'md';
  clearable?: boolean;
}

interface SingleProps extends BaseProps {
  multiple?: false;
  value: Value;
  onChange: (v: Value, option?: Option | null) => void;
}
interface MultiProps extends BaseProps {
  multiple: true;
  value: (string | number)[];
  onChange: (v: (string | number)[]) => void;
}

/**
 * Searchable select. Works with static options, a named lookup source or an options URL (server-side search).
 *   <Combobox source="students" value={id} onChange={setId} placeholder="Search student…" />
 */
export function Combobox(props: SingleProps | MultiProps) {
  const { options, source, optionsUrl, params, placeholder = 'Select…', disabled, invalid, initialOptions = [], id, size = 'md', clearable = true } = props;
  const [query, setQuery] = useState('');
  const debounced = useDebounce(query, 250);
  const remotePath = source ? `lookup/${source}` : optionsUrl;
  const { data: remote = [], isFetching } = useQuery({
    queryKey: ['combobox', remotePath, params, debounced],
    queryFn: ({ signal }) => api.get<Option[]>(remotePath as string, { ...(params ?? {}), q: debounced || undefined, limit: 30 }, { signal }),
    enabled: !!remotePath && !disabled,
    staleTime: 30_000,
  });
  const [known, setKnown] = useState<Option[]>(initialOptions);
  useEffect(() => {
    if (initialOptions.length) setKnown((k) => mergeOptions(k, initialOptions));
  }, [initialOptions]);

  const list = useMemo(() => {
    if (remotePath) return remote;
    const q = query.trim().toLowerCase();
    const all = options ?? [];
    return q ? all.filter((o) => `${o.label} ${o.sub ?? ''}`.toLowerCase().includes(q)) : all;
  }, [remotePath, remote, options, query]);

  const lookup = (v: string | number) => [...(options ?? []), ...known, ...remote].find((o) => String(o.value) === String(v));

  if (props.multiple) {
    const selected = props.value.map((v) => lookup(v) ?? { value: v, label: String(v) });
    return (
      <HCombobox
        multiple
        value={selected}
        by={(a: Option, b: Option) => String(a?.value) === String(b?.value)}
        onChange={(opts: Option[]) => {
          setKnown((k) => mergeOptions(k, opts));
          props.onChange(opts.map((o) => o.value));
        }}
        disabled={disabled}
      >
        <div className={clsx('form-input flex min-h-[42px] flex-wrap items-center gap-1.5 !py-1.5', invalid && 'form-input-error', disabled && 'opacity-60')}>
          {selected.map((o) => (
            <span key={String(o.value)} className="inline-flex items-center gap-1 rounded-md bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">
              {o.label}
              <button type="button" aria-label={`Remove ${o.label}`} onClick={() => props.onChange(props.value.filter((v) => String(v) !== String(o.value)))}>
                <X className="h-3 w-3" />
              </button>
            </span>
          ))}
          <ComboboxInput id={id} className="min-w-[8rem] flex-1 border-0 bg-transparent p-0 text-sm focus:ring-0 dark:text-white" placeholder={selected.length ? '' : placeholder} onChange={(e) => setQuery(e.target.value)} />
        </div>
        <OptionsPanel list={list} loading={isFetching} />
      </HCombobox>
    );
  }

  const current = props.value !== null && props.value !== undefined && props.value !== '' ? lookup(props.value) ?? { value: props.value, label: known.length || remote.length ? String(props.value) : '…' } : null;
  return (
    <HCombobox
      value={current}
      by={(a: Option | null, b: Option | null) => String(a?.value) === String(b?.value)}
      onChange={(o: Option | null) => {
        if (o) setKnown((k) => mergeOptions(k, [o]));
        props.onChange(o ? o.value : null, o);
      }}
      onClose={() => setQuery('')}
      disabled={disabled}
    >
      <div className="relative">
        <ComboboxInput
          id={id}
          className={clsx('form-input pr-14', size === 'sm' && 'form-input-sm', invalid && 'form-input-error')}
          displayValue={(o: Option | null) => o?.label ?? ''}
          placeholder={placeholder}
          onChange={(e) => setQuery(e.target.value)}
          autoComplete="off"
        />
        <div className="absolute inset-y-0 right-0 flex items-center gap-0.5 pr-2">
          {isFetching && <Spinner className="h-3.5 w-3.5 text-slate-400" />}
          {clearable && current && !disabled && (
            <button type="button" className="rounded p-1 text-slate-400 hover:text-slate-600" aria-label="Clear selection" onClick={() => props.onChange(null, null)}>
              <X className="h-3.5 w-3.5" />
            </button>
          )}
          <ComboboxButton className="rounded p-1 text-slate-400 hover:text-slate-600" aria-label="Show options">
            <ChevronsUpDown className="h-4 w-4" />
          </ComboboxButton>
        </div>
      </div>
      <OptionsPanel list={list} loading={isFetching} />
    </HCombobox>
  );
}

function OptionsPanel({ list, loading }: { list: Option[]; loading: boolean }) {
  return (
    <ComboboxOptions anchor="bottom start" className="menu-panel z-[60] max-h-72 w-[var(--input-width)] overflow-y-auto [--anchor-gap:6px] empty:invisible">
      {list.length === 0 ? (
        <div className="px-3 py-2.5 text-sm text-slate-500">{loading ? 'Searching…' : 'No matches found'}</div>
      ) : (
        list.map((o) => (
          <ComboboxOption key={String(o.value)} value={o} className="group flex cursor-pointer items-start gap-2 rounded-lg px-3 py-2 text-sm text-slate-700 data-[focus]:bg-brand-50 data-[focus]:text-brand-900 dark:text-slate-200 dark:data-[focus]:bg-slate-800 dark:data-[focus]:text-white">
            <Check className="invisible mt-0.5 h-4 w-4 shrink-0 text-brand-700 group-data-[selected]:visible dark:text-brand-300" />
            <span className="min-w-0">
              <span className="block truncate font-medium">{o.label}</span>
              {o.sub && <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{o.sub}</span>}
            </span>
          </ComboboxOption>
        ))
      )}
    </ComboboxOptions>
  );
}

function mergeOptions(a: Option[], b: Option[]): Option[] {
  const map = new Map(a.map((o) => [String(o.value), o]));
  b.forEach((o) => map.set(String(o.value), o));
  return [...map.values()];
}
