import { useState } from 'react';
import { CalendarClock, FlaskConical } from 'lucide-react';
import { Button, Field, Input, Modal, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, isoDate } from '@/lib/format';
import { DeliveryProgressModal, type QueueProgress } from '../shared';
import type { CampaignRef } from './types';

interface Sending {
  id: number;
  title: string;
  initial: QueueProgress | null;
}

/**
 * Campaign actions shared by the list, the report drawer and the composer:
 * test send, schedule, send now (with live progress), resume, cancel and duplicate.
 * Render `dialogs` once on the page.
 */
export function useCampaignActions(onChanged: () => void) {
  const toast = useToast();
  const confirm = useConfirm();
  const { session } = useAuth();
  const [test, setTest] = useState<CampaignRef | null>(null);
  const [testTo, setTestTo] = useState('');
  const [schedule, setSchedule] = useState<CampaignRef | null>(null);
  const [when, setWhen] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [sending, setSending] = useState<Sending | null>(null);

  const openTest = (c: CampaignRef) => {
    setErrors({});
    setTestTo(c.channel && c.channel !== 'email' ? session?.user?.phone ?? '' : session?.user?.email ?? '');
    setTest(c);
  };
  const openSchedule = (c: CampaignRef) => {
    setErrors({});
    const d = new Date(Date.now() + 24 * 3600 * 1000);
    setWhen(`${isoDate(d)}T09:00`);
    setSchedule(c);
  };

  const run = async <T,>(fn: () => Promise<{ data: T; message: string }>, after?: (data: T) => void) => {
    setBusy(true);
    setErrors({});
    try {
      const res = await fn();
      toast.success(res.message);
      after?.(res.data);
      onChanged();
      return true;
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
      return false;
    } finally {
      setBusy(false);
    }
  };

  const sendNow = async (c: CampaignRef) => {
    const ok = await confirm({
      title: 'Send campaign now?',
      message: (
        <>
          <strong>{c.title}</strong> will be sent{c.recipients ? <> to <strong>{formatNumber(c.recipients)}</strong> recipients</> : ' to everyone in the selected audience'}. This cannot be undone.
        </>
      ),
      confirmText: 'Send now',
    });
    if (!ok) return;
    await run(
      () => api.post<{ progress: QueueProgress }>(`newsletter/campaigns/${c.id}/send`),
      (d) => setSending({ id: c.id, title: c.title, initial: d.progress }),
    );
  };

  const cancel = async (c: CampaignRef & { status?: string }) => {
    const sendingNow = c.status === 'sending';
    const ok = await confirm({
      title: sendingNow ? 'Stop sending?' : 'Cancel schedule?',
      message: sendingNow ? 'Messages that were not sent yet will be cancelled. Delivered messages cannot be recalled.' : 'The campaign goes back to draft and will not be sent automatically.',
      confirmText: sendingNow ? 'Stop sending' : 'Cancel schedule',
      danger: sendingNow,
    });
    if (!ok) return;
    await run(() => api.post(`newsletter/campaigns/${c.id}/cancel`));
  };

  const duplicate = async (c: CampaignRef, then?: (id: number) => void) => {
    await run(() => api.post<{ id: number }>(`newsletter/campaigns/${c.id}/duplicate`), (d) => then?.(d.id));
  };

  const resume = (c: CampaignRef) => setSending({ id: c.id, title: c.title, initial: null });

  const dialogs = (
    <>
      <Modal
        open={!!test}
        onClose={() => setTest(null)}
        static={busy}
        size="sm"
        title="Send a test"
        description={test ? `Preview “${test.title}” in your own inbox before sending it to everyone.` : undefined}
        footer={
          <>
            <Button variant="secondary" onClick={() => setTest(null)} disabled={busy}>
              Cancel
            </Button>
            <Button
              icon={FlaskConical}
              loading={busy}
              onClick={() =>
                test &&
                void run(() => api.post(`newsletter/campaigns/${test.id}/test`, test.channel && test.channel !== 'email' ? { phone: testTo } : { email: testTo })).then((ok) => ok && setTest(null))
              }
            >
              Send test
            </Button>
          </>
        }
      >
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (test) void run(() => api.post(`newsletter/campaigns/${test.id}/test`, test.channel && test.channel !== 'email' ? { phone: testTo } : { email: testTo })).then((ok) => ok && setTest(null));
          }}
        >
          {test?.channel && test.channel !== 'email' ? (
            <Field label="Mobile number" required htmlFor="test-phone" error={errors.phone}>
              <Input id="test-phone" type="tel" value={testTo} onChange={(e) => setTestTo(e.target.value)} invalid={!!errors.phone} placeholder="+91 98765 43210" />
            </Field>
          ) : (
            <Field label="Send test to" required htmlFor="test-email" error={errors.email} hint="Placeholders are filled with your name. The subject is prefixed with [Test].">
              <Input id="test-email" type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} invalid={!!errors.email} placeholder="you@gimt.ac.in" autoFocus />
            </Field>
          )}
        </form>
      </Modal>

      <Modal
        open={!!schedule}
        onClose={() => setSchedule(null)}
        static={busy}
        size="sm"
        title="Schedule campaign"
        description={schedule ? `“${schedule.title}” will be sent automatically at the chosen time.` : undefined}
        footer={
          <>
            <Button variant="secondary" onClick={() => setSchedule(null)} disabled={busy}>
              Cancel
            </Button>
            <Button icon={CalendarClock} loading={busy} onClick={() => schedule && void run(() => api.post(`newsletter/campaigns/${schedule.id}/schedule`, { scheduled_at: when })).then((ok) => ok && setSchedule(null))}>
              Schedule
            </Button>
          </>
        }
      >
        <Field label="Send on" required htmlFor="schedule-at" error={errors.scheduled_at} hint="The campaign starts automatically at this time through the server scheduled task (Campaigns → Automatic delivery).">
          <Input id="schedule-at" type="datetime-local" value={when} min={`${isoDate()}T00:00`} onChange={(e) => setWhen(e.target.value)} invalid={!!errors.scheduled_at} />
        </Field>
      </Modal>

      <DeliveryProgressModal
        open={!!sending}
        title={sending ? `Sending “${sending.title}”` : ''}
        description="Messages are delivered in batches with live counts."
        initial={sending?.initial}
        step={() => api.post<QueueProgress>(`newsletter/campaigns/${sending?.id}/process`).then((r) => r.data)}
        onClose={() => {
          setSending(null);
          onChanged();
        }}
        onFinished={() => onChanged()}
      />
    </>
  );

  return { openTest, openSchedule, sendNow, cancel, duplicate, resume, dialogs, sendingId: sending?.id ?? null };
}
