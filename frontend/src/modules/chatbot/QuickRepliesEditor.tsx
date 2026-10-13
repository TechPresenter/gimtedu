import { ArrowDown, ArrowUp, ListPlus, MessageCircleReply, Plus, Trash2 } from 'lucide-react';
import { Button, Card, CardHeader, Input, Select } from '@/components/ui';
import type { QuickReply } from './types';

const ACTIONS = [
  { value: '', label: 'Send a question' },
  { value: 'lead', label: 'Open callback form' },
  { value: 'whatsapp', label: 'Open WhatsApp' },
  { value: 'link', label: 'Open a page' },
];

/** Quick-reply chips shown under "How can I help you?" (max 10). */
export function QuickRepliesEditor({ value, onChange, error }: { value: QuickReply[]; onChange: (v: QuickReply[]) => void; error?: string }) {
  const update = (i: number, patch: Partial<QuickReply>) => onChange(value.map((q, j) => (j === i ? { ...q, ...patch } : q)));
  const move = (i: number, dir: -1 | 1) => {
    const j = i + dir;
    if (j < 0 || j >= value.length) return;
    const next = [...value];
    [next[i], next[j]] = [next[j], next[i]];
    onChange(next);
  };
  return (
    <Card>
      <CardHeader
        title="Quick replies"
        subtitle="Topic chips shown when the chat opens. A question chip sends its text as the visitor's message."
        icon={MessageCircleReply}
        actions={
          <Button size="sm" variant="secondary" icon={Plus} disabled={value.length >= 10} onClick={() => onChange([...value, { label: '', message: '' }])}>
            Add
          </Button>
        }
      />
      <div className="card-body space-y-2">
        {value.length === 0 && (
          <p className="flex items-center gap-2 rounded-xl border border-dashed border-slate-300 px-3 py-4 text-sm text-slate-500 dark:border-slate-700">
            <ListPlus className="h-4 w-4" /> No quick replies — visitors start by typing.
          </p>
        )}
        {value.map((q, i) => {
          const action = q.action ?? '';
          return (
            <div key={i} className="grid grid-cols-1 gap-2 rounded-xl border border-slate-200 p-2.5 sm:grid-cols-[minmax(0,1fr)_170px_minmax(0,1.4fr)_auto] dark:border-slate-700">
              <Input inputSize="sm" aria-label={`Quick reply ${i + 1} label`} placeholder="Chip label" maxLength={40} value={q.label} onChange={(e) => update(i, { label: e.target.value })} />
              <Select
                inputSize="sm"
                aria-label={`Quick reply ${i + 1} action`}
                value={action}
                options={ACTIONS}
                onChange={(e) => {
                  const a = e.target.value as QuickReply['action'] | '';
                  update(i, { action: a || undefined, message: a ? undefined : q.message ?? q.label, url: a === 'link' ? q.url ?? '/' : undefined });
                }}
              />
              {action === '' ? (
                <Input inputSize="sm" aria-label={`Quick reply ${i + 1} question`} placeholder="Question sent to the assistant" maxLength={200} value={q.message ?? ''} onChange={(e) => update(i, { message: e.target.value })} />
              ) : action === 'link' ? (
                <Input inputSize="sm" aria-label={`Quick reply ${i + 1} link`} placeholder="/apply or https://…" value={q.url ?? ''} onChange={(e) => update(i, { url: e.target.value })} />
              ) : (
                <p className="self-center text-xs text-slate-500">{action === 'lead' ? 'Shows the callback form in the chat.' : 'Opens WhatsApp chat with the admissions number.'}</p>
              )}
              <div className="flex items-center justify-end gap-0.5">
                <button type="button" onClick={() => move(i, -1)} disabled={i === 0} className="rounded p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label={`Move quick reply ${i + 1} up`}>
                  <ArrowUp className="h-3.5 w-3.5" />
                </button>
                <button type="button" onClick={() => move(i, 1)} disabled={i === value.length - 1} className="rounded p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label={`Move quick reply ${i + 1} down`}>
                  <ArrowDown className="h-3.5 w-3.5" />
                </button>
                <button type="button" onClick={() => onChange(value.filter((_, j) => j !== i))} className="rounded p-1.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10" aria-label={`Remove quick reply ${i + 1}`}>
                  <Trash2 className="h-3.5 w-3.5" />
                </button>
              </div>
            </div>
          );
        })}
        {error && <p className="form-error" role="alert">{error}</p>}
      </div>
    </Card>
  );
}
