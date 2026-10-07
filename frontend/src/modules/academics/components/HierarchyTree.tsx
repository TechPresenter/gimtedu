import { useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, BookOpen, Building2, ChevronRight, FlaskConical, GraduationCap, Layers, Star, Users } from 'lucide-react';
import { Alert, Avatar, Badge, Skeleton } from '@/components/ui';
import { formatNumber } from '@/lib/format';
import { useApi } from '@/lib/queries';
import type { ApiError } from '@/lib/api';
import type { ProgramTree, TreeDepartment, TreeProgram, TreeSemester, TreeSubject } from '../types';

const levelColor = { UG: 'blue', PG: 'purple', Diploma: 'cyan', Certificate: 'amber', PhD: 'navy' } as const;

/** Small "label value" chip used across tree rows. */
function Meta({ children, tone = 'slate' }: { children: ReactNode; tone?: 'slate' | 'amber' }) {
  return (
    <span
      className={clsx(
        'inline-flex items-center gap-1 whitespace-nowrap rounded-md px-1.5 py-0.5 text-[11px] font-medium',
        tone === 'amber' ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
      )}
    >
      {children}
    </span>
  );
}

function Toggle({ open, onClick, label }: { open: boolean; onClick: () => void; label: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-expanded={open}
      aria-label={`${open ? 'Collapse' : 'Expand'} ${label}`}
      className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-white"
    >
      <ChevronRight className={clsx('h-4 w-4 transition-transform duration-200 motion-reduce:transition-none', open && 'rotate-90')} />
    </button>
  );
}

/** Expandable Department -> Program -> Semester -> Subject -> Faculty -> Students explorer. */
export function HierarchyTree({ tree, sessionId, query }: { tree: TreeDepartment[]; sessionId: number | null; query: string }) {
  const [openDepts, setOpenDepts] = useState<Set<number>>(() => new Set(tree.length ? [tree[0].id] : []));
  const [openPrograms, setOpenPrograms] = useState<Set<number>>(new Set());
  const q = query.trim().toLowerCase();

  const filtered = useMemo(() => {
    if (!q) return tree;
    return tree
      .map((d) => {
        const deptHit = `${d.name} ${d.code}`.toLowerCase().includes(q);
        const programs = deptHit ? d.programs : d.programs.filter((p) => `${p.name} ${p.short_name} ${p.code} ${p.courses.map((c) => c.name).join(' ')}`.toLowerCase().includes(q));
        return programs.length || deptHit ? { ...d, programs } : null;
      })
      .filter(Boolean) as TreeDepartment[];
  }, [tree, q]);

  const flip = <T,>(set: Set<T>, v: T) => {
    const next = new Set(set);
    if (next.has(v)) next.delete(v);
    else next.add(v);
    return next;
  };

  if (!filtered.length) {
    return <p className="px-5 py-10 text-center text-sm text-slate-500">No department or program matches “{query}”.</p>;
  }

  return (
    <ul className="divide-y divide-slate-100 dark:divide-slate-800" role="tree" aria-label="Academic hierarchy">
      {filtered.map((d) => {
        const open = !!q || openDepts.has(d.id);
        return (
          <li key={d.id} role="treeitem" aria-expanded={open}>
            <div className="flex items-center gap-3 px-3 py-3 sm:px-5">
              <Toggle open={open} onClick={() => setOpenDepts((s) => flip(s, d.id))} label={d.name} />
              <span className="kpi-icon !h-9 !w-9 shrink-0 bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200">
                <Building2 className="h-[18px] w-[18px]" />
              </span>
              <button type="button" onClick={() => setOpenDepts((s) => flip(s, d.id))} className="min-w-0 flex-1 text-left">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                  <span className="font-semibold text-slate-900 dark:text-white">{d.name}</span>
                  <Badge color="navy">{d.code}</Badge>
                  {d.status !== 'active' && <Badge color="red">Inactive</Badge>}
                </span>
                <span className="block truncate text-xs text-slate-500 dark:text-slate-400">HOD: {d.hod_name ?? 'Not assigned'}</span>
              </button>
              <div className="hidden shrink-0 flex-wrap justify-end gap-1.5 md:flex">
                <Meta>{d.programs.length} program{d.programs.length === 1 ? '' : 's'}</Meta>
                <Meta>{formatNumber(d.faculty)} faculty</Meta>
                <Meta>{formatNumber(d.students)} students</Meta>
              </div>
            </div>
            {open && (
              <ul className="pb-2 pl-6 motion-safe:animate-slide-up sm:pl-12" role="group">
                {d.programs.length === 0 && <li className="px-5 py-3 text-sm text-slate-500">No programs in this department yet.</li>}
                {d.programs.map((p) => (
                  <ProgramNode key={p.id} program={p} open={openPrograms.has(p.id)} onToggle={() => setOpenPrograms((s) => flip(s, p.id))} sessionId={sessionId} />
                ))}
              </ul>
            )}
          </li>
        );
      })}
    </ul>
  );
}

function ProgramNode({ program: p, open, onToggle, sessionId }: { program: TreeProgram; open: boolean; onToggle: () => void; sessionId: number | null }) {
  return (
    <li role="treeitem" aria-expanded={open} className="relative border-l border-slate-200 pl-3 dark:border-slate-800">
      <div className="flex items-start gap-2.5 rounded-xl px-2 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
        <Toggle open={open} onClick={onToggle} label={p.name} />
        <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
          <GraduationCap className="h-4 w-4" />
        </span>
        <div className="min-w-0 flex-1">
          <button type="button" onClick={onToggle} className="block w-full text-left">
            <span className="flex flex-wrap items-center gap-1.5">
              <span className="font-semibold text-slate-900 dark:text-white">{p.short_name}</span>
              <span className="hidden truncate text-sm text-slate-500 sm:inline dark:text-slate-400">· {p.name}</span>
              <Badge color={levelColor[p.level as keyof typeof levelColor] ?? 'slate'}>{p.level}</Badge>
              {p.featured && <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" aria-label="Featured on website" />}
              {p.status !== 'active' && <Badge color="red">Inactive</Badge>}
            </span>
          </button>
          <div className="mt-1 flex flex-wrap gap-1.5">
            <Meta>{p.duration}</Meta>
            <Meta>{p.total_semesters} semesters</Meta>
            <Meta>{formatNumber(p.subjects)} subjects</Meta>
            <Meta>{p.sections} sections</Meta>
            <Meta>
              <Users className="h-3 w-3" /> {formatNumber(p.students)}
              {p.intake ? ` / ${formatNumber(p.intake)} intake` : ''}
            </Meta>
          </div>
          {p.courses.length > 0 && (
            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
              <span className="text-[11px] font-medium text-slate-400">Specializations:</span>
              {p.courses.map((c) => (
                <span key={c.id} className="inline-flex items-center gap-1 rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300">
                  {c.name.replace(/^.*? - /, '')}
                  <span className="opacity-70">· {c.students}</span>
                </span>
              ))}
            </div>
          )}
        </div>
      </div>
      {open && <ProgramBranch programId={p.id} sessionId={sessionId} />}
    </li>
  );
}

function ProgramBranch({ programId, sessionId }: { programId: number; sessionId: number | null }) {
  const { data, isLoading, error } = useApi<ProgramTree>(['acad-program-tree', programId, sessionId], `academics/programs/${programId}/tree`, { session_id: sessionId ?? undefined });
  const [openSem, setOpenSem] = useState<number | null>(null);
  if (isLoading) {
    return (
      <div className="space-y-2 py-2 pl-10 pr-2">
        <Skeleton className="h-9 w-full rounded-lg" />
        <Skeleton className="h-9 w-5/6 rounded-lg" />
      </div>
    );
  }
  if (error || !data) return <Alert variant="error" className="my-2 ml-10">{(error as ApiError)?.message ?? 'Unable to load this program.'}</Alert>;
  const firstActive = data.semesters.find((s) => s.sections.length > 0)?.number ?? null;
  const current = openSem === null ? firstActive : openSem;
  return (
    <ul className="ml-5 border-l border-dashed border-slate-200 pb-2 pl-3 motion-safe:animate-slide-up dark:border-slate-700" role="group">
      {data.semesters.map((s) => (
        <SemesterNode key={s.number} sem={s} open={current === s.number} onToggle={() => setOpenSem(current === s.number ? 0 : s.number)} />
      ))}
    </ul>
  );
}

function SemesterNode({ sem, open, onToggle }: { sem: TreeSemester; open: boolean; onToggle: () => void }) {
  const gaps = sem.subjects.reduce((n, s) => n + (s.missing_sections > 0 ? 1 : 0), 0);
  return (
    <li role="treeitem" aria-expanded={open}>
      <div className="flex flex-wrap items-center gap-2 rounded-lg px-2 py-2 hover:bg-slate-50 dark:hover:bg-slate-800/40">
        <Toggle open={open} onClick={onToggle} label={sem.name} />
        <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-cyan-50 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300">
          <Layers className="h-3.5 w-3.5" />
        </span>
        <button type="button" onClick={onToggle} className="text-sm font-semibold text-slate-800 dark:text-slate-100">
          {sem.name}
        </button>
        <Meta>{sem.subjects.length} subjects</Meta>
        <Meta>{sem.credits} credits</Meta>
        {sem.sections.length > 0 ? (
          sem.sections.map((sc) => (
            <span key={sc.id} className="inline-flex items-center gap-1 rounded-md bg-accent-50 px-1.5 py-0.5 text-[11px] font-semibold text-accent-700 dark:bg-accent-500/10 dark:text-accent-300" title={`Class teacher: ${sc.class_teacher ?? '—'} · Room ${sc.room ?? '—'}`}>
              Sec {sc.name} · {sc.students}/{sc.capacity}
            </span>
          ))
        ) : (
          <span className="text-[11px] text-slate-400">Not running this session</span>
        )}
        {gaps > 0 && (
          <Meta tone="amber">
            <AlertTriangle className="h-3 w-3" /> {gaps} without faculty
          </Meta>
        )}
      </div>
      {open && (
        <div className="mb-2 ml-9 overflow-x-auto rounded-xl border border-slate-200 motion-safe:animate-fade-in dark:border-slate-800">
          {sem.subjects.length === 0 ? (
            <p className="px-4 py-4 text-sm text-slate-500">
              No subjects yet. <Link to="/academics?tab=subjects" className="link">Add subjects</Link>
            </p>
          ) : (
            <table className="w-full min-w-[640px] text-sm">
              <thead className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                <tr>
                  <th className="px-3 py-2">Subject</th>
                  <th className="px-3 py-2">Type</th>
                  <th className="px-3 py-2 text-center">Credits</th>
                  <th className="px-3 py-2">Faculty → Students</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {sem.subjects.map((s) => (
                  <SubjectRow key={s.id} s={s} hasSections={sem.sections.length > 0} />
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </li>
  );
}

function SubjectRow({ s, hasSections }: { s: TreeSubject; hasSections: boolean }) {
  return (
    <tr className="align-top">
      <td className="px-3 py-2.5">
        <div className="flex items-start gap-2">
          {s.type === 'lab' ? <FlaskConical className="mt-0.5 h-4 w-4 shrink-0 text-violet-500" /> : <BookOpen className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />}
          <div className="min-w-0">
            <p className="font-medium text-slate-900 dark:text-white">{s.name}</p>
            <p className="text-xs text-slate-500">
              {s.code}
              {s.course && <> · {s.course}</>}
            </p>
          </div>
        </div>
      </td>
      <td className="px-3 py-2.5">
        <div className="flex flex-wrap gap-1">
          <Badge color={s.type === 'lab' ? 'purple' : s.type === 'project' ? 'amber' : 'blue'}>{s.type}</Badge>
          {s.elective && <Badge color="cyan">Elective</Badge>}
        </div>
      </td>
      <td className="px-3 py-2.5 text-center tabular-nums">{s.credits}</td>
      <td className="px-3 py-2.5">
        {s.faculty.length === 0 ? (
          hasSections ? (
            <Link to="/academics?tab=assignments" className="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 hover:underline dark:text-amber-300">
              <AlertTriangle className="h-3.5 w-3.5" /> Assign faculty
            </Link>
          ) : (
            <span className="text-xs text-slate-400">—</span>
          )
        ) : (
          <div className="flex flex-wrap gap-1.5">
            {s.faculty.map((f, i) => (
              <span
                key={`${f.faculty_id}-${f.section_id}-${i}`}
                title={f.section ? `${f.name} teaches Section ${f.section}${f.students !== null ? ` (${f.students} students)` : ''}` : f.name}
                className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-slate-200 bg-white py-0.5 pl-0.5 pr-2 text-xs dark:border-slate-700 dark:bg-slate-900"
              >
                <Avatar name={f.name} src={f.photo} size="xs" />
                <span className="font-medium text-slate-700 dark:text-slate-200">{f.name}</span>
                {f.section && (
                  <span className="inline-flex items-center gap-0.5 text-slate-400">
                    · {f.section}
                    {f.students !== null && (
                      <>
                        <Users className="ml-0.5 h-3 w-3" aria-hidden />
                        {f.students}
                      </>
                    )}
                  </span>
                )}
              </span>
            ))}
            {s.missing_sections > 0 && <Meta tone="amber">{s.missing_sections} section{s.missing_sections > 1 ? 's' : ''} unassigned</Meta>}
          </div>
        )}
      </td>
    </tr>
  );
}
