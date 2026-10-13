import { Link } from 'react-router-dom';
import { CheckCircle2, ClipboardList, Lock, LockOpen, PenLine, RefreshCw, Send, ShieldCheck, Trophy, Undo2 } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, Reveal, type Column } from '@/components/ui';
import type { BadgeColor } from '@/lib/status';
import { formatDate, formatDateTime } from '@/lib/format';
import { MiniProgress, fmtMarks } from '../shared';
import type { ExamOverview, SubjectProgress } from '../types';
import type { useExamWorkflow } from '../useExamWorkflow';

const STATE: Record<SubjectProgress['state'], { label: string; color: BadgeColor }> = {
  not_started: { label: 'Not started', color: 'slate' },
  in_progress: { label: 'In progress', color: 'amber' },
  complete: { label: 'Complete', color: 'blue' },
  submitted: { label: 'Submitted', color: 'green' },
};

export function MarksTab({ data, wf }: { data: ExamOverview; wf: ReturnType<typeof useExamWorkflow> }) {
  const e = data.exam;
  const perm = data.permissions;
  const missing = data.progress.filter((p) => p.entered < p.students);
  const locked = e.status === 'verified' || e.status === 'published';
  const columns: Column<SubjectProgress>[] = [
    { key: 'name', header: 'Subject', render: (p) => (
      <div className="min-w-0 leading-tight">
        <p className="font-semibold text-slate-900 dark:text-white">{p.name}</p>
        <p className="text-xs text-slate-500"><span className="font-mono">{p.code}</span> · {p.credits} credits · max {p.max_total} · pass {p.pass_marks}</p>
      </div>
    ) },
    { key: 'exam_date', header: 'Paper', render: (p) => <span className="whitespace-nowrap text-sm">{p.exam_date ? formatDate(p.exam_date) : '—'}</span> },
    { key: 'entered', header: 'Entered', render: (p) => (
      <div className="min-w-[9rem]">
        <MiniProgress value={p.percent} tone={p.percent >= 100 ? 'green' : p.percent > 0 ? 'blue' : 'amber'} label={`${p.name} marks entered`} />
        <p className="mt-0.5 text-xs text-slate-500">{p.entered} of {p.students}{p.absent ? ` · ${p.absent} absent` : ''}</p>
      </div>
    ) },
    { key: 'average', header: 'Average', align: 'center', render: (p) => (p.average === null ? '—' : <span className="tabular-nums">{fmtMarks(p.average, 1)}</span>) },
    { key: 'highest', header: 'Highest', align: 'center', render: (p) => (p.highest === null ? '—' : <span className="tabular-nums">{fmtMarks(p.highest)}</span>) },
    { key: 'pass_percent', header: 'Pass %', align: 'center', render: (p) => (p.pass_percent === null ? '—' : <span className="tabular-nums">{p.pass_percent}%</span>) },
    { key: 'state', header: 'Status', render: (p) => <Badge color={locked ? 'purple' : STATE[p.state].color} dot>{locked ? (e.status === 'published' ? 'Published' : 'Verified') : STATE[p.state].label}</Badge> },
  ];

  return (
    <div className="space-y-6">
      <Reveal>
        <Card>
          <CardHeader
            title="Verification & publication"
            subtitle={
              e.status === 'published'
                ? `Results published ${formatDateTime(e.published_at)}${e.published_by_name ? ` by ${e.published_by_name}` : ''}`
                : e.status === 'verified'
                  ? `Marks locked ${formatDateTime(e.verified_at)}${e.verified_by_name ? ` by ${e.verified_by_name}` : ''} — results computed, not yet published`
                  : 'Lock the marks once every subject is complete, then publish the results'
            }
            icon={ShieldCheck}
          />
          <div className="space-y-4 p-5">
            {!locked && missing.length > 0 && (
              <Alert variant="warning" title={`Marks incomplete in ${missing.length} subject${missing.length === 1 ? '' : 's'}`}>
                {missing.slice(0, 6).map((p) => `${p.code} (${p.entered}/${p.students})`).join(' · ')}
                {missing.length > 6 ? ` and ${missing.length - 6} more` : ''}
              </Alert>
            )}
            {!locked && !missing.length && data.progress.length > 0 && (
              <Alert variant="success" title="All marks are entered">Verify the marks to lock them and compute the results.</Alert>
            )}
            {e.status === 'verified' && (
              <Alert variant="info" title={`${data.results.total} results computed`}>
                {data.results.pass} pass · {data.results.backlog} backlog · {data.results.fail} fail · {data.results.absent} absent · {data.results.withheld} withheld — pass percentage {data.results.pass_percent ?? 0}%.
              </Alert>
            )}
            <div className="flex flex-wrap gap-2">
              {!locked && perm.marks && (
                <Button variant="secondary" icon={PenLine} to={`/marks-entry?exam=${e.id}`}>
                  Enter marks
                </Button>
              )}
              {!locked && perm.verify && (
                <Button icon={Lock} onClick={wf.verify} loading={wf.busy === 'verify'} disabled={missing.length > 0 || !data.progress.length}>
                  Verify & lock marks
                </Button>
              )}
              {e.status === 'verified' && perm.publish && (
                <Button variant="success" icon={Send} onClick={wf.publish}>
                  Publish results
                </Button>
              )}
              {e.status === 'verified' && perm.verify && (
                <Button variant="secondary" icon={LockOpen} onClick={wf.unverify} loading={wf.busy === 'unverify'}>
                  Unlock marks
                </Button>
              )}
              {locked && perm.verify && (
                <Button variant="secondary" icon={RefreshCw} onClick={wf.recompute} loading={wf.busy === 'recompute'}>
                  Recompute
                </Button>
              )}
              {e.status === 'published' && perm.publish && (
                <Button variant="secondary" icon={Undo2} onClick={wf.unpublish} loading={wf.busy === 'unpublish'}>
                  Unpublish
                </Button>
              )}
              {data.results.total > 0 && (
                <Button variant="ghost" icon={Trophy} to={`/results?exam=${e.id}`}>
                  Result analysis
                </Button>
              )}
              {e.status === 'published' && <Badge color="green"><CheckCircle2 className="h-3 w-3" /> Published</Badge>}
            </div>
          </div>
        </Card>
      </Reveal>

      <Reveal delay={60}>
        <Card className="overflow-hidden">
          <CardHeader title="Marks progress by subject" subtitle={`${data.counts.marks_percent}% of ${data.counts.marks_expected} entries`} icon={ClipboardList} />
          <DataTable
            columns={columns}
            rows={data.progress}
            rowKey={(p) => p.subject_id}
            actions={(p) =>
              perm.marks || locked ? (
                <Link to={`/marks-entry?exam=${e.id}&subject=${p.subject_id}`} className="btn btn-soft btn-xs whitespace-nowrap">
                  {locked ? 'View marks' : p.entered >= p.students ? 'Review' : p.entered ? 'Continue' : 'Enter marks'}
                </Link>
              ) : null
            }
            empty={<EmptyState icon={ClipboardList} title="No subjects yet" description="Add subjects to the exam to track marks entry." />}
          />
        </Card>
      </Reveal>
    </div>
  );
}
