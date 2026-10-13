import { useState } from 'react';
import clsx from 'clsx';
import { BellRing, CalendarDays, CheckCircle2, ExternalLink, Mail, MapPin, Pencil, Printer, RotateCcw, Ticket, Undo2, UserCheck, UserRound, Users, XCircle } from 'lucide-react';
import { Alert, Badge, Button, Drawer, Dropdown, Field, Modal, PageLoader, PersonCell, StatusBadge, Textarea, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatDate, formatDateTime, formatNumber, formatTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { DeliveryProgressModal, EVENT_CATEGORIES, EVENT_STATUS_COLORS, PARTICIPANT_TYPES, REG_STATUS, openApiPrint, processBatch, useCommRefresh, type QueueProgress } from '../shared';
import type { EventDetail } from './types';

const REG_STATE: Record<string, { label: string; color: 'green' | 'amber' | 'red' | 'slate' }> = {
  open: { label: 'Registration open', color: 'green' },
  closed: { label: 'Registration closed', color: 'slate' },
  full: { label: 'Fully booked', color: 'red' },
  disabled: { label: 'No registration', color: 'slate' },
};

/** Compact date + time cell (keeps the participants table within the drawer width). */
function TwoLineDate({ value }: { value: unknown }) {
  return (
    <span className="whitespace-nowrap text-sm leading-tight">
      {formatDate(value)}
      <span className="block text-xs text-slate-500 dark:text-slate-400">{formatTime(String(value ?? '').slice(11, 16))}</span>
    </span>
  );
}

interface Props {
  id: number | null;
  onClose: () => void;
  onEdit: (id: number) => void;
}

/** Event details with registration statistics, reminders, attendance sheet and the participant list (check-in). */
export function EventDrawer({ id, onClose, onEdit }: Props) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCommRefresh();
  const { data, isLoading, error } = useApi<EventDetail>(['comm', 'event', id], `events/${id}`, undefined, { enabled: !!id });
  const [remindOpen, setRemindOpen] = useState(false);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [sending, setSending] = useState<{ initial: QueueProgress | null; step: () => Promise<QueueProgress> } | null>(null);
  const e = data?.event;
  const st = data?.stats;
  const canEdit = can('events', 'edit');

  const regAction = async (r: Row, action: 'check-in' | 'undo-check-in' | 'cancel' | 'restore') => {
    if (action === 'cancel') {
      const ok = await confirm({ title: 'Cancel registration?', message: <>Cancel the registration of <strong>{String(r.name)}</strong>? The seat becomes available to others.</>, confirmText: 'Cancel registration', danger: true });
      if (!ok) return;
    }
    try {
      const res = await api.post(`events/registrations/${r.id}/${action}`);
      toast.success(res.message);
      await refresh('crud');
    } catch (err) {
      toast.error((err as ApiError).message);
    }
  };

  const bulk = (action: 'check_in' | 'cancel') => async (ids: number[], clear: () => void) => {
    if (action === 'cancel') {
      const ok = await confirm({ title: `Cancel ${ids.length} registrations?`, message: 'The selected participants will be marked as cancelled.', confirmText: 'Cancel registrations', danger: true });
      if (!ok) return;
    }
    try {
      const res = await api.post('crud/event_registrations/bulk', { action, ids });
      toast.success(res.message);
      clear();
      await refresh('crud');
    } catch (err) {
      toast.error((err as ApiError).message);
    }
  };

  const sendReminder = async () => {
    if (!e) return;
    setBusy(true);
    try {
      const res = await api.post<{ batch: string; progress: QueueProgress }>(`events/${e.id}/remind`, { note });
      toast.success(res.message);
      setRemindOpen(false);
      setNote('');
      const batch = res.data.batch;
      setSending({ initial: res.data.progress, step: () => processBatch(batch) });
    } catch (err) {
      toast.error((err as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const cat = EVENT_CATEGORIES[String(e?.category)] ?? { label: String(e?.category ?? ''), color: 'slate' as const };
  const reg = REG_STATE[String(e?.registration_state ?? 'disabled')];
  const capacityLeft = e?.max_participants ? Math.max(0, Number(e.max_participants) - (st?.active ?? 0)) : null;
  const attendRate = st && st.active ? Math.round((st.attended / st.active) * 100) : 0;

  const tiles = st
    ? [
        { label: 'Registered', value: formatNumber(st.active), sub: st.last7 ? `+${formatNumber(st.last7)} in 7 days` : 'Active registrations', icon: Ticket, tone: 'text-brand-700 bg-brand-50 dark:bg-brand-500/15 dark:text-brand-200' },
        { label: 'Checked in', value: formatNumber(st.attended), sub: st.active ? `${attendRate}% of registered` : '—', icon: UserCheck, tone: 'text-emerald-700 bg-emerald-50 dark:bg-emerald-500/15 dark:text-emerald-300' },
        { label: 'Seats left', value: capacityLeft === null ? '∞' : formatNumber(capacityLeft), sub: e?.max_participants ? `of ${formatNumber(e.max_participants)} seats` : 'Unlimited capacity', icon: Users, tone: 'text-violet-700 bg-violet-50 dark:bg-violet-500/15 dark:text-violet-300' },
        { label: 'Cancelled', value: formatNumber(st.cancelled), sub: `${formatNumber(st.website)} registered online`, icon: XCircle, tone: 'text-red-600 bg-red-50 dark:bg-red-500/15 dark:text-red-300' },
      ]
    : [];

  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width="max-w-5xl"
        title={e ? String(e.title) : 'Event'}
        description={
          e ? (
            <span className="flex flex-wrap items-center gap-1.5 pt-1">
              <Badge color={cat.color}>{cat.label}</Badge>
              <StatusBadge status={String(e.status)} colors={EVENT_STATUS_COLORS} />
              {reg && <Badge color={reg.color}>{reg.label}</Badge>}
              {e.is_featured && <Badge color="amber">Featured</Badge>}
            </span>
          ) : undefined
        }
        footer={
          e && (
            <div className="flex w-full flex-wrap items-center justify-between gap-2">
              <Dropdown
                align="left"
                trigger={<>More</>}
                items={[
                  { label: 'Print attendance sheet', icon: Printer, onClick: () => openApiPrint(`events/${e.id}/print`) },
                  { label: 'Print checked-in list', icon: Printer, onClick: () => openApiPrint(`events/${e.id}/print`, { status: 'attended' }) },
                  e.show_on_website && e.status === 'published' && { label: 'View on website', icon: ExternalLink, href: String(e.public_url), target: '_blank' },
                ]}
              />
              <div className="flex flex-wrap gap-2">
                {canEdit && (
                  <Button variant="secondary" icon={Pencil} onClick={() => onEdit(Number(e.id))}>
                    Edit event
                  </Button>
                )}
                {canEdit && e.phase !== 'past' && e.status !== 'cancelled' && (
                  <Button icon={BellRing} onClick={() => setRemindOpen(true)} disabled={!st?.registered}>
                    Send reminder
                  </Button>
                )}
              </div>
            </div>
          )
        }
      >
        {error ? (
          <Alert variant="error">{(error as ApiError).message}</Alert>
        ) : isLoading || !data || !e || !st ? (
          <PageLoader />
        ) : (
          <div className="space-y-6">
            <div className="grid grid-cols-1 gap-5 md:grid-cols-5">
              {e.image_url && (
                <img src={appUrl(String(e.image_url))} alt="" className="h-44 w-full rounded-xl object-cover ring-1 ring-slate-200 md:col-span-2 md:h-full dark:ring-slate-700" />
              )}
              <div className={clsx('space-y-2.5 text-sm', e.image_url ? 'md:col-span-3' : 'md:col-span-5')}>
                <p className="flex items-start gap-2 text-slate-700 dark:text-slate-200">
                  <CalendarDays className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                  <span>
                    <strong>{formatDateTime(e.start_datetime)}</strong>
                    {e.end_datetime && <> – {formatDateTime(e.end_datetime)}</>}
                    {e.phase === 'ongoing' && <Badge color="green" className="ml-2">Happening now</Badge>}
                  </span>
                </p>
                {e.venue && <p className="flex items-start gap-2 text-slate-700 dark:text-slate-200"><MapPin className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />{String(e.venue)}</p>}
                {e.organizer && <p className="flex items-start gap-2 text-slate-700 dark:text-slate-200"><UserRound className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />{String(e.organizer)}</p>}
                {e.contact_email && <p className="flex items-start gap-2 text-slate-700 dark:text-slate-200"><Mail className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" /><a className="link !font-normal" href={`mailto:${e.contact_email}`}>{String(e.contact_email)}</a></p>}
                {e.registration_enabled && e.registration_deadline && (
                  <p className="text-xs text-slate-500 dark:text-slate-400">Registration closes {formatDateTime(e.registration_deadline)}</p>
                )}
                {data.reminders.sent > 0 && (
                  <p className="text-xs text-slate-500 dark:text-slate-400">{formatNumber(data.reminders.sent)} reminder e-mails sent{data.reminders.last_at ? `, last on ${formatDateTime(data.reminders.last_at)}` : ''}</p>
                )}
                {e.excerpt && <p className="text-slate-600 dark:text-slate-300">{String(e.excerpt)}</p>}
              </div>
            </div>

            {data.pending > 0 && canEdit && (
              <Alert variant="warning" title={`${formatNumber(data.pending)} reminder e-mails are waiting to be sent`}
                action={<Button size="sm" icon={RotateCcw} onClick={() => setSending({ initial: null, step: () => api.post<QueueProgress>(`events/${e.id}/resume`).then((r) => r.data) })}>Resume</Button>}
              />
            )}

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              {tiles.map((t) => (
                <div key={t.label} className="flex items-center gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                  <span className={clsx('inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', t.tone)}>
                    <t.icon className="h-5 w-5" />
                  </span>
                  <div className="min-w-0">
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">{t.label}</p>
                    <p className="font-display text-lg font-bold leading-tight text-slate-900 dark:text-white">{t.value}</p>
                    <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">{t.sub}</p>
                  </div>
                </div>
              ))}
            </div>

            {st.by_type.length > 0 && (
              <div className="flex flex-wrap gap-2 text-xs">
                {st.by_type.map((b) => (
                  <span key={b.type} className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {b.label} <strong className="tabular-nums">{formatNumber(b.count)}</strong>
                  </span>
                ))}
              </div>
            )}

            <CrudTable
              module="event_registrations"
              scope={{ event_id: Number(e.id) }}
              formDefaults={{ event_id: Number(e.id) }}
              title="Participants"
              hideColumns={['event_title']}
              hideFilters={['event_id']}
              addLabel="Add participant"
              emptyTitle="No registrations yet"
              emptyText={e.registration_enabled ? 'Registrations from the website appear here automatically. You can also add or import participants.' : 'Turn on registrations for this event or add participants manually.'}
              onSaved={() => void refresh('crud')}
              renderers={{
                participant_type: (r) => <span className="badge badge-slate">{PARTICIPANT_TYPES[String(r.participant_type)] ?? 'Guest'}</span>,
                created_at: (r) => <TwoLineDate value={r.created_at} />,
                checked_in_at: (r) => (r.checked_in_at ? <TwoLineDate value={r.checked_in_at} /> : <span className="text-slate-400">—</span>),
                name: (r) => <div className="min-w-[11rem]"><PersonCell name={String(r.name)} sub={String(r.email)} size="sm" /></div>,
                status: (r) => {
                  const s = REG_STATUS[String(r.status)];
                  if (r.status === 'registered' && canEdit && e.phase !== 'upcoming') {
                    return (
                      <Button size="xs" variant="soft" icon={CheckCircle2} onClick={() => void regAction(r, 'check-in')}>
                        Check in
                      </Button>
                    );
                  }
                  return <StatusBadge status={String(r.status)} label={s?.label} colors={{ [String(r.status)]: s?.color ?? 'slate' }} />;
                },
              }}
              rowMenu={(r) => [
                canEdit && r.status === 'registered' && { label: 'Check in', icon: CheckCircle2, onClick: () => void regAction(r, 'check-in') },
                canEdit && r.status === 'attended' && { label: 'Undo check-in', icon: Undo2, onClick: () => void regAction(r, 'undo-check-in') },
                canEdit && r.status !== 'cancelled' && { label: 'Cancel registration', icon: XCircle, danger: true, onClick: () => void regAction(r, 'cancel') },
                canEdit && r.status === 'cancelled' && { label: 'Restore registration', icon: RotateCcw, onClick: () => void regAction(r, 'restore') },
              ]}
              bulkActions={
                canEdit
                  ? [
                      { label: 'Check in', icon: CheckCircle2, onClick: bulk('check_in') },
                      { label: 'Cancel', icon: XCircle, danger: true, onClick: bulk('cancel') },
                    ]
                  : []
              }
              className="!shadow-none"
            />
          </div>
        )}
      </Drawer>

      <Modal
        open={remindOpen}
        onClose={() => setRemindOpen(false)}
        static={busy}
        size="md"
        title="Send reminder e-mail"
        description={e ? `To ${formatNumber(st?.registered ?? 0)} registered participants of “${String(e.title)}”.` : undefined}
        footer={
          <>
            <Button variant="secondary" onClick={() => setRemindOpen(false)} disabled={busy}>
              Cancel
            </Button>
            <Button icon={BellRing} loading={busy} onClick={() => void sendReminder()}>
              Send to {formatNumber(st?.registered ?? 0)}
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-slate-600 dark:text-slate-300">The “Event reminder” template is used (date, time, venue and a link to the event page). Checked-in and cancelled participants are skipped.</p>
          <Field label="Additional note (optional)" htmlFor="remind-note" hint="Shown below the reminder, e.g. reporting time or what to bring.">
            <Textarea id="remind-note" rows={3} maxLength={1000} value={note} onChange={(ev) => setNote(ev.target.value)} placeholder="Please report at the registration desk by 9:15 AM with your college ID." />
          </Field>
        </div>
      </Modal>

      <DeliveryProgressModal
        open={!!sending}
        title="Sending reminders"
        description={e ? String(e.title) : undefined}
        initial={sending?.initial}
        step={sending?.step ?? (() => Promise.reject(new Error('idle')))}
        onClose={() => {
          setSending(null);
          void refresh('crud');
        }}
        onFinished={() => void refresh('crud')}
      />
    </>
  );
}
