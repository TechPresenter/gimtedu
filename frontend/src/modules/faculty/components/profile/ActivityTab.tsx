import { Activity, CheckCircle2, Download, FilePlus2, Pencil, Trash2, Upload, XCircle, type LucideIcon } from 'lucide-react';
import { Alert, Card, CardHeader, EmptyState, Reveal, Skeleton, Timeline, type Tone } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDateTime } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import type { ActivityRow, EmployeeType } from '../../hr';

const actionMeta: Record<string, { icon: LucideIcon; tone: Tone }> = {
  create: { icon: FilePlus2, tone: 'blue' },
  update: { icon: Pencil, tone: 'amber' },
  delete: { icon: Trash2, tone: 'red' },
  approve: { icon: CheckCircle2, tone: 'green' },
  reject: { icon: XCircle, tone: 'red' },
  export: { icon: Download, tone: 'cyan' },
  import: { icon: Upload, tone: 'purple' },
};
const moduleLabel: Record<string, string> = { faculty: 'Profile', staff: 'Profile', leaves: 'Leave', employee_documents: 'Document' };

/** Audit trail of the profile, its leave applications and documents. */
export default function ActivityTab({ type, id }: { type: EmployeeType; id: number }) {
  const { data, isLoading, error } = useApi<ActivityRow[]>(['hr-activity', type, id], `${type}/${id}/activity`);
  return (
    <Reveal>
      <Card>
        <CardHeader title="Activity" subtitle="Profile changes, leave decisions and document verification" icon={Activity} />
        <div className="card-body">
          {isLoading ? (
            <div className="space-y-5">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="flex gap-3">
                  <Skeleton className="h-8 w-8 !rounded-full" />
                  <div className="flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/3" /></div>
                </div>
              ))}
            </div>
          ) : error ? (
            <Alert variant="error">{(error as ApiError).message}</Alert>
          ) : !data?.length ? (
            <EmptyState icon={Activity} title="No activity yet" description="Changes to this profile, leave decisions and document checks will be listed here." />
          ) : (
            <Timeline
              items={data.map((a) => ({
                icon: actionMeta[a.action]?.icon ?? Activity,
                tone: actionMeta[a.action]?.tone ?? 'slate',
                title: a.description,
                description: `${moduleLabel[a.module] ?? a.module} · ${a.user_name ?? 'System'}`,
                time: <span title={formatDateTime(a.created_at)}>{a.time_ago}</span>,
              }))}
            />
          )}
        </div>
      </Card>
    </Reveal>
  );
}
