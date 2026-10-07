import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BarChart3, CalendarCheck, CalendarDays, CalendarX2, ClipboardList, Fingerprint, LayoutDashboard, Lock, LockOpen, PenLine, Printer } from 'lucide-react';
import { Button, Card, IconButton, PageHeader, PersonCell, Tabs, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useAuth } from '@/lib/auth';
import { api, type ApiError } from '@/lib/api';
import { useInvalidate } from '@/lib/queries';
import { formatDate } from '@/lib/format';
import { printUrl } from '@/lib/config';
import type { Row } from '@/lib/types';
import { AttendanceOverview } from './components/AttendanceOverview';
import { MarkAttendance, type MarkSelection } from './components/MarkAttendance';
import { SectionCalendar } from './components/SectionCalendar';
import { DeviceIntegrationModal } from './components/DeviceIntegrationModal';
import { currentMonth, PercentPill, todayIso } from './components/shared';

const TABS = ['overview', 'mark', 'calendar', 'sessions', 'holidays'] as const;
type Tab = (typeof TABS)[number];

const num = (v: string | null) => (v && /^\d+$/.test(v) ? Number(v) : null);

export default function StudentAttendancePage() {
  const { can } = useAuth();
  const toast = useToast();
  const invalidate = useInvalidate();
  const [params, setParams] = useSearchParams();
  const [deviceOpen, setDeviceOpen] = useState(false);
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'overview';
  const date = params.get('date') && /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '') ? (params.get('date') as string) : todayIso();
  const month = params.get('month') && /^\d{4}-\d{2}$/.test(params.get('month') ?? '') ? (params.get('month') as string) : currentMonth();
  const selection: MarkSelection = { date, section: num(params.get('section')), subject: num(params.get('subject')), slot: num(params.get('slot')) };

  const update = (patch: Record<string, string | number | null | undefined>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === undefined || v === '' ? next.delete(k) : next.set(k, String(v))));
    setParams(next, { replace: false });
  };
  const setTab = (t: string) => {
    const next = new URLSearchParams(params);
    next.set('tab', t);
    ['page', 'q'].forEach((k) => next.delete(k));
    [...next.keys()].filter((k) => k.startsWith('f.')).forEach((k) => next.delete(k));
    setParams(next);
  };
  const openMark = (s: Partial<MarkSelection>) => {
    const next = new URLSearchParams(params);
    next.set('tab', 'mark');
    const merged = { ...selection, ...s };
    next.set('date', merged.date);
    (['section', 'subject', 'slot'] as const).forEach((k) => (merged[k] ? next.set(k, String(merged[k])) : next.delete(k)));
    setParams(next);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const lockRow = async (r: Row, locked: boolean) => {
    try {
      const res = await api.post(`attendance/sheet/${r.id}/lock`, { locked });
      toast.success(res.message);
      await invalidate('crud', 'attendance');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Student Attendance"
        description="Mark period-wise attendance, track today's classes and review each section's month at a glance."
        breadcrumbs={[{ label: 'Attendance' }, { label: 'Student Attendance' }]}
        actions={
          <>
            <IconButton icon={Fingerprint} label="Device integration" onClick={() => setDeviceOpen(true)} className="border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800" />
            <Button variant="secondary" icon={BarChart3} to="/attendance/reports">
              Reports
            </Button>
            {can('attendance', 'create') && tab !== 'mark' && (
              <Button icon={PenLine} onClick={() => openMark({})}>
                Mark Attendance
              </Button>
            )}
          </>
        }
      >
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <Tabs
            className="flex-1"
            value={tab}
            onChange={setTab}
            tabs={[
              { key: 'overview', label: 'Today', icon: LayoutDashboard },
              { key: 'mark', label: 'Mark Attendance', icon: CalendarCheck },
              { key: 'calendar', label: 'Calendar', icon: CalendarDays },
              { key: 'sessions', label: 'Sessions', icon: ClipboardList },
              { key: 'holidays', label: 'Holidays', icon: CalendarX2 },
            ]}
          />
          {tab === 'overview' && (
            <label className="flex items-center gap-2 pb-1 text-sm text-slate-500">
              <span className="whitespace-nowrap">Showing</span>
              <input type="date" value={date} max={todayIso()} onChange={(e) => e.target.value && update({ date: e.target.value })} className="form-input form-input-sm !w-auto" aria-label="Dashboard date" />
            </label>
          )}
        </div>
      </PageHeader>

      {tab === 'overview' && <AttendanceOverview date={date} onMark={openMark} onDevice={() => setDeviceOpen(true)} />}
      {tab === 'mark' && <MarkAttendance selection={selection} onChange={(s) => update({ ...s })} />}
      {tab === 'calendar' && <SectionCalendar section={selection.section} month={month} onChange={(p) => update(p)} onOpen={openMark} />}
      {tab === 'sessions' && (
        <CrudTable
          module="attendance"
          urlState
          title="Attendance sessions"
          addLabel="Mark Attendance"
          onCreate={() => openMark({})}
          onView={(r) => openMark({ date: String(r.attendance_date), section: Number(r.section_id), subject: Number(r.subject_id), slot: r.time_slot_id ? Number(r.time_slot_id) : null })}
          emptyTitle="No attendance marked yet"
          emptyText="Sessions appear here as soon as faculty mark a class."
          renderers={{
            percent: (r) => <PercentPill value={r.percent === null ? null : Number(r.percent)} />,
            class_label: (r) => <span className="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{r.class_label}</span>,
            subject_name: (r) => (
              <div className="max-w-[15rem] leading-tight">
                <p className="truncate font-medium text-slate-800 dark:text-slate-100" title={r.subject_name}>{r.subject_name}</p>
                <p className="text-xs text-slate-500">{r.subject_code}</p>
              </div>
            ),
            faculty_name: (r) => (r.faculty_name ? <div className="whitespace-nowrap"><PersonCell name={String(r.faculty_name)} src={r.faculty_photo} size="sm" /></div> : <span className="text-slate-400">—</span>),
            attendance_date: (r) => (
              <div className="whitespace-nowrap leading-tight">
                <div className="font-medium text-slate-800 dark:text-slate-100">{formatDate(r.attendance_date)}</div>
                <div className="text-xs text-slate-500">{r.slot_name ?? 'No period'}</div>
              </div>
            ),
          }}
          rowMenu={(r) => [
            { label: 'Open roster', icon: PenLine, onClick: () => openMark({ date: String(r.attendance_date), section: Number(r.section_id), subject: Number(r.subject_id), slot: r.time_slot_id ? Number(r.time_slot_id) : null }) },
            { label: 'Section calendar', icon: CalendarDays, onClick: () => update({ tab: 'calendar', section: r.section_id, month: String(r.attendance_date).slice(0, 7) }) },
            { label: 'Print monthly register', icon: Printer, href: printUrl('attendance-report.php', { report: 'monthly', section_id: r.section_id, month: String(r.attendance_date).slice(0, 7) }), target: '_blank' },
            can('attendance', 'approve') && (Number(r.is_locked) ? { label: 'Unlock session', icon: LockOpen, onClick: () => lockRow(r, false) } : { label: 'Lock session', icon: Lock, onClick: () => lockRow(r, true) }),
          ]}
          header={({ summary }) =>
            summary ? (
              <div className="flex flex-wrap gap-x-6 gap-y-2 border-b border-slate-100 px-4 py-3 text-sm dark:border-slate-800">
                <span className="text-slate-500">Sessions <strong className="text-slate-900 dark:text-white">{Number(summary.sessions).toLocaleString('en-IN')}</strong></span>
                <span className="text-slate-500">Sections <strong className="text-slate-900 dark:text-white">{String(summary.sections)}</strong></span>
                <span className="text-slate-500">Average attendance <strong className="text-emerald-600 dark:text-emerald-400">{summary.percent !== null ? `${summary.percent}%` : '—'}</strong></span>
                <span className="text-slate-500">Absences <strong className="text-red-600 dark:text-red-400">{Number(summary.absent).toLocaleString('en-IN')}</strong></span>
                <span className="text-slate-500">Locked <strong className="text-slate-900 dark:text-white">{String(summary.locked)}</strong></span>
              </div>
            ) : null
          }
        />
      )}
      {tab === 'holidays' && (
        <div className="space-y-4">
          <Card className="flex flex-col gap-1 p-4 text-sm text-slate-600 sm:flex-row sm:items-center sm:gap-3 dark:text-slate-300">
            <CalendarX2 className="hidden h-5 w-5 shrink-0 text-rose-500 sm:block" aria-hidden />
            <p>
              <strong className="text-slate-900 dark:text-white">Holidays</strong> and <strong className="text-slate-900 dark:text-white">vacations</strong> block student attendance marking.
              Exam breaks and institute events only show a warning. Sundays are weekly offs.
            </p>
          </Card>
          <CrudTable
            module="holidays"
            urlState
            title="Holiday calendar"
            addLabel="Add Holiday"
            emptyTitle="No holidays yet"
            emptyText="Add national holidays, vacations and exam breaks for the academic session."
            header={({ summary }) =>
              summary ? (
                <div className="flex flex-wrap gap-x-6 gap-y-2 border-b border-slate-100 px-4 py-3 text-sm dark:border-slate-800">
                  <span className="text-slate-500">Holidays <strong className="text-slate-900 dark:text-white">{String(summary.holidays ?? 0)}</strong></span>
                  <span className="text-slate-500">Vacations <strong className="text-slate-900 dark:text-white">{String(summary.vacations ?? 0)}</strong></span>
                  <span className="text-slate-500">Exam breaks <strong className="text-slate-900 dark:text-white">{String(summary.exam_breaks ?? 0)}</strong></span>
                  <span className="text-slate-500">Closed days <strong className="text-slate-900 dark:text-white">{String(summary.closed_days ?? 0)}</strong></span>
                  {!!summary.next && typeof summary.next === 'object' && (
                    <span className="text-slate-500">
                      Next: <strong className="text-brand-700 dark:text-brand-300">{String((summary.next as Row).title)}</strong> · {formatDate((summary.next as Row).holiday_date)}
                    </span>
                  )}
                </div>
              ) : null
            }
          />
        </div>
      )}

      <DeviceIntegrationModal open={deviceOpen} onClose={() => setDeviceOpen(false)} />
    </>
  );
}
