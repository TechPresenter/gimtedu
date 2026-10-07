import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, ArrowRightLeft, CalendarClock, CheckCircle2, Save, Trash2 } from 'lucide-react';
import { Alert, Button, Field, Modal, PageLoader, Textarea, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi, useLookup } from '@/lib/queries';
import type { TtCellOptions, TtDay, TtEntry, TtSectionContext, TtSlot } from '../types';
import { slotRange, TYPE_LABEL } from './helpers';

interface EntryModalProps {
  open: boolean;
  onClose: () => void;
  sessionId: number;
  section: TtSectionContext;
  day: number;
  slot: TtSlot | null;
  entry: TtEntry | null;
  days: TtDay[];
  slots: TtSlot[];
  /** All periods of this section (to detect swaps when moving). */
  sectionEntries: TtEntry[];
  perms: { create: boolean; edit: boolean; delete: boolean };
  onChanged: () => void;
}

interface FormState {
  subject_id: string;
  faculty_id: string;
  classroom_id: string;
  type: string;
  notes: string;
}

const EMPTY: FormState = { subject_id: '', faculty_id: '', classroom_id: '', type: 'lecture', notes: '' };
const ROOM_GROUPS: [string, string][] = [['classroom', 'Classrooms'], ['lab', 'Laboratories'], ['seminar_hall', 'Seminar halls'], ['exam_hall', 'Examination halls'], ['auditorium', 'Auditorium']];

/** Add / edit / move / delete one period of a section's weekly timetable, with live conflict checking. */
export function EntryModal({ open, onClose, sessionId, section, day, slot, entry, days, slots, sectionEntries, perms, onChanged }: EntryModalProps) {
  const toast = useToast();
  const confirm = useConfirm();
  const [form, setForm] = useState<FormState>(EMPTY);
  const [override, setOverride] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [conflicts, setConflicts] = useState<Record<string, string>>({});
  const [checking, setChecking] = useState(false);
  const [saving, setSaving] = useState(false);
  const [moveDay, setMoveDay] = useState(day);
  const [moveSlot, setMoveSlot] = useState<number>(slot?.id ?? 0);
  const checkSeq = useRef(0);
  const editable = entry ? perms.edit : perms.create;

  const cell = useApi<TtCellOptions>(
    ['tt', 'cell', section.id, day, slot?.id, entry?.id ?? 0, sessionId],
    'timetable/cell',
    { section_id: section.id, day, time_slot_id: slot?.id, exclude_id: entry?.id, session_id: sessionId },
    { enabled: open && !!slot },
  );
  const allFaculty = useLookup('faculty', {}, open && override);
  const subjects = cell.data?.subjects ?? [];
  const subject = subjects.find((s) => String(s.id) === form.subject_id);
  const busyFaculty = cell.data?.busy.faculty ?? {};
  const busyRooms = cell.data?.busy.rooms ?? {};
  const strength = cell.data?.section.strength ?? section.strength;
  const homeRoom = cell.data?.section.classroom_id ?? section.classroom_id;

  // Reset the form whenever the modal opens for a cell / entry.
  useEffect(() => {
    if (!open) return;
    setErrors({});
    setConflicts({});
    setMoveDay(day);
    setMoveSlot(slot?.id ?? 0);
    setForm(
      entry
        ? { subject_id: String(entry.subject_id), faculty_id: entry.faculty_id ? String(entry.faculty_id) : '', classroom_id: entry.classroom_id ? String(entry.classroom_id) : '', type: entry.type, notes: entry.notes ?? '' }
        : EMPTY,
    );
    setOverride(false);
  }, [open, entry, day, slot]);

  // Editing a period taught by a non-assigned faculty member: start in override mode.
  useEffect(() => {
    if (!open || !entry || !cell.data) return;
    const sub = cell.data.subjects.find((s) => s.id === entry.subject_id);
    if (entry.faculty_id && sub && !sub.faculty.some((f) => f.id === entry.faculty_id)) setOverride(true);
  }, [open, entry, cell.data]);

  const pickDefaults = (subjectId: string) => {
    const sub = subjects.find((s) => String(s.id) === subjectId);
    if (!sub || !cell.data) return;
    const lab = sub.type === 'lab';
    const freeFaculty = sub.faculty.find((f) => !busyFaculty[String(f.id)]);
    const rooms = cell.data.rooms;
    const roomFree = (id: number) => !busyRooms[String(id)];
    const room = lab
      ? rooms.find((r) => r.type === 'lab' && roomFree(r.id))
      : (homeRoom && roomFree(homeRoom) ? rooms.find((r) => r.id === homeRoom) : undefined) ?? rooms.find((r) => r.type === 'classroom' && r.capacity >= strength && roomFree(r.id));
    setForm((f) => ({
      ...f,
      subject_id: subjectId,
      faculty_id: freeFaculty ? String(freeFaculty.id) : '',
      classroom_id: f.classroom_id && !entry ? f.classroom_id : room ? String(room.id) : f.classroom_id,
      type: lab ? 'lab' : sub.type === 'project' ? 'tutorial' : f.type === 'lab' ? 'lecture' : f.type,
    }));
    setErrors({});
  };

  const payload = useMemo(
    () => ({
      id: entry?.id,
      academic_session_id: sessionId,
      section_id: section.id,
      day_of_week: day,
      time_slot_id: slot?.id,
      subject_id: form.subject_id ? Number(form.subject_id) : null,
      faculty_id: form.faculty_id ? Number(form.faculty_id) : null,
      classroom_id: form.classroom_id ? Number(form.classroom_id) : null,
      type: form.type,
      notes: form.notes || null,
      override_faculty: override,
    }),
    [entry, sessionId, section.id, day, slot, form, override],
  );

  // Live conflict check (debounced) - the server re-validates on save.
  useEffect(() => {
    if (!open || !slot || !form.subject_id) {
      setConflicts({});
      return;
    }
    const seq = ++checkSeq.current;
    const t = setTimeout(async () => {
      setChecking(true);
      try {
        const res = await api.post<{ ok: boolean; errors: Record<string, string> }>('timetable/check', payload);
        if (seq === checkSeq.current) setConflicts(res.data.errors ?? {});
      } catch {
        /* the save will report problems */
      } finally {
        if (seq === checkSeq.current) setChecking(false);
      }
    }, 350);
    return () => clearTimeout(t);
  }, [open, slot, payload, form.subject_id]);

  const save = async () => {
    if (!form.subject_id) {
      setErrors({ subject_id: 'Select a subject.' });
      return;
    }
    setSaving(true);
    try {
      const res = entry ? await api.put<TtEntry>(`timetable/entries/${entry.id}`, payload) : await api.post<TtEntry>('timetable/entries', payload);
      toast.success(res.message || 'Timetable updated.');
      onChanged();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      toast.error(err.message || 'Unable to save this period. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!entry) return;
    const ok = await confirm({
      title: 'Remove this period?',
      message: (
        <>
          <strong>{entry.subject_code} {entry.subject_name}</strong> will be removed from {entry.day_name}, {entry.slot_name}.
        </>
      ),
      confirmText: 'Remove period',
      danger: true,
    });
    if (!ok) return;
    setSaving(true);
    try {
      const res = await api.del(`timetable/entries/${entry.id}`);
      toast.success(res.message);
      onChanged();
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };

  const move = async () => {
    if (!entry || !moveSlot) return;
    const target = sectionEntries.find((x) => x.day_of_week === moveDay && x.time_slot_id === moveSlot && x.id !== entry.id);
    if (target) {
      const ok = await confirm({
        title: 'Swap periods?',
        message: (
          <>
            {target.day_name}, {target.slot_name} already has <strong>{target.subject_code} {target.subject_name}</strong>. The two periods will exchange places.
          </>
        ),
        confirmText: 'Swap periods',
      });
      if (!ok) return;
    }
    setSaving(true);
    try {
      const res = await api.post(`timetable/entries/${entry.id}/move`, { day_of_week: moveDay, time_slot_id: moveSlot, swap: !!target, session_id: sessionId });
      toast.success(res.message);
      onChanged();
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };

  const fieldError = (k: string) => errors[k] ?? conflicts[k];
  const conflictList = Object.values(conflicts);
  const facultyOptions = override
    ? (allFaculty.data ?? []).map((o) => ({ id: Number(o.value), name: o.label, designation: o.sub ?? null, primary: false }))
    : subject?.faculty ?? [];
  const dayName = days.find((d) => d.no === day)?.name ?? '';
  const teaching = slots.filter((s) => !s.is_break);

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
          <CalendarClock className="h-5 w-5" />
        </span>
      }
      title={entry ? (editable ? 'Edit class' : 'Class details') : 'Schedule a class'}
      description={slot ? `${section.label} · ${dayName}, ${slot.name} (${slotRange(slot)})` : section.label}
      footer={
        <>
          {entry && perms.delete && (
            <Button variant="ghost" icon={Trash2} className="mr-auto !text-red-600 hover:!bg-red-50 dark:hover:!bg-red-500/10" onClick={remove} disabled={saving}>
              Remove
            </Button>
          )}
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            {editable ? 'Cancel' : 'Close'}
          </Button>
          {editable && (
            <Button icon={Save} onClick={save} loading={saving} disabled={cell.isLoading}>
              {entry ? 'Save changes' : 'Add to timetable'}
            </Button>
          )}
        </>
      }
    >
      {cell.isLoading ? (
        <PageLoader label="Loading subjects, faculty and rooms…" />
      ) : cell.error ? (
        <Alert variant="error">{(cell.error as ApiError).message}</Alert>
      ) : (
        <div className="space-y-4">
          {subjects.length === 0 && (
            <Alert variant="warning" title="No subjects for this semester">
              Add subjects for {section.program_short} Semester {section.semester_no} under <Link to="/academics?tab=subjects" className="link">Academics → Subjects</Link> first.
            </Alert>
          )}
          <fieldset disabled={!editable} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Subject" required error={fieldError('subject_id')} htmlFor="tt-subject" className="sm:col-span-2">
              <select id="tt-subject" className={clsx('form-input', fieldError('subject_id') && 'form-input-error')} value={form.subject_id} onChange={(e) => pickDefaults(e.target.value)}>
                <option value="">Select a subject…</option>
                {subjects.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.code} — {s.name} · {s.scheduled}/{s.required} periods{s.type !== 'theory' ? ` · ${s.type}` : ''}{s.elective ? ' · elective' : ''}
                  </option>
                ))}
              </select>
            </Field>
            {subject && (
              <div className="sm:col-span-2 -mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <span className={clsx('inline-flex items-center gap-1 font-medium', subject.scheduled >= subject.required ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-300')}>
                  {subject.scheduled >= subject.required && <CheckCircle2 className="h-3.5 w-3.5" />}
                  {subject.scheduled} of {subject.required} weekly periods scheduled
                </span>
                <span>· {subject.credits} credits</span>
                {subject.faculty.length > 0 && <span>· Assigned: {subject.faculty.map((f) => f.name).join(', ')}</span>}
              </div>
            )}

            <Field
              label="Faculty"
              error={fieldError('faculty_id')}
              htmlFor="tt-faculty"
              hint={
                !override && subject && subject.faculty.length === 0
                  ? 'No faculty is assigned to this subject for the section. Assign one in Academics → Assignments, or turn on override.'
                  : override
                    ? 'Override: any faculty member can be scheduled.'
                    : 'Only faculty assigned to this subject and section are listed.'
              }
            >
              <select id="tt-faculty" className={clsx('form-input', fieldError('faculty_id') && 'form-input-error')} value={form.faculty_id} onChange={(e) => setForm((f) => ({ ...f, faculty_id: e.target.value }))} disabled={!form.subject_id}>
                <option value="">{form.subject_id ? '— No faculty (TBA) —' : 'Select a subject first'}</option>
                {facultyOptions.map((f) => {
                  const busy = busyFaculty[String(f.id)];
                  return (
                    <option key={f.id} value={f.id} disabled={!!busy}>
                      {f.name}
                      {f.designation ? ` · ${f.designation}` : ''}
                      {busy ? ` — busy: ${busy}` : ''}
                    </option>
                  );
                })}
              </select>
            </Field>

            <Field label="Room" error={fieldError('classroom_id')} htmlFor="tt-room" hint={`Section strength: ${strength} students`}>
              <select id="tt-room" className={clsx('form-input', fieldError('classroom_id') && 'form-input-error')} value={form.classroom_id} onChange={(e) => setForm((f) => ({ ...f, classroom_id: e.target.value }))}>
                <option value="">— No room —</option>
                {ROOM_GROUPS.map(([type, label]) => {
                  const list = (cell.data?.rooms ?? []).filter((r) => r.type === type);
                  if (!list.length) return null;
                  return (
                    <optgroup key={type} label={label}>
                      {list.map((r) => {
                        const busy = busyRooms[String(r.id)];
                        return (
                          <option key={r.id} value={r.id} disabled={!!busy}>
                            {r.code}
                            {r.name !== `Room ${r.code}` ? ` · ${r.name}` : ''} · {r.capacity} seats
                            {r.id === homeRoom ? ' · home room' : ''}
                            {r.capacity < strength ? ' · too small' : ''}
                            {busy ? ` — busy: ${busy}` : ''}
                          </option>
                        );
                      })}
                    </optgroup>
                  );
                })}
              </select>
            </Field>

            <Field label="Period type" htmlFor="tt-type" error={errors.type}>
              <select id="tt-type" className="form-input" value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}>
                {Object.entries(TYPE_LABEL).map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </Field>
            <div className="flex items-end pb-1.5">
              <Toggle checked={override} onChange={setOverride} label="Override faculty assignment" description="Allow faculty not assigned to this subject" disabled={!editable} />
            </div>
            <Field label="Notes" htmlFor="tt-notes" className="sm:col-span-2" aside={`${form.notes.length}/255`}>
              <Textarea id="tt-notes" rows={2} maxLength={255} value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} placeholder="e.g. Combined class, guest lecture, remedial session" />
            </Field>
          </fieldset>

          {form.subject_id && (conflictList.length > 0 || errors.section_id) ? (
            <Alert variant="error" title="This period clashes with the existing timetable">
              <ul className="mt-1 list-disc space-y-0.5 pl-4">
                {[...new Set([...(errors.section_id ? [errors.section_id] : []), ...conflictList])].map((m) => (
                  <li key={m}>{m}</li>
                ))}
              </ul>
            </Alert>
          ) : form.subject_id && !checking && editable ? (
            <p className="flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 className="h-3.5 w-3.5" /> No faculty, room or class conflicts.
            </p>
          ) : null}

          {entry && perms.edit && (
            <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
              <p className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                <ArrowRightLeft className="h-4 w-4 text-brand-600" /> Move or swap this period
              </p>
              <p className="mt-0.5 text-xs text-slate-500">Tip: on a desktop you can also drag a period onto another cell.</p>
              <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                <select className="form-input form-input-sm sm:w-40" value={moveDay} onChange={(e) => setMoveDay(Number(e.target.value))} aria-label="Move to day">
                  {days.map((d) => (
                    <option key={d.no} value={d.no}>{d.name}</option>
                  ))}
                </select>
                <select className="form-input form-input-sm sm:flex-1" value={moveSlot} onChange={(e) => setMoveSlot(Number(e.target.value))} aria-label="Move to period">
                  {teaching.map((s) => {
                    const occupant = sectionEntries.find((x) => x.day_of_week === moveDay && x.time_slot_id === s.id && x.id !== entry.id);
                    return (
                      <option key={s.id} value={s.id}>
                        {s.name} ({slotRange(s)}){occupant ? ` · swap with ${occupant.subject_code}` : ' · free'}
                      </option>
                    );
                  })}
                </select>
                <Button size="sm" variant="secondary" icon={ArrowRightLeft} onClick={move} disabled={saving || (moveDay === entry.day_of_week && moveSlot === entry.time_slot_id)}>
                  Move
                </Button>
              </div>
            </div>
          )}
          {entry && entry.status === 'draft' && (
            <p className="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300">
              <AlertTriangle className="h-3.5 w-3.5" /> This timetable is a draft - publish it to make it visible to faculty and attendance.
            </p>
          )}
        </div>
      )}
    </Modal>
  );
}
