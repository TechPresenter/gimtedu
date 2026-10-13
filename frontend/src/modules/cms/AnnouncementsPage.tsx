import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, BellRing, CalendarClock, GripVertical, Megaphone, MessageSquareText, Plus, Radio, Save, TriangleAlert, Undo2, X } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, IconButton, PageHeader, Skeleton, Stagger, StatCard, StatusBadge, Tabs, useToast } from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useCrudList, useCrudMeta } from '@/lib/queries';
import { formatDateTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { moveItem, useSortable } from './site/sortable';

const TYPES = [
  { key: 'all', label: 'All' }, { key: 'topbar', label: 'Top bar' }, { key: 'ticker', label: 'News ticker' }, { key: 'popup', label: 'Popups' }, { key: 'banner', label: 'Alert banners' },
];
const STATE_COLORS = { live: 'green', scheduled: 'amber', expired: 'slate', inactive: 'red' } as const;

export default function AnnouncementsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const tab = TYPES.some((t) => t.key === params.get('type')) ? (params.get('type') as string) : 'all';
  const { data: meta } = useCrudMeta('announcements');
  const all = useCrudList('announcements', { per_page: 100, sort: 'sort_order', dir: 'asc' });
  const rows = useMemo(() => all.data?.rows ?? [], [all.data]);
  const live = rows.filter((r) => r.live_state === 'live');
  const [form, setForm] = useState<{ id: number | null } | null>(null);
  const defaults = useMemo(() => ({ type: tab === 'all' ? 'ticker' : tab }), [tab]);
  const counts = useMemo(() => Object.fromEntries(TYPES.map((t) => [t.key, t.key === 'all' ? rows.length : rows.filter((r) => r.type === t.key).length])), [rows]);

  return (
    <>
      <PageHeader
        title="Announcements"
        description="Top bar messages, the scrolling news ticker, popups and alert banners — each with its own schedule."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Announcements' }]}
        actions={can('cms', 'create') && <Button icon={Plus} onClick={() => setForm({ id: null })}>New announcement</Button>}
      />
      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatCard key="l" label="Live now" value={live.length} icon={Radio} tone="green" loading={all.isLoading} hint="Visible on the website" />,
          <StatCard key="t" label="Ticker items live" value={live.filter((r) => r.type === 'ticker').length} icon={Megaphone} tone="orange" loading={all.isLoading} hint="Scrolling news strip" />,
          <StatCard key="s" label="Scheduled" value={rows.filter((r) => r.live_state === 'scheduled').length} icon={CalendarClock} tone="amber" loading={all.isLoading} hint="Will go live automatically" />,
          <StatCard key="p" label="Popups & alerts live" value={live.filter((r) => r.type === 'popup' || r.type === 'banner').length} icon={BellRing} tone="purple" loading={all.isLoading} hint="Shown above the page" />,
        ]}
      </Stagger>

      <LivePreview live={live} loading={all.isLoading} />

      <div className="mb-4 mt-6">
        <Tabs value={tab} onChange={(k) => setParams(k === 'all' ? {} : { type: k })} tabs={TYPES.map((t) => ({ key: t.key, label: t.label, count: counts[t.key] }))} />
      </div>

      <div className={clsx('grid grid-cols-1 gap-6', tab !== 'all' && '2xl:grid-cols-[minmax(0,1fr)_360px]')}>
        <CrudTable
          key={tab}
          module="announcements"
          title={TYPES.find((t) => t.key === tab)?.label ?? 'All'}
          scope={tab === 'all' ? undefined : { type: tab }}
          hideColumns={tab === 'all' ? [] : ['type']}
          renderers={{
            title: (r) => (
              <div className="min-w-[240px] max-w-[460px] leading-snug">
                <p className="line-clamp-2 font-semibold text-slate-900 dark:text-white">{r.title}</p>
                {r.link && <p className="truncate text-xs text-slate-500">{r.link}</p>}
              </div>
            ),
          }}
          formDefaults={defaults}
          addLabel="New announcement"
          emptyTitle="No announcements here yet"
          emptyText="Announce admissions, events, results or holidays on the website."
        />
        {tab !== 'all' && <OrderCard type={tab} rows={rows.filter((r) => r.type === tab)} canEdit={can('cms', 'edit')} onSaved={() => void qc.invalidateQueries({ queryKey: ['crud', 'announcements'] })} toastOk={(m) => toast.success(m)} toastErr={(m) => toast.error(m)} />}
      </div>

      <CrudFormModal open={!!form} onClose={() => setForm(null)} meta={meta} module="announcements" id={form?.id ?? null} defaults={defaults}
        onSaved={() => void qc.invalidateQueries({ queryKey: ['crud', 'announcements'] })} />
    </>
  );
}

/** What visitors see right now: announcement strip (top bar), alert banner, ticker and popup — Design v3 styling. */
function LivePreview({ live, loading }: { live: Row[]; loading: boolean }) {
  const topbar = live.filter((r) => r.type === 'topbar');
  const ticker = live.filter((r) => r.type === 'ticker');
  // like site_announcement_items(): the strip shows top bar items, or the ticker items when no top bar item is live
  const strip = topbar.length ? topbar : ticker;
  const popup = live.find((r) => r.type === 'popup');
  const alertBar = live.find((r) => r.type === 'banner');
  return (
    <Card className="overflow-hidden">
      <CardHeader icon={MessageSquareText} title="Live on the website" subtitle="Announcements currently inside their schedule, in display order" />
      {loading ? <div className="p-5"><Skeleton className="h-40 w-full rounded-xl" /></div> : (
        <div className="border-t border-slate-100 bg-[#FAF8F5] dark:border-slate-800">
          <p className="px-4 pb-1 pt-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">Announcement strip above the header</p>
          <div className="flex items-center gap-3 overflow-hidden bg-[#0B2A5B] px-4 py-2 text-xs font-semibold text-white">
            {strip.length ? (
              <span className="flex min-w-0 items-center gap-3 whitespace-nowrap">
                {strip.map((t, i) => (
                  <span key={t.id} className="inline-flex items-center gap-3">
                    {i > 0 && <span className="h-1.5 w-1.5 rounded-full bg-[#F05A28]" />}
                    <span className={clsx(t.link && 'underline decoration-white/40 underline-offset-2')}>{t.title}</span>
                  </span>
                ))}
              </span>
            ) : <span className="text-white/60">Nothing live — the strip shows the “announcement strip text” setting</span>}
          </div>
          {!topbar.length && ticker.length > 0 && <p className="px-4 pt-1.5 text-[11px] text-slate-500">No top bar item is live, so the ticker items fill the strip.</p>}

          <p className="px-4 pb-1 pt-4 text-[10px] font-bold uppercase tracking-wider text-slate-400">Alert banner</p>
          {alertBar ? (
            <div className="flex items-center gap-2 bg-[#FDD900] px-4 py-2 text-xs font-semibold text-[#0B2A5B]">
              <TriangleAlert className="h-3.5 w-3.5 shrink-0" />
              <span className="min-w-0 flex-1 truncate">{alertBar.title}</span>
              {alertBar.link_text && <span className="shrink-0 underline">{alertBar.link_text}</span>}
              <X className="h-3.5 w-3.5 shrink-0" />
            </div>
          ) : <p className="px-4 text-xs text-slate-400">No alert banner live</p>}

          <p className="px-4 pb-1 pt-4 text-[10px] font-bold uppercase tracking-wider text-slate-400">News ticker</p>
          <div className="flex items-center gap-3 overflow-hidden border-y border-[#EDE8E2] bg-white px-4 py-2.5 text-xs font-semibold text-[#C93C14]">
            <span className="shrink-0 rounded-full bg-[#D03E16] px-2 py-0.5 text-[10px] font-bold uppercase text-white">Latest</span>
            <span className="flex min-w-0 gap-8 whitespace-nowrap">
              {ticker.length ? ticker.map((t) => <span key={t.id}>{t.title}</span>) : <span className="text-slate-400">No ticker items live</span>}
            </span>
          </div>

          <div className="flex justify-center p-5">
            {popup ? (
              <div className="w-full max-w-sm rounded-[22px] border border-[#EDE8E2] bg-white p-5 shadow-[0_20px_50px_-20px_rgba(11,42,91,.35)]">
                <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-[#C93C14]">Popup</p>
                <p className="mt-1 font-display text-xl font-extrabold tracking-tight text-[#0B2A5B]">{popup.title}</p>
                {popup.content && <p className="mt-2 text-sm leading-relaxed text-[#4A5568]">{popup.content}</p>}
                {popup.link_text && <span className="mt-4 inline-flex rounded-full bg-[#D03E16] px-4 py-2 text-xs font-semibold text-white">{popup.link_text} →</span>}
              </div>
            ) : <p className="text-xs text-slate-400">No popup live</p>}
          </div>
          <p className="border-t border-slate-100 px-4 py-2.5 text-[11px] text-slate-500 dark:border-slate-800">
            The current website shows the announcement strip on every page. Alert banners, the separate ticker and popups are displayed by the page templates that support them (docs/CMS.md §5).
          </p>
        </div>
      )}
    </Card>
  );
}

/** Drag / button ordering for one announcement type. */
function OrderCard({ type, rows, canEdit, onSaved, toastOk, toastErr }: { type: string; rows: Row[]; canEdit: boolean; onSaved: () => void; toastOk: (m: string) => void; toastErr: (m: string) => void }) {
  const [order, setOrder] = useState<number[] | null>(null);
  const [saving, setSaving] = useState(false);
  const list = order ? (order.map((id) => rows.find((r) => Number(r.id) === id)).filter(Boolean) as Row[]) : rows;
  const dirty = !!order && order.join() !== rows.map((r) => Number(r.id)).join();
  const move = (from: number, to: number) => setOrder(moveItem(list.map((r) => Number(r.id)), from, to));
  const sortable = useSortable(move, `ann-${type}`);
  const save = async () => {
    setSaving(true);
    try {
      const res = await api.post('cms/announcements/reorder', { ids: list.map((r) => Number(r.id)) });
      toastOk(res.message || 'Order saved.');
      setOrder(null);
      onSaved();
    } catch (e) {
      toastErr((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Card className="h-fit">
      <CardHeader title="Display order" subtitle="First item is shown first" actions={canEdit && (
        <>
          {dirty && <IconButton icon={Undo2} label="Undo" size="sm" onClick={() => setOrder(null)} />}
          <Button size="sm" icon={Save} onClick={save} loading={saving} disabled={!dirty}>Save</Button>
        </>
      )} />
      {!list.length ? <EmptyState icon={Megaphone} title="Nothing to order" description="Add an announcement of this type first." /> : (
        <ol className="space-y-1.5 p-3">
          {list.map((r, i) => (
            <li key={r.id} {...sortable.dropProps(i)} className={clsx('flex items-center gap-2 rounded-xl border border-slate-200 px-2 py-2 dark:border-slate-700', sortable.overIndex === i && sortable.dragIndex !== i && 'ring-2 ring-[#D03E16]/50', sortable.dragIndex === i && 'opacity-50')}>
              {canEdit && <span {...sortable.dragProps(i)} className="cursor-grab text-slate-300 active:cursor-grabbing dark:text-slate-600"><GripVertical className="h-4 w-4" /></span>}
              <span className="min-w-0 flex-1">
                <span className="line-clamp-2 text-xs font-medium text-slate-800 dark:text-slate-100">{r.title}</span>
                <span className="mt-0.5 flex items-center gap-1.5">
                  <StatusBadge status={String(r.live_state)} colors={STATE_COLORS} />
                  {r.ends_at && <span className="truncate text-[10px] text-slate-400">until {formatDateTime(r.ends_at)}</span>}
                </span>
              </span>
              {canEdit && (
                <span className="flex shrink-0 flex-col">
                  <button type="button" className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Move up" disabled={i === 0} onClick={() => move(i, i - 1)}><ArrowUp className="h-3.5 w-3.5" /></button>
                  <button type="button" className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Move down" disabled={i === list.length - 1} onClick={() => move(i, i + 1)}><ArrowDown className="h-3.5 w-3.5" /></button>
                </span>
              )}
            </li>
          ))}
        </ol>
      )}
      {!canEdit && <Alert variant="info" className="m-3">Only editors can change the order.</Alert>}
    </Card>
  );
}
