import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Activity, CalendarClock, CircleX, CopyCheck, Link2, ListChecks, Plus, ScrollText, Send, Zap } from 'lucide-react';
import { Alert, Button, Dropdown, PageHeader, Spinner, Stagger, StatCard, Tabs, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import { ModeBanner, useWaRefresh } from '../shared';
import { useEventOptions, useEvents } from './api';
import { BulkDeliveries } from './BulkDeliveries';
import { CustomEventModal } from './CustomEventModal';
import { EventDrawer } from './EventDrawer';
import { EventList } from './EventList';
import { EventLogs } from './EventLogs';
import type { WaEvent, WaEventSaved } from './types';

/**
 * Event Manager (WhatsApp Module 4 + 10): the catalogue of GIMT business events that send WhatsApp templates
 * automatically, custom events for external systems, the execution log, tests and integration code.
 */
export default function EventManagerPage() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'logs' ? 'logs' : 'events';
  const openId = Number(params.get('event')) || null;
  const drawerTab = ['settings', 'logs', 'test', 'code'].includes(params.get('panel') ?? '') ? (params.get('panel') as string) : 'settings';
  const { data, isLoading, error, refetch } = useEvents();
  const { data: options } = useEventOptions();
  const [toggling, setToggling] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const s = data?.summary;

  const setParam = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, v)));
    setParams(next, { replace: true });
  };
  const openEvent = (e: Pick<WaEvent, 'id'>, panel = 'settings') => setParam({ event: String(e.id), panel: panel === 'settings' ? null : panel });

  const toggle = async (e: WaEvent, active: boolean) => {
    setToggling(e.id);
    try {
      const r = await api.post<WaEventSaved>(`whatsapp/events/${e.id}/toggle`, { active });
      toast.success(r.message);
      // an active workflow started by the same event also messages the contact: warn, never block
      if (r.data?.warning) toast.warning(r.data.warning, 'Contacts may get two messages');
      await refresh('events');
    } catch (err) {
      toast.error((err as ApiError).message);
    } finally {
      setToggling(null);
    }
  };

  const linkTemplates = async () => {
    setBusy('link');
    try {
      const r = await api.post('whatsapp/events/link-templates', { activate_core: false });
      toast.success(r.message);
      await refresh('events');
    } catch (err) {
      toast.error((err as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const runScan = async () => {
    setBusy('scan');
    try {
      const r = await api.post('whatsapp/events/scan');
      toast.success(r.message);
      await refresh('events');
    } catch (err) {
      toast.error((err as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const more = [
    ...(can('whatsapp_automation', 'edit') ? [{ label: busy === 'link' ? 'Linking templates…' : 'Link templates by name', icon: Link2, onClick: linkTemplates, disabled: !!busy }] : []),
    ...(can('whatsapp_automation', 'manage') ? [{ label: busy === 'scan' ? 'Running scan…' : 'Run daily scan now', icon: CalendarClock, onClick: runScan, disabled: !!busy }] : []),
  ];

  return (
    <>
      <PageHeader
        title="Event Manager"
        description="Automatic WhatsApp messages for admissions, fees, exams, certificates and support — fired by GIMT, the website and external systems."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Event Manager' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {more.length > 0 && (
              <Dropdown
                label="Event tools"
                triggerClassName="btn btn-secondary"
                trigger={<>{busy ? <Spinner className="h-4 w-4" /> : <ListChecks className="h-4 w-4" aria-hidden />} Tools</>}
                items={more}
              />
            )}
            {can('whatsapp_automation', 'create') && <Button icon={Plus} onClick={() => setCreating(true)}>New custom event</Button>}
          </div>
        }
      />
      <ModeBanner />

      {error ? (
        <Alert variant="error" title="Could not load the event catalogue" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <Stagger className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" itemClassName="last:col-span-2 md:last:col-span-1" step={50}>
          {[
            <StatCard key="ev" label="Active events" icon={Zap} tone="orange" loading={isLoading} value={s ? `${formatNumber(s.active)} / ${formatNumber(s.events)}` : 0} hint={s ? `${s.no_template} without a template` : undefined} />,
            <StatCard key="today" label="Fired today" icon={Activity} tone="navy" loading={isLoading} value={s?.fired_today ?? 0} hint={s ? `${formatNumber(s.fired_7d)} in 7 days` : undefined} />,
            <StatCard key="msg" label="Messages (7 days)" icon={Send} tone="green" loading={isLoading} value={s?.messages_7d ?? 0} hint="Queued by events" />,
            <StatCard key="fail" label="Failed (7 days)" icon={CircleX} tone="red" loading={isLoading} value={s?.failed_7d ?? 0} hint={s ? `${formatNumber(s.skipped_7d)} skipped` : undefined} />,
            <StatCard key="dup" label="Duplicates blocked" icon={CopyCheck} tone="purple" loading={isLoading} value={s?.duplicates_7d ?? 0} hint={s ? (s.last_scan ? `Daily scan ${timeAgo(s.last_scan)}` : 'Daily scan not run yet') : undefined} />,
          ]}
        </Stagger>
      )}

      {!!data?.fanouts?.length && <BulkDeliveries jobs={data.fanouts} />}

      <Tabs
        className="mb-4"
        variant="pills"
        value={tab}
        onChange={(t) => setParam({ tab: t === 'events' ? null : t })}
        tabs={[
          { key: 'events', label: 'Events', icon: Zap, count: data?.events.length },
          { key: 'logs', label: 'Execution logs', icon: ScrollText },
        ]}
      />

      {tab === 'events' ? (
        <EventList
          events={data?.events}
          categories={data?.categories ?? options?.categories ?? {}}
          sources={options?.sources ?? {}}
          loading={isLoading}
          onOpen={(e) => openEvent(e)}
          onToggle={toggle}
          toggling={toggling}
          onCreate={() => setCreating(true)}
        />
      ) : (
        <EventLogs events={data?.events} sources={options?.sources ?? {}} />
      )}

      <EventDrawer id={openId} tab={drawerTab} onTab={(t) => setParam({ panel: t === 'settings' ? null : t })} onClose={() => setParam({ event: null, panel: null })} options={options} />
      <CustomEventModal
        open={creating}
        onClose={() => setCreating(false)}
        options={options}
        onCreated={(e) => {
          setCreating(false);
          openEvent(e);
        }}
      />
    </>
  );
}
