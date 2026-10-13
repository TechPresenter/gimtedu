import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { BookOpen, ClipboardList, Lock, PenLine, Workflow } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Combobox, EmptyState, Field, PageHeader, Reveal, Select, Skeleton, Stagger } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useAcademicSession } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { ExamStatusBadge, MiniProgress, dateRange } from './components/shared';
import type { MarksExamOption, MarksSheet, SubjectProgress } from './components/types';
import { MarksGrid } from './components/MarksGrid';

interface ProgressPayload {
  exam: { id: number; name: string; status: string; display_status: string; cohort: string; lock_reason: string | null };
  subjects: SubjectProgress[];
  sections: { id: number; name: string; students: number }[];
}

export default function MarksEntryPage() {
  const [params, setParams] = useSearchParams();
  const { id: currentSession, all: sessions } = useAcademicSession();
  const sessionId = Number(params.get('session') ?? currentSession ?? 0) || null;
  const examId = Number(params.get('exam') ?? 0) || null;
  const subjectId = Number(params.get('subject') ?? 0) || null;
  const sectionId = Number(params.get('section') ?? 0) || null;
  const qc = useQueryClient();

  const set = (patch: Record<string, string | number | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, String(v))));
    setParams(next);
  };

  const exams = useApi<{ rows: MarksExamOption[]; restricted: boolean }>(['marks', 'exams', sessionId], 'exams/marks/exams', { session_id: sessionId ?? undefined });
  const progress = useApi<ProgressPayload>(['marks', 'progress', examId], `exams/${examId}/progress`, undefined, { enabled: !!examId });
  const sheetKey = ['marks', 'sheet', examId, subjectId, sectionId];
  const sheet = useApi<MarksSheet>(sheetKey, `exams/${examId}/marks/${subjectId}`, { section_id: sectionId ?? undefined }, { enabled: !!examId && !!subjectId, staleTime: 0 });
  const examOptions = useMemo(
    () => (exams.data?.rows ?? []).map((e) => ({ value: e.id, label: e.name, sub: `${e.cohort} · ${e.type_name} · ${Math.round(e.percent)}% entered${e.locked ? ' · locked' : ''}` })),
    [exams.data],
  );
  const subjects = progress.data?.subjects ?? [];
  const currentExam = exams.data?.rows.find((e) => e.id === examId);
  const pending = (exams.data?.rows ?? []).filter((e) => !e.locked && e.percent < 100 && (e.display_status === 'marks_entry' || e.display_status === 'ongoing'));

  return (
    <>
      <PageHeader
        title="Marks Entry"
        description="Enter internal, external and practical marks subject by subject — totals and grades are calculated live, saved automatically and locked once verified."
        breadcrumbs={[{ label: 'Examination', to: '/examination' }, { label: 'Marks Entry' }]}
        actions={
          examId ? (
            <Button variant="secondary" icon={Workflow} to={`/examination/${examId}?tab=marks`}>
              Exam workflow
            </Button>
          ) : undefined
        }
      />

      <Card className="mb-5 p-4 sm:p-5">
        <div className="grid gap-3 md:grid-cols-[10rem_minmax(0,2fr)_minmax(0,2fr)_12rem]">
          <Field label="Session" htmlFor="me-session">
            <Select id="me-session" value={String(sessionId ?? '')} onChange={(ev) => set({ session: ev.target.value, exam: null, subject: null, section: null })} options={sessions.map((s) => ({ value: String(s.id), label: s.name }))} />
          </Field>
          <Field label="Exam">
            {exams.isLoading ? (
              <Skeleton className="h-10 w-full" />
            ) : (
              <Combobox value={examId} options={examOptions} onChange={(v) => set({ exam: v ? Number(v) : null, subject: null, section: null })} placeholder="Choose an exam…" />
            )}
          </Field>
          <Field label="Subject" htmlFor="me-subject">
            <Select
              id="me-subject"
              value={String(subjectId ?? '')}
              disabled={!examId || progress.isLoading}
              onChange={(ev) => set({ subject: ev.target.value || null })}
              placeholder={examId ? 'Choose a subject…' : 'Choose an exam first'}
              options={subjects.map((s) => ({ value: String(s.subject_id), label: `${s.code} — ${s.name} (${s.entered}/${s.students})` }))}
            />
          </Field>
          <Field label="Section" htmlFor="me-section">
            <Select
              id="me-section"
              value={String(sectionId ?? '')}
              disabled={!examId || !progress.data?.sections.length}
              onChange={(ev) => set({ section: ev.target.value || null })}
              placeholder="All sections"
              options={(progress.data?.sections ?? []).map((s) => ({ value: String(s.id), label: `Section ${s.name} (${s.students})` }))}
            />
          </Field>
        </div>
        {exams.data?.restricted && <p className="mt-3 text-xs text-slate-500">Showing the exams and subjects you teach.</p>}
      </Card>

      {exams.error && <Alert variant="error" title="Unable to load exams">{(exams.error as ApiError).message}</Alert>}

      {!examId && !exams.error && (
        <Reveal>
          <Card>
            <CardHeader title="Exams awaiting marks" subtitle="Papers held and marks still pending" icon={ClipboardList} />
            {exams.isLoading ? (
              <div className="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3">
                {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-28 w-full rounded-xl" />)}
              </div>
            ) : pending.length ? (
              <Stagger className="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3" step={50}>
                {pending.map((e) => (
                  <button key={e.id} type="button" onClick={() => set({ exam: e.id, subject: null, section: null })} className="w-full rounded-xl border border-slate-200 p-4 text-left transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-soft dark:border-slate-800 dark:hover:border-brand-500/30">
                    <div className="flex items-start justify-between gap-2">
                      <p className="font-semibold text-slate-900 dark:text-white">{e.cohort}</p>
                      <ExamStatusBadge status={e.display_status} />
                    </div>
                    <p className="mt-0.5 truncate text-xs text-slate-500">{e.type_name} · {dateRange(e.start_date, e.end_date)}</p>
                    <div className="mt-3">
                      <MiniProgress value={e.percent} tone={e.percent >= 100 ? 'green' : 'blue'} label={`${e.name} marks entered`} />
                      <p className="mt-1 text-xs text-slate-500">{e.entered} of {e.subjects * e.students} entries · {e.subjects} subjects</p>
                    </div>
                  </button>
                ))}
              </Stagger>
            ) : (
              <EmptyState icon={PenLine} title="No exams are waiting for marks" description="Exams appear here once their papers are held. You can still open any exam above to view or correct marks." action={<Button variant="secondary" to="/examination">View examinations</Button>} />
            )}
          </Card>
        </Reveal>
      )}

      {examId && !subjectId && (
        <Reveal>
          <Card>
            <CardHeader
              title={currentExam?.name ?? progress.data?.exam.name ?? 'Subjects'}
              subtitle={progress.data?.exam.lock_reason ?? 'Choose a subject to open its marks sheet'}
              icon={BookOpen}
              actions={progress.data?.exam.lock_reason ? <Badge color="purple"><Lock className="h-3 w-3" /> Locked</Badge> : undefined}
            />
            {progress.error ? (
              <div className="p-5"><Alert variant="error">{(progress.error as ApiError).message}</Alert></div>
            ) : !progress.data ? (
              <div className="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-24 w-full rounded-xl" />)}</div>
            ) : subjects.length ? (
              <Stagger className="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3" step={50}>
                {subjects.map((s) => (
                  <button
                    key={s.subject_id}
                    type="button"
                    onClick={() => set({ subject: s.subject_id })}
                    className={clsx('w-full rounded-xl border p-4 text-left transition hover:-translate-y-0.5 hover:shadow-soft', s.can_enter === false ? 'border-slate-200 opacity-70 dark:border-slate-800' : 'border-slate-200 hover:border-brand-200 dark:border-slate-800 dark:hover:border-brand-500/30')}
                  >
                    <p className="font-mono text-xs text-slate-500">{s.code}</p>
                    <p className="truncate font-semibold text-slate-900 dark:text-white">{s.name}</p>
                    <p className="mt-0.5 text-xs text-slate-500">{s.exam_date ? `Paper ${formatDate(s.exam_date)}` : 'Not scheduled'} · max {s.max_total}</p>
                    <div className="mt-3">
                      <MiniProgress value={s.percent} tone={s.percent >= 100 ? 'green' : s.percent > 0 ? 'blue' : 'amber'} label={`${s.name} marks entered`} />
                      <p className="mt-1 text-xs text-slate-500">
                        {s.entered}/{s.students} entered{s.state === 'submitted' ? ' · submitted' : ''}{s.can_enter === false ? ' · view only' : ''}
                      </p>
                    </div>
                  </button>
                ))}
              </Stagger>
            ) : (
              <EmptyState icon={BookOpen} title="No subjects in this exam" description="Add subjects to the exam first." action={<Button to={`/examination/${examId}?tab=subjects`}>Open exam subjects</Button>} />
            )}
          </Card>
        </Reveal>
      )}

      {examId && subjectId && (
        <>
          {sheet.error ? (
            <Alert variant="error" title="Unable to open the marks sheet" action={<Button size="sm" variant="secondary" onClick={() => set({ subject: null })}>Choose another subject</Button>}>
              {(sheet.error as ApiError).message}
            </Alert>
          ) : !sheet.data ? (
            <Card className="p-5">
              <Skeleton className="mb-3 h-6 w-1/3" />
              <Skeleton className="mb-5 h-3 w-full" />
              {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="mb-2 h-10 w-full" />)}
            </Card>
          ) : (
            <MarksGrid
              key={`${examId}-${subjectId}-${sectionId}`}
              sheet={sheet.data}
              examId={examId}
              sectionId={sectionId}
              onSaved={(fresh) => {
                qc.setQueryData(sheetKey, fresh);
                progress.refetch();
                exams.refetch();
              }}
            />
          )}
        </>
      )}
    </>
  );
}
