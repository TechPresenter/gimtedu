import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  BriefcaseBusiness, CalendarDays, CheckCheck, ClipboardList, Clock3, Download, FileSpreadsheet, FileText, Printer, Save, Undo2, UserCheck, UserMinus, Users, UserX, Hourglass,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, Dropdown, EmptyState, Input, PageHeader, Pagination, SearchInput, Select, Skeleton, StatCard, Stagger, Tabs, useConfirm, useToast,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, downloadFile, type ApiError } from '@/lib/api';
import { useApi, useInvalidate, useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatDate, labelize, timeAgo } from '@/lib/format';
import { printUrl } from '@/lib/config';
import type { EmployeeRegisterPayload, EmployeeSheetPayload, EmployeeStatus } from './types';
import { currentMonth, DayBanner, EMPLOYEE_STATUSES, MiniStat, monthLabel, MonthNav, PercentPill, STATUS_META, StatusSegment, todayIso } from './components/shared';
import { RegisterGrid, RegisterLegend, type RegisterCell } from './components/RegisterGrid';

const TABS = ['daily', 'register', 'records'] as const;
type Tab = (typeof TABS)[number];
type EmpType = 'all' | 'faculty' | 'staff';

interface Entry {
  status: EmployeeStatus | null;
  in_time: string;
  out_time: string;
  remarks: string;
}

const TYPE_TABS = [
  { key: 'all', label: 'All' },
  { key: 'faculty', label: 'Faculty' },
  { key: 'staff', label: 'Staff' },
];

export default function EmployeeAttendancePage() {
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'daily';
  const setTab = (t: string) => {
    const next = new URLSearchParams(params);
    next.set('tab', t);
    ['page', 'q'].forEach((k) => next.delete(k));
    [...next.keys()].filter((k) => k.startsWith('f.')).forEach((k) => next.delete(k));
    setParams(next);
  };
  return (
    <>
      <PageHeader
        title="Faculty & Staff Attendance"
        description="Daily attendance with in/out times for teaching and non-teaching employees, plus the monthly register."
        breadcrumbs={[{ label: 'Attendance', to: '/attendance' }, { label: 'Faculty & Staff' }]}
        actions={
          <Button variant="secondary" icon={Users} to="/attendance">
            Student Attendance
          </Button>
        }
      >
        <Tabs
          value={tab}
          onChange={setTab}
          tabs={[
            { key: 'daily', label: 'Daily Attendance', icon: UserCheck },
            { key: 'register', label: 'Monthly Register', icon: CalendarDays },
            { key: 'records', label: 'Records Log', icon: ClipboardList },
          ]}
        />
      </PageHeader>
      {tab === 'daily' && <DailyAttendance />}
      {tab === 'register' && <MonthlyRegister />}
      {tab === 'records' && (
        <CrudTable
          module="attendance_records"
          urlState
          title="Attendance records"
          description="Every individual entry — correct a status, in/out time or remarks. Use the Type filter to switch between faculty, staff and students."
          defaultFilters={{ person_type: 'faculty' }}
          addLabel="Mark Attendance"
          onCreate={() => setTab('daily')}
          renderers={{ class_label: (r) => <span className="whitespace-nowrap">{r.class_label}</span>, remarks: (r) => (r.remarks ? <span className="line-clamp-1 max-w-[14rem] text-sm" title={r.remarks}>{r.remarks}</span> : <span className="text-slate-400">—</span>) }}
          emptyTitle="No attendance records"
          emptyText="Records appear once attendance is marked."
        />
      )}
    </>
  );
}

/* ---------------------------------------------------------------- Daily marking */
function DailyAttendance() {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const date = /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '') ? (params.get('date') as string) : todayIso();
  const type = (['all', 'faculty', 'staff'].includes(params.get('type') ?? '') ? params.get('type') : 'all') as EmpType;
  const department = params.get('department') ?? '';
  const [search, setSearch] = useState('');
  const q = useDebounce(search, 350);
  const depts = useLookup('departments');
  const sheet = useApi<EmployeeSheetPayload>(['attendance', 'employees', 'sheet', date, type, department, q], 'attendance/employees/sheet', { date, type, department_id: department || undefined, q: q || undefined });
  const data = sheet.data;
  const [entries, setEntries] = useState<Record<string, Entry>>({});
  const [initial, setInitial] = useState<Record<string, Entry>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!data) return;
    const e: Record<string, Entry> = {};
    data.people.forEach((p) => (e[`${p.person_type}:${p.person_id}`] = { status: p.status, in_time: p.in_time ?? '', out_time: p.out_time ?? '', remarks: p.remarks ?? '' }));
    setEntries(e);
    setInitial(e);
    setErrors({});
  }, [data]);

  const dirtyKeys = useMemo(() => Object.keys(entries).filter((k) => JSON.stringify(entries[k]) !== JSON.stringify(initial[k])), [entries, initial]);
  const counts = useMemo(() => {
    const c: Record<EmployeeStatus | 'unmarked', number> = { present: 0, absent: 0, late: 0, leave: 0, half_day: 0, unmarked: 0 };
    Object.values(entries).forEach((e) => c[e.status ?? 'unmarked']++);
    return c;
  }, [entries]);

  const setParam = async (key: string, value: string) => {
    if (dirtyKeys.length) {
      const ok = await confirm({ title: 'Discard unsaved changes?', message: `You have ${dirtyKeys.length} unsaved change(s). Changing the view will discard them.`, confirmText: 'Discard changes', danger: true });
      if (!ok) return;
    }
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  const update = (key: string, patch: Partial<Entry>) =>
    setEntries((e) => {
      const cur = e[key] ?? { status: null, in_time: '', out_time: '', remarks: '' };
      const n = { ...cur, ...patch };
      if (patch.status && ['present', 'late', 'half_day'].includes(patch.status) && !n.in_time) n.in_time = patch.status === 'late' ? '09:30' : '09:00';
      if (patch.status && ['absent', 'leave'].includes(patch.status)) {
        n.in_time = '';
        n.out_time = '';
      }
      return { ...e, [key]: n };
    });

  const markAllPresent = () =>
    setEntries((e) => {
      const n = { ...e };
      data?.people.forEach((p) => {
        const k = `${p.person_type}:${p.person_id}`;
        if (n[k]?.status) return;
        n[k] = p.leave ? { status: 'leave', in_time: '', out_time: '', remarks: `Approved ${p.leave.type} leave` } : { ...n[k], status: 'present', in_time: n[k]?.in_time || '09:00', out_time: n[k]?.out_time ?? '', remarks: n[k]?.remarks ?? '' };
      });
      return n;
    });

  const save = async () => {
    const changed = dirtyKeys.filter((k) => entries[k].status);
    if (!changed.length) {
      toast.info('There are no changes to save.');
      return;
    }
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post('attendance/employees/sheet', {
        date,
        records: changed.map((k) => {
          const [person_type, id] = k.split(':');
          const e = entries[k];
          return { person_type, person_id: Number(id), status: e.status, in_time: e.in_time || null, out_time: e.out_time || null, remarks: e.remarks || null };
        }),
      });
      toast.success(res.message);
      await invalidate('attendance');
    } catch (err) {
      const e = err as ApiError;
      setErrors(e.errors ?? {});
      toast.error(e.message || 'Unable to save attendance. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const canSave = !!data?.can_save;
  const sheets = data ? [data.sheets.faculty, data.sheets.staff].filter(Boolean) : [];
  const lastUpdate = sheets.map((s) => s?.updated_at ?? s?.created_at).filter(Boolean).sort().pop();

  return (
    <div className="space-y-5">
      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 xl:grid-cols-6" step={60}>
        {[
          <StatCard key="t" label="Employees" icon={BriefcaseBusiness} tone="navy" loading={sheet.isLoading} value={data?.total ?? 0} hint={type === 'all' ? 'Faculty & staff' : labelize(type)} />,
          <StatCard key="p" label="Present" icon={UserCheck} tone="green" loading={sheet.isLoading} value={counts.present} hint={`Late after ${data?.late_after ?? '09:15'}`} />,
          <StatCard key="l" label="Late" icon={Clock3} tone="amber" loading={sheet.isLoading} value={counts.late} />,
          <StatCard key="h" label="Half day" icon={Hourglass} tone="purple" loading={sheet.isLoading} value={counts.half_day} />,
          <StatCard key="lv" label="On leave" icon={UserMinus} tone="cyan" loading={sheet.isLoading} value={counts.leave} />,
          <StatCard key="a" label="Absent" icon={UserX} tone="red" loading={sheet.isLoading} value={counts.absent} hint={counts.unmarked ? `${counts.unmarked} not marked` : 'All marked'} />,
        ]}
      </Stagger>

      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center dark:border-slate-800">
          <Input type="date" value={date} max={todayIso()} onChange={(e) => e.target.value && setParam('date', e.target.value)} aria-label="Attendance date" className="lg:!w-44" inputSize="sm" />
          <Tabs variant="pills" tabs={TYPE_TABS} value={type} onChange={(v) => setParam('type', v === 'all' ? '' : v)} />
          <Select inputSize="sm" className="lg:!w-56" options={(depts.data ?? []).map((d) => ({ value: d.value, label: d.label }))} placeholder="All departments" value={department} onChange={(e) => setParam('department', e.target.value)} aria-label="Department" />
          <SearchInput size="sm" value={search} onChange={setSearch} placeholder="Search name or employee ID…" className="lg:ml-auto lg:w-64" />
        </div>

        {data && (data.day.blocked || data.day.warning) && <DayBanner day={data.day} className="m-4" />}

        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-2.5 text-sm dark:border-slate-800">
          <span className="text-slate-500 dark:text-slate-400">
            {formatDate(date)}
            {lastUpdate ? <> · last saved {timeAgo(lastUpdate)}{sheets[0]?.taken_by_name ? ` by ${sheets[0]?.taken_by_name}` : ''}</> : ' · not marked yet'}
          </span>
          {sheets.some((s) => s?.is_locked) && <Badge color="slate">Locked</Badge>}
          <div className="ml-auto flex flex-wrap gap-2">
            <Button size="sm" variant="success" icon={CheckCheck} disabled={!canSave || !counts.unmarked} onClick={markAllPresent}>
              Mark unmarked present
            </Button>
            <Button size="sm" variant="ghost" icon={Undo2} disabled={!dirtyKeys.length} onClick={() => setEntries(initial)}>
              Reset
            </Button>
          </div>
        </div>

        {errors.records && <Alert variant="error" className="m-4">{errors.records}</Alert>}

        {sheet.error ? (
          <div className="p-4"><Alert variant="error" title="Unable to load employees">{(sheet.error as ApiError).message}</Alert></div>
        ) : sheet.isLoading ? (
          <div className="space-y-3 p-4">{Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
        ) : !data?.people.length ? (
          <EmptyState icon={Users} title={q || department ? 'No matching employees' : 'No employees found'} description={q || department ? 'Try a different search or department.' : 'Add faculty and staff in the Faculty & Staff module.'} action={<Button variant="secondary" to="/faculty">Faculty & Staff</Button>} />
        ) : (
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            <div className="hidden grid-cols-[minmax(0,1.6fr)_auto_minmax(0,1.2fr)] gap-4 bg-slate-50/80 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 lg:grid dark:bg-slate-800/50 dark:text-slate-400">
              <span>Employee</span>
              <span>Status</span>
              <span>In / Out · Remarks</span>
            </div>
            {data.people.map((p) => {
              const k = `${p.person_type}:${p.person_id}`;
              const e = entries[k] ?? { status: null, in_time: '', out_time: '', remarks: '' };
              const err = errors[`records.${p.person_type}.${p.person_id}`];
              const dirty = dirtyKeys.includes(k);
              const timed = e.status && ['present', 'late', 'half_day'].includes(e.status);
              return (
                <div key={k} className={clsx('grid gap-3 px-4 py-3 lg:grid-cols-[minmax(0,1.6fr)_auto_minmax(0,1.2fr)] lg:items-center lg:gap-4', dirty && 'bg-brand-50/40 dark:bg-brand-500/5')}>
                  <div className="flex min-w-0 items-center gap-3">
                    <Avatar name={p.name} src={p.photo} />
                    <div className="min-w-0 leading-tight">
                      <p className="truncate font-semibold text-slate-900 dark:text-white">
                        {p.name}
                        <Badge color={p.person_type === 'faculty' ? 'purple' : 'cyan'} className="ml-2 align-middle !text-[10px]">{labelize(p.person_type)}</Badge>
                      </p>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">{p.employee_id} · {p.designation}{p.department ? ` · ${p.department}` : ''}</p>
                      {p.leave && <p className="mt-0.5 text-[11px] font-medium text-sky-600 dark:text-sky-300">On approved {p.leave.type} leave ({formatDate(p.leave.from)} – {formatDate(p.leave.to)})</p>}
                    </div>
                  </div>
                  <StatusSegment name={`emp-${k}`} label={`Attendance for ${p.name}`} value={e.status} onChange={(s) => update(k, { status: s })} statuses={EMPLOYEE_STATUSES} disabled={!canSave} invalid={!!err} compact />
                  <div className="grid grid-cols-[6.75rem_6.75rem_minmax(0,1fr)] gap-2">
                    <Input type="time" inputSize="sm" value={e.in_time} disabled={!canSave || !timed} onChange={(ev) => update(k, { in_time: ev.target.value })} aria-label={`In time for ${p.name}`} />
                    <Input type="time" inputSize="sm" value={e.out_time} disabled={!canSave || !timed} onChange={(ev) => update(k, { out_time: ev.target.value })} aria-label={`Out time for ${p.name}`} />
                    <Input inputSize="sm" value={e.remarks} maxLength={255} disabled={!canSave} placeholder="Remarks" onChange={(ev) => update(k, { remarks: ev.target.value })} aria-label={`Remarks for ${p.name}`} />
                  </div>
                  {err && <p className="form-error lg:col-span-3">{err}</p>}
                </div>
              );
            })}
          </div>
        )}

        <div className="sticky bottom-0 z-10 flex flex-col gap-3 border-t border-slate-200 bg-white/90 px-4 py-3 backdrop-blur sm:flex-row sm:items-center dark:border-slate-800 dark:bg-slate-900/90">
          <div className="flex flex-wrap gap-1.5 text-xs">
            {EMPLOYEE_STATUSES.map((s) => (
              <span key={s} className={clsx('rounded-full px-2 py-0.5 font-semibold', STATUS_META[s].soft)}>{STATUS_META[s].label} {counts[s]}</span>
            ))}
            {counts.unmarked > 0 && <span className="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Unmarked {counts.unmarked}</span>}
          </div>
          <div className="flex items-center gap-3 sm:ml-auto">
            {dirtyKeys.length > 0 && <span className="text-xs font-medium text-brand-700 dark:text-brand-300">{dirtyKeys.length} unsaved change{dirtyKeys.length === 1 ? '' : 's'}</span>}
            {canSave || can('attendance', 'create') ? (
              <Button icon={Save} loading={saving} disabled={!canSave || !dirtyKeys.length} onClick={save} className="w-full sm:w-auto">
                Save attendance
              </Button>
            ) : (
              <Badge color="slate">View only</Badge>
            )}
          </div>
        </div>
      </Card>
    </div>
  );
}

/* ---------------------------------------------------------------- Monthly register */
function MonthlyRegister() {
  const [params, setParams] = useSearchParams();
  const month = /^\d{4}-\d{2}$/.test(params.get('month') ?? '') ? (params.get('month') as string) : currentMonth();
  const type = (['all', 'faculty', 'staff'].includes(params.get('type') ?? '') ? params.get('type') : 'all') as EmpType;
  const department = params.get('department') ?? '';
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const q = useDebounce(search, 350);
  const depts = useLookup('departments');
  useEffect(() => setPage(1), [month, type, department, q, perPage]);
  const query = { month, type, department_id: department || undefined, q: q || undefined };
  const reg = useApi<EmployeeRegisterPayload>(['attendance', 'employees', 'register', month, type, department, q, page, perPage], 'attendance/employees/register', { ...query, page, per_page: perPage });
  const d = reg.data;
  const set = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };
  const rows = useMemo(
    () =>
      (d?.people ?? []).map((p) => {
        const cells: Record<number, RegisterCell> = {};
        Object.entries(p.cells).forEach(([day, st]) => (cells[Number(day)] = { label: STATUS_META[st].short, tone: st, title: STATUS_META[st].label }));
        return {
          key: `${p.person_type}:${p.person_id}`, name: p.name, sub: `${p.employee_id} · ${p.designation}`, photo: p.photo, cells,
          totals: [p.totals.present, p.totals.late, p.totals.half_day, p.totals.leave, p.totals.absent, <PercentPill key="pct" value={p.totals.percent} min={90} />],
        };
      }),
    [d],
  );
  const footerCells = useMemo(() => {
    const out: Record<number, string> = {};
    if (!d) return out;
    Object.entries(d.daily).forEach(([day, c]) => {
      const total = Object.values(c).reduce((a, b) => a + (b ?? 0), 0);
      if (total) out[Number(day)] = `${(c.present ?? 0) + (c.late ?? 0) + (c.half_day ?? 0)}`;
    });
    return out;
  }, [d]);

  return (
    <Card className="overflow-hidden">
      <CardHeader
        title={`Employee register · ${monthLabel(month)}`}
        subtitle="Day-wise status for every employee with monthly totals"
        icon={CalendarDays}
        actions={
          <div className="flex gap-2">
            <Dropdown
              label="Export register"
              trigger={<><Download className="h-4 w-4" /><span className="hidden sm:inline">Export</span></>}
              items={[
                { label: 'Export as CSV', icon: FileText, onClick: () => downloadFile('attendance/reports/export', { report: 'employees', format: 'csv', ...query }) },
                { label: 'Export as Excel', icon: FileSpreadsheet, onClick: () => downloadFile('attendance/reports/export', { report: 'employees', format: 'xlsx', ...query }) },
              ]}
            />
            <Button size="sm" variant="secondary" icon={Printer} href={printUrl('attendance-report.php', { report: 'employees', month, type, department_id: department || undefined, q: q || undefined })} target="_blank">
              <span className="hidden sm:inline">Print</span>
            </Button>
          </div>
        }
      />
      <div className="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center dark:border-slate-800">
        <MonthNav value={month} onChange={(m) => set('month', m)} max={currentMonth()} />
        <Tabs variant="pills" tabs={TYPE_TABS} value={type} onChange={(v) => set('type', v === 'all' ? '' : v)} />
        <Select inputSize="sm" className="lg:!w-56" options={(depts.data ?? []).map((x) => ({ value: x.value, label: x.label }))} placeholder="All departments" value={department} onChange={(e) => set('department', e.target.value)} aria-label="Department" />
        <SearchInput size="sm" value={search} onChange={setSearch} placeholder="Search employee…" className="lg:ml-auto lg:w-60" />
      </div>
      {d && (
        <div className="grid grid-cols-2 gap-4 border-b border-slate-100 px-4 py-3 sm:grid-cols-3 lg:grid-cols-6 dark:border-slate-800">
          <MiniStat label="Employees" value={d.summary.employees} />
          <MiniStat label="Attendance" value={d.summary.percent !== null ? `${d.summary.percent}%` : '—'} tone="green" />
          <MiniStat label="Present days" value={d.summary.present} />
          <MiniStat label="Late marks" value={d.summary.late} tone="amber" />
          <MiniStat label="Leave days" value={d.summary.leave} tone="blue" />
          <MiniStat label="Absences" value={d.summary.absent} tone="red" />
        </div>
      )}
      {reg.error ? (
        <div className="p-4"><Alert variant="error" title="Unable to load the register">{(reg.error as ApiError).message}</Alert></div>
      ) : reg.isLoading || !d ? (
        <div className="space-y-2 p-4">{Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}</div>
      ) : d.people.length === 0 ? (
        <EmptyState icon={Users} title="No employees match" description="Change the filters to see the register." />
      ) : (
        <>
          <RegisterGrid caption={`Employee attendance register for ${monthLabel(month)}`} days={d.days} rows={rows} totalHeaders={['P', 'L', 'HD', 'LV', 'A', '%']} footer={{ label: 'In on duty', cells: footerCells }} />
          <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
            <RegisterLegend items={[['P', 'present', 'Present'], ['L', 'late', 'Late'], ['HD', 'half_day', 'Half day'], ['LV', 'leave', 'Leave'], ['A', 'absent', 'Absent']]} />
          </div>
          <Pagination className="border-t border-slate-100 dark:border-slate-800" page={d.page} pages={d.pages} total={d.total} perPage={d.per_page} onPage={setPage} onPerPage={setPerPage} />
        </>
      )}
    </Card>
  );
}
