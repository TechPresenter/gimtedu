import { useEffect, useRef, useState, type DragEvent, type KeyboardEvent } from 'react';
import clsx from 'clsx';
import { Star, X } from 'lucide-react';

/* ---------------------------------------------------------------- Star rating */

/** Read-only star rating (1–5). */
export function Stars({ value, size = 'sm', className }: { value: number; size?: 'xs' | 'sm' | 'md'; className?: string }) {
  const s = size === 'xs' ? 'h-3 w-3' : size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4';
  const v = Math.round(Number(value) || 0);
  return (
    <span className={clsx('inline-flex items-center gap-0.5', className)} aria-label={`${v} out of 5 stars`} role="img">
      {[1, 2, 3, 4, 5].map((i) => (
        <Star key={i} className={clsx(s, i <= v ? 'fill-amber-400 text-amber-400' : 'fill-slate-200 text-slate-200 dark:fill-slate-700 dark:text-slate-700')} aria-hidden />
      ))}
    </span>
  );
}

/** Accessible 1–5 star input (radio group, arrow keys). */
export function StarInput({ value, onChange, id, invalid }: { value: number; onChange: (v: number) => void; id?: string; invalid?: boolean }) {
  const [hover, setHover] = useState<number | null>(null);
  const v = Number(value) || 0;
  const shown = hover ?? v;
  const labels = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];
  const onKey = (e: KeyboardEvent) => {
    if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
      e.preventDefault();
      onChange(Math.min(5, v + 1));
    }
    if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
      e.preventDefault();
      onChange(Math.max(1, v - 1));
    }
  };
  return (
    <div className={clsx('flex h-10 items-center gap-3 rounded-xl', invalid && 'ring-1 ring-red-300')}>
      <div id={id} role="radiogroup" aria-label="Rating" tabIndex={0} onKeyDown={onKey} className="flex items-center gap-1 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500" onMouseLeave={() => setHover(null)}>
        {[1, 2, 3, 4, 5].map((i) => (
          <button
            key={i}
            type="button"
            role="radio"
            aria-checked={v === i}
            aria-label={`${i} star${i > 1 ? 's' : ''}`}
            tabIndex={-1}
            onMouseEnter={() => setHover(i)}
            onClick={() => onChange(i)}
            className="rounded p-0.5 transition hover:scale-110"
          >
            <Star className={clsx('h-5 w-5 transition', i <= shown ? 'fill-amber-400 text-amber-400' : 'fill-slate-200 text-slate-300 dark:fill-slate-700 dark:text-slate-600')} />
          </button>
        ))}
      </div>
      <span className="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{labels[shown] ?? ''}</span>
    </div>
  );
}

/* ---------------------------------------------------------------- Tag input */

const splitTags = (s: string) => s.split(',').map((t) => t.trim()).filter(Boolean);

/** Chip input stored as a comma-separated string ("admissions, mba"). */
export function TagInput({ value, onChange, id, placeholder = 'Add a tag and press Enter', invalid, max = 12, disabled }: { value: string; onChange: (v: string) => void; id?: string; placeholder?: string; invalid?: boolean; max?: number; disabled?: boolean }) {
  const tags = splitTags(value || '');
  const [draft, setDraft] = useState('');
  const set = (list: string[]) => onChange([...new Set(list.map((t) => t.replace(/\s+/g, ' ')))].slice(0, max).join(', '));
  const commit = () => {
    const add = splitTags(draft);
    if (add.length) set([...tags, ...add]);
    setDraft('');
  };
  return (
    <div className={clsx('form-input flex min-h-[2.5rem] flex-wrap items-center gap-1.5 !py-1.5', invalid && 'form-input-error', disabled && 'cursor-not-allowed opacity-60')} onClick={() => !disabled && document.getElementById(id ?? '')?.focus()}>
      {tags.map((t) => (
        <span key={t} className="inline-flex items-center gap-1 rounded-md bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">
          {t}
          <button type="button" disabled={disabled} onClick={() => set(tags.filter((x) => x !== t))} className="rounded text-brand-600 hover:text-red-600 disabled:pointer-events-none dark:text-brand-300" aria-label={`Remove tag ${t}`}>
            <X className="h-3 w-3" />
          </button>
        </span>
      ))}
      <input
        id={id}
        value={draft}
        onChange={(e) => (e.target.value.endsWith(',') ? (set([...tags, ...splitTags(e.target.value)]), setDraft('')) : setDraft(e.target.value))}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            commit();
          } else if (e.key === 'Backspace' && !draft && tags.length) {
            set(tags.slice(0, -1));
          }
        }}
        onBlur={commit}
        placeholder={tags.length >= max ? '' : tags.length ? '' : placeholder}
        disabled={disabled || tags.length >= max}
        className="min-w-[8rem] flex-1 border-0 bg-transparent p-0.5 text-sm outline-none ring-0 placeholder:text-slate-400 focus:ring-0 dark:text-slate-100"
      />
    </div>
  );
}

/* ---------------------------------------------------------------- Drag & drop reordering */

/**
 * Local ordering state with HTML5 drag & drop + keyboard-friendly move up/down. `onCommit` receives the new id order.
 *   const s = useReorder(rows, (ids) => api.post(...));
 *   s.items.map((r) => <li {...s.dragProps(r.id)}>…<button onClick={() => s.move(r.id, -1)}>Up</button></li>)
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function useReorder<T extends Record<string, any>>(source: T[], onCommit: (ids: number[]) => void, enabled = true) {
  const [items, setItems] = useState<T[]>(source);
  const [overId, setOverId] = useState<number | null>(null);
  const [dragId, setDragId] = useState<number | null>(null);
  const dragRef = useRef<number | null>(null);
  useEffect(() => setItems(source), [source]);

  const commit = (next: T[]) => {
    setItems(next);
    onCommit(next.map((i) => Number(i.id)));
  };
  const moveTo = (fromId: number, toId: number) => {
    const from = items.findIndex((i) => Number(i.id) === fromId);
    const to = items.findIndex((i) => Number(i.id) === toId);
    if (from < 0 || to < 0 || from === to) return;
    const next = [...items];
    const [it] = next.splice(from, 1);
    next.splice(to, 0, it);
    commit(next);
  };
  const move = (id: number, dir: -1 | 1) => {
    const idx = items.findIndex((i) => Number(i.id) === id);
    const to = idx + dir;
    if (idx < 0 || to < 0 || to >= items.length) return;
    moveTo(id, Number(items[to].id));
  };
  const dragProps = (id: number) =>
    enabled
      ? {
          draggable: true,
          onDragStart: (e: DragEvent) => {
            dragRef.current = id;
            setDragId(id);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(id));
          },
          onDragOver: (e: DragEvent) => {
            if (dragRef.current === null) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (overId !== id) setOverId(id);
          },
          onDragLeave: () => setOverId((o) => (o === id ? null : o)),
          onDrop: (e: DragEvent) => {
            e.preventDefault();
            const from = dragRef.current;
            dragRef.current = null;
            setDragId(null);
            setOverId(null);
            if (from !== null && from !== id) moveTo(from, id);
          },
          onDragEnd: () => {
            dragRef.current = null;
            setDragId(null);
            setOverId(null);
          },
        }
      : {};
  return { items, move, dragProps, overId, dragId };
}
