import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import clsx from 'clsx';
import { Search, UserRound, X } from 'lucide-react';
import { Avatar, Spinner } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { formatMoney } from '@/lib/format';
import { MemberTypeBadge, membershipState } from './shared';
import type { LibMember } from './types';

interface MemberPickerProps {
  value: LibMember | null;
  onChange: (m: LibMember | null) => void;
  autoFocus?: boolean;
  placeholder?: string;
  size?: 'sm' | 'md';
  id?: string;
}

/** Search library members by name, membership no or student / employee ID (issue desk). */
export function MemberPicker({ value, onChange, autoFocus, placeholder = 'Search name, membership no or ID…', size = 'md', id }: MemberPickerProps) {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const dq = useDebounce(q.trim(), 250);
  const listId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const boxRef = useRef<HTMLDivElement>(null);
  const res = useApi<LibMember[]>(['library', 'member-search', dq], 'library/members/search', { q: dq, limit: 8 }, { enabled: open && dq.length >= 2, staleTime: 15_000 });
  const rows = dq.length >= 2 ? res.data ?? [] : [];

  useEffect(() => setActive(0), [dq]);
  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  const pick = (m: LibMember) => {
    onChange(m);
    setQ('');
    setOpen(false);
  };
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setOpen(true);
      setActive((a) => Math.min(a + 1, Math.max(0, rows.length - 1)));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((a) => Math.max(0, a - 1));
    } else if (e.key === 'Enter') {
      if (rows[active]) {
        e.preventDefault();
        pick(rows[active]);
      }
    } else if (e.key === 'Escape') {
      setOpen(false);
    }
  };

  if (value) {
    const state = membershipState(value);
    return (
      <div className={clsx('flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50/60 dark:border-brand-500/30 dark:bg-brand-500/10', size === 'sm' ? 'px-2.5 py-2' : 'px-3 py-2.5')}>
        <Avatar name={value.name} src={value.photo} size={size === 'sm' ? 'sm' : 'md'} />
        <div className="min-w-0 flex-1 leading-tight">
          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{value.name}</p>
          <p className="truncate text-xs text-slate-500 dark:text-slate-400">
            <span className="font-mono">{value.membership_no}</span> · {value.loans}/{value.max_books} books{state !== 'active' ? ` · ${state}` : ''}
          </p>
        </div>
        <button
          type="button"
          onClick={() => {
            onChange(null);
            setTimeout(() => inputRef.current?.focus(), 0);
          }}
          className="btn-icon !h-8 !w-8"
          aria-label="Change member"
          title="Change member"
        >
          <X className="h-4 w-4" />
        </button>
      </div>
    );
  }

  return (
    <div ref={boxRef} className="relative">
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
        <input
          ref={inputRef}
          id={id}
          type="search"
          role="combobox"
          aria-expanded={open && rows.length > 0}
          aria-controls={listId}
          aria-autocomplete="list"
          autoFocus={autoFocus}
          autoComplete="off"
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKey}
          placeholder={placeholder}
          className={clsx('form-input pl-9', size === 'sm' && 'form-input-sm')}
        />
        {res.isFetching && <Spinner className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />}
      </div>
      {open && dq.length >= 2 && (
        <div className="menu-panel absolute left-0 right-0 z-30 mt-1.5 max-h-80 overflow-y-auto p-1">
          {rows.length === 0 && !res.isFetching ? (
            <p className="flex items-center gap-2 px-3 py-3 text-sm text-slate-500">
              <UserRound className="h-4 w-4" /> No member matches “{dq}”.
            </p>
          ) : (
            <ul id={listId} role="listbox" aria-label="Members">
              {rows.map((m, i) => {
                const state = membershipState(m);
                return (
                  <li key={m.id} role="option" aria-selected={i === active}>
                    <button
                      type="button"
                      onMouseEnter={() => setActive(i)}
                      onClick={() => pick(m)}
                      className={clsx('flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left', i === active ? 'bg-brand-50 dark:bg-brand-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800')}
                    >
                      <Avatar name={m.name} src={m.photo} size="sm" />
                      <div className="min-w-0 flex-1 leading-tight">
                        <p className="flex items-center gap-2 truncate text-sm font-medium text-slate-900 dark:text-white">
                          {m.name} <MemberTypeBadge type={m.member_type} />
                        </p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                          <span className="font-mono">{m.membership_no}</span>
                          {m.code ? ` · ${m.code}` : ''}
                          {m.info ? ` · ${m.info}` : ''}
                        </p>
                      </div>
                      <div className="shrink-0 text-right text-[11px] leading-tight">
                        <p className="font-semibold text-slate-700 dark:text-slate-200">
                          {m.loans}/{m.max_books}
                        </p>
                        {state !== 'active' ? (
                          <p className="font-semibold uppercase text-red-600 dark:text-red-400">{state}</p>
                        ) : m.overdue ? (
                          <p className="text-red-600 dark:text-red-400">{m.overdue} overdue</p>
                        ) : m.unpaid_fines > 0 ? (
                          <p className="text-amber-600 dark:text-amber-400">{formatMoney(m.unpaid_fines)} due</p>
                        ) : (
                          <p className="text-slate-400">books</p>
                        )}
                      </div>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
