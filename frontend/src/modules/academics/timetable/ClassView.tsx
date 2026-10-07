import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle, CalendarCheck, CalendarX2, CheckCircle2, Copy, Eraser, GraduationCap, MapPin, Printer, School, UserRound, Users,
} from 'lucide-react';
import { Alert, Badge, Button, Card, EmptyState, ProgressBar, Reveal, Skeleton, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { printUrl } from '@/lib/config';
import { useApi, useCrudList, useLookup } from '@/lib/queries';
import type { Row } from '@/lib/types';
import type { TtDay, TtEntry, TtGrid, TtSlot } from '../types';
import { CopyModal } from './CopyModal';
import { EntryModal } from './EntryModal';
import { toneFor, useTimetableInvalidate } from './helpers';
import { WeekGrid } from './WeekGrid';

const STATUS_BADGE = {
  published: { color: 'green', label: 'Published' },
  draft: { color: 'amber', label: 'Draft' },
  partial: { color: 'blue', label: 'Partly published' },
  empty: { color: 'slate', label: 'Not created' },
} as const;

const sectionDot: Record<string, string> = { published: 'bg-emerald-500', draft: 'bg-amber-500', partial: 'bg-blue-500', not_created: 'bg-slate-300 dark:bg-slate-600' };

interface ClassViewProps {
  sessionId: number;
  sessions: { id: number; name: string }[];
  sectionId: number | null;
  programId: number | null;
  days: TtDay[];
  slots: TtSlot[];
  perms: { create: boolean; edit: boolean; delete: boolean; publish: boolean };
  onChange: (patch: Record<string, string | null>) => void;
}

/** Class (section) timetable editor: pickers, weekly grid, publish/copy/clear, subject coverage. */
export function ClassView({ sessionId, sessions, sectionId, programId, days, slots, perms, onChange }: ClassViewProps) {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useTimetableInvalidate();
  const { data: programs = [], isLoading: programsLoading } = useLookup('programs');
  const grid = useApi<TtGrid>(['tt', 'grid', 'class', sectionId, sessionId], 'timetable/grid', { view: 'class', id: sectionId ?? undefined, session_id: sessionId }, { enabled: !!sectionId });
  const ctx = grid.data?.context.section ?? null;
  const effectiveProgram = programId ?? ctx?.program_id ?? null;
  const sections = useCrudList('sections', { per_page: 100, f: { program_id: effectiveProgram, academic_session_id: sessionId, status: 'active' } }, !!effectiveProgram);
  const [modal, setModal] = useState<{ day: number; slot: TtSlot | null; entry: TtEntry | null } | null>(null);
  const [copyOpen, setCopyOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  // Landing on /timetable with nothing selected: open the first program / first section.
  useEffect(() => {
    if (!sectionId && !programId && programs.length) onChange({ program: String(programs[0].value) });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sectionId, programId, programs]);
  useEffect(() => {
    if (!sectionId && effectiveProgram && sections.data?.rows.length) onChange({ section: String(sections.data.rows[0].id) });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sectionId, effectiveProgram, sections.data]);

  const bySemester = useMemo(() => {
    const m = new Map<number, Row[]>();
    (sections.data?.rows ?? []).forEach((r) => {
      const k = Number(r.semester_no);
      m.set(k, [...(m.get(k) ?? []), r]);
    });
    return [...m.entries()].sort((a, b) => a[0] - b[0]);
  }, [sections.data]);

  const subjectOrder = useMemo(() => new Map((grid.data?.subjects ?? []).map((s, i) => [s.id, i] as [number, number])), [grid.data]);
  const stats = grid.data?.stats;
  const status = STATUS_BADGE[stats?.status ?? 'empty'];
  const canEditGrid = perms.create || perms.edit;

  const refresh = () => invalidate();

  const onMove = async (entry: TtEntry, day: number, slotId: number, target: TtEntry | null) => {
    if (target) {
      const ok = await confirm({
        title: 'Swap periods?',
        message: (
          <>
            <strong>{entry.subject_code}</strong> ({entry.day_name}, {entry.slot_name}) and <strong>{target.subject_code}</strong> ({target.day_name}, {target.slot_name}) will exchange places.
          </>
        ),
        confirmText: 'Swap',
      });
      if (!ok) return;
    }
    try {
      const res = await api.post(`timetable/entries/${entry.id}/move`, { day_of_week: day, time_slot_id: slotId, swap: !!target });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const publish = async (on: boolean) => {
    if (!ctx) return;
    if (!on) {
      const ok = await confirm({ title: 'Unpublish this timetable?', message: 'Faculty and attendance will stop seeing it until you publish it again.', confirmText: 'Unpublish' });
      if (!ok) return;
    }
    setBusy(true);
    try {
      const res = await api.post('timetable/publish', { section_id: ctx.id, publish: on, session_id: sessionId });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const clear = async () => {
    if (!ctx) return;
    const ok = await confirm({
      title: `Clear the timetable of ${ctx.label}?`,
      message: `All ${stats?.periods ?? 0} periods of this section will be permanently removed for the session. This action cannot be undone.`,
      confirmText: 'Clear timetable',
      danger: true,
    });
    if (!ok) return;
    setBusy(true);
    try {
      const res = await api.del(`timetable/sections/${ctx.id}`, { session_id: sessionId });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-5">
      {/* Pickers */}
      <Card className="p-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[10rem_minmax(0,22rem)]">
          <div>
            <label htmlFor="tt-session" className="form-label">Session</label>
            <select id="tt-session" className="form-input form-input-sm" value={sessionId} onChange={(e) => onChange({ session: e.target.value, section: null })}>
              {sessions.map((s) => (
                <option key={s.id} value={s.id}>{s.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="tt-program" className="form-label">Program</label>
            <select
              id="tt-program"
              className="form-input form-input-sm"
              value={effectiveProgram ?? ''}
              onChange={(e) => onChange({ program: e.target.value || null, section: null })}
              disabled={programsLoading}
            >
              <option value="">Select a program…</option>
              {programs.map((p) => (
                <option key={p.value} value={p.value}>{p.label}</option>
              ))}
            </select>
          </div>
        </div>
        <div className="mt-4">
          <p className="form-label">Section</p>
          {!effectiveProgram ? (
            <p className="text-sm text-slate-500">Choose a program to see its sections.</p>
          ) : sections.isLoading ? (
            <div className="flex gap-2">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-9 w-24 rounded-xl" />)}</div>
          ) : bySemester.length === 0 ? (
            <p className="text-sm text-slate-500">
              No active sections for this program in the session. <Link className="link" to="/academics?tab=sections">Create a section</Link>.
            </p>
          ) : (
            <div className="flex flex-wrap gap-x-5 gap-y-3">
              {bySemester.map(([sem, rows]) => (
                <div key={sem} className="flex items-center gap-1.5">
                  <span className="mr-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Sem {sem}</span>
                  {rows.map((r) => {
                    const active = Number(r.id) === sectionId;
                    return (
                      <button
                        key={String(r.id)}
                        type="button"
                        onClick={() => onChange({ section: String(r.id) })}
                        aria-pressed={active}
                        title={`${r.label} · ${String(r.timetable_status).replace('_', ' ')}`}
                        className={clsx(
                          'inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-sm font-semibold transition',
                          active
                            ? 'border-brand-800 bg-brand-800 text-white shadow-sm dark:border-brand-500 dark:bg-brand-600'
                            : 'border-slate-200 bg-white text-slate-700 hover:border-brand-300 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200',
                        )}
                      >
                        <span className={clsx('h-2 w-2 rounded-full', sectionDot[String(r.timetable_status)] ?? 'bg-slate-300')} aria-hidden />
                        Sec {String(r.name)}
                      </button>
                    );
                  })}
                </div>
              ))}
            </div>
          )}
        </div>
      </Card>

      {!sectionId ? (
        <Card>
          <EmptyState icon={School} title="Select a section" description="Pick a program and a section above to view and edit its weekly timetable." />
        </Card>
      ) : grid.error ? (
        <Alert variant="error" title="Unable to load the timetable">{(grid.error as ApiError).message}</Alert>
      ) : (
        <>
          <Card className="overflow-hidden">
            <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 lg:flex-row lg:items-center dark:border-slate-800">
              <div className="min-w-0 flex-1">
                {grid.isLoading || !ctx ? (
                  <>
                    <Skeleton className="h-5 w-64" />
                    <Skeleton className="mt-2 h-3.5 w-96 max-w-full" />
                  </>
                ) : (
                  <>
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="font-display text-lg font-bold text-slate-900 dark:text-white">{ctx.label}</h2>
                      <Badge color={status.color} dot>{status.label}</Badge>
                      {stats && stats.draft > 0 && stats.status === 'partial' && <Badge color="amber">{stats.draft} unpublished</Badge>}
                    </div>
                    <p className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                      <span className="inline-flex items-center gap-1"><GraduationCap className="h-3.5 w-3.5" />{ctx.program}</span>
                      <span className="inline-flex items-center gap-1"><UserRound className="h-3.5 w-3.5" />Class teacher: {ctx.class_teacher ?? '—'}</span>
                      <span className="inline-flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />Home room: {ctx.room ?? '—'}</span>
                      <span className="inline-flex items-center gap-1"><Users className="h-3.5 w-3.5" />{ctx.strength} students</span>
                    </p>
                  </>
                )}
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {perms.publish && stats && stats.periods > 0 && (stats.status === 'published' ? (
                  <Button size="sm" variant="secondary" icon={CalendarX2} onClick={() => publish(false)} disabled={busy}>Unpublish</Button>
                ) : (
                  <Button size="sm" variant="success" icon={CalendarCheck} onClick={() => publish(true)} loading={busy}>Publish</Button>
                ))}
                {perms.create && ctx && (stats?.periods ?? 0) > 0 && (
                  <Button size="sm" variant="secondary" icon={Copy} onClick={() => setCopyOpen(true)}>Copy to…</Button>
                )}
                {perms.delete && ctx && (stats?.periods ?? 0) > 0 && (
                  <Button size="sm" variant="ghost" icon={Eraser} onClick={clear} disabled={busy} className="!text-red-600 hover:!bg-red-50 dark:hover:!bg-red-500/10">Clear</Button>
                )}
                {ctx && (
                  <Button size="sm" variant="secondary" icon={Printer} href={printUrl('timetable.php', { view: 'class', id: ctx.id, session_id: sessionId })} target="_blank">Print</Button>
                )}
              </div>
            </div>
            {stats && stats.periods === 0 && !grid.isLoading && (
              <div className="px-5 pt-4">
                <Alert variant="info" title="No classes scheduled yet">
                  {canEditGrid ? 'Click any empty period to schedule a class, or copy the timetable of another section of this semester.' : 'The timetable for this section has not been created yet.'}
                </Alert>
              </div>
            )}
            {stats && (stats.without_faculty > 0 || stats.without_room > 0) && (
              <div className="px-5 pt-4">
                <Alert variant="warning">
                  {stats.without_faculty > 0 && `${stats.without_faculty} period${stats.without_faculty === 1 ? '' : 's'} without faculty. `}
                  {stats.without_room > 0 && `${stats.without_room} period${stats.without_room === 1 ? '' : 's'} without a room.`}
                </Alert>
              </div>
            )}
            <WeekGrid
              days={days}
              slots={slots}
              entries={grid.data?.entries ?? []}
              mode="class"
              loading={grid.isLoading}
              canAdd={perms.create}
              canMove={perms.edit}
              subjectOrder={subjectOrder}
              onAdd={(day, slot) => setModal({ day, slot, entry: null })}
              onOpen={(entry) => setModal({ day: entry.day_of_week, slot: slots.find((s) => s.id === entry.time_slot_id) ?? null, entry })}
              onMove={onMove}
            />
          </Card>

          {grid.data?.subjects && grid.data.subjects.length > 0 && (
            <Reveal>
              <Card>
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3.5 dark:border-slate-800">
                  <div>
                    <h3 className="card-title">Subject coverage</h3>
                    <p className="card-subtitle">Weekly periods scheduled vs. required (hours per week)</p>
                  </div>
                  {(() => {
                    const done = grid.data.subjects.filter((s) => s.scheduled >= s.required).length;
                    return done === grid.data.subjects.length ? (
                      <Badge color="green"><CheckCircle2 className="h-3 w-3" /> All {done} subjects complete</Badge>
                    ) : (
                      <Badge color="amber"><AlertTriangle className="h-3 w-3" /> {grid.data.subjects.length - done} subject(s) short</Badge>
                    );
                  })()}
                </div>
                <ul className="grid grid-cols-1 gap-px bg-slate-100 sm:grid-cols-2 xl:grid-cols-3 dark:bg-slate-800">
                  {grid.data.subjects.map((s) => {
                    const tone = toneFor(s.id, subjectOrder);
                    const pct = s.required ? Math.min(100, (s.scheduled / s.required) * 100) : 100;
                    return (
                      <li key={s.id} className="bg-white px-5 py-3.5 dark:bg-slate-900">
                        <div className="flex items-start gap-2.5">
                          <span className={clsx('mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full', tone.dot)} aria-hidden />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{s.name}</p>
                            <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                              {s.code} · {s.faculty.length ? s.faculty.map((f) => f.name).join(', ') : <span className="text-amber-600">No faculty assigned</span>}
                            </p>
                            <div className="mt-2 flex items-center gap-2">
                              <ProgressBar value={pct} tone={s.scheduled > s.required ? 'orange' : pct >= 100 ? 'green' : 'blue'} label={`${s.code} coverage`} />
                              <span className="shrink-0 text-xs font-semibold tabular-nums text-slate-600 dark:text-slate-300">{s.scheduled}/{s.required}</span>
                            </div>
                          </div>
                        </div>
                      </li>
                    );
                  })}
                </ul>
              </Card>
            </Reveal>
          )}
        </>
      )}

      {ctx && modal && (
        <EntryModal
          open={!!modal}
          onClose={() => setModal(null)}
          sessionId={sessionId}
          section={ctx}
          day={modal.day}
          slot={modal.slot}
          entry={modal.entry}
          days={days}
          slots={slots}
          sectionEntries={grid.data?.entries ?? []}
          perms={perms}
          onChanged={refresh}
        />
      )}
      {ctx && <CopyModal open={copyOpen} onClose={() => setCopyOpen(false)} section={ctx} sessionId={sessionId} onDone={refresh} />}
    </div>
  );
}
