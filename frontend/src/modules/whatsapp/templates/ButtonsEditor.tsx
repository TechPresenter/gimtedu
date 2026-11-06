import clsx from 'clsx';
import { AlertTriangle, ArrowDown, ArrowUp, Copy, ExternalLink, GripVertical, MessageSquareReply, MousePointerClick, Phone, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Field, IconButton, Input } from '@/components/ui';
import { VariablePicker } from './BodyEditor';
import { extractKeys, isLinkValuedKey, newButton, urlBase } from './lib';
import type { TplButton, TplButtonGroup, TplLimits } from './types';

const GROUPS: { key: TplButtonGroup; label: string; hint: string; icon: typeof Plus }[] = [
  { key: 'none', label: 'No buttons', hint: 'Text only', icon: X },
  { key: 'cta', label: 'Call to action', hint: 'Website, call, copy code', icon: MousePointerClick },
  { key: 'quick_reply', label: 'Quick replies', hint: 'Up to 10 reply chips', icon: MessageSquareReply },
];

const TYPE_LABEL: Record<string, string> = { URL: 'Visit website', PHONE_NUMBER: 'Call phone number', COPY_CODE: 'Copy offer code', QUICK_REPLY: 'Quick reply' };
const TYPE_ICON = { URL: ExternalLink, PHONE_NUMBER: Phone, COPY_CODE: Copy, QUICK_REPLY: MessageSquareReply, OTP: Copy } as const;

/**
 * Buttons editor: one group per template (Meta groups quick replies and call-to-action buttons separately; Interakt's
 * create API takes one group). CTA: up to 2 website buttons (static or one trailing variable), 1 call button,
 * 1 copy-code button. Quick replies: up to 10, 25 characters each.
 */
export function ButtonsEditor({
  group,
  buttons,
  onChange,
  limits,
  errors,
  disabled,
  allowCopyCode,
  onVariableInserted,
}: {
  group: TplButtonGroup;
  buttons: TplButton[];
  onChange: (group: TplButtonGroup, buttons: TplButton[]) => void;
  limits: TplLimits;
  errors: Record<string, string>;
  disabled?: boolean;
  allowCopyCode?: boolean;
  onVariableInserted?: (key: string, sample: string) => void;
}) {
  const count = (t: TplButton['type']) => buttons.filter((b) => b.type === t).length;
  const canAdd: Record<string, boolean> = {
    URL: count('URL') < limits.url_buttons,
    PHONE_NUMBER: count('PHONE_NUMBER') < limits.phone_buttons,
    COPY_CODE: !!allowCopyCode && count('COPY_CODE') < limits.copy_code_buttons,
    QUICK_REPLY: count('QUICK_REPLY') < limits.quick_replies,
  };
  const set = (i: number, patch: Partial<TplButton>) => onChange(group, buttons.map((b, j) => (j === i ? { ...b, ...patch } : b)));
  const move = (i: number, d: -1 | 1) => {
    const j = i + d;
    if (j < 0 || j >= buttons.length) return;
    const next = [...buttons];
    [next[i], next[j]] = [next[j], next[i]];
    onChange(group, next);
  };
  const remove = (i: number) => onChange(group, buttons.filter((_, j) => j !== i));
  const add = (t: TplButton['type']) => onChange(group, [...buttons, newButton(t)]);

  // drag-and-drop reordering: dragging starts from the grip handle only (text in the inputs stays selectable);
  // the arrow buttons remain for keyboard users
  const [armed, setArmed] = useState<number | null>(null);
  const [dragFrom, setDragFrom] = useState<number | null>(null);
  const [dragOver, setDragOver] = useState<number | null>(null);
  const endDrag = () => {
    setArmed(null);
    setDragFrom(null);
    setDragOver(null);
  };
  const dropAt = (to: number) => {
    if (dragFrom === null || dragFrom === to) return endDrag();
    const next = [...buttons];
    const [moved] = next.splice(dragFrom, 1);
    next.splice(to, 0, moved);
    onChange(group, next);
    endDrag();
  };

  const switchGroup = (g: TplButtonGroup) => {
    if (g === group) return;
    if (g === 'none') onChange('none', []);
    else if (g === 'quick_reply') onChange('quick_reply', [newButton('QUICK_REPLY')]);
    else onChange('cta', [newButton('URL')]);
  };

  return (
    <div className="space-y-4">
      <div className="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Button type">
        {GROUPS.map((g) => (
          <button
            key={g.key}
            type="button"
            role="radio"
            aria-checked={group === g.key}
            disabled={disabled}
            onClick={() => switchGroup(g.key)}
            className={clsx(
              'flex items-center gap-2.5 rounded-xl border px-3 py-2.5 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-60',
              group === g.key
                ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600 dark:border-brand-400 dark:bg-brand-500/10 dark:ring-brand-400'
                : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/60',
            )}
          >
            <g.icon size={16} className={group === g.key ? 'text-brand-700 dark:text-brand-200' : 'text-slate-400'} aria-hidden />
            <span className="min-w-0">
              <span className="block text-sm font-semibold text-slate-900 dark:text-white">{g.label}</span>
              <span className="block text-[11px] text-slate-500 dark:text-slate-400">{g.hint}</span>
            </span>
          </button>
        ))}
      </div>
      {errors.buttons && <p className="text-xs font-medium text-red-600 dark:text-red-400">{errors.buttons}</p>}

      {group !== 'none' && (
        <ol className="space-y-3">
          {buttons.map((b, i) => {
            const Icon = TYPE_ICON[b.type] ?? MessageSquareReply;
            const e = (f: string) => errors[`buttons.${i}.${f}`];
            const urlKeys = b.type === 'URL' ? extractKeys(b.url) : [];
            return (
              <li
                key={i}
                draggable={!disabled && armed === i}
                onDragStart={(ev) => {
                  setDragFrom(i);
                  ev.dataTransfer.effectAllowed = 'move';
                  ev.dataTransfer.setData('application/x-gimt-button', String(i));
                }}
                onDragOver={(ev) => {
                  if (dragFrom === null) return;
                  ev.preventDefault();
                  ev.dataTransfer.dropEffect = 'move';
                  if (dragOver !== i) setDragOver(i);
                }}
                onDrop={(ev) => {
                  if (dragFrom === null) return;
                  ev.preventDefault();
                  dropAt(i);
                }}
                onDragEnd={endDrag}
                className={clsx(
                  'rounded-xl border bg-slate-50/60 p-3 transition dark:bg-slate-800/40',
                  dragOver === i && dragFrom !== null && dragFrom !== i ? 'border-accent-400 ring-2 ring-accent-200 dark:border-accent-500/60 dark:ring-accent-500/20' : 'border-slate-200 dark:border-slate-700',
                  dragFrom === i && 'opacity-50',
                )}
              >
                <div className="mb-2.5 flex items-center gap-2">
                  {buttons.length > 1 && !disabled && (
                    <span
                      className="-ml-1 flex h-6 w-5 cursor-grab items-center justify-center rounded text-slate-400 hover:text-slate-600 active:cursor-grabbing dark:hover:text-slate-200"
                      title="Drag to reorder"
                      aria-hidden
                      onMouseDown={() => setArmed(i)}
                      onMouseUp={() => setArmed(null)}
                    >
                      <GripVertical size={14} />
                    </span>
                  )}
                  <span className="flex h-6 w-6 items-center justify-center rounded-md bg-white text-brand-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-brand-200 dark:ring-slate-700">
                    <Icon size={13} aria-hidden />
                  </span>
                  <span className="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
                    {TYPE_LABEL[b.type] ?? b.type} {group === 'quick_reply' ? i + 1 : ''}
                  </span>
                  {e('type') && <span className="text-xs text-red-600 dark:text-red-400">{e('type')}</span>}
                  <span className="ml-auto flex items-center gap-0.5">
                    <IconButton icon={ArrowUp} label="Move up" size="sm" disabled={disabled || i === 0} onClick={() => move(i, -1)} />
                    <IconButton icon={ArrowDown} label="Move down" size="sm" disabled={disabled || i === buttons.length - 1} onClick={() => move(i, 1)} />
                    <IconButton icon={Trash2} label="Remove button" size="sm" tone="danger" disabled={disabled} onClick={() => remove(i)} />
                  </span>
                </div>
                <div className="grid gap-3 sm:grid-cols-12">
                  {b.type !== 'COPY_CODE' && (
                    <Field label="Button text" required error={e('text')} className={b.type === 'QUICK_REPLY' ? 'sm:col-span-12' : 'sm:col-span-5'} htmlFor={`btn-${i}-text`}>
                      <Input
                        id={`btn-${i}-text`}
                        inputSize="sm"
                        value={b.text}
                        maxLength={limits.button_text}
                        disabled={disabled}
                        invalid={!!e('text')}
                        onChange={(ev) => set(i, { text: ev.target.value })}
                        suffix={<span className="text-[10px] tabular-nums text-slate-400">{Array.from(b.text).length}/{limits.button_text}</span>}
                      />
                    </Field>
                  )}
                  {b.type === 'URL' && (
                    <Field
                      label="Website link"
                      required
                      error={e('url')}
                      className="sm:col-span-7"
                      htmlFor={`btn-${i}-url`}
                      hint={
                        urlKeys.length && isLinkValuedKey(urlKeys[0]) ? (
                          <span className="flex items-start gap-1.5 text-amber-700 dark:text-amber-300" role="status">
                            <AlertTriangle size={13} className="mt-px shrink-0" aria-hidden />
                            <span>
                              <code className="font-mono">{`{{${urlKeys[0]}}}`}</code> holds a whole link, but WhatsApp adds the variable to the end of{' '}
                              <span className="break-all font-mono">{urlBase(b.url) || 'this address'}</span>, so the button would open a broken address. Use a value that completes it, e.g.{' '}
                              <span className="break-all font-mono">{'https://gimtedu.com/verify-receipt/{{receipt_no}}'}</span>, or a static link. Templates with this link cannot be submitted.
                            </span>
                          </span>
                        ) : urlKeys.length ? (
                          'Dynamic link — the variable completes the address per message (its value is added to the end).'
                        ) : (
                          'Static link, or one variable at the end that completes it, e.g. https://gimtedu.com/verify-receipt/{{receipt_no}}.'
                        )
                      }
                      aside={
                        <VariablePicker
                          size="xs"
                          label={urlKeys.length ? 'Change variable' : 'Add variable'}
                          disabled={disabled}
                          align="right"
                          onPick={(k, sample) => {
                            const base = (b.url ?? '').replace(/\{\{[^{}]*\}\}\s*$/, '').replace(/\{\{[^{}]*\}\}/g, '');
                            set(i, { url: `${base}{{${k}}}` });
                            onVariableInserted?.(k, sample);
                          }}
                        />
                      }
                    >
                      <Input
                        id={`btn-${i}-url`}
                        inputSize="sm"
                        value={b.url ?? ''}
                        disabled={disabled}
                        invalid={!!e('url')}
                        placeholder="https://gimtedu.com/apply"
                        onChange={(ev) => set(i, { url: ev.target.value })}
                        className="font-mono"
                      />
                    </Field>
                  )}
                  {b.type === 'PHONE_NUMBER' && (
                    <Field label="Phone number" required error={e('phone_number')} className="sm:col-span-7" htmlFor={`btn-${i}-phone`} hint="With country code, e.g. +91 90600 31540">
                      <Input
                        id={`btn-${i}-phone`}
                        inputSize="sm"
                        type="tel"
                        value={b.phone_number ?? ''}
                        disabled={disabled}
                        invalid={!!e('phone_number')}
                        placeholder="+91 90600 31540"
                        onChange={(ev) => set(i, { phone_number: ev.target.value })}
                      />
                    </Field>
                  )}
                  {b.type === 'COPY_CODE' && (
                    <Field
                      label="Sample offer code"
                      required
                      error={e('example')}
                      className="sm:col-span-12"
                      htmlFor={`btn-${i}-code`}
                      hint={`Shown as "Copy offer code". Letters and digits, max ${limits.copy_code}. Not documented in Interakt's template API (only OTP copy-code is) — confirm it on your account after approval.`}
                    >
                      <Input
                        id={`btn-${i}-code`}
                        inputSize="sm"
                        value={b.example ?? ''}
                        maxLength={limits.copy_code}
                        disabled={disabled}
                        invalid={!!e('example')}
                        placeholder="GIMT2026"
                        onChange={(ev) => set(i, { example: ev.target.value.toUpperCase() })}
                        className="font-mono"
                      />
                    </Field>
                  )}
                </div>
              </li>
            );
          })}
        </ol>
      )}

      {group === 'cta' && (
        <div className="flex flex-wrap gap-2">
          {(['URL', 'PHONE_NUMBER', ...(allowCopyCode ? ['COPY_CODE'] : [])] as TplButton['type'][]).map((t) => {
            const Icon = TYPE_ICON[t];
            return (
              <button
                key={t}
                type="button"
                disabled={disabled || !canAdd[t] || buttons.length >= limits.buttons_total}
                onClick={() => add(t)}
                className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:border-brand-400 hover:text-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-600 dark:text-slate-300 dark:hover:border-brand-400 dark:hover:text-brand-200"
              >
                <Plus size={13} aria-hidden />
                <Icon size={13} aria-hidden />
                {TYPE_LABEL[t]}
              </button>
            );
          })}
          <span className="self-center text-[11px] text-slate-500 dark:text-slate-400">Max 2 website, 1 call{allowCopyCode ? ', 1 copy code' : ''}.</span>
        </div>
      )}
      {group === 'quick_reply' && (
        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            disabled={disabled || !canAdd.QUICK_REPLY}
            onClick={() => add('QUICK_REPLY')}
            className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:border-brand-400 hover:text-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-600 dark:text-slate-300 dark:hover:border-brand-400 dark:hover:text-brand-200"
          >
            <Plus size={13} aria-hidden /> Add quick reply
          </button>
          <span className="text-[11px] text-slate-500 dark:text-slate-400">
            {buttons.length} / {limits.quick_replies} — replies arrive in the inbox and can trigger workflows.
          </span>
        </div>
      )}
    </div>
  );
}

export default ButtonsEditor;
