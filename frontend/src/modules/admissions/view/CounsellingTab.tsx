import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { CheckCheck, MessageSquareText, NotebookPen, PhoneCall, Trash2 } from 'lucide-react';
import { Button, Card, CardHeader, EmptyState, IconButton, Textarea, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useInvalidate } from '@/lib/queries';
import { formatDateTime } from '@/lib/format';
import { DueChip, FollowupForm, OUTCOMES, followupIcon, followupLabel } from '../shared';
import type { AdmissionProfile } from './types';

export function CounsellingTab({ data }: { data: AdmissionProfile }) {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const a = data.admission;
  const canEdit = data.can.edit;
  const [notes, setNotes] = useState(String(a.counselling_notes ?? ''));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState<number | null>(null);
  useEffect(() => setNotes(String(a.counselling_notes ?? '')), [a.counselling_notes]);
  const dirty = notes !== String(a.counselling_notes ?? '');

  const saveNotes = async () => {
    setSaving(true);
    setError('');
    try {
      const res = await api.post(`admissions/${a.id}/notes`, { counselling_notes: notes });
      toast.success(res.message);
      await invalidate('adm-profile');
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.counselling_notes ?? err.message);
    } finally {
      setSaving(false);
    }
  };
  const done = async (id: number) => {
    setBusy(id);
    try {
      const res = await api.post(`admissions/followups/${id}/done`);
      toast.success(res.message);
      await invalidate('adm-profile', 'adm-dashboard', 'adm-board');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const remove = async (id: number) => {
    if (!(await confirm({ title: 'Delete follow-up?', message: 'This entry will be removed from the counselling history.', confirmText: 'Delete', danger: true }))) return;
    setBusy(id);
    try {
      const res = await api.del(`admissions/followups/${id}`);
      toast.success(res.message);
      await invalidate('adm-profile', 'adm-dashboard', 'adm-board');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="grid gap-6 xl:grid-cols-5">
      <div className="space-y-6 xl:col-span-2">
        <Card>
          <CardHeader title="Counselling notes" subtitle="Goals, preferences and concerns discussed with the applicant" icon={NotebookPen} />
          <div className="card-body space-y-3">
            <Textarea rows={6} value={notes} onChange={(e) => setNotes(e.target.value)} disabled={!canEdit} maxLength={5000} invalid={!!error} aria-label="Counselling notes" placeholder="No counselling notes yet." />
            {error && <p className="form-error">{error}</p>}
            {canEdit && (
              <div className="flex justify-end">
                <Button size="sm" onClick={saveNotes} loading={saving} disabled={!dirty}>
                  Save notes
                </Button>
              </div>
            )}
          </div>
        </Card>
        {canEdit && !['confirmed', 'rejected', 'withdrawn'].includes(String(a.stage)) && (
          <Card>
            <CardHeader title="Log a follow-up" subtitle="Record the interaction and schedule the next one" icon={PhoneCall} />
            <div className="card-body">
              <FollowupForm target={{ kind: 'admission', id: Number(a.id), name: String(a.full_name) }} compact />
            </div>
          </Card>
        )}
      </div>
      <Card className="xl:col-span-3">
        <CardHeader title="Follow-up history" subtitle={`${data.followups.length} interaction${data.followups.length === 1 ? '' : 's'} (including the original enquiry)`} icon={MessageSquareText} />
        {data.followups.length === 0 ? (
          <EmptyState icon={PhoneCall} title="No follow-ups yet" description="Log calls, WhatsApp messages, meetings and campus visits to keep the whole team in sync." />
        ) : (
          <ol className="relative space-y-1 px-5 py-4 before:absolute before:bottom-6 before:left-9 before:top-6 before:w-px before:bg-slate-200 dark:before:bg-slate-800">
            {data.followups.map((f) => {
              const Icon = followupIcon(f.type);
              return (
                <li key={f.id} className="relative flex gap-3 rounded-xl p-2 transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                  <span className={clsx('relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-slate-900', f.from_enquiry ? 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300' : 'bg-brand-50 text-brand-700 dark:bg-brand-500/20 dark:text-brand-200')}>
                    <Icon className="h-4 w-4" aria-hidden />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="text-sm font-semibold text-slate-900 dark:text-white">{followupLabel(f.type)}</p>
                      {f.outcome && <span className="badge badge-slate">{OUTCOMES[f.outcome] ?? f.outcome}</span>}
                      {f.from_enquiry && <span className="badge badge-purple">Enquiry</span>}
                    </div>
                    <p className="mt-0.5 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{f.notes}</p>
                    <p className="mt-1 text-xs text-slate-400">{f.created_by_name ?? 'System'} · {formatDateTime(f.created_at)}</p>
                    {f.next_followup_date && (
                      <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span className="text-slate-500">Next follow-up:</span>
                        {f.completed_at ? (
                          <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"><CheckCheck className="h-3.5 w-3.5" /> Done {formatDateTime(f.completed_at)}{f.completed_by_name ? ` by ${f.completed_by_name}` : ''}</span>
                        ) : (
                          <>
                            <DueChip date={f.next_followup_date} />
                            {canEdit && (
                              <button type="button" disabled={busy === f.id} onClick={() => done(f.id)} className="font-semibold text-emerald-700 hover:underline disabled:opacity-50 dark:text-emerald-400">
                                Mark done
                              </button>
                            )}
                          </>
                        )}
                      </div>
                    )}
                  </div>
                  {canEdit && !f.from_enquiry && <IconButton size="sm" icon={Trash2} tone="danger" label="Delete follow-up" disabled={busy === f.id} onClick={() => remove(f.id)} />}
                </li>
              );
            })}
          </ol>
        )}
      </Card>
    </div>
  );
}
