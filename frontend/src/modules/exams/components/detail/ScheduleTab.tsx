import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { CalendarCog, ClipboardCheck, Printer, UserCheck, UserX, Wand2 } from 'lucide-react';
import { Alert, Avatar, Button, Combobox, DataTable, Field, Input, Modal, Select, Skeleton, useToast, type Column } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, type ApiError } from '@/lib/api';
import { printUrl } from '@/lib/config';
import { useApi, useInvalidate } from '@/lib/queries';
import { formatDate, formatTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { Chip, timeRange, todayIso } from '../shared';
import type { AttendanceSheet, ExamOverview } from '../types';

export function ScheduleTab({ data }: { data: ExamOverview }) {
  const e = data.exam;
  const [autoOpen, setAutoOpen] = useState(false);
  const [paper, setPaper] = useState<Row | null>(null);
  const invalidate = useInvalidate();
  const editable = data.permissions.edit && !['published', 'cancelled', 'verified'].includes(e.status);
  const today = todayIso();
  const unscheduled = Math.max(0, data.counts.subjects - data.counts.scheduled);

  return (
    <div className="space-y-4">
      {unscheduled > 0 && editable && (
        <Alert
          variant="warning"
          title={`${unscheduled} paper${unscheduled === 1 ? '' : 's'} without a date`}
          action={
            <Button size="sm" icon={Wand2} onClick={() => setAutoOpen(true)}>
              Auto-generate
            </Button>
          }
        >
          Generate the remaining papers automatically — Sundays, holidays and room / invigilator / student clashes are avoided.
        </Alert>
      )}
      <CrudTable
        module="exam_schedules"
        title="Date sheet"
        description="One paper per subject. Clashes are checked for rooms, invigilators and students writing two papers at once."
        scope={{ exam_id: e.id }}
        formDefaults={{ exam_id: e.id, start_time: '10:00', end_time: e.is_final ? '13:00' : '11:30', exam_date: e.start_date ?? '' }}
        addLabel="Schedule paper"
        onSaved={() => invalidate('exams')}
        emptyTitle="No papers scheduled"
        emptyText="Auto-generate the date sheet or schedule each paper with its date, time, room and invigilators."
        toolbar={
          <>
            {editable && data.counts.subjects > 0 && (
              <Button size="sm" variant="secondary" icon={Wand2} onClick={() => setAutoOpen(true)} disabled={!unscheduled} title={unscheduled ? undefined : 'Every subject already has a paper'}>
                Auto-generate
              </Button>
            )}
            <Button size="sm" variant="secondary" icon={Printer} href={printUrl('exam-schedule.php', { exam_id: e.id })} target="_blank">
              Print
            </Button>
          </>
        }
        rowMenu={(r) => [{ label: r.exam_date <= today ? 'Exam attendance' : 'Attendance sheet (after the paper)', icon: ClipboardCheck, onClick: () => setPaper(r) }]}
        renderers={{
          exam_date: (r) => (
            <div className="leading-tight">
              <div className={clsx('whitespace-nowrap font-medium', r.exam_date === today ? 'text-accent-700 dark:text-accent-400' : 'text-slate-800 dark:text-slate-100')}>{formatDate(r.exam_date)}</div>
              <div className="text-xs text-slate-500">{r.exam_date === today ? 'Today' : r.weekday}</div>
            </div>
          ),
          start_time: (r) => (
            <span className="block whitespace-nowrap leading-tight">
              {formatTime(r.start_time)}
              {r.end_time && <span className="block text-xs text-slate-500">to {formatTime(r.end_time)}</span>}
            </span>
          ),
          room_code: (r) =>
            r.room_code ? (
              <div className="leading-tight">
                <div className="font-medium">{r.room_code}</div>
                <div className="whitespace-nowrap text-xs text-slate-500">
                  {r.room_capacity} seats · {r.students_count} stu.
                </div>
              </div>
            ) : (
              <span className="text-xs text-amber-600 dark:text-amber-400">Not assigned</span>
            ),
          present_count: (r) =>
            r.exam_date > today ? (
              <span className="text-xs text-slate-400">Upcoming</span>
            ) : Number(r.present_count) + Number(r.absent_count) + Number(r.malpractice_count) === 0 ? (
              <button type="button" onClick={() => setPaper(r)} className="text-xs font-medium text-amber-600 hover:underline dark:text-amber-400">
                Not marked
              </button>
            ) : (
              <button type="button" onClick={() => setPaper(r)} className="whitespace-nowrap text-xs tabular-nums hover:underline">
                <span className="font-semibold text-emerald-700 dark:text-emerald-400">{r.present_count}</span> / <span className="text-red-600 dark:text-red-400">{r.absent_count} AB</span>
                {Number(r.malpractice_count) > 0 && <span className="text-violet-600"> · {r.malpractice_count} UFM</span>}
              </button>
            ),
        }}
      />
      <AutoScheduleModal open={autoOpen} onClose={() => setAutoOpen(false)} data={data} />
      {paper && <PaperAttendanceModal examId={e.id} paper={paper} onClose={() => setPaper(null)} />}
    </div>
  );
}

function AutoScheduleModal({ open, onClose, data }: { open: boolean; onClose: () => void; data: ExamOverview }) {
  const e = data.exam;
  const toast = useToast();
  const invalidate = useInvalidate();
  const [form, setForm] = useState({ start_date: e.start_date ?? '', start_time: '10:00', duration: e.is_final ? '180' : '90', gap: '1', classroom_id: null as number | string | null });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (open) setErrors({});
  }, [open]);
  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post(`exams/${e.id}/schedule/auto`, { ...form, classroom_id: form.classroom_id || undefined });
      toast.success(res.message);
      await invalidate('crud', 'exams');
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      toast.error(ex.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      title="Auto-generate date sheet"
      description={`Schedules every subject of ${e.cohort} that has no paper yet.`}
      icon={<span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200"><CalendarCog className="h-5 w-5" /></span>}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button icon={Wand2} loading={saving} onClick={submit}>
            Generate
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="First paper on" error={errors.start_date} htmlFor="as-date" hint={e.start_date ? `Exam window ${formatDate(e.start_date)} – ${formatDate(e.end_date)}` : undefined}>
          <Input id="as-date" type="date" value={form.start_date} onChange={(ev) => setForm({ ...form, start_date: ev.target.value })} invalid={!!errors.start_date} />
        </Field>
        <Field label="Start time" error={errors.start_time} htmlFor="as-time">
          <Input id="as-time" type="time" value={form.start_time} onChange={(ev) => setForm({ ...form, start_time: ev.target.value })} invalid={!!errors.start_time} />
        </Field>
        <Field label="Duration" error={errors.duration} htmlFor="as-dur">
          <Select id="as-dur" value={form.duration} onChange={(ev) => setForm({ ...form, duration: ev.target.value })} options={[{ value: '60', label: '1 hour' }, { value: '90', label: '1 hour 30 minutes' }, { value: '120', label: '2 hours' }, { value: '180', label: '3 hours' }]} />
        </Field>
        <Field label="Gap between papers" error={errors.gap} htmlFor="as-gap">
          <Select id="as-gap" value={form.gap} onChange={(ev) => setForm({ ...form, gap: ev.target.value })} options={[{ value: '1', label: 'Every working day' }, { value: '2', label: 'Every other day' }, { value: '3', label: 'Two days between papers' }]} />
        </Field>
        <Field label="Room / hall (optional)" error={errors.classroom_id} className="sm:col-span-2" hint="Leave blank to pick the smallest free room that seats every student.">
          <Combobox source="classrooms" value={form.classroom_id} onChange={(v) => setForm({ ...form, classroom_id: v })} placeholder="Any free room" clearable />
        </Field>
      </div>
    </Modal>
  );
}

function PaperAttendanceModal({ examId, paper, onClose }: { examId: number; paper: Row; onClose: () => void }) {
  const toast = useToast();
  const invalidate = useInvalidate();
  const q = useApi<AttendanceSheet>(['exams', 'attendance', paper.id], `exams/${examId}/papers/${paper.id}/attendance`);
  const [marks, setMarks] = useState<Record<number, { status: string; remarks: string }>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (!q.data) return;
    const m: Record<number, { status: string; remarks: string }> = {};
    q.data.rows.forEach((r) => (m[r.student_id] = { status: r.status ?? 'present', remarks: r.remarks ?? '' }));
    setMarks(m);
  }, [q.data]);
  const d = q.data;
  const editable = !!d?.can_mark && !!d?.can_edit;
  const counts = Object.values(marks).reduce((a, m) => ({ ...a, [m.status]: (a[m.status] ?? 0) + 1 }), {} as Record<string, number>);
  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post(`exams/${examId}/papers/${paper.id}/attendance`, { rows: Object.entries(marks).map(([sid, m]) => ({ student_id: Number(sid), ...m })) });
      toast.success(res.message);
      await invalidate('crud', 'exams');
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      toast.error(ex.message);
    } finally {
      setSaving(false);
    }
  };
  const columns: Column<AttendanceSheet['rows'][number]>[] = [
    { key: 'name', header: 'Student', render: (r) => (
      <div className="flex min-w-0 items-center gap-2.5">
        <Avatar name={r.name} src={r.photo} size="sm" />
        <div className="min-w-0 leading-tight">
          <p className="truncate text-sm font-medium text-slate-900 dark:text-white">{r.name}</p>
          <p className="truncate text-xs text-slate-500">{r.roll_no ?? r.student_uid}{r.seat_no ? ` · Seat ${r.seat_no}` : ''}</p>
        </div>
      </div>
    ) },
    { key: 'status', header: 'Status', render: (r) => {
      const m = marks[r.student_id] ?? { status: 'present', remarks: '' };
      return (
        <div className="space-y-1">
          <div className="inline-flex overflow-hidden rounded-lg border border-slate-200 text-xs dark:border-slate-700" role="radiogroup" aria-label={`Attendance of ${r.name}`}>
            {[{ v: 'present', l: 'Present', c: 'bg-emerald-600 text-white' }, { v: 'absent', l: 'Absent', c: 'bg-red-600 text-white' }, { v: 'malpractice', l: 'UFM', c: 'bg-violet-600 text-white' }].map((o) => (
              <button
                key={o.v}
                type="button"
                role="radio"
                aria-checked={m.status === o.v}
                disabled={!editable}
                onClick={() => setMarks({ ...marks, [r.student_id]: { ...m, status: o.v } })}
                className={clsx('px-2.5 py-1 font-medium transition disabled:cursor-not-allowed', m.status === o.v ? o.c : 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300')}
              >
                {o.l}
              </button>
            ))}
          </div>
          {m.status === 'malpractice' && (
            <Input inputSize="sm" placeholder="Describe the case (required)" value={m.remarks} disabled={!editable} invalid={!!errors[`rows.${r.student_id}.remarks`]}
              onChange={(ev) => setMarks({ ...marks, [r.student_id]: { ...m, remarks: ev.target.value } })} aria-label="Malpractice remarks" />
          )}
          {errors[`rows.${r.student_id}.remarks`] && <p className="text-xs text-red-600">{errors[`rows.${r.student_id}.remarks`]}</p>}
        </div>
      );
    } },
  ];
  return (
    <Modal
      open
      onClose={onClose}
      static={saving}
      size="lg"
      title={`Exam attendance · ${paper.subject_code} ${paper.subject_name}`}
      description={`${formatDate(paper.exam_date)} · ${timeRange(paper.start_time, paper.end_time)}${paper.room_code ? ` · Room ${paper.room_code}` : ''}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Close
          </Button>
          {editable && (
            <Button icon={UserCheck} loading={saving} onClick={save} disabled={!d?.rows.length}>
              Save attendance
            </Button>
          )}
        </>
      }
    >
      {!d ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-10 w-full" />
          ))}
        </div>
      ) : (
        <div className="space-y-3">
          {!d.can_mark && <Alert variant="info">Attendance can be marked on or after the date of the paper.</Alert>}
          {d.can_mark && !d.can_edit && <Alert variant="info">Attendance is read-only for this exam.</Alert>}
          <div className="flex flex-wrap items-center gap-2">
            <Chip label="Present" value={counts.present ?? 0} tone="green" />
            <Chip label="Absent" value={counts.absent ?? 0} tone="red" />
            <Chip label="Malpractice" value={counts.malpractice ?? 0} tone="purple" />
            {editable && (
              <Button size="xs" variant="ghost" icon={UserX} className="ml-auto" onClick={() => setMarks(Object.fromEntries(Object.entries(marks).map(([k, m]) => [k, { ...m, status: 'present' }])))}>
                Mark all present
              </Button>
            )}
          </div>
          <DataTable columns={columns} rows={d.rows} rowKey={(r) => r.student_id} dense empty={<p className="py-8 text-center text-sm text-slate-500">No eligible students allocated to this exam.</p>} />
        </div>
      )}
    </Modal>
  );
}
