import { useState } from 'react';
import { ListPlus, Lock, PenLine } from 'lucide-react';
import { Alert, Badge, Button, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, type ApiError } from '@/lib/api';
import { useInvalidate } from '@/lib/queries';
import { formatDate } from '@/lib/format';
import { fmtMarks } from '../shared';
import type { ExamOverview } from '../types';

export function SubjectsTab({ data }: { data: ExamOverview }) {
  const e = data.exam;
  const toast = useToast();
  const invalidate = useInvalidate();
  const [syncing, setSyncing] = useState(false);
  const locked = ['verified', 'published', 'cancelled'].includes(e.status);
  const canEdit = data.permissions.edit && !locked;

  const sync = async () => {
    setSyncing(true);
    try {
      const res = await api.post<{ added: number }>(`exams/${e.id}/subjects/sync`);
      toast.success(res.message);
      await invalidate('crud', 'exams');
    } catch (err) {
      toast.error((err as ApiError).message);
    } finally {
      setSyncing(false);
    }
  };

  return (
    <div className="space-y-4">
      {locked && (
        <Alert variant="info" title={e.status === 'cancelled' ? 'Exam cancelled' : 'Subjects are locked'}>
          {e.status === 'cancelled' ? 'Re-open the exam (edit its status) to change subjects.' : 'Marks are verified — unlock the marks from the Marks tab to change subjects or maximum marks.'}
        </Alert>
      )}
      <CrudTable
        module="exam_subjects"
        title="Subjects & maximum marks"
        description="Internal, external and practical maximum marks with pass marks. Defaults come from each subject; edit them per exam."
        scope={{ exam_id: e.id }}
        formDefaults={{ exam_id: e.id }}
        addLabel="Add subject"
        onSaved={() => invalidate('exams')}
        emptyTitle="No subjects in this exam"
        emptyText={e.program_id ? `Load the curriculum subjects of ${e.cohort} or add papers one by one.` : 'Add the papers of this exam.'}
        toolbar={
          canEdit && e.program_id ? (
            <Button size="sm" variant="secondary" icon={ListPlus} loading={syncing} onClick={sync}>
              Load curriculum
            </Button>
          ) : locked ? (
            <Badge color="slate">
              <Lock className="h-3 w-3" /> Locked
            </Badge>
          ) : null
        }
        renderers={{
          subject_name: (r) => (
            <div className="min-w-[11rem] leading-tight">
              <p className="font-semibold text-slate-900 dark:text-white">{r.subject_name}</p>
              <p className="mt-0.5 text-xs text-slate-500">
                <span className="font-mono">{r.subject_code}</span> · <span className="capitalize">{r.subject_type}</span>
                {r.exam_date ? ` · paper ${formatDate(r.exam_date)}` : ''}
              </p>
            </div>
          ),
          max_total: (r) => <span className="font-semibold tabular-nums">{fmtMarks(r.max_total)}</span>,
          pass_marks: (r) => (
            <span className="tabular-nums">
              {fmtMarks(r.pass_marks)} <span className="text-xs text-slate-400">({r.max_total ? Math.round((100 * Number(r.pass_marks)) / Number(r.max_total)) : 0}%)</span>
            </span>
          ),
          marks_count: (r) =>
            Number(r.students_count) ? (
              <span className="inline-flex items-center gap-1 whitespace-nowrap text-xs tabular-nums text-slate-600 dark:text-slate-300">
                <PenLine className="h-3 w-3" /> {r.marks_count}/{r.students_count}
              </span>
            ) : (
              <span className="text-xs text-slate-400">—</span>
            ),
        }}
      />
    </div>
  );
}
