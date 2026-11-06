import clsx from 'clsx';
import { FlaskConical, PauseCircle, ShieldAlert, TriangleAlert, Unplug } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '@/lib/auth';
import { useWaStatus } from './api';
import type { WaStatus } from './types';

type Tone = 'amber' | 'red';

const TONES: Record<Tone, { box: string; icon: string; link: string }> = {
  amber: {
    box: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
    icon: 'text-amber-600 dark:text-amber-300',
    link: 'text-amber-800 ring-amber-300 hover:bg-amber-100 focus-visible:ring-amber-500 dark:bg-amber-500/20 dark:text-amber-100 dark:ring-amber-400/40 dark:hover:bg-amber-500/30',
  },
  red: {
    box: 'border-red-200 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100',
    icon: 'text-red-600 dark:text-red-300',
    link: 'text-red-800 ring-red-300 hover:bg-red-100 focus-visible:ring-red-500 dark:bg-red-500/20 dark:text-red-100 dark:ring-red-400/40 dark:hover:bg-red-500/30',
  },
};

function Notice({ tone, icon: Icon, children, to, cta }: { tone: Tone; icon: typeof FlaskConical; children: ReactNode; to?: string | null; cta?: string }) {
  const t = TONES[tone];
  return (
    <div role="status" className={clsx('flex flex-col gap-2 rounded-xl border px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between', t.box)}>
      <div className="flex min-w-0 items-start gap-2.5">
        <Icon size={18} className={clsx('mt-0.5 shrink-0', t.icon)} aria-hidden />
        <p className="min-w-0">{children}</p>
      </div>
      {to && cta && (
        <Link
          to={to}
          className={clsx(
            'shrink-0 self-start rounded-lg bg-white px-3 py-1.5 text-xs font-semibold ring-1 transition focus:outline-none focus-visible:ring-2 sm:self-auto',
            t.link,
          )}
        >
          {cta}
        </Link>
      )}
    </div>
  );
}

/**
 * Status notices shown at the top of WhatsApp pages: TEST MODE while messages are simulated (no Interakt key, mode =
 * test, or a dev install); in live mode a warning when the background worker (cron) has stopped; a warning when no
 * webhook secret is saved (test and live mode on non-dev installs — unsigned webhooks from Interakt are ignored there;
 * the built-in test tools keep working); in live mode a red notice when the Interakt webhook has gone silent or fails
 * the signature check (webhook_health), and an amber one while no signed webhook has ever arrived; and in any mode a
 * notice while automated messages are paused. Renders nothing when all is well.
 * Pass `status` when the page already loaded whatsapp/status, else the banner fetches it.
 */
export function ModeBanner({ status: given, className }: { status?: WaStatus | null; className?: string }) {
  const { can } = useAuth();
  const q = useWaStatus({ enabled: !given });
  const s = given ?? q.data;
  if (!s) return null;
  const canSetup = can('whatsapp_settings', 'view');
  const live = s.mode === 'live';
  const secretMissing = s.webhook_secret_set === false && !s.is_dev;
  const notices: ReactNode[] = [];

  if (!live) {
    const reason = !s.configured
      ? 'No Interakt API key is saved yet.'
      : s.is_dev
        ? 'This is a development install.'
        : 'Mode is set to Test in WhatsApp settings.';
    notices.push(
      <Notice key="test" tone="amber" icon={FlaskConical} to={canSetup ? '/whatsapp/integrations' : null} cta="Connect Interakt">
        <span className="font-semibold">Test mode</span> — messages are simulated and nothing is sent to WhatsApp. {reason}
      </Notice>,
    );
  } else if (s.worker && s.worker.status !== 'ok') {
    notices.push(
      <Notice key="worker" tone="red" icon={TriangleAlert} to={canSetup ? '/whatsapp/api' : null} cta="Set up cron">
        <span className="font-semibold">Background worker not running</span> — {s.worker.message}
      </Notice>,
    );
  }

  if (secretMissing && canSetup) {
    notices.push(
      <Notice key="secret" tone={live ? 'red' : 'amber'} icon={ShieldAlert} to="/whatsapp/integrations" cta="Add webhook secret">
        <span className="font-semibold">Webhook secret missing</span> — incoming messages, delivery ticks and template updates from Interakt are ignored
        until a webhook secret is saved here and the same secret is entered in Interakt
        {live ? '.' : ' (the built-in test tools still work).'}
      </Notice>,
    );
  }

  // webhook health (live mode only; the missing-secret notice above already explains a silent webhook)
  if (live && !secretMissing && (s.webhook_health === 'silent' || s.webhook_health === 'bad_signature')) {
    notices.push(
      <Notice key="health" tone="red" icon={Unplug} to={canSetup ? '/whatsapp/integrations' : null} cta="Check webhook">
        <span className="font-semibold">
          {s.webhook_health === 'silent' ? 'Interakt webhook silent' : 'Interakt webhooks failing the signature check'}
        </span>{' '}
        — {s.webhook_health_note}
      </Notice>,
    );
  } else if (live && !secretMissing && s.webhook_health === 'never' && canSetup) {
    notices.push(
      <Notice key="health" tone="amber" icon={Unplug} to="/whatsapp/integrations" cta="Webhook setup">
        <span className="font-semibold">No webhook from Interakt yet</span> — {s.webhook_health_note}
      </Notice>,
    );
  }

  if (s.automation_paused) {
    notices.push(
      <Notice key="paused" tone="amber" icon={PauseCircle} to={canSetup ? '/whatsapp/settings?section=automation' : null} cta="Resume">
        <span className="font-semibold">Automated messages are paused</span> — automations, events, follow-ups and campaigns wait in the queue. Agent and bot
        replies still go out.
      </Notice>,
    );
  }

  if (!notices.length) return null;
  return <div className={clsx('mb-4 space-y-2', className)}>{notices}</div>;
}

export default ModeBanner;
