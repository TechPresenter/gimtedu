import clsx from 'clsx';
import { Bold, Braces, Code, GripVertical, Italic, Plus, Smile, Strikethrough } from 'lucide-react';
import { Fragment, forwardRef, useEffect, useImperativeHandle, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { VariableChips, useWaVariables, type WaVariable } from '../shared';
import { KEY_RE, VAR_RE, metaLength } from './lib';

/* ------------------------------------------------------------------ popover */

function Popover({ open, onClose, children, align = 'left', className }: { open: boolean; onClose: () => void; children: ReactNode; align?: 'left' | 'right'; className?: string }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node) && !(e.target as HTMLElement).closest?.('[data-popover-trigger]')) onClose();
    };
    const onKey = (e: globalThis.KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div
      ref={ref}
      role="dialog"
      className={clsx(
        'absolute top-full z-30 mt-1.5 rounded-xl border border-slate-200 bg-white p-3 shadow-xl dark:border-slate-700 dark:bg-slate-900',
        align === 'right' ? 'right-0' : 'left-0',
        className,
      )}
    >
      {children}
    </div>
  );
}

/* ------------------------------------------------------------------ variable picker */

/**
 * "Insert variable" popover: GIMT catalogue chips (shared VariableChips) + a custom variable name.
 * onPick(key, sample) — the sample pre-fills the template's sample value.
 */
export function VariablePicker({
  onPick,
  disabled,
  label = 'Variable',
  used = [],
  align = 'left',
  size = 'sm',
}: {
  onPick: (key: string, sample: string) => void;
  disabled?: boolean;
  label?: string;
  used?: string[];
  align?: 'left' | 'right';
  size?: 'xs' | 'sm';
}) {
  const [open, setOpen] = useState(false);
  const [custom, setCustom] = useState('');
  const [err, setErr] = useState('');
  const addCustom = () => {
    const k = custom.trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_');
    if (!KEY_RE.test(k)) {
      setErr('Start with a letter; use a–z, 0–9 and _ (max 40).');
      return;
    }
    onPick(k, '');
    setCustom('');
    setErr('');
    setOpen(false);
  };
  return (
    <div className="relative">
      <button
        type="button"
        data-popover-trigger
        disabled={disabled}
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className={clsx(
          'inline-flex items-center gap-1 rounded-md font-medium text-brand-700 ring-1 ring-inset ring-brand-200 transition hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-50 dark:text-brand-200 dark:ring-brand-500/40 dark:hover:bg-brand-500/10',
          size === 'xs' ? 'px-1.5 py-0.5 text-[11px]' : 'px-2 py-1 text-xs',
        )}
      >
        <Braces size={size === 'xs' ? 11 : 13} aria-hidden />
        {label}
      </button>
      <Popover open={open} onClose={() => setOpen(false)} align={align} className="w-[min(22rem,calc(100vw-3rem))]">
        <p className="mb-2 text-xs text-slate-500 dark:text-slate-400">GIMT fields are filled automatically from the contact, admission, fee or exam record when the message is sent.</p>
        <div className="max-h-64 overflow-y-auto pr-1">
          <VariableChips
            search
            used={used}
            onPick={(k, v) => {
              onPick(k, v.sample ?? '');
              setOpen(false);
            }}
          />
        </div>
        <div className="mt-3 border-t border-slate-100 pt-3 dark:border-slate-800">
          <label htmlFor="tpl-custom-var" className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            Custom variable
          </label>
          <div className="flex gap-1.5">
            <input
              id="tpl-custom-var"
              value={custom}
              onChange={(e) => {
                setCustom(e.target.value);
                setErr('');
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  addCustom();
                }
              }}
              placeholder="e.g. hostel_block"
              className="form-input form-input-sm min-w-0 flex-1 font-mono"
            />
            <button type="button" onClick={addCustom} className="btn btn-soft btn-sm shrink-0" aria-label="Add custom variable">
              <Plus size={14} aria-hidden />
            </button>
          </div>
          {err ? (
            <p className="mt-1 text-[11px] text-red-600 dark:text-red-400">{err}</p>
          ) : (
            <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">For values the sending event or campaign provides (e.g. ticket number).</p>
          )}
        </div>
      </Popover>
    </div>
  );
}

/* ------------------------------------------------------------------ drag-and-drop variable palette */

/**
 * Draggable GIMT variable chips: drag one into the message body, the header text or a website-button link (native
 * text drop inserts {{key}} where it is dropped), or click it to insert at the body cursor.
 */
export function VariablePalette({ onPick, used = [], disabled }: { onPick: (key: string, sample: string) => void; used?: string[]; disabled?: boolean }) {
  const { data, isLoading, error } = useWaVariables();
  const [q, setQ] = useState('');
  const groups = useMemo(() => {
    const out = new Map<string, WaVariable[]>();
    const needle = q.trim().toLowerCase();
    for (const v of data ?? []) {
      if (needle && !`${v.key} ${v.label}`.toLowerCase().includes(needle)) continue;
      const g = v.group || 'Other';
      if (!out.has(g)) out.set(g, []);
      out.get(g)!.push(v);
    }
    return [...out.entries()];
  }, [data, q]);

  return (
    <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50/60 p-3 dark:border-slate-700 dark:bg-slate-800/30">
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <p className="flex min-w-0 flex-1 items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
          <GripVertical size={13} className="shrink-0 text-slate-400" aria-hidden />
          <span>
            <span className="font-semibold text-slate-800 dark:text-slate-100">Variable palette</span> — drag a variable into the message, header or link, or click to insert it.
          </span>
        </p>
        <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filter…" aria-label="Filter variables" className="form-input form-input-sm w-full sm:w-40" />
      </div>
      {isLoading ? (
        <div className="flex flex-wrap gap-1.5">
          {Array.from({ length: 10 }, (_, i) => (
            <span key={i} className="skeleton h-6 w-24 rounded-full" />
          ))}
        </div>
      ) : error ? (
        <p className="text-xs text-red-600 dark:text-red-400">Could not load the GIMT variable list.</p>
      ) : !groups.length ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">No variables match “{q}”.</p>
      ) : (
        <div className="max-h-40 space-y-2 overflow-y-auto pr-1">
          {groups.map(([group, vars]) => (
            <div key={group} className="flex flex-wrap items-center gap-1.5">
              <span className="w-full text-[10px] font-semibold uppercase tracking-wide text-slate-500 sm:w-auto sm:min-w-[5.5rem] dark:text-slate-400">{group}</span>
              {vars.map((v) => (
                <button
                  key={v.key}
                  type="button"
                  draggable={!disabled}
                  disabled={disabled}
                  onDragStart={(e) => {
                    e.dataTransfer.setData('text/plain', `{{${v.key}}}`);
                    e.dataTransfer.effectAllowed = 'copy';
                  }}
                  onClick={() => onPick(v.key, v.sample ?? '')}
                  title={`${v.label}${v.sample ? ` — e.g. ${v.sample}` : ''}`}
                  className={clsx(
                    'inline-flex cursor-grab items-center gap-1 rounded-full px-2 py-0.5 font-mono text-[11px] ring-1 ring-inset transition active:cursor-grabbing',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-50',
                    used.includes(v.key)
                      ? 'bg-brand-900 text-white ring-brand-900 dark:bg-brand-500 dark:ring-brand-400'
                      : 'bg-white text-slate-700 ring-slate-200 hover:bg-accent-50 hover:text-accent-700 hover:ring-accent-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700',
                  )}
                >
                  <Braces size={11} aria-hidden className="opacity-60" />
                  {v.key}
                </button>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ emoji picker */

const EMOJIS = ['😊', '🙏', '👍', '👋', '🎉', '🎊', '🎓', '📚', '📝', '📅', '⏰', '✅', '☑️', '❗', '⚠️', '📞', '📍', '💳', '🧾', '📄', '🏫', '🎯', '🌟', '✨', '🪔', '🔔', '📢', '💡', '🤝', '🏆', '💼', '🔗', '➡️', '👉', '📲', '💬', '🗓️', '🕒', '🙂', '❤️'];

function EmojiPicker({ onPick, disabled }: { onPick: (e: string) => void; disabled?: boolean }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="relative">
      <ToolButton label="Emoji" icon={Smile} disabled={disabled} onClick={() => setOpen((o) => !o)} trigger />
      <Popover open={open} onClose={() => setOpen(false)} className="w-64">
        <div className="grid grid-cols-8 gap-0.5">
          {EMOJIS.map((e) => (
            <button
              key={e}
              type="button"
              onClick={() => {
                onPick(e);
                setOpen(false);
              }}
              className="flex h-7 w-7 items-center justify-center rounded text-lg transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:hover:bg-slate-800"
              aria-label={`Insert ${e}`}
            >
              {e}
            </button>
          ))}
        </div>
      </Popover>
    </div>
  );
}

function ToolButton({ label, icon: Icon, onClick, disabled, trigger, shortcut }: { label: string; icon: typeof Bold; onClick: () => void; disabled?: boolean; trigger?: boolean; shortcut?: string }) {
  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      disabled={disabled}
      title={shortcut ? `${label} (${shortcut})` : label}
      aria-label={label}
      {...(trigger ? { 'data-popover-trigger': true } : {})}
      className="inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-40 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
    >
      <Icon size={15} aria-hidden />
    </button>
  );
}

/* ------------------------------------------------------------------ body editor */

/**
 * WhatsApp body editor: plain textarea (emoji-safe, native undo) with a highlight layer that shows {{variables}} as
 * chips, a formatting toolbar (*bold* _italic_ ~strike~ ```mono```), emoji and variable insertion, and a Meta
 * character counter (positional form).
 */
export interface BodyEditorHandle {
  /** Insert text at the caret (variables get a leading space when glued to a word). */
  insert: (text: string) => void;
}

export const BodyEditor = forwardRef<
  BodyEditorHandle,
  {
    id?: string;
    value: string;
    onChange: (v: string) => void;
    max: number;
    invalid?: boolean;
    disabled?: boolean;
    placeholder?: string;
    onVariableInserted?: (key: string, sample: string) => void;
    minRows?: number;
  }
>(function BodyEditor({ id, value, onChange, max, invalid, disabled, placeholder, onVariableInserted, minRows = 7 }, ref) {
  const ta = useRef<HTMLTextAreaElement>(null);
  const pendingSel = useRef<[number, number] | null>(null);

  // auto-grow + restore the caret after programmatic edits
  useLayoutEffect(() => {
    const el = ta.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.max(el.scrollHeight, minRows * 24 + 22)}px`;
    if (pendingSel.current) {
      const [s, e] = pendingSel.current;
      pendingSel.current = null;
      el.focus();
      el.setSelectionRange(s, e);
    }
  }, [value, minRows]);

  const edit = (fn: (v: string, s: number, e: number) => { text: string; sel: [number, number] }) => {
    const el = ta.current;
    const s = el ? el.selectionStart : value.length;
    const e = el ? el.selectionEnd : value.length;
    const r = fn(value, s, e);
    pendingSel.current = r.sel;
    onChange(r.text);
  };

  const wrap = (mark: string) =>
    edit((v, s, e) => {
      const sel = v.slice(s, e);
      // unwrap when the selection is already wrapped
      if (sel && v.slice(s - mark.length, s) === mark && v.slice(e, e + mark.length) === mark) {
        return { text: v.slice(0, s - mark.length) + sel + v.slice(e + mark.length), sel: [s - mark.length, e - mark.length] };
      }
      // keep surrounding spaces outside the markers (WhatsApp needs *word* not * word *)
      const lead = sel.match(/^\s*/)?.[0] ?? '';
      const trail = sel.match(/\s*$/)?.[0] ?? '';
      const core = sel.slice(lead.length, sel.length - trail.length) || (mark === '```' ? 'code' : 'text');
      const text = v.slice(0, s) + lead + mark + core + mark + trail + v.slice(e);
      const start = s + lead.length + mark.length;
      return { text, sel: [start, start + core.length] };
    });

  const insert = (str: string) =>
    edit((v, s, e) => {
      const before = v.slice(0, s);
      const pad = str.startsWith('{{') && before && !/\s$/.test(before) ? ' ' : '';
      const text = before + pad + str + v.slice(e);
      const pos = s + pad.length + str.length;
      return { text, sel: [pos, pos] };
    });
  useImperativeHandle(ref, () => ({ insert }));

  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (!(e.ctrlKey || e.metaKey)) return;
    const k = e.key.toLowerCase();
    if (k === 'b') {
      e.preventDefault();
      wrap('*');
    } else if (k === 'i') {
      e.preventDefault();
      wrap('_');
    } else if (k === 'x' && e.shiftKey) {
      e.preventDefault();
      wrap('~');
    } else if (k === 'm' && e.shiftKey) {
      e.preventDefault();
      wrap('```');
    }
  };

  const len = metaLength(value);
  const used = [...new Set([...value.matchAll(VAR_RE)].map((m) => m[1].trim()))];
  const over = len > max;

  // highlight layer: same text, transparent, variables drawn as chips behind the textarea text
  const parts: ReactNode[] = [];
  let last = 0;
  let i = 0;
  for (const m of value.matchAll(VAR_RE)) {
    const idx = m.index ?? 0;
    if (idx > last) parts.push(<Fragment key={`t${i}`}>{value.slice(last, idx)}</Fragment>);
    const ok = KEY_RE.test(m[1].trim());
    parts.push(
      <mark
        key={`v${i}`}
        className={clsx(
          'rounded-[5px] text-transparent [box-decoration-break:clone]',
          ok ? 'bg-accent-100 shadow-[0_0_0_1px_rgba(240,90,40,.35)] dark:bg-accent-500/25' : 'bg-red-100 shadow-[0_0_0_1px_rgba(220,38,38,.5)] dark:bg-red-500/25',
        )}
      >
        {m[0]}
      </mark>,
    );
    last = idx + m[0].length;
    i++;
  }
  parts.push(<Fragment key="end">{value.slice(last) + '​'}</Fragment>);

  return (
    <div
      className={clsx(
        'overflow-visible rounded-xl border bg-white transition focus-within:ring-2 dark:bg-slate-900',
        invalid || over ? 'border-red-400 focus-within:ring-red-500/30 dark:border-red-500/60' : 'border-slate-300 focus-within:border-brand-500 focus-within:ring-brand-500/20 dark:border-slate-700',
        disabled && 'opacity-70',
      )}
    >
      <div className="flex flex-wrap items-center gap-0.5 border-b border-slate-200 px-1.5 py-1 dark:border-slate-700">
        <ToolButton label="Bold" icon={Bold} onClick={() => wrap('*')} disabled={disabled} shortcut="Ctrl+B" />
        <ToolButton label="Italic" icon={Italic} onClick={() => wrap('_')} disabled={disabled} shortcut="Ctrl+I" />
        <ToolButton label="Strikethrough" icon={Strikethrough} onClick={() => wrap('~')} disabled={disabled} shortcut="Ctrl+Shift+X" />
        <ToolButton label="Monospace" icon={Code} onClick={() => wrap('```')} disabled={disabled} shortcut="Ctrl+Shift+M" />
        <span className="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700" aria-hidden />
        <EmojiPicker onPick={insert} disabled={disabled} />
        <span className="mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700" aria-hidden />
        <VariablePicker
          disabled={disabled}
          used={used}
          label="Insert variable"
          onPick={(k, sample) => {
            insert(`{{${k}}}`);
            onVariableInserted?.(k, sample);
          }}
        />
        <span className={clsx('ml-auto pr-1.5 text-[11px] tabular-nums', over ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400')}>
          {len} / {max}
        </span>
      </div>
      <div className="relative">
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 overflow-hidden whitespace-pre-wrap break-words px-3.5 py-3 font-sans text-sm leading-6 text-transparent"
        >
          {parts}
        </div>
        <textarea
          ref={ta}
          id={id}
          value={value}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value)}
          onKeyDown={onKeyDown}
          placeholder={placeholder}
          spellCheck
          aria-invalid={invalid || over || undefined}
          className="relative block w-full resize-none overflow-hidden whitespace-pre-wrap break-words bg-transparent px-3.5 py-3 font-sans text-sm leading-6 text-slate-900 placeholder:text-slate-400 focus:outline-none disabled:cursor-not-allowed dark:text-slate-100 dark:placeholder:text-slate-500"
        />
      </div>
    </div>
  );
});

export default BodyEditor;
