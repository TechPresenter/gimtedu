import { useState } from 'react';
import clsx from 'clsx';
import { Copy, Play, Timer } from 'lucide-react';
import { Button, Card, CardHeader, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, timeAgo } from '@/lib/format';
import { copyText } from '../shared';
import type { NewsletterSummary } from './types';

/**
 * Scheduled-task set-up for administrators: the token URL a server cron job calls every few minutes to start due
 * campaigns and deliver paused / pending sends without anyone keeping the Campaigns screen open.
 */
export function CronCard({ cron, onRan }: { cron: NewsletterSummary['cron']; onRan: () => void }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  if (!cron.url) return null;
  const last = cron.last_run ? new Date(cron.last_run.replace(' ', 'T')).getTime() : 0;
  const healthy = !!last && Date.now() - last < 30 * 60 * 1000;
  const token = new URL(cron.url, window.location.origin).searchParams.get('token') ?? '';

  const copy = async (text: string) => {
    if (await copyText(text)) toast.success('Copied to the clipboard.');
    else toast.error('Copy failed — select the text and copy it manually.');
  };

  const runNow = async () => {
    setBusy(true);
    try {
      const res = await api.getFull('newsletter/cron', { token });
      toast.success(res.message || 'Scheduled task completed.');
      onRan();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const rows = [
    { label: 'Linux / cPanel cron (every 5 minutes)', value: cron.command ?? '' },
    { label: 'Windows Task Scheduler', value: cron.windows ?? '' },
  ];

  return (
    <Card>
      <CardHeader
        title="Automatic delivery"
        subtitle="A server scheduled task starts campaigns at their scheduled time and finishes paused sends — no one needs to keep this screen open."
        icon={Timer}
        actions={
          <Button size="sm" variant="secondary" icon={Play} loading={busy} onClick={() => void runNow()}>
            Run now
          </Button>
        }
      />
      <div className="card-body space-y-3">
        <p className="flex flex-wrap items-center gap-2 text-sm">
          <span className={clsx('inline-flex h-2 w-2 rounded-full', healthy ? 'bg-emerald-500' : 'bg-amber-500')} aria-hidden />
          {cron.last_run ? (
            <span className="text-slate-700 dark:text-slate-200">
              Last run <strong>{timeAgo(cron.last_run)}</strong> <span className="text-slate-500">({formatDateTime(cron.last_run)})</span>
              {!healthy && <span className="text-amber-700 dark:text-amber-400"> — the task does not seem to run regularly.</span>}
            </span>
          ) : (
            <span className="text-slate-700 dark:text-slate-200">Not set up yet — until it is, scheduled campaigns start when someone opens this screen.</span>
          )}
        </p>
        {rows.map((r) => (
          <div key={r.label}>
            <p className="mb-1 text-xs font-medium text-slate-500 dark:text-slate-400">{r.label}</p>
            <div className="flex items-stretch gap-2">
              <code className="min-w-0 flex-1 overflow-x-auto whitespace-nowrap rounded-lg bg-slate-100 px-3 py-2 font-mono text-[11px] text-slate-700 dark:bg-slate-800 dark:text-slate-200">{r.value}</code>
              <Button size="sm" variant="secondary" icon={Copy} onClick={() => void copy(r.value)} aria-label={`Copy: ${r.label}`}>
                Copy
              </Button>
            </div>
          </div>
        ))}
        <p className="text-xs text-slate-500 dark:text-slate-400">Keep this address private — it contains a secret token derived from the application key.</p>
      </div>
    </Card>
  );
}
