import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { BadgeCheck, CalendarClock, ChevronDown, CircleSlash, FileCheck2, Info, ListChecks, Lock, MapPin, RotateCcw, Save, Search, Send, Sigma, Target, TriangleAlert, Users } from 'lucide-react';
import { Alert, SearchInput, Spinner, Stagger, useConfirm, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { EmptyBlock, PageHead, PortalAvatar } from '../../../components/Ui';
import { ScopedError } from '../../shared/ScopedError';
import { errorMessage, fieldErrors } from '../../../lib/errors';
import { usePortalQuery } from '../../../lib/hooks';
import { hhmm } from '../../../lib/time';
import type { GradeBand, MarksRow, MarksSheetData, SaveMarksResult } from '../../shared/types';
import { MiniStat, PillTabs, fpath, useFacultyInvalidate } from '../../shared/ui';

type Field = 'internal_marks' | 'external_marks' | 'practical_marks';
interface Draft {
  internal_marks: string;
  external_marks: string;
  practical_marks: string;
  is_absent: boolean;
  remarks: string;
}

const FIELD_LABEL: Record<Field, string> = { internal_marks: 'Internal', external_marks: 'External', practical_marks: 'Practical' };
const str = (v: number | null) => (v === null || v === undefined ? '' : String(v));

function toDraft(r: MarksRow): Draft {
  return { internal_marks: str(r.internal_marks), external_marks: str(r.external_marks), practical_marks: str(r.practical_marks), is_absent: r.is_absent, remarks: r.remarks ?? '' };
}

/** Same rules as exam_parse_mark() on the server. */
function parseMark(v: string, max: number, label: string): { value: number | null; error: string | null } {
  const s = v.trim().replace(',', '.');
  if (s === '') return { value: null, error: null };
  if (!/^-?\d+(\.\d+)?$/.test(s)) return { value: null, error: `${label} must be a number.` };
  const f = Math.round(Number(s) * 100) / 100;
  if (max <= 0 && f !== 0) return { value: null, error: `${label} is not assessed in this paper.` };
  if (f < 0) return { value: null, error: `${label} cannot be negative.` };
  if (f > max) return { value: null, error: `${label} cannot exceed ${max}.` };
  if (Math.abs(f * 2 - Math.round(f * 2)) > 0.0001) return { value: null, error: `${label} can only use half marks (e.g. 23.5).` };
  return { value: max <= 0 ? null : f, error: null };
}

function gradeFor(pct: number, grades: GradeBand[]): GradeBand | null {
  if (!grades.length) return null;
  return grades.find((g) => pct >= g.min - 0.0001 && pct <= g.max + 0.0099) ?? grades.find((g) => pct >= g.min) ?? grades[grades.length - 1];
}

/** Total / grade preview (mirrors exam_mark_compute()). */
function compute(d: Draft, sheet: MarksSheetData, fields: Field[]) {
  const errors: Partial<Record<Field, string>> = {};
  const vals: Partial<Record<Field, number | null>> = {};
  const max = { internal_marks: sheet.subject.max_internal, external_marks: sheet.subject.max_external, practical_marks: sheet.subject.max_practical };
  fields.forEach((f) => {
    const r = parseMark(d[f], max[f], FIELD_LABEL[f]);
    vals[f] = r.value;
    if (r.error) errors[f] = r.error;
  });
  const any = fields.some((f) => vals[f] !== null && vals[f] !== undefined);
  if (!d.is_absent && any && !Object.keys(errors).length) {
    fields.forEach((f) => {
      if (vals[f] === null || vals[f] === undefined) errors[f] = `${FIELD_LABEL[f]} marks are missing.`;
    });
  }
  if (Object.keys(errors).length) return { errors, total: null, grade: null, pass: null, empty: false };
  if (d.is_absent) return { errors, total: vals.internal_marks ?? 0, grade: 'AB', pass: false, empty: false };
  if (!any) return { errors, total: null, grade: null, pass: null, empty: true };
  const total = Math.round(fields.reduce((n, f) => n + (vals[f] ?? 0), 0) * 100) / 100;
  const pct = sheet.subject.max_total > 0 ? (total * 100) / sheet.subject.max_total : 0;
  const pass = total >= sheet.subject.pass_marks;
  let g = gradeFor(pct, sheet.grades);
  if (g && pass && !g.is_pass) g = [...sheet.grades].reverse().find((x) => x.is_pass) ?? g;
  if (g && !pass && g.is_pass) g = [...sheet.grades].reverse().find((x) => !x.is_pass) ?? g;
  return { errors, total, grade: g?.grade ?? null, pass, empty: false };
}

const same = (a: Draft, b: Draft) => a.internal_marks.trim() === b.internal_marks.trim() && a.external_marks.trim() === b.external_marks.trim() && a.practical_marks.trim() === b.practical_marks.trim() && a.is_absent === b.is_absent && a.remarks.trim() === b.remarks.trim();

export default function MarksEntryPage() {
  const { examId, subjectId } = useParams();
  const exam = Number(examId) || 0;
  const subject = Number(subjectId) || 0;
  const [params, setParams] = useSearchParams();
  const section = Number(params.get('section')) || undefined;
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const invalidate = useFacultyInvalidate();
  const queryArgs = { section_id: section };
  const { data, isLoading, error, refetch, isFetching } = usePortalQuery<MarksSheetData>('faculty', ['marks', 'sheet', exam, subject], `marks/${exam}/${subject}`, queryArgs, { enabled: exam > 0 && subject > 0, placeholderData: undefined });
  const [drafts, setDrafts] = useState<Record<number, Draft>>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<'save' | 'submit' | null>(null);
  const [q, setQ] = useState('');
  const [showGrades, setShowGrades] = useState(false);
  const gridRef = useRef<HTMLDivElement>(null);
  const loadedRef = useRef<MarksSheetData | null>(null);

  const original = useMemo(() => {
    const m: Record<number, Draft> = {};
    data?.rows.forEach((r) => (m[r.student_id] = toDraft(r)));
    return m;
  }, [data]);

  useEffect(() => {
    if (!data || loadedRef.current === data) return;
    loadedRef.current = data;
    setDrafts({ ...original });
    setServerErrors({});
  }, [data, original]);

  const fields: Field[] = useMemo(() => {
    if (!data) return [];
    return (['internal_marks', 'external_marks', 'practical_marks'] as Field[]).filter((f) => (f === 'internal_marks' ? data.subject.max_internal : f === 'external_marks' ? data.subject.max_external : data.subject.max_practical) > 0);
  }, [data]);

  const computed = useMemo(() => {
    const m: Record<number, ReturnType<typeof compute>> = {};
    if (data) data.rows.forEach((r) => (m[r.student_id] = compute(drafts[r.student_id] ?? toDraft(r), data, fields)));
    return m;
  }, [data, drafts, fields]);

  const changed = data ? data.rows.filter((r) => drafts[r.student_id] && original[r.student_id] && !same(drafts[r.student_id], original[r.student_id])) : [];
  const invalid = changed.filter((r) => Object.keys(computed[r.student_id]?.errors ?? {}).length > 0);
  const dirty = changed.length > 0;

  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  const live = useMemo(() => {
    if (!data) return null;
    let entered = 0;
    let absent = 0;
    let passed = 0;
    let sum = 0;
    let n = 0;
    data.rows.forEach((r) => {
      const c = computed[r.student_id];
      if (!c || c.empty || Object.keys(c.errors).length) return;
      entered++;
      if (drafts[r.student_id]?.is_absent) absent++;
      else {
        n++;
        sum += c.total ?? 0;
        if (c.pass) passed++;
      }
    });
    return { entered, absent, passed, average: n ? Math.round((sum / n) * 10) / 10 : null, passPct: n ? Math.round((passed / n) * 1000) / 10 : null };
  }, [data, computed, drafts]);

  const setDraft = (sid: number, patch: Partial<Draft>) => {
    setDrafts((d) => ({ ...d, [sid]: { ...(d[sid] ?? original[sid]), ...patch } }));
    setServerErrors((e) => {
      const next = { ...e };
      Object.keys(next).forEach((k) => k.startsWith(`rows.${sid}.`) && delete next[k]);
      return next;
    });
  };

  const onKey = useCallback((e: KeyboardEvent<HTMLInputElement>) => {
    if (!['Enter', 'ArrowDown', 'ArrowUp'].includes(e.key)) return;
    const el = e.currentTarget;
    const row = Number(el.dataset.row);
    const col = el.dataset.col;
    const next = gridRef.current?.querySelector<HTMLInputElement>(`input[data-row="${e.key === 'ArrowUp' ? row - 1 : row + 1}"][data-col="${col}"]`);
    if (next) {
      e.preventDefault();
      next.focus();
      next.select();
    }
  }, []);

  const send = async (submit: boolean) => {
    if (!data) return;
    if (invalid.length) {
      toast.error(`Fix the highlighted marks of ${invalid.length} student${invalid.length > 1 ? 's' : ''} before saving.`);
      return;
    }
    if (submit) {
      const pendingAfter = data.rows.filter((r) => computed[r.student_id]?.empty).length;
      if (pendingAfter) {
        toast.warning(`Marks are missing for ${pendingAfter} student${pendingAfter > 1 ? 's' : ''}. Enter every mark (or mark absent) before submitting.`);
        return;
      }
      const ok = await confirm({
        title: 'Submit marks for verification?',
        message: `Marks of ${data.stats.students} student${data.stats.students === 1 ? '' : 's'} in ${data.subject.code} will be sent to the examination office. You can still correct them until the office verifies and locks them.`,
        confirmText: 'Submit marks',
      });
      if (!ok) return;
    } else if (!changed.length) {
      return;
    }
    setBusy(submit ? 'submit' : 'save');
    setServerErrors({});
    try {
      const rows = changed.map((r) => {
        const d = drafts[r.student_id];
        return { student_id: r.student_id, internal_marks: d.internal_marks.trim(), external_marks: d.external_marks.trim(), practical_marks: d.practical_marks.trim(), is_absent: d.is_absent, remarks: d.remarks.trim() };
      });
      const res = await api.post<SaveMarksResult>(`portal/faculty/marks/${exam}/${subject}`, { rows, submit, section_id: section ?? null });
      qc.setQueryData(['portal', 'faculty', 'marks', 'sheet', exam, subject, queryArgs], res.data.sheet);
      if (res.data.submit_error) toast.warning(res.message);
      else toast.success(res.message || 'Marks saved.');
      void invalidate('dashboard');
      void qc.invalidateQueries({ queryKey: ['portal', 'faculty', 'marks', 'list'] });
    } catch (e) {
      const fe = fieldErrors(e);
      setServerErrors(fe);
      toast.error(errorMessage(e, 'Unable to save marks. Please try again.'));
    } finally {
      setBusy(null);
    }
  };

  const discard = async () => {
    if (!dirty || (await confirm({ title: 'Discard changes?', message: `Unsaved marks of ${changed.length} student${changed.length > 1 ? 's' : ''} will be lost.`, confirmText: 'Discard', danger: true }))) {
      setDrafts({ ...original });
      setServerErrors({});
    }
  };

  const chooseSection = async (v: string) => {
    if (dirty && !(await confirm({ title: 'Switch section?', message: 'You have unsaved marks. They will be lost if you switch now.', confirmText: 'Switch anyway', danger: true }))) return;
    const next = new URLSearchParams(params);
    if (v) next.set('section', v);
    else next.delete('section');
    setParams(next, { replace: true });
  };

  const visible = useMemo(() => {
    const term = q.trim().toLowerCase();
    return data ? data.rows.filter((r) => !term || `${r.name} ${r.roll_no ?? ''} ${r.student_uid}`.toLowerCase().includes(term)) : [];
  }, [data, q]);

  const s = data?.subject;
  const readOnly = !data?.can_edit;
  const gridCols = `48px minmax(210px,1.7fr) ${fields.map(() => 'minmax(86px,0.7fr)').join(' ')} 64px 72px 60px 112px`;

  return (
    <div>
      <PageHead
        area="faculty"
        title={s ? s.name : 'Marks entry'}
        subtitle={data ? `${s?.code} · ${data.exam.name}` : undefined}
        crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'Marks Entry', to: fpath('marks') }, { label: s ? s.code : 'Paper' }]}
      />
      {error ? (
        <ScopedError error={error} onRetry={() => void refetch()} title="We couldn't open this paper" backTo={fpath('marks')} backLabel="Back to Marks Entry" />
      ) : isLoading || !data || !s || !live ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-[74px] rounded-2xl" />
            ))}
          </div>
          <div className="skeleton h-[520px] rounded-2xl" />
        </div>
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2 text-xs">
            {fields.map((f) => (
              <span key={f} className="badge badge-navy">
                {FIELD_LABEL[f]} / {f === 'internal_marks' ? s.max_internal : f === 'external_marks' ? s.max_external : s.max_practical}
              </span>
            ))}
            <span className="badge badge-slate">
              <Sigma className="h-3 w-3" aria-hidden /> Max {s.max_total}
            </span>
            <span className="badge badge-amber">
              <Target className="h-3 w-3" aria-hidden /> Pass {s.pass_marks}
            </span>
            {s.schedule && (
              <span className="badge badge-slate">
                <CalendarClock className="h-3 w-3" aria-hidden /> {formatDate(s.schedule.exam_date)}, {hhmm(s.schedule.start_time)}
                {s.schedule.room_code && (
                  <>
                    {' '}
                    · <MapPin className="h-3 w-3" aria-hidden /> {s.schedule.room_code}
                  </>
                )}
              </span>
            )}
            <button type="button" className="badge badge-slate cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-700" onClick={() => setShowGrades((v) => !v)} aria-expanded={showGrades}>
              <ListChecks className="h-3 w-3" aria-hidden /> Grade scale <ChevronDown className={clsx('h-3 w-3 transition-transform', showGrades && 'rotate-180')} aria-hidden />
            </button>
          </div>
          {showGrades && (
            <div className="card flex flex-wrap gap-2 p-3">
              {data.grades.map((g) => (
                <span key={g.grade} className={clsx('rounded-lg px-2.5 py-1 text-xs font-semibold', g.is_pass ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200' : 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300')}>
                  {g.grade} · {g.min}–{g.max}% · GP {g.grade_point}
                </span>
              ))}
            </div>
          )}

          {data.lock_reason && (
            <Alert variant={data.lock_kind === 'upcoming' ? 'info' : 'warning'} title={data.lock_kind === 'upcoming' ? 'Not open yet' : 'Read only'}>
              {data.lock_reason}
            </Alert>
          )}

          <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full">
            {[
              <MiniStat key="e" label="Entered" value={`${live.entered}/${data.stats.students}`} icon={Users} tone={live.entered >= data.stats.students ? 'green' : 'flame'} sub={live.entered >= data.stats.students ? 'all students done' : `${data.stats.students - live.entered} remaining`} />,
              <MiniStat key="a" label="Average" value={live.average !== null ? `${live.average} / ${s.max_total}` : '—'} icon={Sigma} tone="navy" sub={`${live.absent} absent`} />,
              <MiniStat key="p" label="Pass rate" value={live.passPct !== null ? `${live.passPct}%` : '—'} icon={BadgeCheck} tone={live.passPct !== null && live.passPct < 60 ? 'amber' : 'green'} sub={`${live.passed} passed`} />,
              <MiniStat key="s" label="Submitted" value={`${data.stats.submitted}/${data.stats.students}`} icon={FileCheck2} tone={data.stats.submitted >= data.stats.students && data.stats.students ? 'green' : 'violet'} sub={data.exam.status === 'verified' ? 'verified by the office' : 'for verification'} />,
            ]}
          </Stagger>

          <section className="card overflow-visible">
            <div className="flex flex-col gap-2 border-b border-slate-100 p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4 dark:border-slate-800">
              <div className="flex flex-wrap items-center gap-2">
                {data.sections.length > 1 && (
                  <PillTabs
                    label="Section"
                    value={String(section ?? '')}
                    onChange={(v) => void chooseSection(v)}
                    tabs={[{ value: '', label: 'All my sections', count: data.sections.reduce((n, x) => n + x.students, 0) }, ...data.sections.map((x) => ({ value: String(x.id), label: `Sec ${x.name}`, count: x.students }))]}
                  />
                )}
                {data.sections.length === 1 && <span className="badge badge-navy">Section {data.sections[0].name} · {data.sections[0].students} students</span>}
                {isFetching && <Spinner className="h-4 w-4 text-slate-400" />}
              </div>
              <SearchInput value={q} onChange={setQ} placeholder="Find a student…" size="sm" className="sm:w-64" />
            </div>

            {data.rows.length === 0 ? (
              <EmptyBlock icon={Users} title="No eligible students" text="Students allocated to this exam (and eligible) in your sections appear here." />
            ) : visible.length === 0 ? (
              <EmptyBlock compact icon={Search} title="No student matches your search" />
            ) : (
              <div ref={gridRef} className="overflow-x-auto">
                <div className="hidden border-b border-slate-200 bg-slate-50/95 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 md:grid md:items-center md:gap-3 dark:border-slate-800 dark:bg-slate-900/95 dark:text-slate-400" style={{ gridTemplateColumns: gridCols }}>
                  <span>#</span>
                  <span>Student</span>
                  {fields.map((f) => (
                    <span key={f} className="text-center">
                      {FIELD_LABEL[f]} <span className="text-slate-400">/{f === 'internal_marks' ? s.max_internal : f === 'external_marks' ? s.max_external : s.max_practical}</span>
                    </span>
                  ))}
                  <span className="text-center">Absent</span>
                  <span className="text-center">Total</span>
                  <span className="text-center">Grade</span>
                  <span>Status</span>
                </div>
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {visible.map((r) => {
                    const i = data.rows.indexOf(r);
                    const d = drafts[r.student_id] ?? original[r.student_id];
                    const c = computed[r.student_id];
                    const isChanged = d && original[r.student_id] && !same(d, original[r.student_id]);
                    const rowErr = (f: string) => serverErrors[`rows.${r.student_id}.${f}`] ?? (isChanged ? c?.errors[f as Field] : undefined);
                    const anyErr = fields.some((f) => rowErr(f)) || serverErrors[`rows.${r.student_id}.student_id`];
                    if (!d) return null;
                    return (
                      <li key={r.student_id} className={clsx('flex flex-col gap-2.5 px-4 py-3 md:py-2', anyErr ? 'bg-red-50/70 dark:bg-red-500/10' : isChanged && 'bg-flame-50/50 dark:bg-flame-500/5')}>
                        {/* mobile header */}
                        <div className="flex items-center gap-3 md:hidden">
                          <span className="w-6 text-right text-[11px] font-semibold tabular-nums text-slate-400">{i + 1}</span>
                          <PortalAvatar name={r.name} src={r.photo} size="sm" />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.name}</p>
                            <p className="truncate text-[11.5px] text-slate-500 dark:text-slate-400">
                              {r.roll_no ?? r.student_uid} · Sec {r.section_name}
                              {r.attendance_status === 'absent' && <span className="ml-1.5 font-semibold text-red-600 dark:text-red-400">absent in hall</span>}
                            </p>
                          </div>
                          <span className="flex flex-col items-end gap-0.5">
                            <span className="font-display text-base font-extrabold tabular-nums text-slate-900 dark:text-white">{c?.total ?? '—'}</span>
                            {c?.grade ? <span className={clsx('badge', c.grade === 'AB' ? 'badge-slate' : c.pass ? 'badge-green' : 'badge-red')}>{c.grade}</span> : <RowStatus r={r} changed={!!isChanged} />}
                          </span>
                        </div>
                        {/* desktop row */}
                        <div className="hidden md:grid md:items-center md:gap-3" style={{ gridTemplateColumns: gridCols }}>
                          <span className="text-right text-[11px] font-semibold tabular-nums text-slate-400">{i + 1}</span>
                          <div className="flex min-w-0 items-center gap-2.5">
                            <PortalAvatar name={r.name} src={r.photo} size="sm" />
                            <div className="min-w-0">
                              <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.name}</p>
                              <p className="truncate text-[11.5px] text-slate-500 dark:text-slate-400">
                                {r.roll_no ?? r.student_uid} · Sec {r.section_name}
                                {r.attendance_status === 'absent' && <span className="ml-1.5 font-semibold text-red-600 dark:text-red-400">absent in hall</span>}
                              </p>
                            </div>
                          </div>
                          {fields.map((f) => (
                            <MarkInput key={f} className="mx-auto max-w-[104px]" value={d[f]} error={rowErr(f)} disabled={readOnly || !!busy || (d.is_absent && f !== 'internal_marks')} label={`${FIELD_LABEL[f]} marks for ${r.name}`} row={i} col={f} onKey={onKey} onChange={(v) => setDraft(r.student_id, { [f]: v } as Partial<Draft>)} />
                          ))}
                          <AbsentToggle value={d.is_absent} disabled={readOnly || !!busy} name={r.name} onChange={(v) => setDraft(r.student_id, { is_absent: v })} />
                          <span className="text-center font-display text-sm font-extrabold tabular-nums text-slate-900 dark:text-white">{c?.total ?? '—'}</span>
                          <span className="text-center">{c?.grade ? <span className={clsx('badge', c.grade === 'AB' ? 'badge-slate' : c.pass ? 'badge-green' : 'badge-red')}>{c.grade}</span> : <span className="text-slate-300">—</span>}</span>
                          <span>
                            <RowStatus r={r} changed={!!isChanged} />
                          </span>
                        </div>
                        {/* mobile inputs */}
                        <div className="grid gap-2 md:hidden" style={{ gridTemplateColumns: `repeat(${fields.length + 1}, minmax(0, 1fr))` }}>
                          {fields.map((f) => (
                            <label key={f} className="block">
                              <span className="mb-1 block text-[10.5px] font-semibold uppercase tracking-wide text-slate-400">
                                {FIELD_LABEL[f]} /{f === 'internal_marks' ? s.max_internal : f === 'external_marks' ? s.max_external : s.max_practical}
                              </span>
                              <MarkInput value={d[f]} error={rowErr(f)} disabled={readOnly || !!busy || (d.is_absent && f !== 'internal_marks')} label={`${FIELD_LABEL[f]} marks for ${r.name}`} row={i} col={`m-${f}`} onKey={onKey} onChange={(v) => setDraft(r.student_id, { [f]: v } as Partial<Draft>)} />
                            </label>
                          ))}
                          <div>
                            <span className="mb-1 block text-[10.5px] font-semibold uppercase tracking-wide text-slate-400">Absent</span>
                            <AbsentToggle value={d.is_absent} disabled={readOnly || !!busy} name={r.name} onChange={(v) => setDraft(r.student_id, { is_absent: v })} />
                          </div>
                        </div>
                        {anyErr && (
                          <p className="flex items-center gap-1 text-xs font-medium text-red-600 dark:text-red-400">
                            <TriangleAlert className="h-3.5 w-3.5 shrink-0" aria-hidden />
                            {serverErrors[`rows.${r.student_id}.student_id`] ?? fields.map((f) => rowErr(f)).filter(Boolean).join(' ')}
                          </p>
                        )}
                      </li>
                    );
                  })}
                </ul>
              </div>
            )}

            {!readOnly && data.rows.length > 0 && (
              <div className="sticky bottom-[84px] z-10 rounded-b-2xl border-t border-slate-200 bg-white/95 p-3 backdrop-blur sm:p-4 lg:bottom-0 dark:border-slate-800 dark:bg-slate-900/95">
                <div className="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between">
                  <p className={clsx('items-center gap-2 text-xs text-slate-500 sm:flex dark:text-slate-400', dirty ? 'flex' : 'hidden')}>
                    <Info className="h-3.5 w-3.5 shrink-0" aria-hidden />
                    {dirty ? (
                      <span>
                        <strong className="text-slate-800 dark:text-slate-100">{changed.length}</strong> unsaved change{changed.length > 1 ? 's' : ''}
                        {invalid.length > 0 && <span className="text-red-600 dark:text-red-400"> · {invalid.length} need attention</span>}
                      </span>
                    ) : (
                      <span>Press Enter or ↓ to move to the next student. Half marks allowed.</span>
                    )}
                  </p>
                  <div className="flex gap-2">
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => void discard()} disabled={!dirty || !!busy}>
                      <RotateCcw className="h-4 w-4" aria-hidden /> <span className="hidden sm:inline">Discard</span>
                    </button>
                    <button type="button" className="btn btn-secondary flex-1 sm:flex-none" onClick={() => void send(false)} disabled={!dirty || !!busy}>
                      {busy === 'save' ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" aria-hidden />} Save draft
                    </button>
                    <button type="button" className="btn btn-primary flex-1 sm:flex-none" onClick={() => void send(true)} disabled={!!busy || (data.stats.submitted >= data.stats.students && !dirty)}>
                      {busy === 'submit' ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" aria-hidden />} {dirty ? 'Save & submit' : 'Submit'}
                    </button>
                  </div>
                </div>
              </div>
            )}
          </section>
          {data.rows.some((r) => r.updated_at) && (
            <p className="text-right text-[11px] text-slate-400">Last change {formatDateTime(data.rows.reduce((m, r) => (r.updated_at && r.updated_at > m ? r.updated_at : m), ''))}</p>
          )}
        </div>
      )}
    </div>
  );
}

function MarkInput({ value, error, disabled, label, row, col, onChange, onKey, className }: { value: string; error?: string; disabled: boolean; label: string; row: number; col: string; onChange: (v: string) => void; onKey: (e: KeyboardEvent<HTMLInputElement>) => void; className?: string }) {
  return (
    <input
      value={value}
      onChange={(e) => onChange(e.target.value)}
      onKeyDown={onKey}
      onFocus={(e) => e.currentTarget.select()}
      inputMode="decimal"
      autoComplete="off"
      disabled={disabled}
      aria-label={label}
      aria-invalid={!!error || undefined}
      title={error}
      data-row={row}
      data-col={col}
      className={clsx('form-input form-input-sm text-center font-semibold tabular-nums', error && 'form-input-error', className)}
    />
  );
}

function AbsentToggle({ value, disabled, name, onChange }: { value: boolean; disabled: boolean; name: string; onChange: (v: boolean) => void }) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={() => onChange(!value)}
      aria-pressed={value}
      aria-label={`Mark ${name} absent`}
      className={clsx(
        'mx-auto inline-flex h-8 w-full max-w-[56px] items-center justify-center rounded-lg text-xs font-extrabold ring-1 ring-inset transition disabled:cursor-not-allowed disabled:opacity-50',
        value ? 'bg-slate-800 text-white ring-slate-800 dark:bg-slate-200 dark:text-slate-900 dark:ring-slate-200' : 'bg-white text-slate-400 ring-slate-200 hover:text-slate-700 dark:bg-slate-900 dark:ring-slate-700',
      )}
    >
      {value ? 'AB' : <CircleSlash className="h-4 w-4" aria-hidden />}
    </button>
  );
}

function RowStatus({ r, changed }: { r: MarksRow; changed: boolean }) {
  if (changed) return <span className="badge badge-flame">Unsaved</span>;
  if (!r.entered) return <span className="badge badge-red">Pending</span>;
  if (r.status === 'verified' || r.status === 'published')
    return (
      <span className="badge badge-slate">
        <Lock className="h-3 w-3" aria-hidden /> {r.status === 'published' ? 'Published' : 'Verified'}
      </span>
    );
  if (r.status === 'submitted') return <span className="badge badge-green">Submitted</span>;
  return <span className="badge badge-amber">Draft</span>;
}
