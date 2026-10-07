import { useEffect, useMemo, useRef, useState } from 'react';
import clsx from 'clsx';
import {
  CalendarClock, Check, CheckCheck, CircleDashed, ClipboardCheck, Lock, LockOpen, MessageSquareText, Plus, RotateCcw, Save, Search, Trash2, Undo2, UserRoundX, Users,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, Combobox, EmptyState, Field, IconButton, Input, Select, Skeleton, useConfirm, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi, useInvalidate } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatTime, timeAgo } from '@/lib/format';
import type { Option } from '@/lib/types';
import type { EmployeeStatus, PeriodOption, PeriodsPayload, SaveSheetResult, SheetPayload, StudentStatus } from '../types';
import { DayBanner, PercentPill, PercentRing, SectionPicker, STATUS_META, StatusSegment, todayIso } from './shared';

export interface MarkSelection {
  date: string;
  section: number | null;
  subject: number | null;
  slot: number | null;
}

interface Mark {
  status: StudentStatus | null;
  remarks: string;
}

export function MarkAttendance({ selection, onChange }: { selection: MarkSelection; onChange: (s: Partial<MarkSelection>) => void }) {
  const { date, section, subject, slot } = selection;
  const [extra, setExtra] = useState(false);
  const periods = useApi<PeriodsPayload>(['attendance', 'periods', section, date], 'attendance/periods', { section_id: section ?? undefined, date }, { enabled: !!section });
  const p = periods.data;

  // Selecting a period that is not in the timetable switches to the "extra class" picker automatically
  useEffect(() => {
    if (!p || !subject) return;
    if (p.source === 'timetable' && !p.periods.some((x) => x.subject_id === subject && x.time_slot_id === slot)) setExtra(true);
  }, [p, subject, slot]);

  const pickPeriod = (x: PeriodOption) => {
    setExtra(false);
    onChange({ subject: x.subject_id, slot: x.time_slot_id });
  };

  return (
    <div className="space-y-5">
      <Card className="p-4 sm:p-5">
        <div className="grid gap-4 md:grid-cols-[minmax(0,11rem)_minmax(0,1fr)]">
          <Field label="Date" htmlFor="att-date">
            <Input id="att-date" type="date" value={date} max={todayIso()} onChange={(e) => e.target.value && onChange({ date: e.target.value, subject: null, slot: null })} />
          </Field>
          <Field label="Class / section" htmlFor="att-section">
            <SectionPicker id="att-section" value={section} onChange={(v) => onChange({ section: v, subject: null, slot: null })} />
          </Field>
        </div>

        {!section ? (
          <div className="mt-5 rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
            <Users className="mx-auto mb-2 h-6 w-6 text-slate-400" aria-hidden />
            Choose a class to see today&apos;s periods and the student roster.
          </div>
        ) : periods.isLoading ? (
          <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-24 rounded-xl" />
            ))}
          </div>
        ) : periods.error ? (
          <Alert variant="error" className="mt-5" title="Unable to load periods">
            {(periods.error as ApiError).message}
          </Alert>
        ) : p ? (
          <div className="mt-5 space-y-4">
            <DayBanner day={p.day} />
            {p.source === 'timetable' && !p.day.blocked && (
              <div>
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    {p.day.day_name}&apos;s timetable <span className="font-normal text-slate-500">· {p.periods.length} periods</span>
                  </p>
                  <button type="button" onClick={() => setExtra((v) => !v)} className="inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">
                    <Plus className="h-3.5 w-3.5" /> {extra ? 'Back to timetable periods' : 'Extra / substitute class'}
                  </button>
                </div>
                <div className="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-1 scrollbar-none sm:grid sm:grid-cols-2 sm:overflow-visible lg:grid-cols-3 xl:grid-cols-4">
                  {p.periods.map((x) => (
                    <PeriodCard key={`${x.time_slot_id}-${x.subject_id}`} period={x} active={!extra && subject === x.subject_id && slot === x.time_slot_id} onClick={() => pickPeriod(x)} />
                  ))}
                </div>
              </div>
            )}
            {(p.source === 'subjects' || extra) && !p.day.blocked && <ManualPeriodPicker data={p} subject={subject} slot={slot} onChange={onChange} />}
            {p.sheets.length > 0 && p.source === 'subjects' && (
              <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="font-medium text-slate-500">Already marked:</span>
                {p.sheets.map((s) => (
                  <button
                    key={s.id}
                    type="button"
                    onClick={() => onChange({ subject: s.subject_id, slot: s.time_slot_id })}
                    className={clsx(
                      'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-semibold ring-1 ring-inset transition hover:-translate-y-px',
                      subject === s.subject_id && slot === s.time_slot_id ? 'bg-brand-800 text-white ring-brand-800' : 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
                    )}
                  >
                    <Check className="h-3 w-3" />
                    {s.subject_code}
                    {s.slot_name ? ` · ${s.slot_name}` : ''}
                    {s.counts ? ` · ${s.counts.present + s.counts.late}/${s.counts.total}` : ''}
                  </button>
                ))}
              </div>
            )}
          </div>
        ) : null}
      </Card>

      {section && subject && !p?.day.blocked && <Roster key={`${section}-${subject}-${slot}-${date}`} selection={selection} onChange={onChange} />}
      {section && !subject && p && !p.day.blocked && (
        <Card>
          <EmptyState
            icon={ClipboardCheck}
            title={p.source === 'timetable' ? 'Choose a period' : 'Choose a subject'}
            description={p.source === 'timetable' ? 'Pick a period from the timetable above to load the roster. Marked periods open in edit mode.' : 'Pick the subject (and period) above to load the student roster.'}
          />
        </Card>
      )}
    </div>
  );
}

function PeriodCard({ period: x, active, onClick }: { period: PeriodOption; active: boolean; onClick: () => void }) {
  const marked = !!x.sheet;
  const c = x.sheet?.counts;
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!x.allowed && !marked}
      aria-pressed={active}
      className={clsx(
        'group relative w-60 shrink-0 snap-start rounded-xl border p-3 text-left transition duration-200 sm:w-auto',
        active ? 'border-brand-600 bg-brand-50/70 ring-2 ring-brand-500/30 dark:border-brand-400 dark:bg-brand-500/10' : 'border-slate-200 bg-white hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-soft dark:border-slate-700 dark:bg-slate-900 dark:hover:border-brand-500/50',
        !x.allowed && !marked && 'cursor-not-allowed opacity-50 hover:translate-y-0 hover:shadow-none',
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
          <CalendarClock className="h-3.5 w-3.5" /> {x.slot_name} · {formatTime(x.start_time)}
        </span>
        {marked ? (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
            {x.sheet?.is_locked ? <Lock className="h-3 w-3" /> : <Check className="h-3 w-3" />}
            {c ? `${c.present + c.late}/${c.total}` : 'Marked'}
          </span>
        ) : (
          <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
            <CircleDashed className="h-3 w-3" /> Pending
          </span>
        )}
      </div>
      <p className="mt-2 truncate text-sm font-semibold text-slate-900 dark:text-white" title={x.subject_name}>
        {x.subject_name}
      </p>
      <p className="truncate text-xs text-slate-500 dark:text-slate-400">
        {x.subject_code} · {x.faculty_name ?? 'Faculty not assigned'}
      </p>
      {!x.allowed && !marked && <p className="mt-1 text-[11px] font-medium text-slate-400">Assigned to another faculty member</p>}
    </button>
  );
}

function ManualPeriodPicker({ data, subject, slot, onChange }: { data: PeriodsPayload; subject: number | null; slot: number | null; onChange: (s: Partial<MarkSelection>) => void }) {
  const subjectOptions: Option[] = data.subjects.filter((s) => s.allowed).map((s) => ({ value: s.id, label: `${s.code} — ${s.name}`, sub: s.faculty_name ?? undefined }));
  const slotOptions: Option[] = data.slots.map((s) => ({ value: s.id, label: s.label }));
  return (
    <div className="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/40">
      {data.source === 'subjects' && (
        <p className="mb-3 text-sm text-slate-600 dark:text-slate-300">
          No timetable is published for this class on {data.day.day_name}. Choose the subject and, optionally, the period.
        </p>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Subject" htmlFor="att-subject" required>
          <Combobox id="att-subject" options={subjectOptions} value={subject} onChange={(v) => onChange({ subject: v === null ? null : Number(v) })} placeholder={subjectOptions.length ? 'Choose subject…' : 'No subjects assigned to you'} />
        </Field>
        <Field label="Period" htmlFor="att-slot" hint="Leave empty if the class did not follow a timetable period.">
          <Select id="att-slot" options={slotOptions} placeholder="No specific period" value={slot ?? ''} onChange={(e) => onChange({ slot: e.target.value ? Number(e.target.value) : null })} />
        </Field>
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------- Roster */
function Roster({ selection, onChange }: { selection: MarkSelection; onChange: (s: Partial<MarkSelection>) => void }) {
  const { date, section, subject, slot } = selection;
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const { can } = useAuth();
  const sheetQ = useApi<SheetPayload>(['attendance', 'sheet', section, subject, slot, date], 'attendance/sheet', { section_id: section ?? undefined, subject_id: subject ?? undefined, time_slot_id: slot ?? undefined, date });
  const data = sheetQ.data;
  const [marks, setMarks] = useState<Record<number, Mark>>({});
  const [initial, setInitial] = useState<Record<number, Mark>>({});
  const [facultyId, setFacultyId] = useState<number | null>(null);
  const [remarks, setRemarks] = useState('');
  const [q, setQ] = useState('');
  const [openRemarks, setOpenRemarks] = useState<Set<number>>(new Set());
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [showErrors, setShowErrors] = useState(false);
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!data) return;
    const m: Record<number, Mark> = {};
    data.students.forEach((s) => (m[s.id] = { status: s.status, remarks: s.remarks ?? '' }));
    setMarks(m);
    setInitial(m);
    setFacultyId(data.sheet?.faculty_id ?? data.default_faculty_id);
    setRemarks(data.sheet?.remarks ?? '');
    setErrors({});
    setShowErrors(false);
  }, [data]);

  const facultyInitial = useMemo<Option[]>(() => {
    const id = data?.sheet?.faculty_id ?? data?.default_faculty_id;
    const name = data?.sheet?.faculty_name ?? data?.default_faculty_name;
    return id && name ? [{ value: id, label: name }] : [];
  }, [data]);

  const students = data?.students ?? [];
  const counts = useMemo(() => {
    const c = { present: 0, absent: 0, late: 0, leave: 0, unmarked: 0 };
    students.forEach((s) => {
      const st = marks[s.id]?.status;
      if (st) c[st]++;
      else c.unmarked++;
    });
    return c;
  }, [marks, students]);
  const dirty = useMemo(
    () => students.some((s) => (marks[s.id]?.status ?? null) !== (initial[s.id]?.status ?? null) || (marks[s.id]?.remarks ?? '') !== (initial[s.id]?.remarks ?? '')) || remarks !== (data?.sheet?.remarks ?? ''),
    [marks, initial, students, remarks, data],
  );
  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    if (!needle) return students;
    return students.filter((s) => `${s.name} ${s.roll_no ?? ''} ${s.student_uid}`.toLowerCase().includes(needle));
  }, [students, q]);

  const setStatus = (id: number, status: EmployeeStatus) => setMarks((m) => ({ ...m, [id]: { remarks: m[id]?.remarks ?? '', status: status as StudentStatus } }));
  const setAll = (status: StudentStatus, onlyUnmarked = false) =>
    setMarks((m) => {
      const n = { ...m };
      students.forEach((s) => {
        if (!onlyUnmarked || !n[s.id]?.status) n[s.id] = { remarks: n[s.id]?.remarks ?? '', status };
      });
      return n;
    });

  const locked = !!data?.sheet?.is_locked;
  const canSave = !!data?.can_save;
  const presentPct = counts.present + counts.late + counts.absent + counts.leave ? ((counts.present + counts.late) / (students.length - counts.unmarked)) * 100 : null;

  const save = async () => {
    if (!data) return;
    if (counts.unmarked > 0) {
      setShowErrors(true);
      const first = students.find((s) => !marks[s.id]?.status);
      if (first) document.getElementById(`att-row-${first.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      toast.warning(`Mark attendance for every student — ${counts.unmarked} not marked yet.`);
      return;
    }
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post<SaveSheetResult>('attendance/sheet', {
        date, section_id: section, subject_id: subject, time_slot_id: slot, faculty_id: facultyId, remarks,
        records: students.map((s) => ({ student_id: s.id, status: marks[s.id]?.status, remarks: marks[s.id]?.remarks || null })),
      });
      toast.success(res.message, res.data.created ? 'Attendance saved' : 'Attendance updated');
      if (res.data.new_defaulters.length) {
        toast.warning(`${res.data.new_defaulters.map((d) => `${d.name} (${d.percent}%)`).slice(0, 3).join(', ')} fell below ${data.min_percent}% attendance.`, 'Low attendance');
      }
      await invalidate('attendance', 'crud');
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message || 'Unable to save attendance. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!data?.sheet) return;
    const ok = await confirm({
      title: 'Delete this attendance session?',
      message: (
        <>
          Attendance of <strong>{students.length} students</strong> for {data.subject.code} on {formatDate(date)} will be permanently deleted and their attendance percentages recalculated.
        </>
      ),
      confirmText: 'Delete session',
      danger: true,
    });
    if (!ok) return;
    try {
      const res = await api.del(`attendance/sheet/${data.sheet.id}`);
      toast.success(res.message || 'Attendance session deleted.');
      await invalidate('attendance', 'crud');
      onChange({ subject: null, slot: null });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const toggleLock = async () => {
    if (!data?.sheet) return;
    try {
      const res = await api.post(`attendance/sheet/${data.sheet.id}/lock`, { locked: !locked });
      toast.success(res.message);
      await invalidate('attendance', 'crud');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  if (sheetQ.isLoading) {
    return (
      <Card className="p-5">
        <Skeleton className="h-6 w-72" />
        <div className="mt-5 space-y-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="flex items-center gap-3">
              <Skeleton className="h-9 w-9 rounded-full" />
              <Skeleton className="h-4 w-48" />
              <Skeleton className="ml-auto h-8 w-64 rounded-xl" />
            </div>
          ))}
        </div>
      </Card>
    );
  }
  if (sheetQ.error) {
    return (
      <Alert variant="error" title="Unable to load the roster" action={<Button size="sm" variant="secondary" onClick={() => sheetQ.refetch()}>Retry</Button>}>
        {(sheetQ.error as ApiError).message}
      </Alert>
    );
  }
  if (!data) return null;

  return (
    <Card className="overflow-hidden">
      {/* Header */}
      <div className="flex flex-col gap-4 border-b border-slate-100 p-4 sm:p-5 lg:flex-row lg:items-center dark:border-slate-800">
        <div className="flex min-w-0 flex-1 items-center gap-4">
          <PercentRing value={presentPct === null ? null : Math.round(presentPct * 10) / 10} min={data.min_percent} label="Present today" />
          <div className="min-w-0">
            <h2 className="truncate font-display text-base font-bold text-slate-900 dark:text-white">
              {data.subject.code} · {data.subject.name}
            </h2>
            <p className="truncate text-sm text-slate-500 dark:text-slate-400">
              {data.section.label} · {formatDate(date)}
              {data.slot ? ` · ${data.slot.name} (${formatTime(data.slot.start_time)} – ${formatTime(data.slot.end_time)})` : ''}
            </p>
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              {data.sheet ? (
                <Badge color="green" dot>
                  Marked by {data.sheet.taken_by_name ?? 'staff'} · {timeAgo(data.sheet.updated_at ?? data.sheet.created_at)}
                </Badge>
              ) : (
                <Badge color="amber" dot>
                  Not marked yet
                </Badge>
              )}
              {locked && (
                <Badge color="slate">
                  <Lock className="h-3 w-3" /> Locked
                </Badge>
              )}
              {data.sheet && data.sheet.method !== 'manual' && <Badge color="cyan">{data.sheet.method.toUpperCase()}</Badge>}
            </div>
          </div>
        </div>
        <div className="grid grid-cols-5 gap-2 text-center sm:flex sm:flex-wrap sm:justify-end">
          {(['present', 'absent', 'late', 'leave'] as const).map((k) => (
            <div key={k} className={clsx('rounded-xl px-3 py-1.5', STATUS_META[k].soft)}>
              <p className="text-[11px] font-medium opacity-80">{STATUS_META[k].label}</p>
              <p className="font-display text-lg font-bold tabular-nums">{counts[k]}</p>
            </div>
          ))}
          <div className={clsx('rounded-xl px-3 py-1.5', counts.unmarked ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' : 'bg-slate-50 text-slate-400 dark:bg-slate-800/50')}>
            <p className="text-[11px] font-medium opacity-80">Unmarked</p>
            <p className="font-display text-lg font-bold tabular-nums">{counts.unmarked}</p>
          </div>
        </div>
      </div>

      {data.restricted && <Alert variant="warning" className="m-4 sm:m-5">{data.restricted}</Alert>}
      {locked && !can('attendance', 'approve') && (
        <Alert variant="info" className="m-4 sm:m-5" title="This session is locked">
          Attendance older than 30 days or approved by an administrator is locked. Ask an attendance approver to unlock it before making changes.
        </Alert>
      )}
      {errors.records && <Alert variant="error" className="m-4 sm:m-5">{errors.records}</Alert>}

      {/* Toolbar */}
      <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-3 sm:px-5 lg:flex-row lg:items-center dark:border-slate-800">
        <div className="relative w-full lg:max-w-xs">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
          <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Find student by name or roll no…" aria-label="Find student" className="form-input form-input-sm pl-9" />
        </div>
        <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
          <Button size="sm" variant="success" icon={CheckCheck} disabled={!canSave} onClick={() => setAll('present')}>
            Mark all present
          </Button>
          <Button size="sm" variant="secondary" icon={UserRoundX} disabled={!canSave || counts.unmarked === 0} onClick={() => setAll('absent', true)}>
            Unmarked → absent
          </Button>
          <Button size="sm" variant="ghost" icon={Undo2} disabled={!dirty} onClick={() => { setMarks(initial); setRemarks(data.sheet?.remarks ?? ''); }}>
            Reset
          </Button>
          {data.sheet && can('attendance', 'approve') && (
            <IconButton icon={locked ? LockOpen : Lock} label={locked ? 'Unlock session' : 'Lock session'} onClick={toggleLock} />
          )}
          {data.sheet && can('attendance', 'delete') && <IconButton icon={Trash2} label="Delete session" tone="danger" onClick={remove} />}
        </div>
      </div>

      {/* Students */}
      {students.length === 0 ? (
        <EmptyState icon={Users} title="No active students in this section" description="Assign students to this section from the Students module, then mark attendance." action={<Button variant="secondary" to="/students">Open Students</Button>} />
      ) : (
        <div ref={listRef} className="divide-y divide-slate-100 dark:divide-slate-800" role="list" aria-label="Student roster">
          {filtered.map((s, i) => {
            const m = marks[s.id];
            const missing = showErrors && !m?.status;
            const remarkOpen = openRemarks.has(s.id) || !!m?.remarks;
            return (
              <div
                key={s.id}
                id={`att-row-${s.id}`}
                role="listitem"
                className={clsx('px-4 py-2.5 transition-colors sm:px-5', missing ? 'bg-red-50/70 dark:bg-red-500/5' : m?.status === 'absent' ? 'bg-red-50/30 dark:bg-red-500/[.03]' : 'hover:bg-slate-50/70 dark:hover:bg-slate-800/30')}
              >
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                  <div className="flex min-w-0 flex-1 items-center gap-3">
                    <span className="hidden w-6 shrink-0 text-right text-xs tabular-nums text-slate-400 sm:block">{i + 1}</span>
                    <Avatar name={s.name} src={s.photo} size="md" />
                    <div className="min-w-0 leading-tight">
                      <p className="truncate font-semibold text-slate-900 dark:text-white">
                        {s.name}
                        {s.moved && <span className="ml-1.5 text-[11px] font-medium text-amber-600">(moved)</span>}
                      </p>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                        {s.roll_no ?? '—'} · {s.student_uid}
                      </p>
                    </div>
                    <div className="ml-auto flex shrink-0 items-center gap-1.5 sm:ml-0">
                      <span title={`Overall attendance (${s.held} classes)`}>
                        <PercentPill value={s.percent} min={data.min_percent} />
                      </span>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <StatusSegment stretch name={`st-${s.id}`} label={`Attendance for ${s.name}`} value={m?.status ?? null} onChange={(v) => setStatus(s.id, v)} disabled={!canSave} invalid={missing || !!errors[`records.${s.id}`]} />
                    <IconButton
                      size="sm"
                      icon={MessageSquareText}
                      label={remarkOpen ? 'Hide remarks' : 'Add remarks'}
                      className={clsx(m?.remarks && '!text-brand-700 dark:!text-brand-300')}
                      onClick={() => setOpenRemarks((o) => { const n = new Set(o); if (n.has(s.id)) n.delete(s.id); else n.add(s.id); return n; })}
                    />
                  </div>
                </div>
                {remarkOpen && (
                  <div className="mt-2 sm:ml-[3.75rem]">
                    <Input
                      inputSize="sm"
                      value={m?.remarks ?? ''}
                      maxLength={255}
                      disabled={!canSave}
                      placeholder="Remarks (e.g. medical leave, came 10 min late)"
                      aria-label={`Remarks for ${s.name}`}
                      onChange={(e) => setMarks((mm) => ({ ...mm, [s.id]: { status: mm[s.id]?.status ?? null, remarks: e.target.value } }))}
                    />
                  </div>
                )}
                {errors[`records.${s.id}`] && <p className="form-error sm:ml-[3.75rem]">{errors[`records.${s.id}`]}</p>}
              </div>
            );
          })}
          {filtered.length === 0 && <p className="px-5 py-8 text-center text-sm text-slate-500">No student matches “{q}”.</p>}
        </div>
      )}

      {/* Class details + sticky save bar */}
      <div className="border-t border-slate-100 bg-slate-50/60 px-4 py-4 sm:px-5 dark:border-slate-800 dark:bg-slate-800/30">
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Conducted by" htmlFor="att-faculty">
            <Combobox id="att-faculty" source="faculty" value={facultyId} initialOptions={facultyInitial} onChange={(v) => setFacultyId(v === null ? null : Number(v))} placeholder="Search faculty…" disabled={!canSave} />
          </Field>
          <Field label="Class remarks / topic covered" htmlFor="att-remarks" error={errors.remarks}>
            <Input id="att-remarks" value={remarks} maxLength={255} onChange={(e) => setRemarks(e.target.value)} placeholder="e.g. Unit 2 — Demand forecasting" disabled={!canSave} />
          </Field>
        </div>
      </div>
      <div className="sticky bottom-0 z-10 flex flex-col gap-3 border-t border-slate-200 bg-white/90 px-4 py-3 backdrop-blur sm:flex-row sm:items-center sm:px-5 dark:border-slate-800 dark:bg-slate-900/90">
        <p className="text-sm text-slate-600 dark:text-slate-300">
          <strong className="text-slate-900 dark:text-white">{counts.present + counts.late}</strong> of {students.length} present
          {counts.unmarked > 0 && <span className="text-amber-600 dark:text-amber-400"> · {counts.unmarked} unmarked</span>}
          {dirty && <span className="ml-2 inline-flex items-center gap-1 text-xs font-medium text-brand-700 dark:text-brand-300"><RotateCcw className="h-3 w-3" /> Unsaved changes</span>}
        </p>
        <div className="flex gap-2 sm:ml-auto">
          {!canSave ? (
            <Badge color="slate">
              <Lock className="h-3 w-3" /> View only
            </Badge>
          ) : (
            <Button icon={data.sheet ? Save : ClipboardCheck} loading={saving} onClick={save} disabled={students.length === 0} className="w-full sm:w-auto">
              {data.sheet ? 'Update attendance' : 'Save attendance'}
            </Button>
          )}
        </div>
      </div>
    </Card>
  );
}

