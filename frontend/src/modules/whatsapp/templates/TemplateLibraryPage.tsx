import clsx from 'clsx';
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  Download,
  Eye,
  FileStack,
  LayoutGrid,
  List as ListIcon,
  Lock,
  MoreVertical,
  PencilLine,
  Plus,
  RefreshCw,
  Send,
  SendHorizontal,
  Variable,
} from 'lucide-react';
import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Alert, Badge, Button, Card, Dropdown, EmptyState, PageHeader, Pagination, SearchInput, Select, Skeleton, Stagger, StatCard, Tabs } from '@/components/ui';
import type { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { ModeBanner, WaText, type WaTemplateStatus } from '../shared';
import { tplMenuItems, useTplActions, type TplActions } from './actions';
import { TemplateDetailDrawer } from './TemplateDetailDrawer';
import { RecategorisedBadge } from './RecategorisedNote';
import { CATEGORY_TONE, STATUS_COLORS, STATUS_LABELS, namedBody, rate, useTplList, useTplMeta, useTplSummary } from './lib';
import type { TplListItem, TplMeta } from './types';

const STATUS_TABS: { key: '' | WaTemplateStatus; label: string }[] = [
  { key: '', label: 'All' },
  { key: 'approved', label: 'Approved' },
  { key: 'pending', label: 'Pending' },
  { key: 'draft', label: 'Drafts' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'paused', label: 'Paused' },
  { key: 'disabled', label: 'Disabled' },
  { key: 'archived', label: 'Archived' },
];

const SORTS = [
  { value: 'updated', label: 'Recently updated' },
  { value: 'usage', label: 'Most sent' },
  { value: 'last_used', label: 'Recently used' },
  { value: 'read_rate', label: 'Best read rate' },
  { value: 'name', label: 'Name (A–Z)' },
];

export default function TemplateLibraryPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const meta = useTplMeta();
  const summary = useTplSummary();
  const status = (params.get('status') ?? '') as '' | WaTemplateStatus;
  const category = params.get('category') ?? '';
  const language = params.get('language') ?? '';
  const sort = params.get('sort') ?? 'updated';
  const view = params.get('layout') === 'list' ? 'list' : 'grid';
  const page = Math.max(1, Number(params.get('page') ?? 1) || 1);
  const perPage = Number(params.get('per_page') ?? 24) || 24;
  const qParam = params.get('q') ?? '';
  const q = useDebounce(qParam, 300);
  const viewId = Number(params.get('view') ?? 0) || null;

  const update = (patch: Record<string, string | number | null>, resetPage = true) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) {
      if (v === null || v === '' || v === undefined) next.delete(k);
      else next.set(k, String(v));
    }
    if (resetPage && !('page' in patch)) next.delete('page');
    setParams(next, { replace: true });
  };

  const query = useMemo(() => ({ status, category, language, q, sort, page, per_page: perPage }), [status, category, language, q, sort, page, perPage]);
  const list = useTplList(query);
  const actions = useTplActions({ onDeleted: () => update({ view: null }, false) });
  const s = summary.data;
  const filtered = !!(status || category || language || q);

  const tabs = STATUS_TABS.filter((t) => t.key !== 'disabled' || status === 'disabled' || (s?.by_status.disabled ?? 0) > 0).map((t) => ({
    key: t.key || 'all',
    label: t.label,
    count: s ? (t.key ? s.by_status[t.key] ?? 0 : s.total) : undefined,
  }));
  const attention = s ? (s.by_status.rejected ?? 0) + (s.by_status.paused ?? 0) + (s.by_status.disabled ?? 0) : 0;
  const m30 = s?.messages_30d;

  return (
    <>
      <PageHeader
        title="Template Library"
        description="Every WhatsApp template with its Meta review status, where it is used and how it performs."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Template Library' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {can('whatsapp_templates', 'approve') && (
              <>
                <Button variant="secondary" icon={RefreshCw} onClick={() => void actions.sync()} loading={actions.busy === 'sync'} title={s?.last_sync ? `Last synced ${timeAgo(s.last_sync)}` : 'Never synced'}>
                  Sync statuses
                </Button>
                <Button variant="secondary" icon={Download} onClick={actions.openImport}>
                  Import from Interakt
                </Button>
              </>
            )}
            {can('whatsapp_templates', 'create') && (
              <Button icon={Plus} to="/whatsapp/templates/designer">
                New template
              </Button>
            )}
          </div>
        }
      />
      <ModeBanner />

      {summary.error && <Alert variant="error" className="mb-5" title="Unable to load template statistics">{(summary.error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
        {[
          <StatCard key="total" label="Templates" icon={FileStack} tone="navy" loading={summary.isLoading} value={s?.total ?? 0} hint={s ? `${s.by_status.draft ?? 0} draft${(s.by_status.draft ?? 0) === 1 ? '' : 's'} · ${s.by_status.archived ?? 0} archived` : undefined} />,
          <StatCard key="approved" label="Approved" icon={CheckCircle2} tone="green" loading={summary.isLoading} value={s?.by_status.approved ?? 0} hint="Ready to send" />,
          <StatCard key="pending" label="Pending review" icon={Clock} tone="amber" loading={summary.isLoading} value={s?.by_status.pending ?? 0} hint={s?.last_sync ? `Synced ${timeAgo(s.last_sync)}` : 'Waiting for Meta'} />,
          <StatCard key="attention" label="Need attention" icon={AlertTriangle} tone="red" loading={summary.isLoading} value={attention} hint="Rejected, paused or disabled" />,
          <StatCard key="sent" label="Sent (30 days)" icon={Send} tone="orange" loading={summary.isLoading} value={m30?.sent ?? 0} hint={m30 ? `${rate(m30.read, m30.sent)} read · ${formatNumber(m30.failed)} failed` : undefined} />,
        ]}
      </Stagger>

      <Tabs tabs={tabs} value={status || 'all'} onChange={(k) => update({ status: k === 'all' ? null : k })} variant="pills" className="mb-4" />

      <Card className="mb-4">
        <div className="flex flex-col gap-3 p-3 md:flex-row md:items-center">
          <SearchInput value={qParam} onChange={(v) => update({ q: v })} placeholder="Search name, wording or description…" className="md:max-w-xs md:flex-1" />
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:flex md:flex-1 md:justify-end">
            <Select
              aria-label="Category"
              inputSize="sm"
              value={category}
              onChange={(e) => update({ category: e.target.value })}
              options={[{ value: '', label: 'All categories' }, ...Object.entries(meta.data?.categories ?? {}).map(([value, c]) => ({ value, label: c.label }))]}
            />
            <Select
              aria-label="Language"
              inputSize="sm"
              value={language}
              onChange={(e) => update({ language: e.target.value })}
              options={[{ value: '', label: 'All languages' }, ...Object.entries(meta.data?.languages ?? {}).map(([value, l]) => ({ value, label: l.label }))]}
            />
            <Select aria-label="Sort" inputSize="sm" value={sort} onChange={(e) => update({ sort: e.target.value === 'updated' ? null : e.target.value })} options={SORTS} className="col-span-2 sm:col-span-1" />
          </div>
          <div className="flex shrink-0 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800" role="group" aria-label="Layout">
            {[
              { k: 'grid', icon: LayoutGrid, label: 'Grid view' },
              { k: 'list', icon: ListIcon, label: 'List view' },
            ].map((o) => (
              <button
                key={o.k}
                type="button"
                aria-pressed={view === o.k}
                aria-label={o.label}
                title={o.label}
                onClick={() => update({ layout: o.k === 'grid' ? null : o.k }, false)}
                className={clsx(
                  'flex h-8 w-9 items-center justify-center rounded-md transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                  view === o.k ? 'bg-white text-brand-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200',
                )}
              >
                <o.icon size={16} aria-hidden />
              </button>
            ))}
          </div>
        </div>
      </Card>

      {list.error ? (
        <Alert variant="error" title="Unable to load templates" action={<Button size="sm" variant="secondary" onClick={() => void list.refetch()}>Retry</Button>}>
          {(list.error as ApiError).message}
        </Alert>
      ) : list.isLoading || !list.data ? (
        <div className={view === 'grid' ? 'grid gap-4 md:grid-cols-2 2xl:grid-cols-3' : 'space-y-2'}>
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} className={view === 'grid' ? 'h-72 w-full' : 'h-14 w-full'} />
          ))}
        </div>
      ) : !list.data.items.length ? (
        <Card>
          {filtered ? (
            <EmptyState
              icon={FileStack}
              title="No templates match"
              description="Try another status, category, language or search term."
              action={
                <Button variant="secondary" onClick={() => update({ status: null, category: null, language: null, q: null })}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={FileStack}
              title="No WhatsApp templates yet"
              description="Templates are the approved messages GIMT sends for enquiries, admissions, fees, exams and campaigns. Design your first one or import approved templates from Interakt."
              action={
                can('whatsapp_templates', 'create') ? (
                  <Button icon={Plus} to="/whatsapp/templates/designer">
                    Design a template
                  </Button>
                ) : undefined
              }
            />
          )}
        </Card>
      ) : view === 'grid' ? (
        <div className={clsx('grid gap-4 md:grid-cols-2 2xl:grid-cols-3 transition-opacity', list.isFetching && 'opacity-70')}>
          {list.data.items.map((t) => (
            <TemplateCard key={t.id} t={t} meta={meta.data} actions={actions} onView={() => update({ view: t.id }, false)} />
          ))}
        </div>
      ) : (
        <TemplateTable rows={list.data.items} meta={meta.data} actions={actions} onView={(id) => update({ view: id }, false)} fetching={list.isFetching} />
      )}

      {list.data && list.data.total > 0 && (
        <Pagination
          className="mt-5"
          page={list.data.page}
          pages={list.data.pages}
          total={list.data.total}
          perPage={list.data.per_page}
          onPage={(p) => update({ page: p }, false)}
          onPerPage={(n) => update({ per_page: n, page: null })}
          perPageOptions={[12, 24, 48, 96]}
        />
      )}

      <TemplateDetailDrawer id={viewId} onClose={() => update({ view: null }, false)} meta={meta.data} actions={actions} />
      {actions.dialogs}
    </>
  );
}

/* ------------------------------------------------------------------ grid card */

function TemplateCard({ t, meta, actions, onView }: { t: TplListItem; meta?: TplMeta; actions: TplActions; onView: () => void }) {
  const { can } = useAuth();
  const st = t.stats;
  const u = t.usage;
  const cat = CATEGORY_TONE[t.category];
  const usedIn = [u.events && `${u.events} event${u.events > 1 ? 's' : ''}`, u.workflows && `${u.workflows} workflow${u.workflows > 1 ? 's' : ''}`, u.followups && `${u.followups} follow-up${u.followups > 1 ? 's' : ''}`, u.campaigns && `${u.campaigns} campaign${u.campaigns > 1 ? 's' : ''}`].filter(Boolean);
  const body = t.category === 'AUTHENTICATION' ? '{{otp_code}} is your verification code.' : namedBody(t);
  const menu = tplMenuItems(t, actions, can, { canSimulate: !!meta?.can_simulate, onView });

  return (
    <Card className="group flex flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-soft">
      <div className={clsx('h-1 w-full', cat.bar)} aria-hidden />
      <div className="flex flex-1 flex-col gap-3 p-4">
        <div className="flex items-start gap-2">
          <button type="button" onClick={onView} className="min-w-0 flex-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded">
            <span className="flex items-center gap-1.5">
              <span className="truncate font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-200">{t.display_name || t.name}</span>
              {t.is_system ? <Lock size={12} className="shrink-0 text-slate-400" aria-label="Built-in template" /> : null}
            </span>
            <code className="block truncate text-[11px] text-slate-500 dark:text-slate-400">{t.name}</code>
          </button>
          {menu.length > 0 && (
            <Dropdown
              label={`Actions for ${t.name}`}
              triggerClassName="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
              trigger={<MoreVertical size={16} aria-hidden />}
              items={menu}
            />
          )}
        </div>
        <div className="flex flex-wrap gap-1.5">
          <Badge color={STATUS_COLORS[t.status]} dot>
            {STATUS_LABELS[t.status]}
          </Badge>
          <Badge color={cat.badge}>{cat.label}</Badge>
          {t.recategorised && <RecategorisedBadge r={t.recategorised} />}
          <Badge color="slate">{meta?.languages[t.language]?.label ?? t.language}</Badge>
          {t.header_type !== 'none' && <Badge color="cyan">{t.header_type === 'text' ? 'Text header' : t.header_type}</Badge>}
          {t.buttons.length > 0 && <Badge color="slate">{t.buttons.length} button{t.buttons.length > 1 ? 's' : ''}</Badge>}
        </div>
        <div className="rounded-xl bg-[#EFEAE2] p-2.5 dark:bg-[#0B141A]">
          <div className="rounded-lg rounded-tl-none bg-white px-2.5 py-2 text-[13px] leading-snug text-slate-800 shadow-sm dark:bg-[#202C33] dark:text-slate-100">
            <p className="line-clamp-4">
              <WaText text={body} highlightVars />
            </p>
          </div>
        </div>
        {t.status === 'rejected' && t.rejection_reason && (
          <p className="line-clamp-2 rounded-lg bg-red-50 px-2.5 py-1.5 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300" title={t.rejection_reason}>
            <strong>Rejected:</strong> {t.rejection_reason}
          </p>
        )}
        <dl className="mt-auto grid grid-cols-4 gap-1 border-t border-slate-100 pt-3 text-center dark:border-slate-800">
          {[
            { k: 'Sent', v: formatNumber(st.sent) },
            { k: 'Read', v: rate(st.read, st.sent) },
            { k: 'Failed', v: formatNumber(st.failed), bad: st.failed > 0 },
            t.buttons.length ? { k: 'Clicks', v: formatNumber(st.clicks) } : { k: 'Replied', v: rate(st.replies, st.delivered) },
          ].map((x) => (
            <div key={x.k}>
              <dt className="text-[10px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{x.k}</dt>
              <dd className={clsx('text-sm font-semibold tabular-nums', x.bad ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white')}>{x.v}</dd>
            </div>
          ))}
        </dl>
        <p className="flex flex-wrap items-center gap-x-2 text-[11px] text-slate-500 dark:text-slate-400">
          <span>{usedIn.length ? `Used in ${usedIn.join(', ')}` : 'Not linked yet'}</span>
          <span aria-hidden>·</span>
          <span>{t.last_used_at ? `Last sent ${timeAgo(t.last_used_at)}` : 'Never sent'}</span>
        </p>
      </div>
      <div className="flex items-center gap-1.5 border-t border-slate-100 bg-slate-50/60 px-3 py-2 dark:border-slate-800 dark:bg-slate-900/40">
        <Button size="xs" variant="ghost" icon={Eye} onClick={onView}>
          Details
        </Button>
        {t.status === 'draft' && can('whatsapp_templates', 'edit') && (
          <Button size="xs" variant="ghost" icon={PencilLine} to={`/whatsapp/templates/designer/${t.id}`}>
            Edit
          </Button>
        )}
        {t.status !== 'draft' && can('whatsapp_templates', 'edit') && (
          <Button size="xs" variant="ghost" icon={Variable} to={`/whatsapp/templates/designer/${t.id}`}>
            Mapping
          </Button>
        )}
        <span className="ml-auto" />
        {t.status === 'draft' && can('whatsapp_templates', 'approve') && (
          <Button size="xs" variant="soft" icon={Send} onClick={() => void actions.submit(t)} loading={actions.busy === `submit-${t.id}`}>
            Submit
          </Button>
        )}
        {t.status === 'approved' && can('whatsapp', 'create') && (
          <Button size="xs" variant="soft" icon={SendHorizontal} onClick={() => actions.openTest(t)}>
            Test
          </Button>
        )}
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------------ list view */

function TemplateTable({ rows, meta, actions, onView, fetching }: { rows: TplListItem[]; meta?: TplMeta; actions: TplActions; onView: (id: number) => void; fetching: boolean }) {
  const { can } = useAuth();
  return (
    <Card className={clsx('overflow-hidden transition-opacity', fetching && 'opacity-70')}>
      <div className="relative overflow-x-auto" tabIndex={0} role="region" aria-label="Templates">
        <table className="w-full min-w-[960px] text-left text-sm">
          <thead className="whitespace-nowrap bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Template</th>
              <th className="px-3 py-2.5 font-semibold">Category</th>
              <th className="px-3 py-2.5 font-semibold">Status</th>
              <th className="px-3 py-2.5 text-right font-semibold">Sent</th>
              <th className="px-3 py-2.5 text-right font-semibold">Delivered</th>
              <th className="px-3 py-2.5 text-right font-semibold">Read</th>
              <th className="px-3 py-2.5 text-right font-semibold">Failed</th>
              <th className="px-3 py-2.5 text-right font-semibold">Clicks</th>
              <th className="px-3 py-2.5 font-semibold">Used in</th>
              <th className="px-3 py-2.5 font-semibold">Last sent</th>
              <th className="px-3 py-2.5">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((t) => {
              const menu = tplMenuItems(t, actions, can, { canSimulate: !!meta?.can_simulate, onView: () => onView(t.id) });
              return (
                <tr key={t.id} className="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                  <td className="max-w-[18rem] px-4 py-2.5">
                    <button type="button" onClick={() => onView(t.id)} className="block max-w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded">
                      <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{t.display_name || t.name}</span>
                      <code className="block truncate text-[11px] text-slate-500 dark:text-slate-400">
                        {t.name} · {meta?.languages[t.language]?.label ?? t.language}
                      </code>
                    </button>
                  </td>
                  <td className="px-3 py-2.5">
                    <span className="flex flex-wrap items-center gap-1">
                      <Badge color={CATEGORY_TONE[t.category].badge}>{CATEGORY_TONE[t.category].label}</Badge>
                      {t.recategorised && <RecategorisedBadge r={t.recategorised} />}
                    </span>
                  </td>
                  <td className="px-3 py-2.5">
                    <Badge color={STATUS_COLORS[t.status]} dot>
                      {STATUS_LABELS[t.status]}
                    </Badge>
                    {t.status === 'rejected' && t.rejection_reason && (
                      <span className="mt-0.5 block max-w-[12rem] truncate text-[11px] text-red-600 dark:text-red-400" title={t.rejection_reason}>
                        {t.rejection_reason}
                      </span>
                    )}
                  </td>
                  <td className="px-3 py-2.5 text-right tabular-nums">{formatNumber(t.stats.sent)}</td>
                  <td className="px-3 py-2.5 text-right tabular-nums">{rate(t.stats.delivered, t.stats.sent)}</td>
                  <td className="px-3 py-2.5 text-right tabular-nums">{rate(t.stats.read, t.stats.sent)}</td>
                  <td className={clsx('px-3 py-2.5 text-right tabular-nums', t.stats.failed > 0 && 'text-red-600 dark:text-red-400')}>{formatNumber(t.stats.failed)}</td>
                  <td className="px-3 py-2.5 text-right tabular-nums">{formatNumber(t.stats.clicks)}</td>
                  <td className="whitespace-nowrap px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300">
                    {t.usage.total ? `${t.usage.events}E · ${t.usage.workflows}W · ${t.usage.followups}F · ${t.usage.campaigns}C` : '—'}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300">{t.last_used_at ? timeAgo(t.last_used_at) : 'Never'}</td>
                  <td className="px-3 py-2.5 text-right">
                    {menu.length > 0 && (
                      <Dropdown
                        label={`Actions for ${t.name}`}
                        triggerClassName="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-slate-400 dark:hover:bg-slate-800"
                        trigger={<MoreVertical size={16} aria-hidden />}
                        items={menu}
                      />
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="border-t border-slate-100 px-4 py-2 text-[11px] text-slate-500 dark:border-slate-800 dark:text-slate-400">E = events · W = workflows · F = follow-up stages · C = campaigns. Rates are of messages sent; clicks come from Interakt click webhooks.</p>
    </Card>
  );
}
