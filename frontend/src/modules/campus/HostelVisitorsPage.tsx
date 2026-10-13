import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { AlarmClock, BarChart3, CalendarDays, Clock, DoorOpen, LogIn, LogOut, Printer, ShieldCheck, Timer, UserCheck, Users } from 'lucide-react';
import { Avatar, Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Select, Skeleton, StatCard, Stagger, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { BarChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatTime } from '@/lib/format';
import { useHostelRefresh } from './hostel/AllocationDialogs';
import { minutesLabel, openHostelPrint, type HostelStat } from './hostel/shared';
import { VisitorDrawer } from './hostel/VisitorDrawer';

interface InsideVisitor {
  id: number;
  pass_no: string | null;
  visitor_name: string;
  relation: string | null;
  relation_label: string | null;
  phone: string | null;
  visitors_count: number;
  purpose: string | null;
  check_in: string;
  hostel_code: string;
  hostel_name: string;
  student_name: string;
  student_uid: string;
  photo: string | null;
  room_no: string | null;
  minutes_inside: number;
}
interface Stats {
  today: number;
  inside: number;
  week: number;
  overstay: number;
  avg_minutes: number | null;
  inside_list: InsideVisitor[];
  trend: { date: string; label: string; visitors: number }[];
}

const PRESENCE_TABS = [
  { key: 'all', label: 'Full register' },
  { key: 'inside', label: 'Inside now' },
  { key: 'left', label: 'Checked out' },
];

export default function HostelVisitorsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useHostelRefresh();
  const [params, setParams] = useSearchParams();
  const presence = ['inside', 'left'].includes(String(params.get('presence'))) ? String(params.get('presence')) : 'all';
  const [hostelId, setHostelId] = useState('');
  const { data: s, isLoading } = useApi<Stats>(['hostel', 'visitor-stats', hostelId], 'hostel/visitors/stats', { hostel_id: hostelId || undefined }, { refetchInterval: 60_000 });
  const { data: overview } = useApi<{ hostels: HostelStat[] }>(['hostel', 'overview'], 'hostel/overview');
  const { data: meta } = useCrudMeta('hostel_visitors');
  const [creating, setCreating] = useState(!!params.get('new'));
  const [viewId, setViewId] = useState<number | null>(Number(params.get('view')) || null);
  const [busy, setBusy] = useState<number | null>(null);
  const canEdit = can('hostel', 'edit');
  const formDefaults = useMemo(() => (hostelId ? { hostel_id: hostelId } : undefined), [hostelId]);

  useEffect(() => {
    const v = Number(params.get('view')) || null;
    if (v) setViewId(v);
  }, [params]);

  const checkout = async (id: number, name: string) => {
    const ok = await confirm({ title: 'Check out visitor?', message: <>Record check-out of <strong>{name}</strong> now?</>, confirmText: 'Check out' });
    if (!ok) return;
    setBusy(id);
    try {
      const res = await api.post(`hostel/visitors/${id}/checkout`, {});
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const bulkCheckout = async (ids: number[], clear: () => void) => {
    const ok = await confirm({ title: `Check out ${ids.length} visitor${ids.length === 1 ? '' : 's'}?`, message: 'Visitors still inside will be checked out with the current time.', confirmText: 'Check out' });
    if (!ok) return;
    try {
      const res = await api.post('crud/hostel_visitors/bulk', { action: 'checkout', ids });
      toast.success(res.message);
      clear();
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const setPresence = (k: string) => {
    const next = new URLSearchParams(params);
    if (k === 'all') next.delete('presence');
    else next.set('presence', k);
    next.delete('page');
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader
        title="Visitor Register"
        description="Gate register of visitors meeting hostel residents, with ID proof and check-out."
        breadcrumbs={[{ label: 'Hostel', to: '/hostel' }, { label: 'Visitors' }]}
        actions={
          <>
            <Select
              aria-label="Hostel"
              inputSize="sm"
              className="!w-auto"
              value={hostelId}
              onChange={(e) => {
                setHostelId(e.target.value);
                if (params.get('page')) {
                  const next = new URLSearchParams(params);
                  next.delete('page');
                  setParams(next, { replace: true });
                }
              }}
              options={(overview?.hostels ?? []).map((h) => ({ value: h.id, label: h.name }))}
              placeholder="All hostels"
            />
            {can('hostel', 'create') && <Button icon={LogIn} onClick={() => setCreating(true)}>Check in visitor</Button>}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
        {[
          <StatCard key="today" label="Visitors today" icon={CalendarDays} tone="blue" loading={isLoading} value={s?.today ?? 0} hint="Checked in since midnight" />,
          <StatCard key="inside" label="Inside now" icon={DoorOpen} tone="amber" loading={isLoading} value={s?.inside ?? 0} hint="Not checked out yet" />,
          <StatCard key="over" label="Overstaying (6h+)" icon={AlarmClock} tone="red" loading={isLoading} value={s?.overstay ?? 0} hint="Follow up at the gate" />,
          <StatCard key="week" label="Last 7 days" icon={Users} tone="purple" loading={isLoading} value={s?.week ?? 0} hint={s ? `≈ ${Math.round(s.week / 7)} visitors a day` : undefined} />,
          <StatCard key="avg" label="Average visit" icon={Timer} tone="green" loading={isLoading} value={s?.avg_minutes ? minutesLabel(s.avg_minutes) : '—'} hint="Last 30 days" />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-5">
        <Card className="xl:col-span-3">
          <CardHeader title="Currently inside" subtitle="Visitors who have not checked out yet — oldest first" icon={ShieldCheck} />
          <div className="card-body !pt-0">
            {isLoading || !s ? (
              <div className="space-y-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-14" />)}</div>
            ) : s.inside_list.length === 0 ? (
              <EmptyState icon={UserCheck} title="Nobody inside right now" description="Every visitor has checked out." className="py-8" />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {s.inside_list.map((v) => {
                  const over = v.minutes_inside > 360;
                  return (
                    <li key={v.id} className="flex flex-wrap items-center gap-3 py-3">
                      <Avatar name={v.visitor_name} size="md" />
                      <button type="button" onClick={() => setViewId(v.id)} className="min-w-0 flex-1 text-left">
                        <p className="truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white">
                          {v.visitor_name}
                          <span className="font-normal text-slate-500"> · {v.relation_label ?? v.relation ?? 'Visitor'}{v.visitors_count > 1 ? ` · ${v.visitors_count} persons` : ''}</span>
                        </p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                          Visiting {v.student_name} ({v.hostel_code}{v.room_no ? ` · Room ${v.room_no}` : ''}){v.purpose ? ` · ${v.purpose}` : ''}
                        </p>
                      </button>
                      <div className="text-right">
                        <p className={clsx('inline-flex items-center gap-1 text-xs font-semibold', over ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-slate-200')}>
                          <Clock className="h-3.5 w-3.5" /> {minutesLabel(v.minutes_inside)}
                        </p>
                        <p className="text-[11px] text-slate-500">in at {formatTime(v.check_in.slice(11, 16))}</p>
                      </div>
                      <div className="flex gap-1.5">
                        <Button size="xs" variant="secondary" icon={Printer} onClick={() => openHostelPrint(`visitor-pass/${v.id}`)} aria-label={`Print pass for ${v.visitor_name}`}>Pass</Button>
                        {canEdit && <Button size="xs" icon={LogOut} loading={busy === v.id} onClick={() => void checkout(v.id, v.visitor_name)}>Check out</Button>}
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        </Card>
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Visitors per day" subtitle="Last 14 days" icon={BarChart3} />
            <div className="card-body">
              {isLoading || !s ? <Skeleton className="h-56 w-full" /> : <BarChart labels={s.trend.map((t) => t.label)} series={[{ label: 'Visitors', data: s.trend.map((t) => t.visitors), color: '#1D4ED8' }]} height={300} />}
            </div>
          </Card>
        </Reveal>
      </div>

      <Tabs tabs={PRESENCE_TABS} value={presence} onChange={setPresence} variant="pills" className="mb-4" />
      <CrudTable
        // re-mount on tab / hostel change so the list starts again at page 1 (filters typed in the table stay in the URL)
        key={`${presence}-${hostelId}`}
        module="hostel_visitors"
        urlState
        scope={{ ...(presence === 'all' ? {} : { presence }), ...(hostelId ? { hostel_id: hostelId } : {}) }}
        title="Visitor log"
        addLabel="Check in visitor"
        onCreate={() => setCreating(true)}
        onView={(r) => setViewId(Number(r.id))}
        emptyTitle="No visitors recorded"
        emptyText="Check in parents and guardians at the gate to build the visitor register."
        renderers={{
          visitor_name: (r) => (
            <button type="button" onClick={() => setViewId(Number(r.id))} className="min-w-[10rem] text-left leading-tight">
              <span className="block font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.visitor_name)}</span>
              <span className="block text-xs text-slate-500">{String(r.visitor_sub ?? '')}</span>
            </button>
          ),
          check_out: (r) =>
            r.check_out ? (
              <div className="whitespace-nowrap leading-tight">
                <p>{formatTime(String(r.check_out).slice(11, 16))}</p>
                <p className="text-xs text-slate-500">{String(r.check_out).slice(0, 10) === String(r.check_in).slice(0, 10) ? 'same day' : formatDate(r.check_out)}</p>
              </div>
            ) : canEdit ? (
              <Button size="xs" variant="secondary" icon={LogOut} loading={busy === Number(r.id)} onClick={() => void checkout(Number(r.id), String(r.visitor_name))}>Check out</Button>
            ) : (
              <span className="text-xs text-amber-600">Inside</span>
            ),
          visit_status: (r) => (
            <div className="leading-tight">
              <StatusBadge status={String(r.visit_status)} label={r.visit_status === 'left' ? 'Left' : r.visit_status === 'overstay' ? 'Overstay' : 'Inside'} colors={{ inside: 'amber', left: 'green', overstay: 'red' }} />
              <p className="mt-0.5 text-[11px] text-slate-500">{minutesLabel(Number(r.duration_minutes))}</p>
            </div>
          ),
          purpose: (r) => <span className="block max-w-[11rem] truncate" title={String(r.purpose ?? '')}>{String(r.purpose ?? '—')}</span>,
          id_proof_label: (r) => <span className="whitespace-nowrap text-xs">{String(r.id_proof_label ?? '—')}</span>,
          check_in: (r) => (
            <div className="whitespace-nowrap leading-tight">
              <p>{formatDate(r.check_in)}</p>
              <p className="text-xs text-slate-500">{formatTime(String(r.check_in).slice(11, 16))}</p>
            </div>
          ),
        }}
        rowMenu={(r) => [
          canEdit && !r.check_out && { label: 'Check out now', icon: LogOut, onClick: () => void checkout(Number(r.id), String(r.visitor_name)) },
          { label: 'Print gate pass', icon: Printer, onClick: () => openHostelPrint(`visitor-pass/${r.id}`) },
        ]}
        bulkActions={canEdit ? [{ label: 'Check out', icon: LogOut, onClick: bulkCheckout }] : []}
        onSaved={() => void refresh()}
      />

      <CrudFormModal
        open={creating}
        onClose={() => {
          setCreating(false);
          if (params.get('new')) {
            const next = new URLSearchParams(params);
            next.delete('new');
            setParams(next, { replace: true });
          }
        }}
        meta={meta}
        module="hostel_visitors"
        title="Check in visitor"
        defaults={formDefaults}
        onSaved={() => void refresh()}
      />
      <VisitorDrawer
        id={viewId}
        onClose={() => {
          setViewId(null);
          if (params.get('view')) {
            const next = new URLSearchParams(params);
            next.delete('view');
            setParams(next, { replace: true });
          }
        }}
      />
    </>
  );
}
