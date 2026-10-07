import { Clock, Globe, Monitor, User } from 'lucide-react';
import { Alert, Avatar, Button, DescriptionList, Modal, Skeleton, StatusBadge } from '@/components/ui';
import type { ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatDateTime, timeAgo } from '@/lib/format';
import { ActionBadge, ActionIcon, JsonView } from '../components';
import type { ActivityItem } from '../types';

interface Detail extends ActivityItem {
  user_designation: string | null;
  user_agent: string | null;
  meta: unknown;
  related_count: number;
}

/** Full audit entry: who, what, where, browser and the structured meta payload. */
export function ActivityDetailModal({ id, onClose, onRelated }: { id: number | null; onClose: () => void; onRelated?: (module: string, record: string) => void }) {
  const { data: d, isLoading, error } = useApi<Detail>(['activity-detail', id], `activity-logs/${id}`, undefined, { enabled: !!id });
  return (
    <Modal
      open={!!id}
      onClose={onClose}
      size="lg"
      title="Activity details"
      description={d ? `Log entry #${d.id}` : undefined}
      icon={d ? <ActionIcon action={d.action} status={d.status} /> : undefined}
      footer={
        <>
          {d && d.record_id && d.related_count > 0 && onRelated && (
            <Button variant="secondary" onClick={() => onRelated(d.module, d.record_id as string)}>
              {d.related_count} other event{d.related_count === 1 ? '' : 's'} for this record
            </Button>
          )}
          <Button onClick={onClose}>Close</Button>
        </>
      }
    >
      {error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : isLoading || !d ? (
        <div className="space-y-3">
          <Skeleton className="h-6 w-2/3" />
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-32 w-full" />
        </div>
      ) : (
        <div className="space-y-5">
          <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/30">
            <div className="flex flex-wrap items-center gap-2">
              <ActionBadge action={d.action} status={d.status} />
              <span className="text-xs font-medium text-slate-500">{d.module_label}</span>
              <span className="ml-auto">
                <StatusBadge status={d.status} />
              </span>
            </div>
            <p className="mt-2 text-[15px] font-medium leading-relaxed text-slate-900 dark:text-white">{d.description || '—'}</p>
          </div>
          <div className="flex items-center gap-3">
            {d.user_name ? <Avatar name={d.user_name} src={d.user_avatar} size="lg" /> : <span className="kpi-icon bg-slate-100 text-slate-500 dark:bg-slate-800"><User className="h-5 w-5" /></span>}
            <div className="min-w-0">
              <p className="font-semibold text-slate-900 dark:text-white">{d.user_name ?? 'System / automated task'}</p>
              <p className="truncate text-xs text-slate-500">{[d.user_designation, d.user_email].filter(Boolean).join(' · ') || 'No signed-in user'}</p>
            </div>
          </div>
          <DescriptionList
            items={[
              { label: 'Date & time', value: <span className="inline-flex items-center gap-1.5"><Clock className="h-3.5 w-3.5 text-slate-400" />{formatDateTime(d.created_at)} <span className="text-xs font-normal text-slate-500">({timeAgo(d.created_at)})</span></span> },
              { label: 'Record', value: d.record_id ? <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">#{d.record_id}</code> : null },
              { label: 'IP address', value: d.ip_address ? <span className="inline-flex items-center gap-1.5"><Globe className="h-3.5 w-3.5 text-slate-400" />{d.ip_address}</span> : null },
              { label: 'Browser / device', value: <span className="inline-flex items-center gap-1.5"><Monitor className="h-3.5 w-3.5 text-slate-400" />{d.browser}</span> },
              { label: 'User agent', value: d.user_agent ? <span className="break-all font-mono text-[11px] font-normal text-slate-500">{d.user_agent}</span> : null, full: true },
            ]}
          />
          <div>
            <p className="mb-1.5 text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Additional data (meta)</p>
            {d.meta ? <JsonView data={d.meta} /> : <p className="rounded-xl border border-dashed border-slate-200 px-4 py-4 text-center text-sm text-slate-500 dark:border-slate-700">No additional data was recorded for this event.</p>}
          </div>
        </div>
      )}
    </Modal>
  );
}
