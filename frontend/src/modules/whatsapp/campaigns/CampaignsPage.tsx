import { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import {
  BarChart3,
  CalendarClock,
  CheckCheck,
  Copy,
  Download,
  Eye,
  Megaphone,
  MessageCircleReply,
  MoreHorizontal,
  Pause,
  Pencil,
  Play,
  Plus,
  RotateCcw,
  Send,
  Trash2,
  TriangleAlert,
  XCircle,
} from 'lucide-react';
import {
  Alert,
  Button,
  Card,
  DataTable,
  Dropdown,
  EmptyState,
  Input,
  PageHeader,
  Pagination,
  SearchInput,
  Select,
  Stagger,
  StatCard,
  Tabs,
  type Column,
  type DropdownItem,
} from '@/components/ui';
import { formatDateTime, formatNumber, pluralize, timeAgo } from '@/lib/format';
import { useDebounce, useMediaQuery } from '@/lib/hooks';
import { ModeBanner } from '../shared';
import { useCampaignList, useCampaignMeta } from './api';
import { futureText } from './lib';
import { CMP_BREAD, CampaignStatusBadge, CategoryBadge, DeliveryBar, TypeTile, rate, useCampaignActions } from './ui';
import type { Campaign } from './types';

const TABS: { key: string; label: string }[] = [
  { key: '', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'draft', label: 'Drafts' },
  { key: 'scheduled', label: 'Scheduled' },
  { key: 'sending', label: 'Sending' },
  { key: 'paused', label: 'Paused' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
  { key: 'failed', label: 'Failed' },
];

function scheduleCell(c: Campaign) {
  if (c.status === 'scheduled' && c.scheduled_at) return { label: 'Scheduled', at: c.scheduled_at };
  if (c.status === 'completed' && c.completed_at) return { label: 'Completed', at: c.completed_at };
  if (c.status === 'cancelled' && c.cancelled_at) return { label: 'Cancelled', at: c.cancelled_at };
  if (c.started_at) return { label: 'Started', at: c.started_at };
  return { label: 'Created', at: c.created_at };
}

export default function CampaignsPage() {
  const navigate = useNavigate();
  const actions = useCampaignActions();
  const [params, setParams] = useSearchParams();
  const status = params.get('status') ?? '';
  const type = params.get('type') ?? '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const [q, setQ] = useState(params.get('q') ?? '');
  const dq = useDebounce(q, 300);
  const [from, setFrom] = useState(params.get('from') ?? '');
  const [to, setTo] = useState(params.get('to') ?? '');
  const meta = useCampaignMeta();
  const wide = useMediaQuery('(min-width: 768px)');

  const setParam = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v);
    else next.delete(k);
    if (k !== 'page') next.delete('page');
    setParams(next, { replace: true });
  };

  const query = useMemo(() => ({ status: status || undefined, type: type || undefined, q: dq || undefined, from: from || undefined, to: to || undefined, page, per_page: 15 }), [status, type, dq, from, to, page]);
  const list = useCampaignList(query);
  const s = list.data?.summary;
  const rows = list.data?.data ?? [];
  const filtered = !!(status || type || dq || from || to);

  const rowMenu = (c: Campaign): DropdownItem[] =>
    [
      { label: 'View report', icon: Eye, to: `/whatsapp/campaigns/${c.id}` },
      actions.canEditCampaign(c) && { label: c.status === 'scheduled' ? 'Edit (back to draft)' : 'Continue editing', icon: Pencil, to: actions.editPath(c.id) },
      !actions.canEditCampaign(c) && actions.canPublish && c.status === 'draft' && { label: 'Review & send', icon: Send, to: actions.editPath(c.id, 'review') },
      actions.canPublish && c.status === 'sending' && { label: 'Pause', icon: Pause, onClick: () => actions.pause(c) },
      actions.canPublish && c.status === 'paused' && { label: 'Resume', icon: Play, onClick: () => actions.resume(c) },
      actions.canPublish && c.failed_count > 0 && ['completed', 'sending', 'paused', 'failed'].includes(c.status) && { label: `Retry ${formatNumber(c.failed_count)} failed`, icon: RotateCcw, onClick: () => actions.retry(c) },
      actions.canCreate && { label: 'Duplicate', icon: Copy, onClick: () => actions.duplicate(c) },
      actions.canExport && !!c.started_at && { label: actions.xlsx ? 'Export report (Excel)' : 'Export report (CSV)', icon: Download, onClick: () => actions.exportReport(c, actions.xlsx ? 'xlsx' : 'csv') },
      actions.canPublish && ['scheduled', 'sending', 'paused'].includes(c.status) && { label: 'Cancel campaign', icon: XCircle, danger: true, divider: true, onClick: () => actions.cancel(c) },
      actions.canDelete &&
        (['draft', 'cancelled', 'failed'].includes(c.status) || (c.status === 'completed' && actions.canManage)) && { label: 'Delete', icon: Trash2, danger: true, divider: true, onClick: () => actions.remove(c) },
    ].filter(Boolean) as DropdownItem[];

  const columns: Column<Campaign>[] = [
    {
      key: 'name',
      header: 'Campaign',
      render: (c) => (
        <div className="flex min-w-[15rem] items-start gap-3">
          <TypeTile type={c.type} size="sm" />
          <div className="min-w-0">
            <Link to={`/whatsapp/campaigns/${c.id}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
              {c.name}
            </Link>
            <p className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
              <span>{c.type_label}</span>
              {c.template && (
                <>
                  <span aria-hidden>·</span>
                  <span className="truncate font-mono text-[11px]">{c.template.name}</span>
                  <CategoryBadge category={c.template.category} />
                </>
              )}
            </p>
          </div>
        </div>
      ),
    },
    { key: 'status', header: 'Status', render: (c) => <CampaignStatusBadge status={c.status} /> },
    {
      key: 'audience',
      header: 'Audience',
      align: 'right',
      render: (c) => (
        <div className="whitespace-nowrap text-right">
          <p className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(c.total_recipients - c.skipped_count)}</p>
          <p className="text-xs text-slate-500 dark:text-slate-400">{c.skipped_count > 0 ? `${formatNumber(c.skipped_count)} skipped` : `of ${formatNumber(c.total_recipients)}`}</p>
        </div>
      ),
    },
    {
      key: 'delivery',
      header: 'Delivery',
      render: (c) =>
        c.started_at ? (
          <div className="w-56 max-w-full">
            <p className="mb-1 flex items-baseline justify-between text-xs">
              <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{c.progress.percent}% done</span>
              <span className="text-slate-500 dark:text-slate-400">read {rate(c.progress.read_rate)}</span>
            </p>
            <DeliveryBar campaign={c} />
            <div className="mt-1.5 grid grid-cols-5 gap-1 text-[11px] leading-tight text-slate-500 dark:text-slate-400">
              <span title="Sent">
                <b className="block font-semibold tabular-nums text-slate-800 dark:text-slate-100">{formatNumber(c.sent_count)}</b>sent
              </span>
              <span title="Delivered">
                <b className="block font-semibold tabular-nums text-emerald-700 dark:text-emerald-300">{formatNumber(c.delivered_count)}</b>deliv.
              </span>
              <span title="Read">
                <b className="block font-semibold tabular-nums text-brand-700 dark:text-brand-300">{formatNumber(c.read_count)}</b>read
              </span>
              <span title="Replied">
                <b className="block font-semibold tabular-nums text-accent-700 dark:text-accent-300">{formatNumber(c.replied_count)}</b>replied
              </span>
              <span title="Failed">
                <b className={c.failed_count ? 'block font-semibold tabular-nums text-red-600 dark:text-red-300' : 'block font-semibold tabular-nums text-slate-800 dark:text-slate-100'}>{formatNumber(c.failed_count)}</b>failed
              </span>
            </div>
          </div>
        ) : (
          <span className="text-xs text-slate-500 dark:text-slate-400">{c.status === 'scheduled' ? 'Starts at the scheduled time' : 'Not sent yet'}</span>
        ),
    },
    {
      key: 'schedule',
      header: 'Schedule',
      render: (c) => {
        const x = scheduleCell(c);
        return (
          <div className="whitespace-nowrap text-sm">
            <p className="text-slate-800 dark:text-slate-100">{formatDateTime(x.at)}</p>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              {x.label} · {futureText(x.at) ?? timeAgo(x.at)}
            </p>
          </div>
        );
      },
    },
  ];

  return (
    <div>
      <PageHeader
        title="Bulk Campaigns"
        description="Broadcast approved WhatsApp templates to students, applicants, parents and alumni who opted in to WhatsApp — with throttling and delivery reports."
        breadcrumbs={[...CMP_BREAD, { label: 'Campaigns' }]}
        actions={
          <>
            <Button variant="secondary" icon={BarChart3} to="/whatsapp/reports">
              Reports
            </Button>
            {actions.canCreate && (
              <Button icon={Plus} to="/whatsapp/campaigns/new">
                New campaign
              </Button>
            )}
          </>
        }
      />
      <ModeBanner />

      {list.error && !list.data ? (
        <Alert variant="error" title="Campaigns could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => list.refetch()}>Retry</Button>}>
          {(list.error as Error).message}
        </Alert>
      ) : (
        <>
          <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-6" step={50}>
            {[
              <StatCard key="c" label="Campaigns" value={s?.total ?? 0} icon={Megaphone} tone="navy" loading={!s} hint={s ? `${formatNumber(s.active)} active · ${pluralize(Number(s.by_status.draft ?? 0), 'draft')}` : undefined} />,
              <StatCard key="s" label="Messages sent (30 days)" value={s?.sent_30d ?? 0} icon={Send} tone="orange" loading={!s} hint={s ? `${formatNumber(s.audience_30d)} in audiences` : undefined} />,
              <StatCard key="d" label="Delivery rate" value={rate(s?.delivery_rate)} icon={CheckCheck} tone="green" loading={!s} hint={s ? `${formatNumber(s.delivered_30d)} delivered` : undefined} />,
              <StatCard key="r" label="Read rate" value={rate(s?.read_rate)} icon={Eye} tone="blue" loading={!s} hint={s ? `${formatNumber(s.read_30d)} read` : undefined} />,
              <StatCard key="p" label="Reply rate" value={rate(s?.reply_rate)} icon={MessageCircleReply} tone="purple" loading={!s} hint={s ? `${pluralize(Number(s.replied_30d ?? 0), 'reply', 'replies')} within 72 h` : undefined} />,
              <StatCard key="f" label="Failed (30 days)" value={s?.failed_30d ?? 0} icon={TriangleAlert} tone="red" loading={!s} hint={s ? `${formatNumber(s.skipped_30d)} skipped by policy` : undefined} />,
            ]}
          </Stagger>

          {s?.next_scheduled && (
            <Link
              to={`/whatsapp/campaigns/${s.next_scheduled.id}`}
              className="mt-4 flex items-center gap-3 rounded-2xl border border-brand-100 bg-brand-50/60 px-4 py-3 text-sm transition hover:-translate-y-0.5 hover:shadow-soft dark:border-brand-500/20 dark:bg-brand-500/[.08]"
            >
              <CalendarClock className="h-5 w-5 shrink-0 text-brand-700 dark:text-brand-300" aria-hidden />
              <span className="min-w-0 flex-1 text-slate-700 dark:text-slate-200">
                Next scheduled: <b className="text-slate-900 dark:text-white">{s.next_scheduled.name}</b> on {formatDateTime(s.next_scheduled.scheduled_at)}
              </span>
              <span className="hidden text-xs font-semibold text-brand-700 dark:text-brand-300 sm:inline">View →</span>
            </Link>
          )}

          <Card className="mt-6 overflow-hidden">
            <div className="space-y-3 border-b border-slate-100 p-4 dark:border-slate-800">
              <Tabs
                variant="pills"
                value={status}
                onChange={(k) => setParam('status', k)}
                tabs={TABS.map((t) => ({
                  ...t,
                  count: s ? (t.key === '' ? s.total : t.key === 'active' ? s.active : s.by_status[t.key as keyof typeof s.by_status]) : undefined,
                }))}
              />
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_14rem_10rem_10rem]">
                <SearchInput
                  value={q}
                  onChange={(v) => {
                    setQ(v);
                    setParam('q', v);
                  }}
                  placeholder="Search campaigns or templates…"
                />
                <Select
                  aria-label="Campaign type"
                  value={type}
                  onChange={(e) => setParam('type', e.target.value)}
                  placeholder="All types"
                  options={(meta.data?.types ?? []).map((t) => ({ value: t.value, label: t.label }))}
                />
                <Input
                  type="date"
                  aria-label="From date"
                  value={from}
                  max={to || undefined}
                  onChange={(e) => {
                    setFrom(e.target.value);
                    setParam('from', e.target.value);
                  }}
                />
                <Input
                  type="date"
                  aria-label="To date"
                  value={to}
                  min={from || undefined}
                  onChange={(e) => {
                    setTo(e.target.value);
                    setParam('to', e.target.value);
                  }}
                />
              </div>
            </div>
            {!wide && rows.length > 0 ? (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {rows.map((c) => {
                  const x = scheduleCell(c);
                  return (
                    <li key={c.id} className="flex gap-3 px-4 py-4">
                      <TypeTile type={c.type} size="sm" />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-start gap-2">
                          <Link to={`/whatsapp/campaigns/${c.id}`} className="min-w-0 flex-1 font-semibold leading-snug text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {c.name}
                          </Link>
                          <Dropdown label={`Actions for ${c.name}`} triggerClassName="btn-icon -mr-1 !h-8 !w-8" trigger={<MoreHorizontal className="h-4 w-4" />} items={rowMenu(c)} />
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                          <CampaignStatusBadge status={c.status} />
                          <span>{c.type_label}</span>
                          {c.template && <CategoryBadge category={c.template.category} />}
                        </div>
                        {c.started_at && (
                          <div className="mt-2.5">
                            <p className="mb-1 flex justify-between text-xs text-slate-500 dark:text-slate-400">
                              <span className="font-semibold text-slate-900 dark:text-white">{c.progress.percent}% done</span>
                              <span>read {rate(c.progress.read_rate)}</span>
                            </p>
                            <DeliveryBar campaign={c} />
                            <p className="mt-1.5 text-xs text-slate-600 dark:text-slate-300">
                              {formatNumber(c.sent_count)} sent · {formatNumber(c.delivered_count)} delivered · {formatNumber(c.read_count)} read · {formatNumber(c.replied_count)} replied
                              {c.failed_count > 0 && <span className="text-red-600 dark:text-red-300"> · {formatNumber(c.failed_count)} failed</span>}
                            </p>
                          </div>
                        )}
                        <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                          {formatNumber(Math.max(0, c.total_recipients - c.skipped_count))} recipients · {x.label} {formatDateTime(x.at)}
                        </p>
                      </div>
                    </li>
                  );
                })}
              </ul>
            ) : (
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(r) => r.id}
              loading={list.isLoading}
              onRowClick={(r) => navigate(`/whatsapp/campaigns/${r.id}`)}
              actions={(r) => (
                <Dropdown label={`Actions for ${r.name}`} triggerClassName="btn-icon !h-8 !w-8" trigger={<MoreHorizontal className="h-4 w-4" />} items={rowMenu(r)} />
              )}
              empty={
                filtered ? (
                  <EmptyState
                    icon={Megaphone}
                    title="No campaigns match"
                    description="Try another status, type or date range."
                    action={
                      <Button
                        variant="secondary"
                        onClick={() => {
                          setQ('');
                          setFrom('');
                          setTo('');
                          setParams(new URLSearchParams(), { replace: true });
                        }}
                      >
                        Clear filters
                      </Button>
                    }
                  />
                ) : (
                  <EmptyState
                    icon={Megaphone}
                    title="No campaigns yet"
                    description="Send admission announcements, scholarship news, exam notices or festival greetings to the right audience in a few steps."
                    action={
                      actions.canCreate ? (
                        <Button icon={Plus} to="/whatsapp/campaigns/new">
                          Create your first campaign
                        </Button>
                      ) : undefined
                    }
                  />
                )
              }
            />
            )}
            {list.data && list.data.meta.total > 0 && (
              <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
                <Pagination page={list.data.meta.page} pages={list.data.meta.pages} total={list.data.meta.total} perPage={list.data.meta.per_page} onPage={(p) => setParam('page', String(p))} />
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
