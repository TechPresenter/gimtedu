import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarCheck, CalendarX, Clock, ExternalLink, MapPin, Pencil, RotateCcw, Trash2, UserPlus, Users, Video } from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Combobox, Drawer, EmptyState, IconButton, Input, PageLoader, ProgressBar, SearchInput, Select, StatusBadge, useConfirm, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { EVENT_TYPE_COLORS, REG_STATUS_COLORS, REG_STATUS_LABELS, useRefreshModules } from './shared';

interface EventDetail {
  event: Row & { id: number; title: string; type_label: string; mode_label: string; status: string; event_date: string; capacity: number | null };
  counts: { registered: number; attended: number; no_show: number; cancelled: number; guests: number };
  registrations: (Row & { id: number; alumni_id: number; status: string; guests: number })[];
}

/** Event details + registrations (register alumni, mark attendance, cancel / remove). */
export function EventDrawer({ id, onClose, onEdit }: { id: number | null; onClose: () => void; onEdit: (id: number) => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useRefreshModules();
  const { data, isLoading, error, refetch } = useApi<EventDetail>(['crud', 'alumni_events', '__event', id], `alumni/events/${id}`, undefined, { enabled: !!id });
  const [toAdd, setToAdd] = useState<(string | number)[]>([]);
  const [guests, setGuests] = useState('0');
  const [addError, setAddError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [q, setQ] = useState('');
  const [filter, setFilter] = useState('');
  const e = data?.event;
  const c = data?.counts;
  const editable = can('alumni', 'edit');
  const past = e ? new Date(e.event_date.replace(' ', 'T')).getTime() < Date.now() : false;

  const after = async () => {
    await refetch();
    await refresh('alumni_events');
  };
  const run = async (key: string, fn: () => Promise<{ message: string }>) => {
    setBusy(key);
    try {
      const res = await fn();
      toast.success(res.message);
      await after();
    } catch (err) {
      toast.error((err as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const register = async () => {
    if (!id) return;
    setAddError(null);
    if (!toAdd.length) {
      setAddError('Select at least one alumni to register.');
      return;
    }
    setBusy('add');
    try {
      const res = await api.post(`alumni/events/${id}/registrations`, { alumni_ids: toAdd, guests: Number(guests) || 0 });
      toast.success(res.message);
      setToAdd([]);
      setGuests('0');
      await after();
    } catch (err) {
      const ae = err as ApiError;
      setAddError(ae.errors?.alumni_ids ?? ae.errors?.guests ?? ae.message);
    } finally {
      setBusy(null);
    }
  };
  const setEventStatus = async (status: 'completed' | 'cancelled' | 'upcoming') => {
    if (!e) return;
    if (status === 'cancelled') {
      const ok = await confirm({ title: 'Cancel this event?', message: <>Registered alumni will need to be informed that <strong>{e.title}</strong> is cancelled.</>, confirmText: 'Cancel event', danger: true });
      if (!ok) return;
    }
    await run(`status-${status}`, () => api.post(`alumni/events/${e.id}/status`, { status }));
  };
  const removeReg = async (r: Row) => {
    const ok = await confirm({ title: 'Remove registration?', message: <>Remove <strong>{String(r.name)}</strong> from this event’s registrations?</>, confirmText: 'Remove', danger: true });
    if (ok) await run(`del-${r.id}`, () => api.del(`alumni/registrations/${r.id}`));
  };

  const regs = useMemo(() => {
    const s = q.trim().toLowerCase();
    return (data?.registrations ?? []).filter((r) => (!filter || r.status === filter) && (!s || `${r.name} ${r.company ?? ''} ${r.batch_year}`.toLowerCase().includes(s)));
  }, [data, q, filter]);

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      width="max-w-2xl"
      title={e?.title ?? 'Event'}
      description={e ? `${e.type_label} · ${formatDateTime(e.event_date)}` : undefined}
      footer={
        e && editable ? (
          <>
            {e.status === 'upcoming' && past && <Button variant="success" icon={CalendarCheck} loading={busy === 'status-completed'} onClick={() => setEventStatus('completed')}>Mark completed</Button>}
            {e.status === 'upcoming' && <Button variant="secondary" icon={CalendarX} loading={busy === 'status-cancelled'} onClick={() => setEventStatus('cancelled')}>Cancel event</Button>}
            {e.status !== 'upcoming' && !past && <Button variant="secondary" icon={RotateCcw} loading={busy === 'status-upcoming'} onClick={() => setEventStatus('upcoming')}>Re-open</Button>}
            <Button icon={Pencil} onClick={() => onEdit(e.id)}>Edit event</Button>
          </>
        ) : undefined
      }
    >
      {error ? (
        <Alert variant="error" title="Unable to load the event">{(error as Error).message}</Alert>
      ) : isLoading || !e || !c ? (
        <PageLoader />
      ) : (
        <div className="space-y-6">
          {e.image && <img src={appUrl(String(e.image))} alt="" className="h-36 w-full rounded-xl bg-slate-100 object-cover dark:bg-slate-800" />}
          <div className="flex flex-wrap gap-1.5">
            <StatusBadge status={e.status} />
            <StatusBadge status={String(e.type)} label={e.type_label} colors={EVENT_TYPE_COLORS} dot={false} />
            <Badge color={e.mode === 'online' ? 'cyan' : e.mode === 'hybrid' ? 'purple' : 'navy'}>{e.mode_label}</Badge>
          </div>
          <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div className="flex items-start gap-2"><Clock className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" /><span>{formatDateTime(e.event_date)}{e.end_date ? ` – ${formatDateTime(e.end_date)}` : ''}</span></div>
            {e.venue && <div className="flex items-start gap-2"><MapPin className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" /><span>{String(e.venue)}</span></div>}
            {e.organiser && <div className="flex items-start gap-2"><Users className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" /><span>{String(e.organiser)}</span></div>}
            {e.registration_link && (
              <a href={String(e.registration_link)} target="_blank" rel="noopener noreferrer" className="link flex items-start gap-2 !font-normal">
                {e.mode === 'in_person' ? <ExternalLink className="mt-0.5 h-4 w-4 shrink-0" /> : <Video className="mt-0.5 h-4 w-4 shrink-0" />}
                <span className="truncate">{String(e.registration_link)}</span>
              </a>
            )}
          </dl>
          {e.description && <div className="prose-sm max-w-none text-sm text-slate-700 dark:text-slate-200 [&_ul]:list-disc [&_ul]:pl-5 [&_p]:mb-2" dangerouslySetInnerHTML={{ __html: String(e.description) }} />}

          <div className="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
              <p className="text-sm font-semibold text-slate-900 dark:text-white">{formatNumber(c.registered)} registered{e.capacity ? ` of ${formatNumber(e.capacity)} seats` : ''}</p>
              <p className="text-xs text-slate-500">{c.attended} attended · {c.no_show} no-show · {c.cancelled} cancelled{c.guests ? ` · +${c.guests} guests` : ''}</p>
            </div>
            {e.capacity ? <ProgressBar value={(c.registered / e.capacity) * 100} tone={c.registered >= e.capacity ? 'red' : 'green'} label="Seats filled" /> : <p className="text-xs text-slate-500">No seat limit for this event.</p>}
          </div>

          {editable && e.status !== 'cancelled' && (
            <div className="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/50">
              <p className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">{e.status === 'completed' ? 'Add attendees' : 'Register alumni'}</p>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                <div className="min-w-0 flex-1">
                  <Combobox multiple source="alumni" value={toAdd} onChange={(v) => { setToAdd(v); setAddError(null); }} placeholder="Search alumni by name, email or company…" invalid={!!addError} />
                </div>
                <Input type="number" min={0} max={10} value={guests} onChange={(ev) => setGuests(ev.target.value)} className="sm:!w-24" aria-label="Guests per alumni" title="Guests per alumni" />
                <Button icon={UserPlus} loading={busy === 'add'} onClick={register}>Register</Button>
              </div>
              {addError ? <p className="form-error mt-1.5" role="alert">{addError}</p> : <p className="form-hint mt-1.5">Guests = accompanying family members per alumni (homecoming / reunions).</p>}
            </div>
          )}

          <div>
            <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center">
              <SearchInput value={q} onChange={setQ} placeholder="Search registrations…" className="w-full sm:max-w-[16rem]" size="sm" />
              <Select inputSize="sm" value={filter} onChange={(ev) => setFilter(ev.target.value)} options={Object.entries(REG_STATUS_LABELS).map(([value, label]) => ({ value, label }))} placeholder="All statuses" className="sm:!w-40" aria-label="Filter by registration status" />
            </div>
            {regs.length === 0 ? (
              <EmptyState icon={Users} title={data.registrations.length ? 'No matching registrations' : 'No registrations yet'} description={data.registrations.length ? 'Try another search or status.' : 'Register alumni using the search box above.'} className="py-8" />
            ) : (
              <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                {regs.map((r) => (
                  <li key={r.id} className="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center">
                    <div className="flex min-w-0 flex-1 items-center gap-3">
                      <Avatar name={String(r.name)} src={r.photo as string | null} size="sm" />
                      <div className="min-w-0">
                        <Link to={`/alumni/${r.alumni_id}`} className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.name)}{r.guests ? <span className="font-normal text-slate-500"> +{r.guests}</span> : null}</Link>
                        <p className="truncate text-xs text-slate-500">Batch {String(r.batch_year)}{r.program_short ? ` · ${r.program_short}` : ''}{r.company ? ` · ${r.company}` : ''}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5 pl-11 sm:pl-0">
                      {editable ? (
                        <Select
                          inputSize="sm"
                          value={r.status}
                          disabled={busy === `reg-${r.id}`}
                          onChange={(ev) => {
                            const status = ev.target.value;
                            void run(`reg-${r.id}`, () => api.post(`alumni/registrations/${r.id}`, { status }).then(() => ({ message: `${r.name} marked ${(REG_STATUS_LABELS[status] ?? status).toLowerCase()}.` })));
                          }}
                          options={Object.entries(REG_STATUS_LABELS).map(([value, label]) => ({ value, label }))}
                          aria-label={`Registration status of ${r.name}`}
                          className="!w-36"
                        />
                      ) : (
                        <StatusBadge status={r.status} label={REG_STATUS_LABELS[r.status]} colors={REG_STATUS_COLORS} />
                      )}
                      {can('alumni', 'delete') && <IconButton size="sm" icon={Trash2} label={`Remove ${r.name}`} tone="danger" onClick={() => removeReg(r)} />}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Drawer>
  );
}
