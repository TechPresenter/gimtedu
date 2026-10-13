import { useState } from 'react';
import { Activity, CheckCircle2, FilePlus2, Pencil, RefreshCcw, Trash2, TrendingUp, XCircle, type LucideIcon } from 'lucide-react';
import { Card, CardBody, CardHeader, EmptyState, Pagination, Timeline, type Tone } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDateTime, labelize, timeAgo } from '@/lib/format';
import type { ActivityRow, PagedRows } from '../types';
import { TabError, TabLoading, type TabProps } from './shared';

const look: Record<string, { icon: LucideIcon; tone: Tone }> = {
  create: { icon: FilePlus2, tone: 'green' },
  update: { icon: Pencil, tone: 'blue' },
  delete: { icon: Trash2, tone: 'red' },
  status: { icon: RefreshCcw, tone: 'amber' },
  approve: { icon: CheckCircle2, tone: 'green' },
  reject: { icon: XCircle, tone: 'red' },
  promote: { icon: TrendingUp, tone: 'purple' },
};

export default function ActivityTab({ studentId }: TabProps) {
  const [page, setPage] = useState(1);
  const q = useApi<PagedRows<ActivityRow>>(['students', studentId, 'activity', page], `students/${studentId}/activity`, { page, per_page: 20 });
  if (q.isLoading) return <TabLoading cards={1} />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data!;
  return (
    <Card>
      <CardHeader title="Activity history" subtitle={`${d.total} event${d.total === 1 ? '' : 's'} — profile edits, status changes, documents and records mentioning this student`} icon={Activity} />
      {d.rows.length === 0 ? (
        <EmptyState icon={Activity} title="No activity recorded" description="Every change made to this student's record (by any staff member) is logged here with who made it and when." />
      ) : (
        <>
          <CardBody>
            <Timeline
              items={d.rows.map((a) => ({
                icon: look[a.action]?.icon ?? Activity,
                tone: a.status === 'failed' ? 'red' : look[a.action]?.tone ?? 'slate',
                title: a.description,
                description: `${labelize(a.action)} · ${labelize(a.module)}`,
                time: <span title={formatDateTime(a.created_at)}>{a.user_name ?? 'System'} · {timeAgo(a.created_at)}</span>,
              }))}
            />
          </CardBody>
          {d.pages > 1 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={d.page} pages={d.pages} total={d.total} perPage={d.per_page} onPage={setPage} />}
        </>
      )}
    </Card>
  );
}
