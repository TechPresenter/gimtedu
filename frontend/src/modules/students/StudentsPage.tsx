import { useMemo, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  Award, Bus, ChevronDown, ContactRound, Eye, FilterX, GraduationCap, Hotel, IdCard, Pencil, Plus, Printer, SlidersHorizontal, TrendingUp, UserCheck, UserPlus, Users, UserX,
} from 'lucide-react';
import { Button, Card, PageHeader, Select, Skeleton, StatCard, Stagger, Combobox, toneClasses } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi, useInvalidate } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { CATEGORIES, GENDERS, INACTIVE_STATUSES, STUDENT_STATUSES } from './constants';
import { useAcademicTree, useTreeOptions } from './hooks';
import { StatusDialog } from './components/StatusDialog';
import type { StudentStats } from './types';

const FILTER_KEYS = ['department_id', 'program_id', 'semester', 'section_id', 'batch_id', 'status', 'gender', 'category', 'academic_session_id'] as const;
type FilterKey = (typeof FILTER_KEYS)[number];
/** Child filters cleared when a parent changes (department → program → semester → section, program → batch). */
const DEPENDENTS: Partial<Record<FilterKey, FilterKey[]>> = {
  department_id: ['program_id', 'semester', 'section_id', 'batch_id'],
  program_id: ['semester', 'section_id', 'batch_id'],
  semester: ['section_id'],
};
const HIDDEN_BUILTIN = [...FILTER_KEYS, 'course_id', 'is_hosteller', 'uses_transport', 'admission_date'];
const nowrap = (v: unknown) => (v === null || v === undefined || v === '' ? <span className="text-slate-400">—</span> : <span className="whitespace-nowrap">{String(v)}</span>);
/** Compact, non-wrapping academic cells. */
const RENDERERS: Record<string, (r: Row) => ReactNode> = {
  program_name: (r) => (
    <div className="min-w-0 whitespace-nowrap leading-tight">
      <div className="font-semibold text-slate-900 dark:text-white">{r.program_name}</div>
      {r.course_short && (
        <div className="max-w-[11rem] truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.course_name ?? '')}>
          {r.course_short}
        </div>
      )}
    </div>
  ),
  semester_label: (r) => nowrap(r.semester_label),
  section_name: (r) => nowrap(r.section_name),
  batch_label: (r) => nowrap(r.batch_label),
  mobile: (r) =>
    r.mobile ? (
      <a href={`tel:${String(r.mobile).replace(/\s/g, '')}`} className="whitespace-nowrap hover:text-brand-700 dark:hover:text-brand-300">
        {r.mobile}
      </a>
    ) : (
      nowrap(null)
    ),
};

export default function StudentsPage() {
  const { can } = useAuth();
  const invalidate = useInvalidate();
  const [params, setParams] = useSearchParams();
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [dialog, setDialog] = useState<{ mode: 'deactivate' | 'activate'; students: { id: number; name: string }[]; clear?: () => void } | null>(null);
  const stats = useApi<StudentStats>(['students', 'stats'], 'students/stats');
  const { data: tree } = useAcademicTree();

  const filters = useMemo(() => {
    const f: Partial<Record<FilterKey, string>> = {};
    FILTER_KEYS.forEach((k) => {
      const v = params.get(`f.${k}`);
      if (v) f[k] = v;
    });
    return f;
  }, [params]);
  const filterKey = FILTER_KEYS.map((k) => filters[k] ?? '').join('|');
  const opts = useTreeOptions(tree, { department_id: filters.department_id, program_id: filters.program_id, semester: filters.semester });

  const setFilter = (key: FilterKey, value: string | number | null | undefined) => {
    const next = new URLSearchParams(params);
    const v = value === null || value === undefined ? '' : String(value);
    if (v) next.set(`f.${key}`, v);
    else next.delete(`f.${key}`);
    (DEPENDENTS[key] ?? []).forEach((d) => next.delete(`f.${d}`));
    if (key === 'program_id' && v && tree) {
      const p = tree.programs.find((x) => String(x.id) === v);
      if (p && !next.get('f.department_id')) next.set('f.department_id', String(p.department_id));
    }
    next.delete('page');
    setParams(next, { replace: true });
  };
  const clearAll = () => {
    const next = new URLSearchParams(params);
    FILTER_KEYS.forEach((k) => next.delete(`f.${k}`));
    next.delete('page');
    setParams(next, { replace: true });
  };
  const activeCount = Object.keys(filters).length;

  const refresh = () => invalidate('crud', 'students');
  const s = stats.data;
  const genderTotal = (s?.male ?? 0) + (s?.female ?? 0) + (s?.other ?? 0);
  const femalePct = genderTotal ? Math.round(((s?.female ?? 0) * 100) / genderTotal) : 0;

  const rowMenu = (r: Row) => {
    const inactive = INACTIVE_STATUSES.includes(String(r.status));
    return [
      { label: 'View profile', icon: Eye, to: `/students/${r.id}` },
      can('students', 'edit') && { label: 'Edit details', icon: Pencil, to: `/students/${r.id}/edit` },
      { label: 'Print profile', icon: Printer, href: printUrl('student-profile.php', { id: r.id }), target: '_blank' },
      { label: 'Print ID card', icon: IdCard, href: printUrl('id-cards.php', { ids: r.id }), target: '_blank' },
      can('certificates', 'create') && { label: 'Generate certificate', icon: Award, to: `/certificates?student_id=${r.id}` },
      can('students', 'edit') && { label: '', divider: true },
      can('students', 'edit') && !inactive && { label: 'Deactivate…', icon: UserX, danger: true, onClick: () => setDialog({ mode: 'deactivate', students: [{ id: Number(r.id), name: String(r.full_name) }] }) },
      can('students', 'edit') && inactive && { label: 'Re-activate', icon: UserCheck, onClick: () => setDialog({ mode: 'activate', students: [{ id: Number(r.id), name: String(r.full_name) }] }) },
    ];
  };

  return (
    <>
      <PageHeader
        title="Students"
        description="Every enrolled student with program, section and status. Filter, export, import or open a profile."
        breadcrumbs={[{ label: 'Students' }]}
        actions={
          <>
            <Button variant="secondary" icon={IdCard} to="/students/id-cards">
              ID Cards
            </Button>
            {can('students', 'edit') && (
              <Button variant="secondary" icon={TrendingUp} to="/students/promotion">
                Promote
              </Button>
            )}
            {can('students', 'create') && (
              <Button icon={Plus} to="/students/new">
                Add Student
              </Button>
            )}
          </>
        }
      />

      {/* KPI strip */}
      <Stagger className="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" step={70}>
        {[
          <StatCard key="t" label="Total Students" value={s?.total ?? 0} icon={Users} tone="navy" loading={stats.isLoading} hint={`${formatNumber(s?.active ?? 0)} active · ${formatNumber((s?.total ?? 0) - (s?.active ?? 0))} other`} />,
          <StatCard key="a" label="Active Students" value={s?.active ?? 0} icon={UserCheck} tone="green" loading={stats.isLoading}
            hint={s ? `${s.total ? Math.round((s.active * 100) / s.total) : 0}% of all enrolments` : undefined} to="/students?f.status=active" />,
          <StatCard key="n" label={`New this session${s?.session ? ` (${s.session})` : ''}`} value={s?.new_this_session ?? 0} icon={UserPlus} tone="blue" loading={stats.isLoading}
            trend={s?.new_growth_percent !== null && s?.new_growth_percent !== undefined ? { value: `${Math.abs(s.new_growth_percent)}%`, dir: s.new_growth_percent > 0 ? 'up' : s.new_growth_percent < 0 ? 'down' : 'flat', label: 'vs last session' } : undefined} />,
          <GenderCard key="g" loading={stats.isLoading} male={s?.male ?? 0} female={s?.female ?? 0} other={s?.other ?? 0} femalePct={femalePct} />,
        ]}
      </Stagger>

      {/* Status chips + campus facts */}
      <div className="mb-5 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by status">
          <StatusChip label="All" count={s?.total} active={!filters.status} onClick={() => setFilter('status', '')} loading={stats.isLoading} />
          {(s?.by_status ?? STUDENT_STATUSES.map((o) => ({ status: String(o.value), label: o.label, count: 0 }))).map((b) => (
            <StatusChip key={b.status} label={b.label} count={b.count} status={b.status} active={filters.status === b.status} onClick={() => setFilter('status', filters.status === b.status ? '' : b.status)} loading={stats.isLoading} />
          ))}
        </div>
        <div className="flex flex-wrap gap-4 text-xs text-slate-500 dark:text-slate-400">
          <span className="inline-flex items-center gap-1.5"><Hotel className="h-3.5 w-3.5 text-violet-500" /> {formatNumber(s?.hostellers ?? 0)} hostellers</span>
          <span className="inline-flex items-center gap-1.5"><Bus className="h-3.5 w-3.5 text-amber-500" /> {formatNumber(s?.transport_users ?? 0)} use transport</span>
          <span className="inline-flex items-center gap-1.5"><GraduationCap className="h-3.5 w-3.5 text-brand-600" /> {formatNumber(s?.by_program.length ?? 0)} programs</span>
        </div>
      </div>

      {/* Academic filters */}
      <Card className="mb-5 p-4">
        <div className="flex items-center justify-between gap-2">
          <button type="button" onClick={() => setFiltersOpen((o) => !o)} aria-expanded={filtersOpen} aria-controls="student-filters"
            className="flex items-center gap-2 text-sm font-semibold text-slate-800 lg:pointer-events-none dark:text-slate-100">
            <SlidersHorizontal className="h-4 w-4 text-brand-600 dark:text-brand-300" /> Filter students
            {activeCount > 0 && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{activeCount} active</span>}
            <ChevronDown className={clsx('h-4 w-4 text-slate-400 transition-transform duration-200 lg:hidden', filtersOpen && 'rotate-180')} aria-hidden />
          </button>
          {activeCount > 0 && (
            <button type="button" onClick={clearAll} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-white">
              <FilterX className="h-3.5 w-3.5" /> Clear all
            </button>
          )}
        </div>
        <div id="student-filters" className={clsx('mt-3 grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid lg:grid-cols-5', filtersOpen ? 'grid motion-safe:animate-fade-in' : 'hidden')}>
          <FilterSelect label="Department" value={filters.department_id} options={opts.departments} onChange={(v) => setFilter('department_id', v)} />
          <FilterCombo label="Program" value={filters.program_id} options={opts.programs} onChange={(v) => setFilter('program_id', v)} />
          <FilterSelect label="Semester" value={filters.semester} options={opts.semesters} onChange={(v) => setFilter('semester', v)} />
          <FilterSelect label="Section" value={filters.section_id} options={opts.sections} onChange={(v) => setFilter('section_id', v)} disabled={!filters.program_id} hint="Pick a program first" />
          <FilterSelect label="Batch" value={filters.batch_id} options={opts.batches} onChange={(v) => setFilter('batch_id', v)} disabled={!filters.program_id} hint="Pick a program first" />
          <FilterSelect label="Status" value={filters.status} options={STUDENT_STATUSES} onChange={(v) => setFilter('status', v)} />
          <FilterSelect label="Gender" value={filters.gender} options={GENDERS} onChange={(v) => setFilter('gender', v)} />
          <FilterSelect label="Category" value={filters.category} options={CATEGORIES} onChange={(v) => setFilter('category', v)} />
          <FilterSelect label="Admission session" value={filters.academic_session_id} options={opts.sessions} onChange={(v) => setFilter('academic_session_id', v)} />
        </div>
      </Card>

      <CrudTable
        key={filterKey}
        module="students"
        urlState
        defaultFilters={filters}
        hideFilters={HIDDEN_BUILTIN}
        title="Student directory"
        viewTo={(r) => `/students/${r.id}`}
        createTo="/students/new"
        editTo={(r) => `/students/${r.id}/edit`}
        addLabel="Add Student"
        emptyTitle="No students yet"
        emptyText="Add your first student or import a class list from Excel/CSV."
        rowMenu={rowMenu}
        renderers={RENDERERS}
        onSaved={refresh}
        bulkActions={[
          { label: 'Print ID cards', icon: IdCard, onClick: (ids) => void window.open(printUrl('id-cards.php', { ids: ids.join(',') }), '_blank') },
          ...(can('students', 'edit')
            ? [{ label: 'Deactivate…', icon: UserX, danger: true, onClick: (ids: number[], clear: () => void) => setDialog({ mode: 'deactivate', students: ids.map((id) => ({ id, name: ids.length === 1 ? 'selected student' : `#${id}` })), clear }) }]
            : []),
        ]}
        header={({ total, summary }) => <TableSummary total={total} summary={summary} />}
      />

      <StatusDialog
        open={!!dialog}
        onClose={() => setDialog(null)}
        mode={dialog?.mode ?? 'deactivate'}
        students={dialog?.students ?? []}
        onDone={() => {
          dialog?.clear?.();
          void invalidate('crud', 'students');
          void stats.refetch();
        }}
      />
    </>
  );
}

function GenderCard({ male, female, other, femalePct, loading }: { male: number; female: number; other: number; femalePct: number; loading: boolean }) {
  const t = toneClasses.purple;
  return (
    <div className={clsx('rounded-2xl border border-slate-200/70 p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-card dark:border-slate-800', t.bg)}>
      <span className={clsx('kpi-icon', t.icon)}>
        <ContactRound className="h-5 w-5" aria-hidden />
      </span>
      <p className="mt-3 text-[13px] font-medium text-slate-600 dark:text-slate-300">Gender split (active)</p>
      {loading ? (
        <Skeleton className="mt-1.5 h-7 w-28" />
      ) : (
        <>
          <p className="mt-0.5 font-display text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
            {100 - femalePct}<span className="text-base font-semibold text-slate-400"> : </span>{femalePct}
          </p>
          <div className="mt-2 flex h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" aria-hidden>
            <div className="h-full bg-blue-500 transition-all duration-700" style={{ width: `${100 - femalePct}%` }} />
            <div className="h-full bg-rose-400 transition-all duration-700" style={{ width: `${femalePct}%` }} />
          </div>
          <p className="mt-1.5 truncate text-xs text-slate-500 dark:text-slate-400">
            {formatNumber(male)} male · {formatNumber(female)} female{other ? ` · ${formatNumber(other)} other` : ''}
          </p>
        </>
      )}
    </div>
  );
}

function StatusChip({ label, count, status, active, onClick, loading }: { label: string; count?: number; status?: string; active: boolean; onClick: () => void; loading?: boolean }) {
  const dot: Record<string, string> = { active: 'bg-emerald-500', inactive: 'bg-slate-400', suspended: 'bg-amber-500', dropped: 'bg-red-500', graduated: 'bg-blue-500', alumni: 'bg-brand-700' };
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={clsx(
        'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition duration-150 active:scale-[.97]',
        active
          ? 'border-brand-700 bg-brand-800 text-white shadow-sm dark:border-brand-400 dark:bg-brand-600'
          : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:text-white',
      )}
    >
      {status && <span className={clsx('h-1.5 w-1.5 rounded-full', dot[status] ?? 'bg-slate-400')} aria-hidden />}
      {label}
      <span className={clsx('rounded-full px-1.5 py-px text-[10px] tabular-nums', active ? 'bg-white/20' : 'bg-slate-100 dark:bg-slate-800')}>{loading ? '…' : formatNumber(count ?? 0)}</span>
    </button>
  );
}

function FilterSelect({ label, value, options, onChange, disabled, hint }: { label: string; value?: string; options: { value: string | number; label: string }[]; onChange: (v: string) => void; disabled?: boolean; hint?: string }) {
  return (
    <label className="block">
      <span className="sr-only">{label}</span>
      <Select inputSize="sm" value={value ?? ''} onChange={(e) => onChange(e.target.value)} options={options} placeholder={disabled && hint ? `${label}: —` : `${label}: All`} disabled={disabled} aria-label={label} />
    </label>
  );
}

function FilterCombo({ label, value, options, onChange }: { label: string; value?: string; options: { value: string | number; label: string; sub?: string }[]; onChange: (v: string) => void }) {
  return (
    <div aria-label={label}>
      <Combobox size="sm" value={value ?? null} onChange={(v) => onChange(v === null ? '' : String(v))} options={options} placeholder={`${label}: All`} />
    </div>
  );
}

function TableSummary({ total, summary }: { total: number; summary?: Record<string, unknown> | null }) {
  if (!summary || !total) return null;
  const n = (k: string) => Number(summary[k] ?? 0);
  const items = [
    { label: 'active', value: n('active'), tone: 'green' as const },
    { label: 'male', value: n('male'), tone: 'blue' as const },
    { label: 'female', value: n('female'), tone: 'pink' as const },
    { label: 'hostellers', value: n('hostellers'), tone: 'purple' as const },
    { label: 'use transport', value: n('transport'), tone: 'amber' as const },
  ];
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-800/30 dark:text-slate-300">
      <span className="font-semibold text-slate-800 dark:text-white">{formatNumber(total)} matching</span>
      {items.map((it) => (
        <span key={it.label} className="inline-flex items-center gap-1.5">
          <span className={clsx('h-1.5 w-1.5 rounded-full', toneClasses[it.tone].bar)} aria-hidden />
          <span className="tabular-nums font-semibold text-slate-800 dark:text-slate-100">{formatNumber(it.value)}</span> {it.label}
        </span>
      ))}
    </div>
  );
}
