import { Fragment, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle, BellRing, BookOpen, Building2, CalendarDays, ChevronDown, ChevronRight, ClipboardCheck, GraduationCap, Percent, Send, TrendingDown, Users, UserX,
} from 'lucide-react';
import {
  Alert, Avatar, Button, Card, CardHeader, DataTable, EmptyState, Field, PageHeader, Pagination, Reveal, Select, Skeleton, StatCard, Stagger, Tabs, type Column, type SortState,
} from '@/components/ui';
import { useApi, useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatNumber } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import type { DepartmentReportPayload, MonthlyPayload, StudentReportPayload, StudentReportRow, SubjectReportPayload, SubjectReportRow } from './types';
import { filtersQuery, ReportExport, ReportFilterBar, useReportFilters, type ReportFilters } from './components/ReportFilters';
import { StudentDrawer } from './components/StudentDrawer';
import { AlertDialog } from './components/AlertDialog';
import { RegisterGrid, RegisterLegend, type RegisterCell } from './components/RegisterGrid';
import { CODE_STATUS, currentMonth, monthLabel, MonthNav, PercentBar, PercentPill, SectionPicker, useAttendanceOptions } from './components/shared';

const TABS = ['students', 'defaulters', 'department', 'subject', 'monthly'] as const;
type Tab = (typeof TABS)[number];

export default function AttendanceReportsPage() {
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as Tab) : 'students';
  const setTab = (t: string) => {
    const next = new URLSearchParams(params);
    next.set('tab', t);
    next.delete('page');
    setParams(next);
  };
  return (
    <>
      <PageHeader
        title="Attendance Reports"
        description="Student attendance percentages, defaulters, department and subject analysis and the monthly register — export or print any view."
        breadcrumbs={[{ label: 'Attendance', to: '/attendance' }, { label: 'Reports' }]}
        actions={<Button variant="secondary" icon={ClipboardCheck} to="/attendance">Student Attendance</Button>}
      >
        <Tabs
          value={tab}
          onChange={setTab}
          tabs={[
            { key: 'students', label: 'Student-wise', icon: GraduationCap },
            { key: 'defaulters', label: 'Defaulters', icon: UserX },
            { key: 'department', label: 'Department', icon: Building2 },
            { key: 'subject', label: 'Subject', icon: BookOpen },
            { key: 'monthly', label: 'Monthly Register', icon: CalendarDays },
          ]}
        />
      </PageHeader>
      {tab === 'students' && <StudentsReport key="students" defaulters={false} />}
      {tab === 'defaulters' && <StudentsReport key="defaulters" defaulters />}
      {tab === 'department' && <DepartmentReport />}
      {tab === 'subject' && <SubjectReport />}
      {tab === 'monthly' && <MonthlyReport />}
    </>
  );
}

function usePage() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page') ?? 1) || 1);
  const setPage = (p: number) => {
    const next = new URLSearchParams(params);
    if (p > 1) next.set('page', String(p));
    else next.delete('page');
    setParams(next, { replace: true });
  };
  return [page, setPage] as const;
}

const COMPOSITION = [
  { key: 'present', label: 'Present', cls: 'bg-emerald-500' },
  { key: 'late', label: 'Late', cls: 'bg-amber-400' },
  { key: 'leave', label: 'Leave', cls: 'bg-sky-500' },
  { key: 'absent', label: 'Absent', cls: 'bg-red-500' },
] as const;

const pct = (n: number, total: number) => (total ? Math.round((n / total) * 1000) / 10 : 0);

function periodLabel(f: { from: string; to: string } | undefined) {
  return f ? `${formatDate(f.from)} – ${formatDate(f.to)}` : '';
}

/* ---------------------------------------------------------------- Student-wise & defaulters */
function StudentsReport({ defaulters }: { defaulters: boolean }) {
  const { can } = useAuth();
  const opts = useAttendanceOptions();
  const [f, set, clear, patch] = useReportFilters();
  const [page, setPage] = usePage();
  const [perPage, setPerPage] = useState(25);
  const [sort, setSort] = useState<SortState | null>(defaulters ? { key: 'percent', dir: 'asc' } : null);
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [params, setParams] = useSearchParams();
  const drawer = /^\d+$/.test(params.get('student') ?? '') ? Number(params.get('student')) : null;
  const setDrawer = (id: number | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('student', String(id));
    else next.delete('student');
    setParams(next, { replace: true });
  };
  const [alertFor, setAlertFor] = useState<{ students: { id: number; name: string; percent: number | null }[] | null } | null>(null);
  const query = filtersQuery(f);
  const q = useApi<StudentReportPayload>(['attendance', 'report', defaulters ? 'defaulters' : 'students', query, page, perPage, sort], `attendance/reports/${defaulters ? 'defaulters' : 'students'}`, { ...query, page, per_page: perPage, sort: sort?.key, dir: sort?.dir }, { placeholderData: (prev) => prev });
  const d = q.data;
  const min = opts.data?.min_percent ?? 75;
  const threshold = d?.summary.threshold ?? min;
  useEffect(() => setSelected(new Set()), [JSON.stringify(query)]); // eslint-disable-line react-hooks/exhaustive-deps

  const columns: Column<StudentReportRow>[] = [
    { key: 'name', header: 'Student', sortable: true, render: (r) => (
      <button type="button" className="flex min-w-0 items-center gap-3 text-left" onClick={() => setDrawer(r.id)}>
        <Avatar name={r.name} src={r.photo} />
        <span className="min-w-0 leading-tight">
          <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.name}</span>
          <span className="block truncate text-xs text-slate-500">{r.roll_no ?? '—'} · {r.student_uid}</span>
        </span>
      </button>
    ) },
    { key: 'class', header: 'Class', sortable: true, render: (r) => <span className="whitespace-nowrap">{r.class_label ?? '—'}</span> },
    { key: 'held', header: 'Classes', sortable: true, align: 'center', render: (r) => formatNumber(r.held) },
    { key: 'present', header: 'Present', sortable: true, align: 'center', hidden: defaulters, render: (r) => <span className="text-emerald-700 dark:text-emerald-400">{r.present}</span> },
    { key: 'absent', header: 'Absent', sortable: true, align: 'center', render: (r) => <span className="text-red-600 dark:text-red-400">{r.absent}</span> },
    { key: 'late', header: 'Late / Leave', sortable: true, align: 'center', hidden: defaulters, render: (r) => <span className="whitespace-nowrap tabular-nums text-slate-600 dark:text-slate-300">{r.late} / {r.leave}</span> },
    { key: 'percent', header: 'Attendance', sortable: true, render: (r) => <PercentBar value={r.percent} min={threshold} /> },
    { key: 'shortfall', header: 'To recover', align: 'center', hidden: !defaulters, render: (r) => (r.shortfall ? <span className="whitespace-nowrap text-xs font-semibold text-amber-700 dark:text-amber-300">{r.shortfall} classes</span> : '—') },
    { key: 'last_alert', header: 'Last alert', hidden: !defaulters, render: (r) => (r.last_alert ? <span className="whitespace-nowrap text-xs">{formatDate(r.last_alert)}</span> : <span className="text-xs text-slate-400">Never</span>) },
  ];
  const selectedRows = (d?.rows ?? []).filter((r) => selected.has(r.id));
  const s = d?.summary;

  return (
    <div className="space-y-5">
      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={60}>
        {[
          <StatCard key="s" label={defaulters ? 'Defaulters' : 'Students'} icon={defaulters ? UserX : Users} tone={defaulters ? 'red' : 'blue'} loading={!d} value={s?.students ?? 0} hint={defaulters ? `Below ${threshold}% attendance` : periodLabel(d?.filters)} />,
          <StatCard key="p" label={defaulters ? 'Lowest attendance' : 'Average attendance'} icon={defaulters ? TrendingDown : Percent} tone={defaulters ? 'amber' : 'green'} loading={!d} value={defaulters ? (s?.lowest !== null && s?.lowest !== undefined ? `${s.lowest.toFixed(1)}%` : '—') : s?.percent !== null && s?.percent !== undefined ? `${s.percent.toFixed(1)}%` : '—'} hint={defaulters ? 'Most at-risk student' : `Minimum required ${min}%`} />,
          <StatCard key="b" label={defaulters ? 'Group average' : `Below ${threshold}%`} icon={defaulters ? Percent : AlertTriangle} tone={defaulters ? 'purple' : 'red'} loading={!d} value={defaulters ? (s?.percent ? `${s.percent.toFixed(1)}%` : '—') : s?.below ?? 0} hint={defaulters ? 'Average of the listed students' : 'Students at risk of detention'} />,
          <StatCard key="c" label="Classes held" icon={ClipboardCheck} tone="navy" loading={!d} value={s?.sessions ?? 0} hint="Sessions in the selected scope" />,
        ]}
      </Stagger>

      <Card className="overflow-hidden">
        <CardHeader
          title={defaulters ? 'Attendance defaulters' : 'Student-wise attendance'}
          subtitle={d ? `${formatNumber(d.total)} students · ${periodLabel(d.filters)}${d.source === 'summary' ? '' : ' · live calculation'}` : 'Loading…'}
          icon={defaulters ? UserX : GraduationCap}
          actions={<ReportExport report={defaulters ? 'defaulters' : 'students'} query={{ ...query, sort: sort?.key, dir: sort?.dir }} disabled={!d?.total} />}
        />
        <div className="border-b border-slate-100 p-4 dark:border-slate-800">
          <ReportFilterBar filters={f} set={set} clear={clear} patch={patch} show={{ subject: true, threshold: defaulters }} defaultThreshold={min} />
        </div>
        {defaulters && can('attendance', 'manage') && d && d.total > 0 && (
          <div className="flex flex-col gap-2 border-b border-amber-100 bg-amber-50/60 px-4 py-2.5 text-sm sm:flex-row sm:items-center dark:border-amber-500/20 dark:bg-amber-500/5">
            <span className="text-amber-800 dark:text-amber-200">
              <BellRing className="mr-1.5 inline h-4 w-4" />
              {selected.size ? `${selected.size} selected` : 'Select students to alert, or alert every defaulter in this view.'}
            </span>
            <div className="flex gap-2 sm:ml-auto">
              <Button size="sm" variant="secondary" icon={Send} disabled={!selected.size} onClick={() => setAlertFor({ students: selectedRows.map((r) => ({ id: r.id, name: r.name, percent: r.percent })) })}>
                Alert selected
              </Button>
              <Button size="sm" icon={BellRing} onClick={() => setAlertFor({ students: null })}>
                Alert all {formatNumber(d.total)}
              </Button>
            </div>
          </div>
        )}
        {q.error ? (
          <div className="p-4"><Alert variant="error" title="Unable to load the report">{(q.error as ApiError).message}</Alert></div>
        ) : (
          <DataTable<StudentReportRow>
            columns={columns}
            rows={d?.rows ?? []}
            loading={q.isFetching}
            sort={sort}
            onSort={(st) => { setSort(st); setPage(1); }}
            selectable={defaulters && can('attendance', 'manage')}
            selected={selected}
            onSelectedChange={setSelected}
            onRowClick={(r) => setDrawer(r.id)}
            caption={defaulters ? 'Attendance defaulters' : 'Student attendance'}
            empty={
              <EmptyState
                icon={defaulters ? ClipboardCheck : Users}
                title={defaulters ? 'No defaulters' : 'No attendance in this scope'}
                description={defaulters ? `Every student in this view is at or above ${threshold}% attendance.` : 'Change the period or filters — attendance appears once classes are marked.'}
                action={Object.keys(f).length ? <Button variant="secondary" onClick={clear}>Clear filters</Button> : undefined}
              />
            }
          />
        )}
        {d && d.total > 0 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={d.page} pages={d.pages} total={d.total} perPage={d.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />}
      </Card>

      <StudentDrawer studentId={drawer} query={query} onClose={() => setDrawer(null)} onAlert={(st) => setAlertFor({ students: [st] })} />
      {alertFor && (
        <AlertDialog
          open
          onClose={() => setAlertFor(null)}
          students={alertFor.students}
          total={d?.total ?? 0}
          threshold={alertFor.students ? Math.max(threshold, min) : threshold}
          query={{ ...query, threshold: alertFor.students && !defaulters ? String(Math.max(threshold, min)) : query.threshold }}
          onSent={() => setSelected(new Set())}
        />
      )}
    </div>
  );
}

/* ---------------------------------------------------------------- Department */
function DepartmentReport() {
  const [f, set, clear, patch] = useReportFilters();
  const [open, setOpen] = useState<Set<number>>(new Set());
  const query = filtersQuery(f);
  const q = useApi<DepartmentReportPayload>(['attendance', 'report', 'department', query], 'attendance/reports/department', query, { placeholderData: (prev) => prev });
  const d = q.data;
  const s = d?.summary;
  const toggle = (id: number) => setOpen((o) => { const n = new Set(o); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  return (
    <div className="space-y-5">
      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={60}>
        {[
          <StatCard key="d" label="Departments" icon={Building2} tone="navy" loading={!d} value={s?.departments ?? 0} />,
          <StatCard key="p" label="Average attendance" icon={Percent} tone="green" loading={!d} value={s?.percent !== null && s?.percent !== undefined ? `${s.percent}%` : '—'} hint={periodLabel(d?.filters)} />,
          <StatCard key="s" label="Students" icon={Users} tone="blue" loading={!d} value={s?.students ?? 0} />,
          <StatCard key="b" label={`Below ${s?.threshold ?? 75}%`} icon={UserX} tone="red" loading={!d} value={s?.below ?? 0} />,
        ]}
      </Stagger>
      <Card className="overflow-hidden">
        <CardHeader title="Department-wise attendance" subtitle={d ? periodLabel(d.filters) : 'Loading…'} icon={Building2} actions={<ReportExport report="department" query={query} disabled={!d?.rows.length} />} />
        <div className="border-b border-slate-100 p-4 dark:border-slate-800">
          <ReportFilterBar filters={f} set={set} clear={clear} patch={patch} show={{ search: false, section: false }} />
        </div>
        {q.error ? (
          <div className="p-4"><Alert variant="error">{(q.error as ApiError).message}</Alert></div>
        ) : !d ? (
          <div className="space-y-3 p-5"><Skeleton className="h-56 w-full" /><Skeleton className="h-40 w-full" /></div>
        ) : d.rows.length === 0 ? (
          <EmptyState icon={Building2} title="No attendance in this period" description="Change the period or filters." action={<Button variant="secondary" onClick={clear}>Clear filters</Button>} />
        ) : (
          <>
            <Reveal className="border-b border-slate-100 p-4 sm:p-5 dark:border-slate-800">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">Attendance composition by department</p>
                <div className="flex flex-wrap gap-3 text-xs text-slate-500 dark:text-slate-400">
                  {COMPOSITION.map((c) => (
                    <span key={c.key} className="inline-flex items-center gap-1.5"><span className={clsx('h-2.5 w-2.5 rounded-full', c.cls)} />{c.label}</span>
                  ))}
                </div>
              </div>
              <ul className="space-y-3">
                {d.rows.map((r) => (
                  <li key={r.id} className="grid grid-cols-[3.5rem_minmax(0,1fr)_3.5rem] items-center gap-3 text-sm sm:grid-cols-[12rem_minmax(0,1fr)_4rem]">
                    <span className="truncate font-medium text-slate-700 dark:text-slate-200" title={r.name}><span className="sm:hidden">{r.code}</span><span className="hidden sm:inline">{r.name}</span></span>
                    <div className="flex h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" aria-label={`${r.name}: ${COMPOSITION.map((c) => `${c.label} ${pct(r[c.key], r.held)}%`).join(', ')}`}>
                      {COMPOSITION.map((c) => (
                        <span key={c.key} className={c.cls} style={{ width: `${pct(r[c.key], r.held)}%` }} title={`${c.label} ${pct(r[c.key], r.held)}%`} />
                      ))}
                    </div>
                    <span className="text-right font-semibold tabular-nums text-slate-900 dark:text-white">{r.percent?.toFixed(1)}%</span>
                  </li>
                ))}
              </ul>
            </Reveal>
            <div className="table-wrap">
              <table className="data-table">
                <caption className="sr-only">Department attendance</caption>
                <thead>
                  <tr>
                    <th>Department</th>
                    <th className="text-center">Students</th>
                    <th className="text-center">Classes</th>
                    <th className="text-center">Absences</th>
                    <th>Attendance</th>
                    <th className="text-center">Below {d.summary.threshold}%</th>
                  </tr>
                </thead>
                <tbody>
                  {d.rows.map((r) => (
                    <Fragment key={r.id}>
                      <tr className="cursor-pointer" onClick={() => toggle(r.id)}>
                        <td>
                          <button type="button" className="flex items-center gap-2 text-left" aria-expanded={open.has(r.id)}>
                            {open.has(r.id) ? <ChevronDown className="h-4 w-4 text-slate-400" /> : <ChevronRight className="h-4 w-4 text-slate-400" />}
                            <span className="leading-tight">
                              <span className="block font-semibold text-slate-900 dark:text-white">{r.name}</span>
                              <span className="block text-xs text-slate-500">{r.code} · {r.programs.length} programs</span>
                            </span>
                          </button>
                        </td>
                        <td className="text-center tabular-nums">{formatNumber(r.students)}</td>
                        <td className="text-center tabular-nums">{formatNumber(r.sessions)}</td>
                        <td className="text-center tabular-nums text-red-600 dark:text-red-400">{formatNumber(r.absent)}</td>
                        <td><PercentBar value={r.percent} min={d.summary.threshold} /></td>
                        <td className="text-center"><span className={clsx('font-semibold tabular-nums', r.below ? 'text-red-600 dark:text-red-400' : 'text-slate-400')}>{r.below}</span></td>
                      </tr>
                      {open.has(r.id) &&
                        r.programs.map((p) => (
                          <tr key={`p${p.id}`} className="bg-slate-50/60 dark:bg-slate-800/30">
                            <td className="!py-2 !pl-12 text-sm text-slate-600 dark:text-slate-300">{p.short_name} <span className="text-xs text-slate-400">· {p.name}</span></td>
                            <td className="!py-2 text-center text-sm tabular-nums">{formatNumber(p.students)}</td>
                            <td className="!py-2 text-center text-sm tabular-nums">{formatNumber(p.sessions)}</td>
                            <td className="!py-2" />
                            <td className="!py-2"><PercentBar value={p.percent} min={d.summary.threshold} /></td>
                            <td className="!py-2" />
                          </tr>
                        ))}
                    </Fragment>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </Card>
    </div>
  );
}

/* ---------------------------------------------------------------- Subject */
function SubjectReport() {
  const [f, set, clear, patch] = useReportFilters();
  const [page, setPage] = usePage();
  const [perPage, setPerPage] = useState(25);
  const [sort, setSort] = useState<SortState | null>(null);
  const query = filtersQuery(f);
  const q = useApi<SubjectReportPayload>(['attendance', 'report', 'subject', query, page, perPage, sort], 'attendance/reports/subject', { ...query, page, per_page: perPage, sort: sort?.key, dir: sort?.dir }, { placeholderData: (prev) => prev });
  const d = q.data;
  const s = d?.summary;
  const threshold = s?.threshold ?? 75;
  const columns: Column<SubjectReportRow>[] = [
    { key: 'code', header: 'Subject', sortable: true, render: (r) => (
      <div className="min-w-0 leading-tight">
        <p className="max-w-[16rem] truncate font-semibold text-slate-900 dark:text-white" title={r.name}>{r.name}</p>
        <p className="text-xs text-slate-500">{r.code}</p>
      </div>
    ) },
    { key: 'section', header: 'Class', sortable: true, render: (r) => <span className="whitespace-nowrap">{r.section_label}</span> },
    { key: 'faculty', header: 'Faculty', sortable: true, render: (r) => <span className="whitespace-nowrap">{r.faculty_name ?? '—'}</span> },
    { key: 'sessions', header: 'Classes', sortable: true, align: 'center', render: (r) => r.sessions },
    { key: 'absent', header: 'Absences', sortable: true, align: 'center', render: (r) => <span className="text-red-600 dark:text-red-400">{formatNumber(r.absent)}</span> },
    { key: 'percent', header: 'Attendance', sortable: true, render: (r) => <PercentBar value={r.percent} min={threshold} /> },
    { key: 'below', header: `Below ${threshold}%`, align: 'center', render: (r) => <span className={clsx('font-semibold tabular-nums', r.below ? 'text-red-600 dark:text-red-400' : 'text-slate-400')}>{r.below ?? '—'}</span> },
    { key: 'last_date', header: 'Last class', render: (r) => <span className="whitespace-nowrap text-xs">{formatDate(r.last_date)}</span> },
  ];
  return (
    <div className="space-y-5">
      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={60}>
        {[
          <StatCard key="s" label="Subject classes" icon={BookOpen} tone="navy" loading={!d} value={s?.subjects ?? 0} hint="Subject × section combinations" />,
          <StatCard key="p" label="Average attendance" icon={Percent} tone="green" loading={!d} value={s?.percent !== null && s?.percent !== undefined ? `${s.percent}%` : '—'} hint={periodLabel(d?.filters)} />,
          <StatCard key="c" label="Classes held" icon={ClipboardCheck} tone="blue" loading={!d} value={s?.sessions ?? 0} />,
          <StatCard key="b" label={`Subjects below ${threshold}%`} icon={TrendingDown} tone="red" loading={!d} value={s?.below_subjects ?? 0} hint={s?.lowest !== null && s?.lowest !== undefined ? `Lowest ${s.lowest}%` : undefined} />,
        ]}
      </Stagger>
      <Card className="overflow-hidden">
        <CardHeader title="Subject-wise attendance" subtitle={d ? `${d.total} subject classes · ${periodLabel(d.filters)}` : 'Loading…'} icon={BookOpen} actions={<ReportExport report="subject" query={{ ...query, sort: sort?.key, dir: sort?.dir }} disabled={!d?.total} />} />
        <div className="border-b border-slate-100 p-4 dark:border-slate-800">
          <ReportFilterBar filters={f} set={set} clear={clear} patch={patch} show={{ subject: true }} searchPlaceholder="Search subject, class or faculty…" />
        </div>
        {q.error ? (
          <div className="p-4"><Alert variant="error">{(q.error as ApiError).message}</Alert></div>
        ) : (
          <DataTable<SubjectReportRow>
            columns={columns}
            rows={d?.rows ?? []}
            rowKey={(r) => r.key}
            loading={q.isFetching}
            sort={sort}
            onSort={(st) => { setSort(st); setPage(1); }}
            caption="Subject attendance"
            empty={<EmptyState icon={BookOpen} title="No subject attendance" description="Change the period or filters." action={Object.keys(f).length ? <Button variant="secondary" onClick={clear}>Clear filters</Button> : undefined} />}
          />
        )}
        {d && d.total > 0 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={d.page} pages={d.pages} total={d.total} perPage={d.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />}
      </Card>
    </div>
  );
}

/* ---------------------------------------------------------------- Monthly register */
function MonthlyReport() {
  const [params, setParams] = useSearchParams();
  const section = /^\d+$/.test(params.get('section_id') ?? '') ? Number(params.get('section_id')) : null;
  const month = /^\d{4}-\d{2}$/.test(params.get('month') ?? '') ? (params.get('month') as string) : currentMonth();
  const subject = params.get('subject_id') ?? '';
  const opts = useAttendanceOptions();
  const sectionOpt = opts.data?.sections.find((s) => s.value === section);
  const subjects = useLookup('subjects', { program_id: sectionOpt?.program_id, semester_no: sectionOpt?.semester_no }, !!sectionOpt);
  const set = (patch: Record<string, string | number | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, String(v))));
    setParams(next, { replace: true });
  };
  const query: ReportFilters & { month: string } = { section_id: section ? String(section) : undefined, subject_id: subject || undefined, month };
  const q = useApi<MonthlyPayload>(['attendance', 'report', 'monthly', section, month, subject], 'attendance/reports/monthly', query, { enabled: !!section, placeholderData: (prev) => prev });
  const d = q.data;
  const rows = useMemo(
    () =>
      (d?.students ?? []).map((st) => {
        const cells: Record<number, RegisterCell> = {};
        Object.entries(st.cells).forEach(([day, c]) => {
          const single = CODE_STATUS[c.v];
          cells[Number(day)] = single
            ? { label: c.v, tone: single, title: `${c.v}` }
            : { label: c.v, tone: c.a === c.h ? 'present' : c.a === 0 ? 'absent' : c.a / c.h < 0.5 ? 'low' : 'partial', title: `${c.a} of ${c.h} classes attended` };
        });
        return { key: st.id, name: st.name, sub: `${st.roll_no ?? '—'} · ${st.student_uid}`, photo: st.photo, cells, totals: [st.held, st.attended, <PercentPill key="p" value={st.percent} min={d?.min_percent} />] };
      }),
    [d],
  );
  const footerCells = useMemo(() => {
    const out: Record<number, string> = {};
    if (d) Object.entries(d.daily).forEach(([day, v]) => v !== null && (out[Number(day)] = `${v}`));
    return out;
  }, [d]);

  return (
    <div className="space-y-5">
      <Card className="p-4 sm:p-5">
        <div className="grid gap-4 md:grid-cols-[minmax(0,1.3fr)_auto_minmax(0,1fr)] md:items-end">
          <Field label="Class / section" htmlFor="mr-section">
            <SectionPicker id="mr-section" value={section} onChange={(v) => set({ section_id: v, subject_id: null })} />
          </Field>
          <div>
            <span className="form-label">Month</span>
            <MonthNav value={month} onChange={(m) => set({ month: m })} max={currentMonth()} />
          </div>
          <Field label="Subject" htmlFor="mr-subject">
            <Select id="mr-subject" options={(subjects.data ?? []).map((o) => ({ value: o.value, label: o.label }))} placeholder="All subjects (attended / held)" value={subject} disabled={!section} onChange={(e) => set({ subject_id: e.target.value })} />
          </Field>
        </div>
      </Card>

      {!section ? (
        <Card>
          <EmptyState icon={CalendarDays} title="Choose a class" description="The monthly register lists every student against each day of the month, with totals and percentages — ready to print." />
        </Card>
      ) : q.error ? (
        <Alert variant="error" title="Unable to load the register">{(q.error as ApiError).message}</Alert>
      ) : (
        <Card className="overflow-hidden">
          <CardHeader
            title={d ? `${d.section.label} · ${monthLabel(month)}` : monthLabel(month)}
            subtitle={d ? (d.subject ? `${d.subject.code} · ${d.subject.name}` : 'All subjects — cells show classes attended / held') : 'Loading…'}
            icon={CalendarDays}
            actions={<ReportExport report="monthly" query={query} disabled={!d?.students.length} />}
          />
          {d && (
            <div className="grid grid-cols-2 gap-4 border-b border-slate-100 px-4 py-3 sm:grid-cols-5 dark:border-slate-800">
              <div><p className="text-xs text-slate-500">Students</p><p className="font-display text-lg font-bold text-slate-900 dark:text-white">{d.summary.students}</p></div>
              <div><p className="text-xs text-slate-500">Class days</p><p className="font-display text-lg font-bold text-slate-900 dark:text-white">{d.summary.days}</p></div>
              <div><p className="text-xs text-slate-500">Classes held</p><p className="font-display text-lg font-bold text-slate-900 dark:text-white">{d.summary.sessions}</p></div>
              <div><p className="text-xs text-slate-500">Average</p><p className="font-display text-lg font-bold text-emerald-600 dark:text-emerald-400">{d.summary.percent !== null ? `${d.summary.percent}%` : '—'}</p></div>
              <div><p className="text-xs text-slate-500">Below {d.min_percent}%</p><p className="font-display text-lg font-bold text-red-600 dark:text-red-400">{d.summary.below}</p></div>
            </div>
          )}
          {q.isLoading || !d ? (
            <div className="space-y-2 p-4">{Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}</div>
          ) : d.students.length === 0 ? (
            <EmptyState icon={Users} title="No students in this section" />
          ) : d.summary.sessions === 0 ? (
            <EmptyState icon={CalendarDays} title={`No attendance in ${monthLabel(month)}`} description="Pick another month or mark attendance for this class." action={<Button variant="secondary" to={`/attendance?tab=mark&section=${section}`}>Mark attendance</Button>} />
          ) : (
            <>
              <RegisterGrid caption={`Monthly attendance register for ${d.section.label}`} days={d.days} rows={rows} totalHeaders={['Held', 'Att.', '%']} footer={{ label: 'Day attendance %', cells: footerCells, totals: [d.summary.sessions, '', d.summary.percent !== null ? `${d.summary.percent}%` : ''] }} />
              <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
                <RegisterLegend items={[['P', 'present', 'Present'], ['A', 'absent', 'Absent'], ['L', 'late', 'Late'], ['LV', 'leave', 'Leave'], ['3/4', 'partial', 'Classes attended / held']]} />
              </div>
            </>
          )}
        </Card>
      )}
    </div>
  );
}
