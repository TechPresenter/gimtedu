import { useEffect, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { CircleCheck, CircleDot, Clock, Lock, RotateCcw, Send, ShieldAlert, Star, UserRound } from 'lucide-react';
import { Button, Checkbox, Drawer, Field, Input, Modal, Select, Textarea } from '@/components/ui';
import { formatDateTime, timeAgo } from '@/lib/format';
import { ErrorState } from '../../../components/Ui';
import { fieldErrors } from '../../../lib/errors';
import { ListSkeleton, StatusBadge, useStudent, useStudentPost } from '../../shared/ui';
import type { TicketDetail } from '../../shared/types';

const TYPE_HELP: Record<string, string> = {
  complaint: 'Something is wrong and needs fixing.',
  grievance: 'A formal grievance for the grievance redressal committee.',
  suggestion: 'An idea to make campus life better.',
  feedback: 'Appreciation or general feedback.',
};

export function NewTicketModal({ open, onClose, types, categories, onCreated }: { open: boolean; onClose: () => void; types: Record<string, string>; categories: Record<string, string>; onCreated: (id: number) => void }) {
  const blank = { type: 'complaint', category: '', priority: 'medium', subject: '', message: '', anonymous: false };
  const [form, setForm] = useState(blank);
  const post = useStudentPost<typeof form, { id: number; ticket_no: string }>('tickets', {
    invalidate: [['tickets'], ['overview']],
    onSuccess: (d) => {
      setForm(blank);
      onClose();
      onCreated(d.id);
    },
  });
  const errors = fieldErrors(post.error);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    post.mutate(form);
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Raise a request"
      description="Complaints, grievances, suggestions or feedback go straight to the institute's help desk."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="ticket-new" loading={post.isPending} icon={Send}>
            Submit
          </Button>
        </>
      }
    >
      <form id="ticket-new" onSubmit={submit} className="space-y-4" noValidate>
        <fieldset>
          <legend className="form-label">What kind of request?</legend>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {Object.entries(types).map(([k, label]) => (
              <button
                key={k}
                type="button"
                onClick={() => setForm((f) => ({ ...f, type: k }))}
                aria-pressed={form.type === k}
                className={clsx('rounded-xl border px-3 py-2 text-left transition', form.type === k ? 'border-flame-500 bg-flame-50 ring-2 ring-flame-500/20 dark:bg-flame-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700')}
              >
                <span className="block text-sm font-semibold text-slate-900 dark:text-white">{label}</span>
              </button>
            ))}
          </div>
          <p className="form-hint">{TYPE_HELP[form.type]}</p>
          {errors.type && <p className="form-error">{errors.type}</p>}
        </fieldset>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Category" required error={errors.category} htmlFor="tk-cat">
            <Select id="tk-cat" value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))} options={categories} placeholder="Choose…" invalid={!!errors.category} />
          </Field>
          <Field label="Priority" error={errors.priority} htmlFor="tk-pri" hint={form.category === 'ragging' ? 'Ragging / harassment is always treated as urgent.' : undefined}>
            <Select id="tk-pri" value={form.category === 'ragging' ? 'high' : form.priority} disabled={form.category === 'ragging'} onChange={(e) => setForm((f) => ({ ...f, priority: e.target.value }))} options={{ low: 'Low', medium: 'Medium', high: 'High' }} />
          </Field>
        </div>
        {form.category === 'ragging' && (
          <p className="flex items-start gap-2 rounded-xl bg-red-50 px-3 py-2 text-[13px] text-red-700 dark:bg-red-500/10 dark:text-red-300">
            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden /> If you are in immediate danger, call the campus security or the anti-ragging helpline right away. This report is marked urgent.
          </p>
        )}
        <Field label="Title" required error={errors.subject} htmlFor="tk-sub" aside={`${form.subject.length}/200`}>
          <Input id="tk-sub" value={form.subject} maxLength={200} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} placeholder="Summarise it in a line" invalid={!!errors.subject} />
        </Field>
        <Field label="Details" required error={errors.message} htmlFor="tk-msg" aside={`${form.message.length}/5000`}>
          <Textarea id="tk-msg" rows={5} value={form.message} maxLength={5000} onChange={(e) => setForm((f) => ({ ...f, message: e.target.value }))} placeholder="What happened, where and when? What outcome do you expect?" invalid={!!errors.message} />
        </Field>
        <Checkbox
          checked={form.anonymous}
          onChange={(e) => setForm((f) => ({ ...f, anonymous: e.target.checked }))}
          label="Hide my name from the staff handling this"
          description="Your name is not shown on the ticket. The grievance committee can still see who raised it if a follow-up is needed."
        />
      </form>
    </Modal>
  );
}

function Stars({ value, onChange, disabled }: { value: number; onChange: (n: number) => void; disabled?: boolean }) {
  const [hover, setHover] = useState(0);
  return (
    <div className="flex gap-1" role="radiogroup" aria-label="Rating">
      {[1, 2, 3, 4, 5].map((n) => (
        <button
          key={n}
          type="button"
          role="radio"
          aria-checked={value === n}
          aria-label={`${n} star${n > 1 ? 's' : ''}`}
          disabled={disabled}
          onMouseEnter={() => setHover(n)}
          onMouseLeave={() => setHover(0)}
          onClick={() => onChange(n)}
          className="rounded-md p-0.5 transition hover:scale-110 disabled:opacity-60"
        >
          <Star className={clsx('h-6 w-6', (hover || value) >= n ? 'fill-amber-400 text-amber-400' : 'text-slate-300 dark:text-slate-600')} aria-hidden />
        </button>
      ))}
    </div>
  );
}

const STEP_ICON: Record<string, typeof Clock> = { created: Send, in_progress: CircleDot, resolved: CircleCheck, closed: Lock };

export function TicketDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data, isLoading, error, refetch } = useStudent<TicketDetail>(['tickets', id], `tickets/${id}`, undefined, { enabled: id !== null });
  const t = id && data?.id === id ? data : undefined;
  const [reason, setReason] = useState('');
  const [reopening, setReopening] = useState(false);
  useEffect(() => {
    setReason('');
    setReopening(false);
  }, [id]);
  const rate = useStudentPost<{ rating: number }>(`tickets/${id}/rate`, { invalidate: [['tickets']] });
  const reopen = useStudentPost<{ reason: string }>(`tickets/${id}/reopen`, { invalidate: [['tickets'], ['overview']], onSuccess: () => setReopening(false) });
  const reopenErr = fieldErrors(reopen.error).reason;

  return (
    <Drawer open={id !== null} onClose={onClose} title={t ? t.subject : 'Request'} description={t ? `${t.ticket_no} · ${t.type_label} · ${t.category_label}` : undefined}>
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !t ? (
        <ListSkeleton rows={5} h="h-12" />
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={t.status} />
            <StatusBadge status={t.priority} label={`${t.priority[0].toUpperCase()}${t.priority.slice(1)} priority`} />
            {t.anonymous && (
              <span className="badge badge-slate">
                <Lock className="h-3 w-3" aria-hidden /> Name hidden
              </span>
            )}
            <span className="text-xs text-slate-500">Raised {timeAgo(t.created_at)}</span>
          </div>
          <div className="whitespace-pre-line rounded-2xl bg-slate-50 p-4 text-sm leading-relaxed text-slate-700 dark:bg-slate-800/40 dark:text-slate-200">{t.message}</div>

          {t.resolution && (
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">
              <p className="mb-1 flex items-center gap-2 font-semibold">
                <CircleCheck className="h-4 w-4" aria-hidden /> Response from the institute
              </p>
              <p className="whitespace-pre-line">{t.resolution}</p>
            </div>
          )}

          <div>
            <p className="mb-3 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Progress</p>
            <ol className="relative space-y-4 before:absolute before:bottom-2 before:left-4 before:top-2 before:w-px before:bg-slate-200 dark:before:bg-slate-800">
              {t.timeline.map((s, i) => {
                const Icon = STEP_ICON[s.type] ?? Clock;
                return (
                  <li key={i} className="relative flex gap-3">
                    <span className={clsx('relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-slate-900', s.type === 'resolved' || s.type === 'closed' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200' : 'bg-brand-50 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200')}>
                      <Icon className="h-4 w-4" aria-hidden />
                    </span>
                    <div className="pt-1">
                      <p className="text-sm font-medium text-slate-800 dark:text-slate-100">{s.title}</p>
                      <p className="text-xs text-slate-400">{formatDateTime(s.at)}</p>
                    </div>
                  </li>
                );
              })}
              {(t.status === 'open' || t.status === 'in_progress') && (
                <li className="relative flex gap-3">
                  <span className="relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-400 ring-4 ring-white dark:bg-slate-800 dark:ring-slate-900">
                    <Clock className="h-4 w-4" aria-hidden />
                  </span>
                  <div className="pt-1">
                    <p className="text-sm text-slate-500">Awaiting resolution</p>
                    <p className="text-xs text-slate-400">Target: within {t.sla_hours} hours of submission</p>
                  </div>
                </li>
              )}
            </ol>
            {t.assigned && (
              <p className="mt-3 flex items-center gap-2 text-xs text-slate-500">
                <UserRound className="h-3.5 w-3.5" aria-hidden /> Handled by {t.assigned}
              </p>
            )}
          </div>

          {t.can_rate && (
            <div className="rounded-2xl border border-slate-200/80 p-4 dark:border-slate-800">
              <p className="text-sm font-semibold text-slate-900 dark:text-white">How satisfied are you with the resolution?</p>
              <div className="mt-2 flex items-center gap-3">
                <Stars value={t.rating ?? 0} onChange={(n) => rate.mutate({ rating: n })} disabled={rate.isPending} />
                {t.rating ? <span className="text-xs text-slate-500">You rated {t.rating}/5</span> : null}
              </div>
            </div>
          )}

          {t.can_reopen &&
            (reopening ? (
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  reopen.mutate({ reason });
                }}
                className="space-y-3 rounded-2xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-500/30 dark:bg-amber-500/10"
              >
                <Field label="Why is the issue not resolved?" required error={reopenErr} htmlFor="tk-reopen">
                  <Textarea id="tk-reopen" rows={3} value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} invalid={!!reopenErr} />
                </Field>
                <div className="flex justify-end gap-2">
                  <Button variant="secondary" size="sm" onClick={() => setReopening(false)}>
                    Cancel
                  </Button>
                  <Button type="submit" size="sm" loading={reopen.isPending} icon={RotateCcw}>
                    Reopen
                  </Button>
                </div>
              </form>
            ) : (
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => setReopening(true)}>
                <RotateCcw className="h-4 w-4" aria-hidden /> Not resolved? Reopen this ticket
              </button>
            ))}
        </div>
      )}
    </Drawer>
  );
}
