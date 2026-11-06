import clsx from 'clsx';
import { Check, ChevronDown, LoaderCircle, Search } from 'lucide-react';
import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent as RKeyboardEvent } from 'react';
import { callingCode, countryList, countryName, filterCountries, flagEmoji, loadPhoneLib, onPhoneLibLoaded, preferredList, supportsFlags, type CountryItem } from './phone';

/** Flag emoji, or a small ISO-code badge on devices without flag glyphs (Windows). */
export function Flag({ iso }: { iso: string }) {
  if (supportsFlags()) {
    return (
      <span className="text-[17px] leading-none" aria-hidden="true">
        {flagEmoji(iso)}
      </span>
    );
  }
  return (
    <span className="inline-flex h-[18px] min-w-[26px] items-center justify-center rounded-[4px] bg-slate-200/80 px-1 text-[10px] font-bold tracking-wide text-slate-600" aria-hidden="true">
      {iso}
    </span>
  );
}

export interface CountrySelectProps {
  /** selected ISO 3166-1 alpha-2 code */
  value: string;
  onChange: (iso: string) => void;
  /** dial: compact trigger "🇮🇳 +91" for phone fields · country: full-width select-like trigger "🇮🇳 India" for addresses */
  mode?: 'dial' | 'country';
  /** accessible name of the trigger, e.g. "Country calling code for Mobile number" or "Country" */
  label: string;
  /** id of the trigger button (label htmlFor in country mode) */
  id?: string;
  disabled?: boolean;
  invalid?: boolean;
  describedBy?: string;
  className?: string;
  /** focus target after a choice (e.g. the phone number input) */
  afterSelect?: () => void;
  /** dial mode in a narrow field: flag only (the code stays in the accessible name and in the list) */
  compact?: boolean;
}

/**
 * Searchable country selector (flag, name, dialling code) — WAI-ARIA "select-only combobox with a search field":
 * a button opens a popup with a search box (role=combobox) controlling a listbox. Keys: ↓/↑ move, Home/End jump,
 * Enter selects, Esc closes (focus returns to the button), Tab closes. India and the most common countries are
 * listed first; the full list comes from libphonenumber-js, loaded lazily on first hover / focus / open.
 */
export function CountrySelect({ value, onChange, mode = 'dial', label, id, disabled, invalid, describedBy, className, afterSelect, compact = false }: CountrySelectProps) {
  const uid = useId().replace(/:/g, '');
  const listId = `${uid}-list`;
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const [up, setUp] = useState(false);
  const [, setLoadedTick] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const btnRef = useRef<HTMLButtonElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLUListElement>(null);

  useEffect(() => onPhoneLibLoaded(() => setLoadedTick((n) => n + 1)), []);

  const all = countryList();
  const items: (CountryItem | 'sep')[] = useMemo(() => {
    const pref = preferredList();
    if (query.trim()) return filterCountries(all ?? pref, query);
    if (!all) return pref;
    const prefIso = new Set(pref.map((p) => p.iso));
    return [...pref, 'sep', ...all.filter((c) => !prefIso.has(c.iso))];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, all]);
  const options = items.filter((i): i is CountryItem => i !== 'sep');

  const openPopup = () => {
    if (disabled) return;
    void loadPhoneLib().catch(() => undefined);
    const r = btnRef.current?.getBoundingClientRect();
    if (r) setUp(window.innerHeight - r.bottom < 340 && r.top > window.innerHeight - r.bottom);
    setQuery('');
    setOpen(true);
  };
  const close = (focusButton = true) => {
    setOpen(false);
    if (focusButton) btnRef.current?.focus();
  };
  const choose = (iso: string) => {
    onChange(iso);
    setOpen(false);
    if (afterSelect) window.setTimeout(afterSelect, 0);
    else btnRef.current?.focus();
  };

  // highlight the current value when opening / when results change
  useLayoutEffect(() => {
    if (!open) return;
    const i = query.trim() ? 0 : Math.max(0, options.findIndex((o) => o.iso === value));
    setActive(i);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, query, all]);

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
    if (!open) return;
    const el = listRef.current?.querySelector<HTMLElement>(`[data-index="${active}"]`);
    el?.scrollIntoView({ block: 'nearest' });
  }, [active, open]);

  const onSearchKey = (e: RKeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((a) => Math.min(options.length - 1, a + 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((a) => Math.max(0, a - 1));
    } else if (e.key === 'Home' && (e.ctrlKey || !query)) {
      e.preventDefault();
      setActive(0);
    } else if (e.key === 'End' && (e.ctrlKey || !query)) {
      e.preventDefault();
      setActive(options.length - 1);
    } else if (e.key === 'PageDown') {
      e.preventDefault();
      setActive((a) => Math.min(options.length - 1, a + 8));
    } else if (e.key === 'PageUp') {
      e.preventDefault();
      setActive((a) => Math.max(0, a - 8));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (options[active]) choose(options[active].iso);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation(); // keep a surrounding modal open
      close();
    } else if (e.key === 'Tab') {
      setOpen(false);
    }
  };

  const onButtonKey = (e: RKeyboardEvent<HTMLButtonElement>) => {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
      e.preventDefault();
      openPopup();
    }
  };

  const dial = callingCode(value);
  const name = countryName(value);
  const activeId = options[active] ? `${uid}-o-${options[active].iso}` : undefined;
  let index = -1;

  return (
    <div ref={rootRef} className={clsx('relative flex', mode === 'country' ? 'w-full' : 'rounded-l-[inherit]')}>
      <button
        ref={btnRef}
        id={id}
        type="button"
        disabled={disabled}
        className={clsx(
          mode === 'dial'
            ? clsx(
                // translucent tint + inherited text colour: works on the light, dark and lead form themes
                'flex h-full shrink-0 items-center rounded-l-[inherit] border-r border-black/10 bg-black/[.035] text-[14px] font-semibold text-inherit transition hover:bg-black/[.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-accent-500 disabled:cursor-not-allowed disabled:opacity-60',
                compact ? 'gap-0.5 pl-1.5 pr-1' : 'gap-1.5 pl-3 pr-2',
              )
            : clsx('form-input flex items-center gap-2.5 text-left', invalid && 'is-invalid'),
          className,
        )}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-label={mode === 'dial' ? `${label}: ${name} +${dial}` : undefined}
        aria-invalid={mode === 'country' && invalid ? true : undefined}
        aria-describedby={describedBy}
        onClick={() => (open ? close() : openPopup())}
        onKeyDown={onButtonKey}
        onPointerEnter={() => void loadPhoneLib().catch(() => undefined)}
        onFocus={() => void loadPhoneLib().catch(() => undefined)}
      >
        <Flag iso={value} />
        {mode === 'dial' ? (
          <span className={clsx('tabular-nums', compact && 'sr-only')} aria-hidden="true">
            +{dial}
          </span>
        ) : (
          <span className="min-w-0 flex-1 truncate">{name}</span>
        )}
        <ChevronDown className={clsx('shrink-0 text-slate-400 transition-transform', compact ? 'h-3 w-3' : 'h-3.5 w-3.5', open && 'rotate-180')} aria-hidden="true" />
      </button>

      {open && (
        <div
          className={clsx(
            'absolute left-0 z-[80] w-[min(20rem,calc(100vw-2.5rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white text-left text-slate-800 shadow-[0_24px_48px_-16px_rgba(7,26,59,.35)]',
            up ? 'bottom-full mb-1.5' : 'top-full mt-1.5',
          )}
          role="dialog"
          aria-label={mode === 'dial' ? 'Choose a country calling code' : 'Choose a country'}
        >
          <div className="relative border-b border-slate-100 p-2">
            <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
            <input
              ref={searchRef}
              type="text"
              role="combobox"
              aria-expanded="true"
              aria-controls={listId}
              aria-activedescendant={activeId}
              aria-autocomplete="list"
              aria-label="Search countries"
              placeholder={mode === 'dial' ? 'Search country or code' : 'Search country'}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={onSearchKey}
              autoComplete="off"
              spellCheck={false}
              className="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-accent-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-accent-500/25"
            />
          </div>
          <ul ref={listRef} id={listId} role="listbox" aria-label={mode === 'dial' ? 'Countries and calling codes' : 'Countries'} className="max-h-64 overflow-y-auto overscroll-contain p-1.5">
            {items.map((it, i) => {
              if (it === 'sep') return <li key={`sep-${i}`} role="presentation" className="mx-2 my-1.5 border-t border-slate-100" />;
              index += 1;
              const idx = index;
              const selected = it.iso === value;
              return (
                <li
                  key={it.iso}
                  id={`${uid}-o-${it.iso}`}
                  role="option"
                  aria-selected={selected}
                  data-index={idx}
                  className={clsx('flex cursor-pointer items-center gap-2.5 rounded-xl px-2.5 py-2 text-[14px]', idx === active ? 'bg-accent-50 text-brand-900' : 'text-slate-700', selected && 'font-semibold')}
                  onPointerMove={() => idx !== active && setActive(idx)}
                  onPointerDown={(e) => e.preventDefault()}
                  onClick={() => choose(it.iso)}
                >
                  <Flag iso={it.iso} />
                  <span className="min-w-0 flex-1 truncate">{it.name}</span>
                  {mode === 'dial' && <span className="shrink-0 tabular-nums text-slate-500">+{it.dial}</span>}
                  {selected && <Check className="h-4 w-4 shrink-0 text-accent-600" aria-hidden="true" />}
                </li>
              );
            })}
            {!all && !query.trim() && (
              <li role="presentation" className="flex items-center gap-2 px-2.5 py-2 text-[13px] text-slate-500">
                <LoaderCircle className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                Loading all countries…
              </li>
            )}
            {options.length === 0 && (
              <li role="presentation" className="px-2.5 py-3 text-[13px] text-slate-500">
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
