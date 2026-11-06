import { useEffect, useState } from 'react';
import { Pencil, Plus, Trash2, Zap } from 'lucide-react';
import { Alert, Badge, Button, EmptyState, Field, IconButton, Input, Modal, SearchInput, Skeleton, Textarea, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { formatNumber } from '@/lib/format';
import { useQueryClient } from '@tanstack/react-query';
import { useQuickReplies } from './hooks';
import type { QuickReply } from './types';

interface Form {
  id: number | null;
  shortcut: string;
  title: string;
  body: string;
  category: string;
}
const EMPTY: Form = { id: null, shortcut: '', title: '', body: '', category: '' };

/** Quick-reply library: everyone with inbox access can browse; WhatsApp managers create, edit and delete. */
export function QuickRepliesManager({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [q, setQ] = useState('');
  const dq = useDebounce(q.trim(), 250);
  const list = useQuickReplies(dq, null, open);
  const [form, setForm] = useState<Form | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const canManage = list.data?.can_manage ?? false;

  useEffect(() => {
    if (!open) {
      setForm(null);
      setQ('');
    }
  }, [open]);

  const refresh = () => qc.invalidateQueries({ queryKey: ['wa', 'inbox', 'quick'] });

  const edit = (r: QuickReply | null) => {
    setErrors({});
    setForm(r ? { id: r.id, shortcut: r.shortcut, title: r.title, body: r.body, category: r.category ?? '' } : { ...EMPTY });
  };

  const save = async () => {
    if (!form) return;
    const e: Record<string, string> = {};
    if (!form.shortcut.trim()) e.shortcut = 'Shortcut is required.';
    if (!form.title.trim()) e.title = 'Title is required.';
    if (!form.body.trim()) e.body = 'Message is required.';
    setErrors(e);
    if (Object.keys(e).length) return;
    setSaving(true);
    try {
      const payload = { shortcut: form.shortcut, title: form.title, body: form.body, category: form.category };
      const res = form.id ? await api.put(`whatsapp/quick-replies/${form.id}`, payload) : await api.post('whatsapp/quick-replies', payload);
      toast.success(res.message || 'Saved.');
      setForm(null);
      void refresh();
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      if (!ae.errors || !Object.keys(ae.errors).length) toast.error(ae.message);
    } finally {
      setSaving(false);
    }
  };

  const remove = async (r: QuickReply) => {
    if (!(await confirm({ title: `Delete /${r.shortcut}?`, message: 'Agents will no longer see this quick reply.', confirmText: 'Delete', danger: true }))) return;
    try {
      const res = await api.del(`whatsapp/quick-replies/${r.id}`);
      toast.success(res.message || 'Deleted.');
      void refresh();
    } catch (err) {
      toast.error((err as ApiError).message);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      title="Quick replies"
      description="Saved answers agents insert by typing / in the composer. Placeholders: {{first_name}}, {{name}}, {{program}}, {{application_no}}, {{agent}}."
      icon={<span className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300"><Zap className="h-5 w-5" aria-hidden /></span>}
      footer={form ? (
        <>
          <Button variant="secondary" onClick={() => setForm(null)} disabled={saving}>Back to list</Button>
          <Button onClick={save} loading={saving}>{form.id ? 'Save changes' : 'Create quick reply'}</Button>
        </>
      ) : (
        <Button variant="secondary" onClick={onClose}>Close</Button>
      )}
    >
      {form ? (
        <div className="grid gap-4 sm:grid-cols-12">
          <Field label="Shortcut" htmlFor="qr-shortcut" required error={errors.shortcut} hint="Letters, numbers, - and _. Typed as /shortcut." className="sm:col-span-4">
            <Input id="qr-shortcut" prefix="/" value={form.shortcut} maxLength={40} invalid={!!errors.shortcut} onChange={(e) => setForm({ ...form, shortcut: e.target.value.replace(/^\/+/, '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-') })} />
          </Field>
          <Field label="Title" htmlFor="qr-title" required error={errors.title} className="sm:col-span-5">
            <Input id="qr-title" value={form.title} maxLength={120} invalid={!!errors.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          </Field>
          <Field label="Category" htmlFor="qr-cat" error={errors.category} className="sm:col-span-3">
            <Input id="qr-cat" value={form.category} maxLength={60} list="qr-cats" onChange={(e) => setForm({ ...form, category: e.target.value })} />
            <datalist id="qr-cats">{(list.data?.categories ?? []).map((c) => <option key={c} value={c} />)}</datalist>
          </Field>
          <Field label="Message" htmlFor="qr-body" required error={errors.body} aside={`${form.body.length}/4096`} className="sm:col-span-12">
            <Textarea id="qr-body" rows={6} value={form.body} maxLength={4096} invalid={!!errors.body} onChange={(e) => setForm({ ...form, body: e.target.value })} placeholder="Hi {{first_name}}, …" />
          </Field>
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <SearchInput value={q} onChange={setQ} placeholder="Search quick replies…" size="sm" className="sm:w-72" />
            {canManage && <Button size="sm" icon={Plus} onClick={() => edit(null)}>New quick reply</Button>}
          </div>
          {list.error ? (
            <Alert variant="error" title="Unable to load quick replies" action={<Button size="xs" variant="secondary" onClick={() => list.refetch()}>Retry</Button>}>{(list.error as ApiError).message}</Alert>
          ) : list.isLoading ? (
            <div className="space-y-2">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-16 w-full rounded-lg" />)}</div>
          ) : !list.data?.rows.length ? (
            <EmptyState icon={Zap} title={dq ? 'No matching quick replies' : 'No quick replies yet'} description={canManage ? 'Create answers for the questions students ask most.' : 'Ask a WhatsApp manager to add quick replies.'} action={canManage ? <Button size="sm" icon={Plus} onClick={() => edit(null)}>New quick reply</Button> : undefined} className="!py-8" />
          ) : (
            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
              {list.data.rows.map((r) => (
                <li key={r.id} className="flex items-start gap-3 p-3">
                  <span className="mt-0.5 shrink-0 rounded bg-accent-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">/{r.shortcut}</span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">{r.title}</span>
                      {r.category && <Badge color="slate">{r.category}</Badge>}
                      <span className="text-[11px] text-slate-400">used {formatNumber(r.usage_count)}×</span>
                    </div>
                    <p className="mt-0.5 line-clamp-2 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{r.body}</p>
                  </div>
                  {canManage && (
                    <div className="flex shrink-0 gap-0.5">
                      <IconButton size="sm" icon={Pencil} label={`Edit /${r.shortcut}`} onClick={() => edit(r)} />
                      <IconButton size="sm" icon={Trash2} tone="danger" label={`Delete /${r.shortcut}`} onClick={() => void remove(r)} />
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Modal>
  );
}
