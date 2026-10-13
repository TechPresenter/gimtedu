import { useEffect, useMemo, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowLeft, BadgeCheck, CheckCheck, Clock3, Lock, MapPin, NotebookText, RotateCcw, Save, Search, TriangleAlert, Users } from 'lucide-react';
import { Alert, SearchInput, Spinner, useConfirm, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { EmptyBlock, PortalAvatar } from '../../../components/Ui';
import { ScopedError } from '../../shared/ScopedError';
import { errorMessage, fieldErrors } from '../../../lib/errors';
import { usePortalQuery } from '../../../lib/hooks';
import { hhmm } from '../../../lib/time';
import type { AttStatus, AttendanceSheetData, SaveAttendanceResult } from '../../shared/types';
import { ATT_META, ATT_ORDER, PercentPill, fpath, useFacultyInvalidate } from '../../shared/ui';

export interface SheetTarget {
  date: string;
  section_id: number;
  subject_id: number;
  time_slot_id: number | null;
}

interface Props {
  target: SheetTarget;
  onBack?: () => void;
  /** reports unsaved changes to the parent (to confirm before switching period) */
  onDirtyChange?: (dirty: boolean) => void;
}

function StatusToggle({ value, onChange, disabled, name }: { value: AttStatus | null; onChange: (s: AttStatus) => void; disabled: boolean; name: string }) {
  return (
    <div className="inline-flex shrink-0 gap-1" role="group" aria-label={`Attendance for ${name}`}>
      {ATT_ORDER.map((s) => {
        const m = ATT_META[s];
        const on = value === s;
        return (
          <button
            key={s}
            type="button"
            disabled={disabled}
            onClick={() => onChange(s)}
            aria-pressed={on}
            title={m.label}
            className={clsx(
              'inline-flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg px-1.5 text-xs font-extrabold ring-1 ring-inset transition active:scale-95 disabled:cursor-not-allowed sm:min-w-[2.5rem] sm:px-2',
              on ? m.on : 'bg-white text-slate-500 ring-slate-200 hover:bg-slate-50 hover:text-slate-800 dark:bg-slate-900 dark:text-slate-400 dark:ring-slate-700 dark:hover:bg-slate-800',
              disabled && !on && 'opacity-50',
            )}
          >
            {m.short}
            <span className="sr-only"> {m.label}</span>
          </button>
        );
      })}
    </div>
  );
}

export function AttendanceSheet({ target, onBack, onDirtyChange }: Props) {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useFacultyInvalidate();
  const query = { date: target.date, section_id: target.section_id, subject_id: target.subject_id, time_slot_id: target.time_slot_id ?? undefined };
  const { data, isLoading, error, refetch, isFetching } = usePortalQuery<AttendanceSheetData>('faculty', ['attendance', 'sheet'], 'attendance/sheet', query, { placeholderData: undefined });
  const [marks, setMarks] = useState<Record<number, AttStatus | null>>({});
  const [remarks, setRemarks] = useState('');
  const [q, setQ] = useState('');
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const loadedFor = useRef<string>('');

  // (re)initialise the local marks whenever a fresh sheet arrives
  useEffect(() => {
    if (!data) return;
    const key = `${data.date}|${data.section.id}|${data.subject.id}|${data.slot?.id ?? 0}|${data.sheet?.updated_at ?? ''}`;
    if (loadedFor.current === key) return;
    loadedFor.current = key;
    const m: Record<number, AttStatus | null> = {};
    data.students.forEach((s) => (m[s.id] = s.status));
    setMarks(m);
    setRemarks(data.sheet?.remarks ?? '');
    setErrors({});
  }, [data]);

  const original = useMemo(() => {
    const m: Record<number, AttStatus | null> = {};
    data?.students.forEach((s) => (m[s.id] = s.status));
    return m;
  }, [data]);
  const dirty = !!data && (data.students.some((s) => (marks[s.id] ?? null) !== original[s.id]) || remarks.trim() !== (data.sheet?.remarks ?? '').trim());

  useEffect(() => {
    onDirtyChange?.(dirty);
  }, [dirty, onDirtyChange]);
  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  const counts = useMemo(() => {
    const c = { present: 0, absent: 0, late: 0, leave: 0, unmarked: 0 };
    data?.students.forEach((s) => {
      const v = marks[s.id];
      if (v) c[v]++;
      else c.unmarked++;
    });
    return c;
  }, [data, marks]);

  const visible = useMemo(() => {
    const term = q.trim().toLowerCase();
    return data ? data.students.filter((s) => !term || `${s.name} ${s.roll_no ?? ''} ${s.student_uid}`.toLowerCase().includes(term)) : [];
  }, [data, q]);

  if (error) return <ScopedError error={error} onRetry={() => void refetch()} title="We couldn't open this register" backTo={fpath('attendance')} backLabel="Back to Attendance" />;
  if (isLoading || !data) {
    return (
      <div className="card space-y-3 p-4 sm:p-5" aria-busy="true" aria-label="Loading register">
        <div className="skeleton h-6 w-2/3" />
        <div className="skeleton h-4 w-1/3" />
        {[0, 1, 2, 3, 4, 5].map((i) => (
          <div key={i} className="skeleton h-14 rounded-xl" />
        ))}
      </div>
    );
  }

  const readOnly = !data.can_save;
  const total = data.students.length;
  const marked = total - counts.unmarked;
  const attended = counts.present + counts.late;

  const setAll = (s: AttStatus, onlyEmpty = false) => setMarks((m) => {
    const next = { ...m };
    data.students.forEach((st) => {
      if (!onlyEmpty || !next[st.id]) next[st.id] = s;
    });
    return next;
  });

  const reset = async () => {
    if (dirty && !(await confirm({ title: 'Discard changes?', message: 'Your unsaved marks for this register will be lost.', confirmText: 'Discard', danger: true }))) return;
    setMarks({ ...original });
    setRemarks(data.sheet?.remarks ?? '');
    setErrors({});
  };

  const save = async () => {
    if (counts.unmarked > 0) {
      setErrors({ records: `Mark attendance for every student (${counts.unmarked} not marked yet).` });
      toast.warning(`${counts.unmarked} student${counts.unmarked > 1 ? 's are' : ' is'} not marked yet.`);
      return;
    }
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post<SaveAttendanceResult>('portal/faculty/attendance/sheet', {
        date: data.date,
        section_id: data.section.id,
        subject_id: data.subject.id,
        time_slot_id: data.slot?.id ?? null,
        remarks: remarks.trim(),
        records: data.students.map((s) => ({ student_id: s.id, status: marks[s.id] })),
      });
      toast.success(res.message || 'Attendance saved.');
      if (res.data.new_defaulters?.length) {
        toast.warning(`${res.data.new_defaulters.map((d) => `${d.name} (${d.percent}%)`).slice(0, 3).join(', ')} fell below ${data.min_percent}% attendance.`, 'Low attendance');
      }
      await invalidate('attendance', 'dashboard', 'timetable', 'classes');
    } catch (e) {
      setErrors(fieldErrors(e));
      toast.error(errorMessage(e, 'Unable to save attendance. Please try again.'));
    } finally {
      setSaving(false);
    }
  };

  const sheet = data.sheet;
  return (
    <section className="card overflow-visible" aria-label="Attendance register">
      {/* header */}
      <div className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5 dark:border-slate-800">
        <div className="flex min-w-0 items-start gap-3">
          {onBack && (
            <button type="button" onClick={onBack} className="btn-icon -ml-1 h-9 w-9 shrink-0 lg:hidden" aria-label="Back to periods">
              <ArrowLeft className="h-5 w-5" aria-hidden />
            </button>
          )}
          <div className="min-w-0">
            <p className="eyebrow">{formatDate(data.date)} · {data.day.day_name}</p>
            <h2 className="mt-1 font-display text-lg font-extrabold leading-snug text-slate-900 dark:text-white">{data.subject.name}</h2>
            <p className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[13px] text-slate-500 dark:text-slate-400">
              <span className="font-semibold text-flame-700 dark:text-flame-300">{data.subject.code}</span>
              <span className="inline-flex items-center gap-1">
                <Users className="h-3.5 w-3.5" aria-hidden /> {data.section.label}
              </span>
              <span className="inline-flex items-center gap-1">
                <Clock3 className="h-3.5 w-3.5" aria-hidden /> {data.slot ? `${data.slot.name} · ${hhmm(data.slot.start_time)} – ${hhmm(data.slot.end_time)}` : 'Extra class (no period)'}
              </span>
              {data.period?.room_code && (
                <span className="inline-flex items-center gap-1">
                  <MapPin className="h-3.5 w-3.5" aria-hidden /> {data.period.room_code}
                </span>
              )}
            </p>
          </div>
        </div>
        <div className="shrink-0 sm:text-right">
          {sheet ? (
            <>
              <span className={clsx('badge', sheet.is_locked ? 'badge-slate' : 'badge-green')}>
                {sheet.is_locked ? <Lock className="h-3 w-3" aria-hidden /> : <BadgeCheck className="h-3 w-3" aria-hidden />} {sheet.is_locked ? 'Locked' : 'Marked'}
              </span>
              <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                {sheet.taken_by_name ? `By ${sheet.taken_by_name} · ` : ''}
                {formatDateTime(sheet.updated_at ?? sheet.created_at)}
              </p>
            </>
          ) : (
            <span className="badge badge-amber">New register</span>
          )}
        </div>
      </div>

      <div className="space-y-3 p-4 sm:p-5">
        {data.read_only_reason && (
          <Alert variant="warning" title="View only">
            {data.read_only_reason}
          </Alert>
        )}
        {!data.read_only_reason && data.day.warning && <Alert variant="info">{data.day.warning}</Alert>}

        {/* summary chips */}
        <div className="flex flex-wrap items-center gap-2" aria-live="polite">
          {ATT_ORDER.map((s) => (
            <span key={s} className={clsx('badge tabular-nums', ATT_META[s].soft)}>
              {ATT_META[s].label} {counts[s]}
            </span>
          ))}
          <span className={clsx('badge tabular-nums', counts.unmarked ? 'badge-red' : 'badge-slate')}>Unmarked {counts.unmarked}</span>
          <span className="ml-auto text-xs font-semibold tabular-nums text-slate-500 dark:text-slate-400">
            {attended}/{total} attending{total ? ` · ${Math.round((attended / total) * 100)}%` : ''}
          </span>
        </div>

        {/* toolbar */}
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <SearchInput value={q} onChange={setQ} placeholder="Find a student…" size="sm" className="sm:flex-1" />
          {!readOnly && (
            <div className="flex gap-2">
              <button type="button" className="btn btn-soft btn-sm flex-1 sm:flex-none" onClick={() => setAll('present', counts.unmarked > 0 && counts.unmarked < total)} disabled={saving}>
                <CheckCheck className="h-4 w-4" aria-hidden /> {counts.unmarked > 0 && counts.unmarked < total ? 'Rest present' : 'All present'}
              </button>
              <button type="button" className="btn btn-ghost btn-sm" onClick={() => void reset()} disabled={!dirty || saving}>
                <RotateCcw className="h-4 w-4" aria-hidden /> Reset
              </button>
            </div>
          )}
        </div>
        {(!readOnly || remarks) && (
          <label className="relative block">
            <span className="sr-only">Topic covered / remarks</span>
            <NotebookText className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
            <input value={remarks} onChange={(e) => setRemarks(e.target.value)} maxLength={255} readOnly={readOnly} placeholder="Topic covered / remarks (optional)" className="form-input form-input-sm pl-9" />
          </label>
        )}
        {errors.records && (
          <p className="form-error" role="alert">
            <TriangleAlert className="h-3.5 w-3.5" aria-hidden /> {errors.records}
          </p>
        )}
      </div>

      {/* roster */}
      {total === 0 ? (
        <EmptyBlock icon={Users} title="No students in this section" text="Students appear here once they are admitted to the section." />
      ) : visible.length === 0 ? (
        <EmptyBlock compact icon={Search} title="No student matches your search" />
      ) : (
        <ul className={clsx('divide-y divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800', isFetching && 'opacity-80')}>
          {visible.map((s, i) => {
            const v = marks[s.id] ?? null;
            const err = errors[`records.${s.id}`];
            return (
              <li key={s.id} className={clsx('flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5 sm:px-5', !v && !readOnly && 'bg-amber-50/40 dark:bg-amber-500/5', err && 'bg-red-50 dark:bg-red-500/10')}>
                <span className="hidden w-6 shrink-0 text-right text-[11px] font-semibold tabular-nums text-slate-400 sm:inline">{i + 1}</span>
                <PortalAvatar name={s.name} src={s.photo} size="sm" />
                <div className="min-w-0 flex-1 basis-24 sm:basis-40">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                    {s.name}
                    {s.moved && <span className="badge badge-slate ml-1.5 align-middle">Moved</span>}
                  </p>
                  <p className="flex items-center gap-2 truncate text-[11.5px] text-slate-500 dark:text-slate-400">
                    <span>{s.roll_no ?? s.student_uid}</span>
                    <PercentPill value={s.subject_percent ?? s.percent} min={data.min_percent} className="!px-1.5 !py-0 !text-[10.5px]" title="Attendance in this subject" />
                  </p>
                  {err && <p className="mt-0.5 text-xs font-medium text-red-600 dark:text-red-400">{err}</p>}
                </div>
                <StatusToggle value={v} disabled={readOnly || saving} name={s.name} onChange={(st) => setMarks((m) => ({ ...m, [s.id]: st }))} />
              </li>
            );
          })}
        </ul>
      )}

      {/* save bar */}
      {!readOnly && total > 0 && (
        <div className="sticky bottom-[84px] z-10 rounded-b-2xl border-t border-slate-200 bg-white/95 p-3 backdrop-blur sm:px-5 lg:bottom-0 dark:border-slate-800 dark:bg-slate-900/95">
          <div className="flex items-center gap-3">
            <p className="min-w-0 flex-1 text-xs leading-tight text-slate-500 dark:text-slate-400">
              <span className="font-bold tabular-nums text-slate-800 dark:text-slate-100">
                {marked}/{total}
              </span>{' '}
              marked
              {counts.unmarked ? <span className="text-red-600 dark:text-red-400"> · {counts.unmarked} left</span> : dirty ? <span className="text-flame-700 dark:text-flame-300"> · unsaved</span> : null}
            </p>
            <button type="button" className="btn btn-primary shrink-0" onClick={() => void save()} disabled={saving || (!dirty && !!sheet)}>
              {saving ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" aria-hidden />}
              {sheet ? 'Update register' : 'Save attendance'}
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
