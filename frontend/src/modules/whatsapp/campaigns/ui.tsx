import clsx from 'clsx';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  AlarmClock,
  Award,
  BookOpen,
  CalendarDays,
  ClipboardList,
  GraduationCap,
  IndianRupee,
  Megaphone,
  Sparkles,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { Badge, useConfirm, useToast } from '@/components/ui';
import { ApiError, api, apiUrl } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';
import { useQueryClient } from '@tanstack/react-query';
import { cmpKeys, useCampaignMeta, useCampaignRefresh } from './api';
import { editorPath } from './lib';
import type { Campaign, CampaignStatus } from './types';

export const CMP_BREAD = [{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }];

export const STATUS_COLORS: Record<CampaignStatus, BadgeColor> = {
  draft: 'slate',
  scheduled: 'blue',
  sending: 'amber',
  paused: 'purple',
  completed: 'green',
  cancelled: 'slate',
  failed: 'red',
};

export const TYPE_ICONS: Record<string, LucideIcon> = {
  admission: GraduationCap,
  course_promotion: BookOpen,
  scholarship: Award,
  admission_closing: AlarmClock,
  exam: ClipboardList,
  fee_reminder: IndianRupee,
  event: CalendarDays,
  alumni: Users,
  festival: Sparkles,
  announcement: Megaphone,
};

/** Campaign status badge; "sending" gets a live pulse. */
export function CampaignStatusBadge({ status }: { status: CampaignStatus }) {
  if (status === 'sending') {
    return (
      <span className="badge badge-amber">
        <span className="relative flex h-1.5 w-1.5" aria-hidden>
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-60 motion-reduce:animate-none" />
          <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-current" />
        </span>
        Sending
      </span>
    );
  }
  return (
    <Badge color={STATUS_COLORS[status] ?? 'slate'} dot>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </Badge>
  );
}

export function TypeIcon({ type, className }: { type: string; className?: string }) {
  const Icon = TYPE_ICONS[type] ?? Megaphone;
  return <Icon className={className ?? 'h-4 w-4'} aria-hidden />;
}

/** Rounded icon tile for a campaign type (navy for utility-ish, accent for marketing-ish types). */
export function TypeTile({ type, size = 'md' }: { type: string; size?: 'sm' | 'md' }) {
  const marketing = ['admission', 'course_promotion', 'scholarship', 'admission_closing', 'event', 'alumni', 'festival'].includes(type);
  return (
    <span
      className={clsx(
        'inline-flex shrink-0 items-center justify-center rounded-xl',
        size === 'sm' ? 'h-8 w-8' : 'h-10 w-10',
        marketing ? 'bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300' : 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200',
      )}
    >
      <TypeIcon type={type} className={size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'} />
    </span>
  );
}

export function CategoryBadge({ category }: { category: string | null | undefined }) {
  if (!category) return null;
  const c = category.toUpperCase();
  return <Badge color={c === 'MARKETING' ? 'amber' : c === 'UTILITY' ? 'blue' : 'purple'}>{c.charAt(0) + c.slice(1).toLowerCase()}</Badge>;
}

const SEGMENTS: { key: 'read' | 'delivered' | 'sent' | 'failed' | 'skipped'; label: string; cls: string }[] = [
  { key: 'read', label: 'Read', cls: 'bg-brand-700 dark:bg-brand-400' },
  { key: 'delivered', label: 'Delivered', cls: 'bg-emerald-500' },
  { key: 'sent', label: 'Sent', cls: 'bg-sky-400' },
  { key: 'failed', label: 'Failed', cls: 'bg-red-500' },
  { key: 'skipped', label: 'Skipped', cls: 'bg-slate-300 dark:bg-slate-600' },
];

/** Exclusive counts for the stacked delivery bar. */
export function deliveryParts(c: Pick<Campaign, 'total_recipients' | 'sent_count' | 'delivered_count' | 'read_count' | 'failed_count' | 'skipped_count'>) {
  return {
    read: c.read_count,
    delivered: Math.max(0, c.delivered_count - c.read_count),
    sent: Math.max(0, c.sent_count - c.delivered_count),
    failed: c.failed_count,
    skipped: c.skipped_count,
    pending: Math.max(0, c.total_recipients - c.sent_count - c.failed_count - c.skipped_count),
  };
}

/** Stacked bar: read / delivered / sent / failed / skipped over the whole audience (rest = waiting). */
export function DeliveryBar({ campaign, className, legend = false, height = 'h-2' }: { campaign: Campaign; className?: string; legend?: boolean; height?: string }) {
  const parts = deliveryParts(campaign);
  const total = Math.max(1, campaign.total_recipients);
  const label = SEGMENTS.map((s) => `${s.label} ${formatNumber(parts[s.key])}`).join(', ') + `, waiting ${formatNumber(parts.pending)}`;
  return (
    <div className={className}>
      <div className={clsx('flex w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800', height)} role="img" aria-label={label} title={label}>
        {SEGMENTS.map((s) =>
          parts[s.key] > 0 ? <span key={s.key} className={clsx('h-full transition-all duration-500', s.cls)} style={{ width: `${(parts[s.key] / total) * 100}%` }} /> : null,
        )}
      </div>
      {legend && (
        <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
          {SEGMENTS.map((s) => (
            <li key={s.key} className="inline-flex items-center gap-1.5">
              <span className={clsx('h-2 w-2 rounded-full', s.cls)} aria-hidden />
              {s.label} <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(parts[s.key])}</span>
            </li>
          ))}
          <li className="inline-flex items-center gap-1.5">
            <span className="h-2 w-2 rounded-full bg-slate-100 ring-1 ring-slate-300 dark:bg-slate-800 dark:ring-slate-600" aria-hidden />
            Waiting <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(parts.pending)}</span>
          </li>
        </ul>
      )}
    </div>
  );
}

/** Small "label: value" stat used inside cards. */
export function MiniStat({ label, value, sub, tone }: { label: string; value: ReactNode; sub?: ReactNode; tone?: 'green' | 'red' | 'navy' | 'amber' | 'slate' }) {
  const cls = {
    green: 'text-emerald-700 dark:text-emerald-300',
    red: 'text-red-600 dark:text-red-300',
    navy: 'text-brand-800 dark:text-brand-200',
    amber: 'text-amber-700 dark:text-amber-300',
    slate: 'text-slate-900 dark:text-white',
  }[tone ?? 'slate'];
  return (
    <div className="min-w-0">
      <p className="truncate text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
      <p className={clsx('font-display text-lg font-bold tabular-nums leading-tight', cls)}>{value}</p>
      {sub && <p className="truncate text-xs text-slate-500 dark:text-slate-400">{sub}</p>}
    </div>
  );
}

export function rate(v: number | null | undefined) {
  return v === null || v === undefined ? '—' : `${v.toLocaleString('en-IN', { maximumFractionDigits: 1 })}%`;
}

/**
 * Download a report through fetch so a refusal (403 export not allowed, 404 …) shows as a message instead of the
 * browser navigating away from the admin to a JSON error page. Resolves with the file name.
 */
export async function downloadReport(path: string, query?: Record<string, string | number | undefined>, fallbackName = 'report.csv'): Promise<string> {
  const res = await fetch(apiUrl(path, query), { credentials: 'same-origin', headers: { Accept: 'text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,*/*' } });
  const type = res.headers.get('Content-Type') ?? '';
  if (!res.ok || type.includes('application/json')) {
    let msg = `The file could not be downloaded (HTTP ${res.status}).`;
    try {
      const j = (await res.json()) as { message?: string };
      if (j?.message) msg = j.message;
    } catch {
      /* not JSON */
    }
    throw new Error(msg);
  }
  const blob = await res.blob();
  const cd = res.headers.get('Content-Disposition') ?? '';
  const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
  const name = m ? decodeURIComponent(m[1]) : fallbackName;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
  return name;
}

/** Shared campaign actions (list + detail): confirm dialogs, API calls, toasts, refresh. */
export function useCampaignActions() {
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCampaignRefresh();
  const navigate = useNavigate();
  const { can, session } = useAuth();
  const qc = useQueryClient();
  const meta = useCampaignMeta();
  const myId = session?.user?.id ?? 0;
  // Settings › Security "who can export" narrows the role's export permission (server decides; meta mirrors it)
  const canExport = can('whatsapp_campaigns', 'export') && (meta.data?.can_export ?? true);
  const xlsx = meta.data?.xlsx ?? true;

  const run = async (fn: () => Promise<{ message: string; data?: unknown }>) => {
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh();
      return res;
    } catch (e) {
      toast.error((e as ApiError).message);
      return null;
    }
  };

  return {
    canCreate: can('whatsapp_campaigns', 'create'),
    /** Same rule as the server: editors change any draft, creators (create permission) only their own drafts. */
    canEditCampaign: (c: Pick<Campaign, 'status' | 'created_by'>) =>
      ['draft', 'scheduled'].includes(c.status) && (can('whatsapp_campaigns', 'edit') || (can('whatsapp_campaigns', 'create') && !!myId && c.created_by === myId)),
    canPublish: can('whatsapp_campaigns', 'publish'),
    /** Editor URL for this user (see editorPath). */
    editPath: (id: number, step?: string) => editorPath(id, step, can('whatsapp_campaigns', 'edit')),
    canDelete: can('whatsapp_campaigns', 'delete'),
    canExport,
    /** Excel export available (PHP zip extension); otherwise the server sends CSV. */
    xlsx,
    canManage: can('whatsapp_campaigns', 'manage'),
    pause: async (c: Campaign) => {
      if (!(await confirm({ title: `Pause “${c.name}”?`, message: 'Messages already handed to WhatsApp still deliver. Queued messages are held back until you resume.', confirmText: 'Pause' }))) return;
      await run(() => api.post(`whatsapp/campaigns/${c.id}/pause`));
    },
    resume: async (c: Campaign) => run(() => api.post(`whatsapp/campaigns/${c.id}/resume`)),
    cancel: async (c: Campaign) => {
      const left = Math.max(0, c.total_recipients - c.sent_count - c.failed_count - c.skipped_count);
      if (
        !(await confirm({
          title: `Cancel “${c.name}”?`,
          message:
            c.status === 'scheduled'
              ? 'The campaign will not be sent. You can duplicate it later to send it again.'
              : `${formatNumber(left)} recipient(s) who have not been messaged yet will be skipped. This cannot be undone.`,
          confirmText: 'Cancel campaign',
          danger: true,
        }))
      )
        return;
      await run(() => api.post(`whatsapp/campaigns/${c.id}/cancel`));
    },
    retry: async (c: Campaign) => {
      if (!(await confirm({ title: 'Retry failed messages?', message: `${formatNumber(c.failed_count)} failed message(s) of “${c.name}” will be queued again. Numbers that are not on WhatsApp will fail again.`, confirmText: 'Retry' }))) return;
      await run(() => api.post(`whatsapp/campaigns/${c.id}/retry`));
    },
    duplicate: async (c: Campaign) => {
      const res = await run(() => api.post<Campaign>(`whatsapp/campaigns/${c.id}/duplicate`));
      const id = (res?.data as Campaign | undefined)?.id;
      if (id) navigate(editorPath(id, undefined, can('whatsapp_campaigns', 'edit')));
    },
    remove: async (c: Campaign, after?: () => void) => {
      if (!(await confirm({ title: `Delete “${c.name}”?`, message: c.status === 'draft' ? 'The draft and its uploaded list are deleted.' : 'The campaign and its recipient report are deleted. Sent messages stay in the chats.', confirmText: 'Delete', danger: true }))) return;
      try {
        const res = await api.del(`whatsapp/campaigns/${c.id}`);
        toast.success(res.message);
        after?.(); // leave the detail page first so its query is not refetched for a deleted campaign
        qc.removeQueries({ queryKey: cmpKeys.detail(c.id) });
        qc.removeQueries({ queryKey: ['wa', 'campaigns', 'recipients', c.id] });
        await refresh();
      } catch (e) {
        toast.error((e as ApiError).message);
      }
    },
    exportReport: async (c: Campaign, format: 'csv' | 'xlsx', status?: string) => {
      toast.info(`Preparing the ${format === 'xlsx' && xlsx ? 'Excel' : 'CSV'} report…`);
      try {
        const name = await downloadReport(`whatsapp/campaigns/${c.id}/export`, { format, status: status || undefined }, `whatsapp-campaign-${c.id}.${format}`);
        toast.success(`Downloaded ${name}`);
      } catch (e) {
        toast.error((e as Error).message);
      }
    },
  };
}
