import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Download, FileSpreadsheet, FileText, FilterX, Printer } from 'lucide-react';
import { Button, Dropdown, Input, SearchInput, Select } from '@/components/ui';
import { downloadFile, type Query } from '@/lib/api';
import { useLookup } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { printUrl } from '@/lib/config';
import { isoDate } from '@/lib/format';
import { todayIso } from './shared';

export const FILTER_KEYS = ['from', 'to', 'department_id', 'program_id', 'semester', 'section_id', 'subject_id', 'q', 'threshold'] as const;
export type FilterKey = (typeof FILTER_KEYS)[number];
export type ReportFilters = Partial<Record<FilterKey, string>>;

const CHILDREN: Partial<Record<FilterKey, FilterKey[]>> = {
  department_id: ['program_id', 'semester', 'section_id', 'subject_id'],
  program_id: ['semester', 'section_id', 'subject_id'],
  semester: ['section_id', 'subject_id'],
  section_id: [],
};

/** Report filters live in the URL so every view is linkable / printable. */
export function useReportFilters(): [ReportFilters, (key: FilterKey, value: string) => void, () => void, (patch: ReportFilters) => void] {
  const [params, setParams] = useSearchParams();
  const f: ReportFilters = {};
  FILTER_KEYS.forEach((k) => {
    const v = params.get(k);
    if (v) f[k] = v;
  });
  const set = (key: FilterKey, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    (CHILDREN[key] ?? []).forEach((c) => next.delete(c));
    next.delete('page');
    setParams(next, { replace: true });
  };
  const patch = (p: ReportFilters) => {
    const next = new URLSearchParams(params);
    Object.entries(p).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
    next.delete('page');
    setParams(next, { replace: true });
  };
  const clear = () => {
    const next = new URLSearchParams(params);
    FILTER_KEYS.forEach((k) => next.delete(k));
    next.delete('page');
    setParams(next, { replace: true });
  };
  return [f, set, clear, patch];
}

export function filtersQuery(f: ReportFilters): Query {
  const q: Query = {};
  FILTER_KEYS.forEach((k) => {
    if (f[k]) q[k] = f[k];
  });
  return q;
}

const SEMESTERS = Array.from({ length: 8 }, (_, i) => ({ value: String(i + 1), label: `Semester ${i + 1}` }));

interface Props {
  filters: ReportFilters;
  set: (key: FilterKey, value: string) => void;
  clear: () => void;
  patch: (p: ReportFilters) => void;
  show?: { subject?: boolean; search?: boolean; threshold?: boolean; section?: boolean };
  defaultThreshold?: number;
  searchPlaceholder?: string;
}

export function ReportFilterBar({ filters: f, set, clear, patch, show = {}, defaultThreshold = 75, searchPlaceholder = 'Search student…' }: Props) {
  const { session } = useAcademicSession();
  const depts = useLookup('departments');
  const programs = useLookup('programs', { department_id: f.department_id });
  const sections = useLookup('sections', { program_id: f.program_id, semester_no: f.semester, academic_session_id: session?.id ?? undefined }, !!f.program_id);
  const subjects = useLookup('subjects', { program_id: f.program_id, semester_no: f.semester }, !!f.program_id && !!f.semester);
  const [search, setSearch] = useState(f.q ?? '');
  const debounced = useDebounce(search, 400);
  useEffect(() => {
    if ((f.q ?? '') !== debounced) set('q', debounced);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  const [threshold, setThreshold] = useState(f.threshold ?? '');
  const active = Object.keys(f).filter((k) => k !== 'q').length;
  const today = todayIso();
  const preset = (key: string) => {
    const d = new Date();
    if (key === 'month') patch({ from: `${today.slice(0, 7)}-01`, to: today });
    if (key === '30') {
      d.setDate(d.getDate() - 29);
      patch({ from: isoDate(d), to: today });
    }
    if (key === 'week') {
      d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
      patch({ from: isoDate(d), to: today });
    }
    if (key === 'session') patch({ from: '', to: '' });
  };

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))]">
        <label className="min-w-0">
          <span className="mb-1 block text-[11px] font-medium text-slate-500">From</span>
          <Input type="date" inputSize="sm" value={f.from ?? ''} max={f.to || today} onChange={(e) => set('from', e.target.value)} aria-label="From date" />
        </label>
        <label className="min-w-0">
          <span className="mb-1 block text-[11px] font-medium text-slate-500">To</span>
          <Input type="date" inputSize="sm" value={f.to ?? ''} min={f.from} max={today} onChange={(e) => set('to', e.target.value)} aria-label="To date" />
        </label>
        <label className="min-w-0">
          <span className="mb-1 block text-[11px] font-medium text-slate-500">Department</span>
          <Select inputSize="sm" options={(depts.data ?? []).map((o) => ({ value: o.value, label: o.label }))} placeholder="All departments" value={f.department_id ?? ''} onChange={(e) => set('department_id', e.target.value)} />
        </label>
        <label className="min-w-0">
          <span className="mb-1 block text-[11px] font-medium text-slate-500">Program</span>
          <Select inputSize="sm" options={(programs.data ?? []).map((o) => ({ value: o.value, label: o.label.split(' — ')[0] }))} placeholder="All programs" value={f.program_id ?? ''} onChange={(e) => set('program_id', e.target.value)} />
        </label>
        <label className="min-w-0">
          <span className="mb-1 block text-[11px] font-medium text-slate-500">Semester</span>
          <Select inputSize="sm" options={SEMESTERS} placeholder="All semesters" value={f.semester ?? ''} onChange={(e) => set('semester', e.target.value)} />
        </label>
        {show.section !== false && (
          <label className="min-w-0">
            <span className="mb-1 block text-[11px] font-medium text-slate-500">Section</span>
            <Select inputSize="sm" options={(sections.data ?? []).map((o) => ({ value: o.value, label: o.label }))} placeholder={f.program_id ? 'All sections' : 'Pick program'} disabled={!f.program_id} value={f.section_id ?? ''} onChange={(e) => set('section_id', e.target.value)} />
          </label>
        )}
        {show.subject && (
          <label className="min-w-0">
            <span className="mb-1 block text-[11px] font-medium text-slate-500">Subject</span>
            <Select inputSize="sm" options={(subjects.data ?? []).map((o) => ({ value: o.value, label: o.label }))} placeholder={f.semester ? 'All subjects' : 'Pick semester'} disabled={!f.program_id || !f.semester} value={f.subject_id ?? ''} onChange={(e) => set('subject_id', e.target.value)} />
          </label>
        )}
        {show.threshold && (
          <label className="min-w-0">
            <span className="mb-1 block text-[11px] font-medium text-slate-500">Below (%)</span>
            <Input
              type="number"
              inputSize="sm"
              min={1}
              max={100}
              value={threshold}
              placeholder={String(defaultThreshold)}
              onChange={(e) => setThreshold(e.target.value)}
              onBlur={() => set('threshold', threshold && Number(threshold) > 0 && Number(threshold) <= 100 ? threshold : '')}
              onKeyDown={(e) => e.key === 'Enter' && set('threshold', threshold)}
              aria-label="Attendance threshold"
            />
          </label>
        )}
      </div>
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <div className="flex flex-wrap items-center gap-1.5 text-xs">
          <span className="font-medium text-slate-500">Period:</span>
          {[
            ['session', 'This session'],
            ['month', 'This month'],
            ['30', 'Last 30 days'],
            ['week', 'This week'],
          ].map(([k, l]) => (
            <button key={k} type="button" onClick={() => preset(k)} className="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600 transition hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-brand-500/15">
              {l}
            </button>
          ))}
          {active > 0 && (
            <button type="button" onClick={() => { setSearch(''); setThreshold(''); clear(); }} className="inline-flex items-center gap-1 rounded-full px-2 py-1 font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800">
              <FilterX className="h-3.5 w-3.5" /> Clear filters
            </button>
          )}
        </div>
        {show.search !== false && <SearchInput size="sm" value={search} onChange={setSearch} placeholder={searchPlaceholder} className="sm:ml-auto sm:w-64" />}
      </div>
    </div>
  );
}

/** Export (CSV / Excel) + Print buttons for a report. */
export function ReportExport({ report, query, disabled }: { report: string; query: Query; disabled?: boolean }) {
  const { can } = useAuth();
  const printParams: Record<string, string | number | undefined> = { report };
  Object.entries(query).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '' && typeof v !== 'object') printParams[k] = v as string | number;
  });
  return (
    <div className="flex gap-2">
      {can('attendance', 'export') && (
        <Dropdown
          label="Export report"
          trigger={<><Download className="h-4 w-4" /><span className="hidden sm:inline">Export</span></>}
          items={[
            { label: 'Export as CSV', icon: FileText, disabled, onClick: () => downloadFile('attendance/reports/export', { ...query, report, format: 'csv' }) },
            { label: 'Export as Excel', icon: FileSpreadsheet, disabled, onClick: () => downloadFile('attendance/reports/export', { ...query, report, format: 'xlsx' }) },
          ]}
        />
      )}
      <Button size="sm" variant="secondary" icon={Printer} href={printUrl('attendance-report.php', printParams)} target="_blank" disabled={disabled}>
        <span className="hidden sm:inline">Print</span>
      </Button>
    </div>
  );
}
