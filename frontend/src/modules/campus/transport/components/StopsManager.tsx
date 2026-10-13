import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, ChevronDown, ListOrdered, MapPin, Pencil, Phone, Plus, Save, Trash2, X } from 'lucide-react';
import { Avatar, Badge, Button, Card, CardHeader, EmptyState, IconButton, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, type ApiError } from '@/lib/api';
import { useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatMoney, formatNumber, formatTime, labelize } from '@/lib/format';
import { useTransportRefresh } from '../shared';
import type { RouteStop } from '../types';

/** Ordered stops of a route with timings, fares and riders; add / edit / delete / reorder. */
export function StopsManager({ routeId, routeCode, stops }: { routeId: number; routeCode: string; stops: RouteStop[] }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useTransportRefresh();
  const meta = useCrudMeta('transport_stops');
  const [formOpen, setFormOpen] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [reorder, setReorder] = useState(false);
  const [order, setOrder] = useState<number[]>([]);
  const [saving, setSaving] = useState(false);
  const [open, setOpen] = useState<Set<number>>(new Set());
  const defaults = useMemo(() => ({ route_id: routeId }), [routeId]);
  const scope = useMemo(() => ({ route_id: routeId }), [routeId]);

  useEffect(() => {
    if (!reorder) setOrder(stops.map((s) => s.id));
  }, [stops, reorder]);

  const byId = useMemo(() => new Map(stops.map((s) => [s.id, s])), [stops]);
  const list = reorder ? order.map((id) => byId.get(id)).filter(Boolean) as RouteStop[] : stops;
  const totalRiders = stops.reduce((a, s) => a + s.rider_count, 0);

  const move = (idx: number, dir: -1 | 1) => {
    setOrder((o) => {
      const next = [...o];
      const j = idx + dir;
      if (j < 0 || j >= next.length) return o;
      [next[idx], next[j]] = [next[j], next[idx]];
      return next;
    });
  };
  const saveOrder = async () => {
    setSaving(true);
    try {
      const res = await api.post(`transport/routes/${routeId}/stops/reorder`, { ids: order });
      toast.success(res.message || 'Stop order saved.');
      setReorder(false);
      await refresh('transport_stops', 'transport_routes');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };
  const remove = async (s: RouteStop) => {
    const ok = await confirm({
      title: 'Delete stop?',
      message: <>Delete <strong>{s.name}</strong> from route {routeCode}? Stops with active riders cannot be deleted — transfer them first.</>,
      confirmText: 'Delete stop',
      danger: true,
    });
    if (!ok) return;
    try {
      const res = await api.del(`crud/transport_stops/${s.id}`);
      toast.success(res.message || 'Stop deleted.');
      await refresh('transport_stops', 'transport_routes');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const toggle = (id: number) =>
    setOpen((o) => {
      const n = new Set(o);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });

  return (
    <Card>
      <CardHeader
        title="Stops & riders"
        subtitle={`${stops.length} stops · ${formatNumber(totalRiders)} riders · morning pickup order`}
        icon={MapPin}
        className="flex-wrap gap-y-3"
        actions={
          can('transport', 'edit') || can('transport', 'create') ? (
            reorder ? (
              <>
                <Button size="sm" variant="secondary" icon={X} onClick={() => setReorder(false)} disabled={saving}>Cancel</Button>
                <Button size="sm" icon={Save} onClick={saveOrder} loading={saving}>Save order</Button>
              </>
            ) : (
              <>
                {can('transport', 'edit') && stops.length > 1 && (
                  <Button size="sm" variant="secondary" icon={ListOrdered} onClick={() => setReorder(true)}>Reorder</Button>
                )}
                {can('transport', 'create') && (
                  <Button size="sm" icon={Plus} onClick={() => { setEditId(null); setFormOpen(true); }}>Add stop</Button>
                )}
              </>
            )
          ) : undefined
        }
      />
      {!stops.length ? (
        <EmptyState icon={MapPin} title="No stops on this route yet" description="Add pickup points in the order the bus reaches them in the morning."
          action={can('transport', 'create') ? <Button icon={Plus} onClick={() => { setEditId(null); setFormOpen(true); }}>Add first stop</Button> : undefined} />
      ) : (
        <ol className="relative px-5 py-4 before:absolute before:bottom-8 before:left-[2.25rem] before:top-8 before:w-px before:bg-slate-200 dark:before:bg-slate-800">
          {list.map((s, i) => {
            const expanded = open.has(s.id);
            return (
              <li key={s.id} className={clsx('relative flex gap-4 py-2.5', reorder && 'rounded-xl bg-slate-50/80 px-2 dark:bg-slate-800/40')}>
                <span className={clsx('relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-4 ring-white dark:ring-slate-900',
                  i === 0 ? 'bg-accent-600 text-white' : 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-100')}>
                  {i + 1}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-900 dark:text-white">{s.name}</p>
                      <p className="text-xs text-slate-500 dark:text-slate-400">
                        {s.landmark ? `${s.landmark} · ` : ''}
                        Pickup {s.pickup_time ? formatTime(s.pickup_time) : '—'} · Drop {s.drop_time ? formatTime(s.drop_time) : '—'}
                        {s.distance_km !== null ? ` · ${formatNumber(s.distance_km, 1)} km` : ''}
                      </p>
                    </div>
                    {reorder ? (
                      <div className="flex gap-1">
                        <IconButton size="sm" icon={ArrowUp} label={`Move ${s.name} up`} onClick={() => move(i, -1)} disabled={i === 0} />
                        <IconButton size="sm" icon={ArrowDown} label={`Move ${s.name} down`} onClick={() => move(i, 1)} disabled={i === list.length - 1} />
                      </div>
                    ) : (
                      <div className="flex items-center gap-1.5">
                        <span className="whitespace-nowrap text-xs font-semibold tabular-nums text-slate-700 dark:text-slate-200">{s.annual_fare ? `${formatMoney(s.annual_fare)}/yr` : 'Route fare'}</span>
                        <button type="button" onClick={() => toggle(s.id)} aria-expanded={expanded}
                          className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 transition hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">
                          {s.rider_count} rider{s.rider_count === 1 ? '' : 's'}
                          <ChevronDown className={clsx('h-3.5 w-3.5 transition-transform', expanded && 'rotate-180')} />
                        </button>
                        {can('transport', 'edit') && <IconButton size="sm" icon={Pencil} label={`Edit ${s.name}`} tone="primary" onClick={() => { setEditId(s.id); setFormOpen(true); }} />}
                        {can('transport', 'delete') && <IconButton size="sm" icon={Trash2} label={`Delete ${s.name}`} tone="danger" onClick={() => remove(s)} />}
                      </div>
                    )}
                  </div>
                  {expanded && !reorder && (
                    <div className="mt-2 motion-safe:animate-fade-in">
                      {!s.riders.length ? (
                        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/50">No riders board at this stop.</p>
                      ) : (
                        <ul className="grid grid-cols-1 gap-1.5 md:grid-cols-2">
                          {s.riders.map((r) => (
                            <li key={r.id} className="flex items-center gap-2.5 rounded-lg border border-slate-100 px-2.5 py-1.5 dark:border-slate-800">
                              <Avatar name={r.name} src={r.photo} size="sm" />
                              <div className="min-w-0 flex-1 leading-tight">
                                <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">
                                  {r.name} {r.person_type !== 'student' && <Badge color="purple" className="ml-1">{labelize(r.person_type)}</Badge>}
                                </p>
                                <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">{r.sub}</p>
                              </div>
                              {r.invoice_status && <StatusBadge status={r.invoice_status} dot={false} />}
                              {r.phone && (
                                <a href={`tel:${String(r.phone).replace(/\s/g, '')}`} className="btn-icon !h-7 !w-7" aria-label={`Call ${r.name}`}>
                                  <Phone className="h-3.5 w-3.5" />
                                </a>
                              )}
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  )}
                </div>
              </li>
            );
          })}
        </ol>
      )}
      <CrudFormModal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        meta={meta.data}
        module="transport_stops"
        id={editId}
        defaults={defaults}
        scope={scope}
        title={editId ? 'Edit stop' : `Add stop to ${routeCode}`}
        onSaved={() => refresh('transport_stops', 'transport_routes')}
      />
    </Card>
  );
}
