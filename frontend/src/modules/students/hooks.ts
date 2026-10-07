import { useMemo } from 'react';
import { useApi } from '@/lib/queries';
import type { Option } from '@/lib/types';
import type { AcademicTree, TreeProgram, TreeSection } from './types';

/** Departments → programs → courses/batches/sections in one cached request (small tables). */
export function useAcademicTree() {
  return useApi<AcademicTree>(['students', 'academic-tree'], 'students/academic-tree', undefined, { staleTime: 5 * 60 * 1000 });
}

const num = (v: unknown) => (v === '' || v === null || v === undefined ? null : Number(v));

/** Dependent option lists derived from the academic tree. */
export function useTreeOptions(tree: AcademicTree | undefined, sel: { department_id?: unknown; program_id?: unknown; semester?: unknown; session_id?: unknown }) {
  return useMemo(() => {
    const dept = num(sel.department_id);
    const programId = num(sel.program_id);
    const sem = num(sel.semester);
    const sessionId = num(sel.session_id);
    const program: TreeProgram | undefined = tree?.programs.find((p) => p.id === programId);
    const sessionName = (id: number | null) => tree?.sessions.find((s) => s.id === id)?.name ?? '';
    const departments: Option[] = (tree?.departments ?? []).map((d) => ({ value: d.id, label: d.name }));
    const programs: Option[] = (tree?.programs ?? [])
      .filter((p) => (!dept || p.department_id === dept) && (p.status === 'active' || p.id === programId))
      .map((p) => ({ value: p.id, label: `${p.short_name} — ${p.name}`, sub: p.level }));
    const semesters: Option[] = Array.from({ length: program?.total_semesters ?? (programId ? 0 : 8) }, (_, i) => ({ value: i + 1, label: `Semester ${i + 1}` }));
    const sectionRows: TreeSection[] = (tree?.sections ?? []).filter(
      (s) => (!programId || s.program_id === programId) && (!sem || s.semester_no === sem) && (!sessionId || s.academic_session_id === sessionId),
    );
    const multiSession = new Set(sectionRows.map((s) => s.academic_session_id)).size > 1;
    const sections: Option[] = sectionRows.map((s) => {
      const p = tree?.programs.find((x) => x.id === s.program_id);
      const base = programId ? `Section ${s.name}` : `${p?.short_name ?? ''} · Sem ${s.semester_no} · ${s.name}`;
      return { value: s.id, label: multiSession ? `${base} (${sessionName(s.academic_session_id)})` : base, sub: `${s.strength}/${s.capacity} students` };
    });
    const batches: Option[] = (tree?.batches ?? []).filter((b) => !programId || b.program_id === programId).map((b) => ({ value: b.id, label: b.name }));
    const courses: Option[] = (tree?.courses ?? []).filter((c) => !programId || c.program_id === programId).map((c) => ({ value: c.id, label: c.name }));
    const sessions: Option[] = (tree?.sessions ?? []).map((s) => ({ value: s.id, label: s.is_current ? `${s.name} (current)` : s.name }));
    return { program, departments, programs, semesters, sections, batches, courses, sessions, sectionRows };
  }, [tree, sel.department_id, sel.program_id, sel.semester, sel.session_id]);
}
