import { useEffect, useMemo, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { CheckCheck, Pencil, Save, Trash2, UserPlus, Users } from 'lucide-react';
import {
  Alert, Badge, Button, Combobox, DescriptionList, Drawer, EmptyState, Field, IconButton, Input, Modal, PageLoader, Pagination, PersonCell, SearchInput, Select,
  StatusBadge, useConfirm, useToast,
} from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta, useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatDate, formatNumber } from '@/lib/format';
import type { ParticipantsPayload } from './types';
import { TRAINING_STATUS_COLORS, TRAINING_TYPES, usePlacementRefresh } from './shared';

type Edit = { attended: boolean; score: string; remarks: string };

/** Training / mock test detail with participants, attendance and scores. */
export function TrainingDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = usePlacementRefresh();
  const { data: meta } = useCrudMeta('placement_trainings');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const [page, setPage] = useState(1);
  const [edits, setEdits] = useState<Record<number, Edit>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState(false);
  useEffect(() => {
    setQ('');
    setPage(1);
    setEdits({});
    setErrors({});
  }, [id]);
  useEffect(() => setPage(1), [dq]);
  const { data, isLoading, error, isFetching } = useApi<ParticipantsPayload>(['placement', 'training', id, dq, page], `placement/trainings/${id}/participants`, { q: dq || undefined, page, per_page: 50 }, { enabled: !!id, placeholderData: (prev) => prev });
  const t = data?.training;
  const max = t?.max_marks !== null && t?.max_marks !== undefined ? Number(t.max_marks) : null;
  const canEdit = can('placement', 'edit');
  const dirty = Object.keys(edits).length;

  const value = (pid: number, field: keyof Edit, fallback: Edit[keyof Edit]) => (edits[pid] ? edits[pid][field] : fallback);
  const setEdit = (pid: number, base: Edit, patch: Partial<Edit>) => {
    setEdits((e) => ({ ...e, [pid]: { ...(e[pid] ?? base), ...patch } }));
    if (errors[`score_${pid}`]) setErrors((x) => ({ ...x, [`score_${pid}`]: '' }));
  };

  const save = async () => {
    setSaving(true);
    try {
      const rows = Object.entries(edits).map(([pid, e]) => ({ id: Number(pid), attended: e.attended, score: e.score === '' ? null : e.score, remarks: e.remarks }));
      const res = await api.post(`placement/trainings/${id}/scores`, { rows });
      toast.success(res.message);
      setEdits({});
      setErrors({});
      await refresh('placement_trainings');
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      toast.error(ae.message);
    } finally {
      setSaving(false);
    }
  };

  const markAll = () => {
    const next = { ...edits };
    (data?.rows ?? []).forEach((r) => {
      next[r.id] = { ...(next[r.id] ?? { attended: r.attended, score: r.score === null ? '' : String(r.score), remarks: r.remarks ?? '' }), attended: true };
    });
    setEdits(next);
  };

  const remove = async (pid: number, name: string) => {
    const ok = await confirm({ title: 'Remove participant?', message: <>Remove <strong>{name}</strong> from this session? Their attendance and score are deleted.</>, confirmText: 'Remove', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`placement/trainings/${id}/participants/${pid}`);
      toast.success(res.message);
      setEdits((e) => {
        const n = { ...e };
        delete n[pid];
        return n;
      });
      await refresh('placement_trainings');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const tryClose = async () => {
    if (dirty) {
      const ok = await confirm({ title: 'Discard unsaved changes?', message: `You have unsaved attendance / score changes for ${dirty} participant(s).`, confirmText: 'Discard', danger: true });
      if (!ok) return;
    }
    onClose();
  };

  return (
    <>
      <Drawer
        open={!!id}
        onClose={() => void tryClose()}
        width="max-w-3xl"
        title={t ? String(t.title) : 'Training'}
        description={t ? `${TRAINING_TYPES[String(t.type)] ?? t.type} · ${formatDate(t.start_date)}${t.end_date && t.end_date !== t.start_date ? ` – ${formatDate(t.end_date)}` : ''}` : undefined}
        footer={
          t && canEdit && (
            <div className="flex w-full flex-wrap items-center justify-end gap-2">
              {dirty > 0 && <span className="mr-auto text-xs font-medium text-amber-700 dark:text-amber-300">{dirty} unsaved change{dirty === 1 ? '' : 's'}</span>}
              <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(true)}>Edit session</Button>
              {t.status !== 'cancelled' && <Button variant="secondary" size="sm" icon={UserPlus} onClick={() => setAdding(true)}>Add participants</Button>}
              <Button size="sm" icon={Save} loading={saving} disabled={!dirty} onClick={() => void save()}>Save attendance & scores</Button>
            </div>
          )
        }
      >
        {error ? (
          <Alert variant="error" title="Unable to load participants">{(error as Error).message}</Alert>
        ) : isLoading || !data || !t ? (
          <PageLoader label="Loading participants…" />
        ) : (
          <div className="space-y-5">
            <div className="flex flex-wrap gap-1.5">
              <StatusBadge status={String(t.status)} colors={TRAINING_STATUS_COLORS} />
              <Badge color="slate">{String(t.mode ?? '').replace(/^./, (c) => c.toUpperCase())}</Badge>
              {max !== null && <Badge color="purple">Max marks {formatNumber(max)}</Badge>}
            </div>
            <DescriptionList
              columns={3}
              items={[
                { label: 'Trainer', value: t.trainer as string | null },
                { label: 'Audience', value: t.program_short ? `${t.program_short}${t.semester_no ? ` · Sem ${t.semester_no}` : ''}` : 'All programs' },
                { label: 'Venue', value: t.venue as string | null },
                { label: 'Duration', value: t.duration_hours ? `${Number(t.duration_hours)} hours` : null },
                ...(t.description ? [{ label: 'About', value: String(t.description), full: true }] : []),
              ]}
            />
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {[
                { label: 'Participants', value: formatNumber(data.summary.total) },
                { label: 'Attended', value: `${formatNumber(data.summary.attended)}${data.summary.total ? ` (${Math.round((data.summary.attended / data.summary.total) * 100)}%)` : ''}` },
                { label: 'Average score', value: data.summary.avg_score !== null ? `${data.summary.avg_score}${max ? ` / ${formatNumber(max)}` : ''}` : '—' },
                { label: 'Top score', value: data.summary.top_score !== null ? String(data.summary.top_score) : '—' },
              ].map((x) => (
                <div key={x.label} className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                  <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{x.label}</p>
                  <p className="mt-0.5 font-display text-lg font-bold text-slate-900 dark:text-white">{x.value}</p>
                </div>
              ))}
            </div>

            <section>
              <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                <SearchInput value={q} onChange={setQ} placeholder="Search participant…" className="w-full sm:max-w-xs" size="sm" />
                {canEdit && data.rows.length > 0 && t.status !== 'scheduled' && (
                  <Button size="sm" variant="secondary" icon={CheckCheck} onClick={markAll} className="sm:ml-auto">Mark page present</Button>
                )}
              </div>
              {!data.rows.length ? (
                <EmptyState icon={Users} title={dq ? 'No matching participants' : 'No participants yet'} description={dq ? 'Try another name or ID.' : 'Add students individually or a whole program / semester.'}
                  action={canEdit && !dq && t.status !== 'cancelled' ? <Button icon={UserPlus} onClick={() => setAdding(true)}>Add participants</Button> : undefined} className="!py-10" />
              ) : (
                <div className={clsx('overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700', isFetching && 'opacity-70')}>
                  <table className="w-full min-w-[36rem] text-sm">
                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                      <tr>
                        <th className="px-3 py-2 font-medium">Student</th>
                        <th className="px-3 py-2 text-center font-medium">Attended</th>
                        {max !== null && <th className="px-3 py-2 font-medium">Score</th>}
                        <th className="px-3 py-2 font-medium">Remarks</th>
                        {canEdit && <th className="w-10 px-2 py-2"><span className="sr-only">Remove</span></th>}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {data.rows.map((r) => {
                        const base: Edit = { attended: r.attended, score: r.score === null ? '' : String(r.score), remarks: r.remarks ?? '' };
                        const err = errors[`score_${r.id}`];
                        return (
                          <tr key={r.id} className={clsx(edits[r.id] && 'bg-amber-50/50 dark:bg-amber-500/5')}>
                            <td className="px-3 py-2"><PersonCell name={r.name} src={r.photo} sub={`${r.student_uid} · ${r.program_short}`} size="sm" /></td>
                            <td className="px-3 py-2 text-center">
                              <input type="checkbox" className="form-checkbox" aria-label={`${r.name} attended`} disabled={!canEdit} checked={Boolean(value(r.id, 'attended', base.attended))}
                                onChange={(e) => setEdit(r.id, base, { attended: e.target.checked })} />
                            </td>
                            {max !== null && (
                              <td className="px-3 py-2">
                                <Input inputSize="sm" type="number" min={0} max={max} step={0.5} className="!w-24" aria-label={`${r.name} score`} disabled={!canEdit} invalid={!!err}
                                  value={String(value(r.id, 'score', base.score))} onChange={(e) => setEdit(r.id, base, { score: e.target.value, attended: e.target.value !== '' ? true : Boolean(value(r.id, 'attended', base.attended)) })} />
                                {err && <p className="mt-0.5 text-[11px] text-red-600">{err}</p>}
                              </td>
                            )}
                            <td className="px-3 py-2">
                              <Input inputSize="sm" maxLength={255} aria-label={`${r.name} remarks`} disabled={!canEdit} value={String(value(r.id, 'remarks', base.remarks))}
                                onChange={(e) => setEdit(r.id, base, { remarks: e.target.value })} placeholder="—" />
                            </td>
                            {canEdit && (
                              <td className="px-2 py-2 text-right"><IconButton size="sm" icon={Trash2} label={`Remove ${r.name}`} tone="danger" onClick={() => void remove(r.id, r.name)} /></td>
                            )}
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
              {data.total > data.per_page && (
                <Pagination className="mt-2" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} />
              )}
            </section>
          </div>
        )}
      </Drawer>
      <AddParticipantsModal trainingId={adding ? id : null} programId={t?.program_id ? Number(t.program_id) : null} semester={t?.semester_no ? Number(t.semester_no) : null} onClose={() => setAdding(false)} />
      <CrudFormModal open={editing && !!t} onClose={() => setEditing(false)} meta={meta} module="placement_trainings" id={id} onSaved={() => void refresh('placement_trainings')} />
    </>
  );
}

function AddParticipantsModal({ trainingId, programId, semester, onClose }: { trainingId: number | null; programId: number | null; semester: number | null; onClose: () => void }) {
  const toast = useToast();
  const refresh = usePlacementRefresh();
  const { data: programs = [] } = useLookup('programs');
  const [mode, setMode] = useState<'program' | 'students'>('program');
  const [program, setProgram] = useState('');
  const [sem, setSem] = useState('');
  const [students, setStudents] = useState<(string | number)[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (trainingId) {
      setMode('program');
      setProgram(programId ? String(programId) : '');
      setSem(semester ? String(semester) : '');
      setStudents([]);
      setErrors({});
      setFormError(null);
    }
  }, [trainingId, programId, semester]);
  const programOptions = useMemo(() => programs.map((p) => ({ value: p.value, label: p.label })), [programs]);
  if (!trainingId) return null;
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (mode === 'program' && !program) {
      setErrors({ program_id: 'Choose a program.' });
      return;
    }
    if (mode === 'students' && !students.length) {
      setErrors({ student_ids: 'Choose at least one student.' });
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const body = mode === 'program' ? { program_id: Number(program), semester_no: sem ? Number(sem) : undefined } : { student_ids: students };
      const res = await api.post(`placement/trainings/${trainingId}/participants`, body);
      toast.success(res.message);
      await refresh('placement_trainings');
      onClose();
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      setFormError(ae.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      size="md"
      title="Add participants"
      description="Enrol a whole class or pick individual students."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button type="submit" form="add-participants-form" loading={saving} icon={UserPlus}>Add</Button>
        </>
      }
    >
      <form id="add-participants-form" onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert variant="error">{formError}</Alert>}
        <div className="pill-tabs" role="tablist">
          <button type="button" role="tab" aria-selected={mode === 'program'} className={clsx('pill-tab', mode === 'program' && 'pill-tab-active')} onClick={() => setMode('program')}>By program</button>
          <button type="button" role="tab" aria-selected={mode === 'students'} className={clsx('pill-tab', mode === 'students' && 'pill-tab-active')} onClick={() => setMode('students')}>Pick students</button>
        </div>
        {mode === 'program' ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field label="Program" required htmlFor="ap-program" error={errors.program_id} className="sm:col-span-2">
              <Select id="ap-program" value={program} onChange={(e) => setProgram(e.target.value)} options={programOptions} placeholder="Select program" invalid={!!errors.program_id} />
            </Field>
            <Field label="Semester" htmlFor="ap-sem" error={errors.semester_no} hint="Blank = all">
              <Input id="ap-sem" type="number" min={1} max={12} value={sem} onChange={(e) => setSem(e.target.value)} />
            </Field>
          </div>
        ) : (
          <Field label="Students" required htmlFor="ap-students" error={errors.student_ids}>
            <Combobox id="ap-students" multiple source="students" value={students} onChange={setStudents} placeholder="Search by name or ID…" invalid={!!errors.student_ids} />
          </Field>
        )}
        <p className="text-xs text-slate-500 dark:text-slate-400">Only active students are added; students already enrolled are skipped.</p>
      </form>
    </Modal>
  );
}
