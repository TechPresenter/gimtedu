import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type ChangeEvent, type KeyboardEvent as RKeyboardEvent } from 'react';
import clsx from 'clsx';
import { Check, ChevronDown, Loader2, Search } from 'lucide-react';

/*
 * International phone input for the admin (CRUD 'tel' fields and custom forms): searchable country-code selector
 * (flag, country name, dialling code — India first), as-you-type formatting and per-country validation with
 * libphonenumber-js ("max" metadata), loaded lazily on first focus / hover so it never weighs on the initial bundle.
 * The value is E.164 ("+919876543210"); the server (app/crud.php + app/services/phone.php, same metadata) validates and
 * normalises again — existing 10-digit Indian numbers are read as +91.
 */

type PhoneLib = typeof import('libphonenumber-js/max');
let libPromise: Promise<PhoneLib> | null = null;
let lib: PhoneLib | null = null;
const subscribers = new Set<() => void>();

/** Load libphonenumber-js once (shared by every PhoneInput on the page). */
export function loadPhoneLib(): Promise<PhoneLib> {
  if (!libPromise) {
    libPromise = import('libphonenumber-js/max').then((m) => {
      lib = m;
      subscribers.forEach((fn) => fn());
      return m;
    });
    libPromise.catch(() => {
      libPromise = null;
    });
  }
  return libPromise;
}

const PREFERRED: [string, string][] = [
  ['IN', '91'], ['NP', '977'], ['BD', '880'], ['BT', '975'], ['LK', '94'], ['AE', '971'], ['SA', '966'], ['QA', '974'],
  ['KW', '965'], ['OM', '968'], ['BH', '973'], ['US', '1'], ['GB', '44'], ['CA', '1'], ['AU', '61'], ['SG', '65'],
];
const SHARED_MAIN: Record<string, string> = { 1: 'US', 7: 'RU', 39: 'IT', 44: 'GB', 47: 'NO', 61: 'AU', 212: 'MA', 262: 'RE', 290: 'SH', 358: 'FI', 590: 'GP', 599: 'CW' };

let names: Intl.DisplayNames | null | undefined;
function countryName(iso: string): string {
  if (names === undefined) {
    try {
      names = new Intl.DisplayNames(['en'], { type: 'region' });
    } catch {
      names = null;
    }
  }
  let n = iso;
  try {
    n = names?.of(iso) ?? iso;
  } catch {
    /* unknown */
  }
  return n.replace(/ & /g, ' and ').replace(/[’‘]/g, "'").replace(/\s*\([^)]*\)/g, '').trim();
}

function dialOf(iso: string): string {
  if (lib) {
    try {
      return lib.getCountryCallingCode(iso as never);
    } catch {
      /* not a region */
    }
  }
  return PREFERRED.find(([c]) => c === iso)?.[1] ?? '';
}

let flags: boolean | null = null;
function supportsFlags(): boolean {
  if (flags !== null) return flags;
  flags = false;
  try {
    const c = document.createElement('canvas');
    c.width = c.height = 16;
    const ctx = c.getContext('2d');
    if (ctx) {
      ctx.textBaseline = 'top';
      ctx.font = '14px "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif';
      ctx.fillText('\u{1F1EE}\u{1F1F3}', 0, 0);
      const d = ctx.getImageData(0, 0, 16, 16).data;
      for (let i = 0; i < d.length; i += 4) {
        if (d[i + 3] > 0 && (Math.abs(d[i] - d[i + 1]) > 30 || Math.abs(d[i + 1] - d[i + 2]) > 30)) {
          flags = true;
          break;
        }
      }
    }
  } catch {
    flags = false;
  }
  return flags;
}

function Flag({ iso }: { iso: string }) {
  if (supportsFlags()) {
    return (
      <span className="text-base leading-none" aria-hidden>
        {String.fromCodePoint(...iso.split('').map((ch) => 0x1f1e6 + ch.charCodeAt(0) - 65))}
      </span>
    );
  }
  return (
    <span className="inline-flex h-[18px] min-w-[26px] items-center justify-center rounded bg-slate-200/80 px-1 text-[10px] font-bold tracking-wide text-slate-600 dark:bg-slate-700 dark:text-slate-300" aria-hidden>
      {iso}
    </span>
  );
}

interface Item {
  iso: string;
  dial: string;
  name: string;
  search: string;
}
const fold = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const item = (iso: string, dial: string): Item => {
  const name = countryName(iso);
  return { iso, dial, name, search: fold(`${name} ${iso} +${dial} ${dial}`) };
};
let all: Item[] | null = null;
function allCountries(): Item[] | null {
  if (all || !lib) return all;
  const l = lib;
  all = l
    .getCountries()
    .map((iso) => item(iso, l.getCountryCallingCode(iso)))
    .sort((a, b) => fold(a.name).localeCompare(fold(b.name)));
  return all;
}

/** E.164 value for national text + country (or text starting with "+"). */
function toE164(text: string, country: string): { value: string; country: string; valid: boolean } {
  const raw = text.trim();
  const digits = raw.replace(/\D/g, '');
  if (!digits) return { value: '', country, valid: false };
  if (lib) {
    const p = lib.parsePhoneNumberFromString(raw, raw.startsWith('+') ? undefined : (country as never));
    if (p) return { value: p.number, country: p.country ?? country, valid: p.isValid() };
  }
  if (raw.startsWith('+')) return { value: `+${digits}`, country, valid: digits.length >= 8 };
  const dial = dialOf(country);
  return { value: dial ? `+${dial}${digits}` : digits, country, valid: digits.length >= 6 };
}

/** Stored value -> country + national display ("+919876543210" -> IN, "98765 43210"; legacy "9876543210" -> IN). */
function fromValue(value: string, fallback: string): { country: string; text: string } {
  const v = (value || '').trim();
  if (!v) return { country: fallback, text: '' };
  if (lib) {
    const p = lib.parsePhoneNumberFromString(v, v.startsWith('+') ? undefined : (fallback as never));
    if (p?.country) return { country: p.country, text: p.formatInternational().replace(/^\+\d+\s*/, '') };
    if (p) return { country: fallback, text: p.formatInternational() };
  }
  if (fallback === 'IN' && /^(?:\+?91)?[6-9]\d{9}$/.test(v.replace(/[\s-]/g, ''))) return { country: 'IN', text: v.replace(/\D/g, '').slice(-10) };
  return { country: fallback, text: v };
}

function formatTyping(text: string, country: string): string {
  if (!lib || !text) return text;
  try {
    if (text.trim().startsWith('+')) return new lib.AsYouType().input(text);
    const digits = text.replace(/\D/g, '');
    if (digits.startsWith('0')) return new lib.AsYouType(country as never).input(text);
    const dial = dialOf(country);
    return dial ? new lib.AsYouType().input(`+${dial}${digits}`).replace(new RegExp(`^\\+${dial}\\s?`), '') : text;
  } catch {
    return text;
  }
}

/** "Enter a valid phone number for India (+91)." — same text as the server (app/services/phone.php). */
export function phoneInputError(value: string, country = 'IN'): string | null {
  const v = (value || '').trim();
  if (!v) return null;
  const msg = (c: string | null | undefined) => (c && dialOf(c) ? `Enter a valid phone number for ${countryName(c)} (+${dialOf(c)}).` : 'Enter a valid phone number, including the country code.');
  if (!lib) return null;
  const p = lib.parsePhoneNumberFromString(v, v.startsWith('+') ? undefined : (country as never));
  if (p?.isValid()) return null;
  return msg(p ? p.country ?? SHARED_MAIN[p.countryCallingCode] ?? lib.getCountries().find((c) => lib!.getCountryCallingCode(c) === p.countryCallingCode) : country);
}

export interface PhoneInputProps {
  id?: string;
  /** E.164 (or legacy national) value */
  value: string;
  /** receives E.164 when the number parses ('' when cleared) */
  onChange: (value: string) => void;
  onBlur?: () => void;
  /** preselected country for empty values / numbers without a code (ISO2, default India) */
  defaultCountry?: string;
  invalid?: boolean;
  disabled?: boolean;
  placeholder?: string;
  className?: string;
  /** shows the per-country validation message under the field after blur */
  showError?: boolean;
}

/** International phone field — see the module comment. */
export function PhoneInput({ id, value, onChange, onBlur, defaultCountry = 'IN', invalid, disabled, placeholder, className, showError = true }: PhoneInputProps) {
  const uid = useId().replace(/:/g, '');
  const listId = `${uid}-list`;
  const init = fromValue(value, defaultCountry);
  const [country, setCountry] = useState(init.country);
  const [text, setText] = useState(init.text);
  const [, setTick] = useState(0);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const [up, setUp] = useState(false);
  const [touched, setTouched] = useState(false);
  const last = useRef(value);
  const rootRef = useRef<HTMLDivElement>(null);
  const btnRef = useRef<HTMLButtonElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const reparse = useRef(!lib && (value || '').trim() !== '');

  // the library arrived: re-read a value shown raw so far, refresh the country list
  useEffect(() => {
    const fn = () => {
      setTick((n) => n + 1);
      if (reparse.current) {
        reparse.current = false;
        const p = fromValue(last.current, country);
        setCountry(p.country);
        setText(p.text);
      }
    };
    subscribers.add(fn);
    return () => {
      subscribers.delete(fn);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // value changed from outside (form reset, record loaded)
  useEffect(() => {
    if (value === last.current) return;
    last.current = value;
    reparse.current = !lib && (value || '').trim() !== '';
    const p = fromValue(value, value ? country : defaultCountry);
    setCountry(p.country);
    setText(p.text);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  const emit = (t: string, c: string) => {
    const r = toE164(t, c);
    last.current = r.value;
    onChange(r.value);
  };

  const onInput = (e: ChangeEvent<HTMLInputElement>) => {
    reparse.current = false;
    const el = e.target;
    let t = el.value.replace(/[^\d\s()+\-.]/g, '').slice(0, 24);
    let c = country;
    if (t.trim().startsWith('+') && lib) {
      const r = toE164(t, c);
      if (r.valid && r.country !== c) {
        c = r.country;
        setCountry(c);
        t = fromValue(r.value, c).text;
      }
    } else if (el.selectionStart === el.value.length) {
      t = formatTyping(t, c);
    }
    setText(t);
    emit(t, c);
  };

  const pick = (iso: string) => {
    reparse.current = false;
    setCountry(iso);
    setOpen(false);
    const t = formatTyping((text.trim().startsWith('+') ? text.replace(/^\s*\+\d{1,3}\s*/, '') : text).replace(/\D/g, ''), iso);
    setText(t);
    emit(t, iso);
    window.setTimeout(() => inputRef.current?.focus(), 0);
  };

  const preload = () => void loadPhoneLib().catch(() => undefined);
  const list = allCountries();
  const options: Item[] = useMemo(() => {
    const pref = PREFERRED.map(([c, d]) => item(c, d));
    const q = fold(query.trim()).replace(/^\+/, '');
    const base = list ?? pref;
    if (!q) {
      const prefIso = new Set(pref.map((p) => p.iso));
      return list ? [...pref, ...list.filter((c) => !prefIso.has(c.iso))] : pref;
    }
    const starts = base.filter((c) => fold(c.name).startsWith(q) || c.dial.startsWith(q) || c.iso.toLowerCase() === q);
    return [...starts, ...base.filter((c) => !starts.includes(c) && c.search.includes(q))];
  }, [query, list]);

  const openPopup = () => {
    if (disabled) return;
    preload();
    const r = btnRef.current?.getBoundingClientRect();
    if (r) setUp(window.innerHeight - r.bottom < 340 && r.top > window.innerHeight - r.bottom);
    setQuery('');
    setOpen(true);
  };

  useLayoutEffect(() => {
    if (open) setActive(query.trim() ? 0 : Math.max(0, options.findIndex((o) => o.iso === country)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, query, list]);

  useEffect(() => {
    if (!open) return;
    searchRef.current?.focus({ preventScroll: true });
    const onDown = (e: PointerEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('pointerdown', onDown);
    return () => document.removeEventListener('pointerdown', onDown);
  }, [open]);

  useEffect(() => {
    if (open) listRef.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
  }, [active, open]);

  const onSearchKey = (e: RKeyboardEvent<HTMLInputElement>) => {
    const k = e.key;
    if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'PageDown' || k === 'PageUp') {
      e.preventDefault();
      const step = k === 'ArrowDown' ? 1 : k === 'ArrowUp' ? -1 : k === 'PageDown' ? 8 : -8;
      setActive((a) => Math.min(options.length - 1, Math.max(0, a + step)));
    } else if ((k === 'Home' || k === 'End') && (e.ctrlKey || !query)) {
      e.preventDefault();
      setActive(k === 'Home' ? 0 : options.length - 1);
    } else if (k === 'Enter') {
      e.preventDefault();
      if (options[active]) pick(options[active].iso);
    } else if (k === 'Escape') {
      e.preventDefault();
      e.stopPropagation(); // keep the surrounding modal open
      setOpen(false);
      btnRef.current?.focus();
    } else if (k === 'Tab') {
      setOpen(false);
    }
  };

  const current = toE164(text, country);
  const err = showError && touched && current.value ? phoneInputError(current.value, country) : null;
  const dial = dialOf(country);

  return (
    <div ref={rootRef} className={clsx('relative', className)} onPointerEnter={preload}>
      <div
        className={clsx(
          'flex items-stretch rounded-xl border bg-white shadow-sm transition focus-within:ring-4 dark:bg-slate-900',
          invalid || err ? 'border-red-400 focus-within:border-red-500 focus-within:ring-red-500/10 dark:border-red-500/70' : 'border-slate-200 focus-within:border-brand-500 focus-within:ring-brand-500/10 dark:border-slate-700 dark:focus-within:border-brand-400',
          disabled && 'cursor-not-allowed bg-slate-50 dark:bg-slate-800',
        )}
      >
        <button
          ref={btnRef}
          type="button"
          disabled={disabled}
          className="flex shrink-0 items-center gap-1.5 rounded-l-xl border-r border-slate-200 bg-slate-50 pl-3 pr-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500 disabled:cursor-not-allowed dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-controls={open ? listId : undefined}
          aria-label={`Country calling code: ${countryName(country)} +${dial}`}
          onClick={() => (open ? setOpen(false) : openPopup())}
          onKeyDown={(e) => {
            if (['ArrowDown', 'ArrowUp'].includes(e.key)) {
              e.preventDefault();
              openPopup();
            }
          }}
          onFocus={preload}
        >
          <Flag iso={country} />
          <span className="tabular-nums" aria-hidden>
            +{dial}
          </span>
          <ChevronDown className={clsx('h-3.5 w-3.5 text-slate-400 transition-transform', open && 'rotate-180')} aria-hidden />
        </button>
        <input
          ref={inputRef}
          id={id}
          type="tel"
          inputMode="tel"
          autoComplete="tel"
          value={text}
          disabled={disabled}
          placeholder={placeholder ?? (country === 'IN' ? '98765 43210' : '')}
          aria-invalid={invalid || !!err || undefined}
          maxLength={24}
          onChange={onInput}
          onFocus={preload}
          onBlur={() => {
            setTouched(true);
            if (lib && current.valid) {
              const tidy = fromValue(current.value, country);
              if (tidy.country === country && tidy.text !== text) setText(tidy.text);
            }
            onBlur?.();
          }}
          className="min-w-0 flex-1 rounded-r-xl border-0 bg-transparent px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0 disabled:cursor-not-allowed disabled:text-slate-500 dark:text-white dark:placeholder:text-slate-500"
        />
      </div>
      {err && !invalid && <p className="form-hint !text-amber-700 dark:!text-amber-400">{err}</p>}

      {open && (
        <div
          role="dialog"
          aria-label="Choose a country calling code"
          className={clsx(
            'absolute left-0 z-[90] w-[min(20rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white text-left shadow-xl dark:border-slate-700 dark:bg-slate-900',
            up ? 'bottom-full mb-1.5' : 'top-full mt-1.5',
          )}
        >
          <div className="relative border-b border-slate-100 p-2 dark:border-slate-800">
            <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <input
              ref={searchRef}
              type="text"
              role="combobox"
              aria-expanded="true"
              aria-controls={listId}
              aria-activedescendant={options[active] ? `${uid}-o-${options[active].iso}` : undefined}
              aria-autocomplete="list"
              aria-label="Search countries"
              placeholder="Search country or code"
              value={query}
              autoComplete="off"
              spellCheck={false}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={onSearchKey}
              className="h-9 w-full rounded-lg border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
          </div>
          <ul ref={listRef} id={listId} role="listbox" aria-label="Countries and calling codes" className="max-h-64 overflow-y-auto overscroll-contain p-1.5">
            {options.map((o, i) => (
              <li
                key={o.iso}
                id={`${uid}-o-${o.iso}`}
                role="option"
                aria-selected={o.iso === country}
                data-index={i}
                onPointerMove={() => i !== active && setActive(i)}
                onPointerDown={(e) => e.preventDefault()}
                onClick={() => pick(o.iso)}
                className={clsx(
                  'flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm',
                  i === active ? 'bg-brand-50 text-brand-900 dark:bg-slate-800 dark:text-white' : 'text-slate-700 dark:text-slate-300',
                  o.iso === country && 'font-semibold',
                )}
              >
                <Flag iso={o.iso} />
                <span className="min-w-0 flex-1 truncate">{o.name}</span>
                <span className="shrink-0 tabular-nums text-slate-500 dark:text-slate-400">+{o.dial}</span>
                {o.iso === country && <Check className="h-4 w-4 shrink-0 text-brand-600" aria-hidden />}
              </li>
            ))}
            {!list && !query.trim() && (
              <li role="presentation" className="flex items-center gap-2 px-2.5 py-2 text-xs text-slate-500">
                <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden />
                Loading all countries…
              </li>
            )}
            {options.length === 0 && (
              <li role="presentation" className="px-2.5 py-3 text-xs text-slate-500">
                No country matches “{query}”.
              </li>
            )}
          </ul>
          <p className="sr-only" aria-live="polite">
            {options.length} {options.length === 1 ? 'country' : 'countries'}
          </p>
        </div>
      )}
    </div>
  );
}
