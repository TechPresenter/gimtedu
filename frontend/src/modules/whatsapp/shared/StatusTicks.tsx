import clsx from 'clsx';
import { Ban, Check, CheckCheck, CircleAlert, Clock3, FlaskConical } from 'lucide-react';
import type { WaMessageStatus } from './types';

const LABELS: Record<string, string> = {
  queued: 'Queued — waiting to be sent',
  sent: 'Sent',
  delivered: 'Delivered',
  read: 'Read',
  failed: 'Failed',
  simulated: 'Simulated (test mode — not sent to WhatsApp)',
  cancelled: 'Cancelled',
  received: 'Received',
};

/**
 * WhatsApp delivery ticks: queued = clock, sent = ✓, delivered = ✓✓, read = blue ✓✓, failed = red !,
 * simulated = flask (test mode), cancelled = ban. Inbound ('received') renders nothing.
 */
export function StatusTicks({ status, className, title, size = 14 }: { status: WaMessageStatus | string | null | undefined; className?: string; title?: string; size?: number }) {
  if (!status || status === 'received') return null;
  const label = title ?? LABELS[status] ?? status;
  const common = { size, strokeWidth: 2.25, 'aria-hidden': true } as const;
  let icon;
  switch (status) {
    case 'queued':
      icon = <Clock3 {...common} className="text-slate-400 dark:text-slate-500" />;
      break;
    case 'sent':
      icon = <Check {...common} className="text-slate-400 dark:text-slate-400" />;
      break;
    case 'delivered':
      icon = <CheckCheck {...common} className="text-slate-400 dark:text-slate-400" />;
      break;
    case 'read':
      icon = <CheckCheck {...common} className="text-[#53BDEB]" />;
      break;
    case 'failed':
      icon = <CircleAlert {...common} className="text-red-500 dark:text-red-400" />;
      break;
    case 'simulated':
      icon = <FlaskConical {...common} className="text-amber-500 dark:text-amber-400" />;
      break;
    case 'cancelled':
      icon = <Ban {...common} className="text-slate-400" />;
      break;
    default:
      icon = <Check {...common} className="text-slate-400" />;
  }
  return (
    <span className={clsx('inline-flex shrink-0 items-center', className)} title={label} role="img" aria-label={label}>
      {icon}
    </span>
  );
}

export default StatusTicks;
