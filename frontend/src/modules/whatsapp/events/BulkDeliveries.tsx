import { useState } from 'react';
import { Ban, Layers, Play } from 'lucide-react';
import { Badge, Button, Card, CardHeader, IconButton, ProgressBar, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, labelize, timeAgo } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';
import { useWaRefresh } from '../shared';
import type { WaEventFanout } from './types';

const STATUS: Record<WaEventFanout['status'], { label: string; color: BadgeColor }> = {
  pending: { label: 'Queued', color: 'amber' },
  running: { label: 'Sending', color: 'blue' },
  done: { label: 'Done', color: 'green' },
  failed: { label: 'Stopped', color: 'red' },
  cancelled: { label: 'Cancelled', color: 'slate' },
};

const ID_LABEL: Record<string, string> = { student_id: 'students', enquiry_id: 'enquiries', ticket_id: 'tickets', contact_id: 'contacts' };

/**
 * Bulk events (results, admit cards, timetable, notices, holidays, bulk assignments …) are stored as one background job
 * by the ERP action and expanded by the WhatsApp worker in batches — this card shows their progress.
 */
export function BulkDeliveries({ jobs }: { jobs: WaEventFanout[] }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const [busy, setBusy] = useState<string | null>(null);
  const open = jobs.filter((j) => j.status === 'pending' || j.status === 'running').length;

  const runNow = async () => {
    setBusy('run');
    try {
      const r = await api.post('whatsapp/events/fanouts/run');
      toast.success(r.message);
      await refresh('events');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const cancel = async (j: WaEventFanout) => {
    const ok = await confirm({
      title: `Cancel ${j.event_name ?? j.event_code}?`,
      message: `${formatNumber(j.total - j.processed)} recipient(s) not processed yet will not get this message. Those already processed keep it.`,
      confirmText: 'Cancel delivery',
      danger: true,
    });
    if (!ok) return;
    setBusy(`c${j.id}`);
    try {
      const r = await api.post(`whatsapp/events/fanouts/${j.id}/cancel`);
      toast.success(r.message);
      await refresh('events');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card className="mb-5 overflow-hidden">
      <CardHeader
        icon={Layers}
        title="Bulk deliveries"
        subtitle={open ? `${open} running in the background — the WhatsApp worker sends them in batches every minute.` : 'Bulk events of the last 24 hours. Each recipient is de-duplicated, so nobody gets the same message twice.'}
        actions={
          open > 0 && can('whatsapp_automation', 'manage') ? (
            <Button size="sm" variant="secondary" icon={Play} loading={busy === 'run'} onClick={runNow}>
              Process now
            </Button>
          ) : undefined
        }
      />
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {jobs.map((j) => {
          const s = STATUS[j.status] ?? STATUS.pending;
          const pct = j.total ? Math.round((j.processed / j.total) * 100) : 0;
          const active = j.status === 'pending' || j.status === 'running';
          const cancelBtn = active && can('whatsapp_automation', 'edit') ? (
            <IconButton icon={Ban} label="Cancel this delivery" size="sm" tone="danger" disabled={busy === `c${j.id}`} onClick={() => cancel(j)} />
          ) : null;
          return (
            <li key={j.id} className="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] sm:items-center sm:gap-4">
              <div className="flex min-w-0 items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate font-medium text-slate-900 dark:text-white">{j.event_name ?? labelize(j.event_code.replace(/^GIMT_/, '').toLowerCase())}</span>
                    <Badge color={s.color} dot>{s.label}</Badge>
                  </div>
                  <div className="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400">
                    <span className="font-mono">{j.codes.join(' + ')}</span> · {timeAgo(j.created_at)}{j.created_by_name ? ` by ${j.created_by_name}` : ''}
                  </div>
                  {j.error && <div className="mt-0.5 line-clamp-2 text-[11px] text-slate-500 dark:text-slate-400">{j.error}</div>}
                </div>
                {cancelBtn && <div className="shrink-0 sm:hidden">{cancelBtn}</div>}
              </div>
              <div className="min-w-0">
                <div className="mb-1 flex items-center justify-between gap-2 text-[11px] text-slate-600 dark:text-slate-300">
                  <span>
                    {formatNumber(j.processed)} / {formatNumber(j.total)} {ID_LABEL[j.id_key] ?? 'recipients'}
                  </span>
                  <span className="text-slate-500 dark:text-slate-400">
                    {formatNumber(j.fired)} fired{j.duplicates ? ` · ${formatNumber(j.duplicates)} duplicate` : ''}
                  </span>
                </div>
                <ProgressBar value={pct} tone={j.status === 'done' ? 'green' : j.status === 'failed' ? 'red' : j.status === 'cancelled' ? 'slate' : 'navy'} label={`${pct}% processed`} />
              </div>
              <div className="hidden justify-end sm:flex">{cancelBtn ?? <span className="h-8 w-8" />}</div>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
