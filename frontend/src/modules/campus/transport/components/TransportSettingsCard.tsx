import { useEffect, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { PlugZap, Radio, Satellite, Settings2, Smartphone } from 'lucide-react';
import { Alert, Button, Card, CardHeader, Field, Input, Modal, ProgressBar, Select, Skeleton, Toggle, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useActionForm } from '../hooks';
import { useTransportRefresh, useTransportSettings } from '../shared';
import type { TransportSettings } from '../types';

/**
 * GPS / live bus tracking and the driver app are future scope (brief §21). This card shows the integration as a
 * clearly labelled, configurable setting — no simulated map or positions.
 */
export function TransportSettingsCard({ className }: { className?: string }) {
  const { can } = useAuth();
  const { data, isLoading, error } = useTransportSettings();
  const [open, setOpen] = useState(false);
  const s = data;
  const mappedPct = s && s.vehicles ? (s.devices_mapped * 100) / s.vehicles : 0;
  const providerLabel = s?.providers.find((p) => p.value === s.gps_provider)?.label ?? 'Not connected';
  return (
    <Card className={clsx('flex flex-col', className)}>
      <CardHeader
        title="Live tracking"
        subtitle="GPS & driver app · integration-ready (future scope)"
        icon={Satellite}
        actions={can('transport', 'manage') ? <Button size="sm" variant="secondary" icon={Settings2} onClick={() => setOpen(true)}>Configure</Button> : undefined}
      />
      <div className="card-body flex-1 space-y-4">
        {error ? (
          <Alert variant="error">Unable to load transport settings.</Alert>
        ) : isLoading || !s ? (
          <div className="space-y-3"><Skeleton className="h-5 w-2/3" /><Skeleton className="h-2 w-full" /><Skeleton className="h-12 w-full" /></div>
        ) : (
          <>
            <div className="flex items-center gap-3">
              <span className={clsx('inline-flex h-10 w-10 items-center justify-center rounded-xl', s.gps_enabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300')}>
                <Radio className="h-5 w-5" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="text-sm font-semibold text-slate-900 dark:text-white">{s.gps_enabled ? `Integration enabled · ${providerLabel}` : 'Not connected'}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">{s.gps_enabled ? `Polling every ${s.gps_refresh_seconds}s is configured; the live map arrives with the tracking module (future scope).` : 'No live positions are shown until a tracking provider is configured.'}</p>
              </div>
            </div>
            <div>
              <div className="mb-1 flex justify-between text-xs">
                <span className="font-medium text-slate-600 dark:text-slate-300">GPS devices mapped</span>
                <span className="tabular-nums text-slate-500">{s.devices_mapped} of {s.vehicles} vehicles</span>
              </div>
              <ProgressBar value={mappedPct} tone="cyan" label="GPS devices mapped" />
              <p className="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">Add the tracker IMEI on each vehicle (Vehicles › Edit › GPS device ID).</p>
            </div>
            <ul className="space-y-2 text-xs text-slate-600 dark:text-slate-300">
              <li className="flex items-start gap-2"><PlugZap className="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600 dark:text-brand-300" /> Supported: Traccar, Fleetx, LocoNav, TrackoLap or any REST endpoint.</li>
              <li className="flex items-start gap-2"><Smartphone className="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600 dark:text-brand-300" /> Driver app & parent arrival alerts: {s.parent_alerts ? 'enabled' : 'planned'}; stop coordinates are already stored per stop.</li>
            </ul>
          </>
        )}
      </div>
      {s && <TransportSettingsModal open={open} onClose={() => setOpen(false)} settings={s} />}
    </Card>
  );
}

function TransportSettingsModal({ open, onClose, settings }: { open: boolean; onClose: () => void; settings: TransportSettings }) {
  const toast = useToast();
  const refresh = useTransportRefresh();
  const form = useActionForm({
    alert_days: String(settings.alert_days), fee_due_days: String(settings.fee_due_days), staff_fee_percent: String(settings.staff_fee_percent ?? 50), gps_enabled: settings.gps_enabled, gps_provider: settings.gps_provider,
    gps_api_url: settings.gps_api_url, gps_refresh_seconds: String(settings.gps_refresh_seconds), parent_alerts: settings.parent_alerts,
  });
  const { values, set, errors } = form;
  useEffect(() => {
    if (open) {
      form.reset({
        alert_days: String(settings.alert_days), fee_due_days: String(settings.fee_due_days), staff_fee_percent: String(settings.staff_fee_percent ?? 50), gps_enabled: settings.gps_enabled, gps_provider: settings.gps_provider,
        gps_api_url: settings.gps_api_url, gps_refresh_seconds: String(settings.gps_refresh_seconds), parent_alerts: settings.parent_alerts,
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const ok = await form.run(async () => {
      const res = await api.post('transport/settings', values);
      toast.success(res.message || 'Settings saved.');
    });
    if (ok) {
      await refresh('vehicles', 'drivers');
      onClose();
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={form.saving}
      size="lg"
      title="Transport settings"
      description="Alert windows, invoice due days, staff fee share and the GPS tracking integration (future scope)."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.saving}>Cancel</Button>
          <Button type="submit" form="transport-settings-form" loading={form.saving}>Save settings</Button>
        </>
      }
    >
      <form id="transport-settings-form" onSubmit={submit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {form.error && <Alert variant="error" className="sm:col-span-2">{form.error}</Alert>}
        <Field label="Expiry alert window (days)" htmlFor="ts-alert" error={errors.alert_days} hint="Insurance, fitness, permit, PUC and licences inside this window are flagged.">
          <Input id="ts-alert" type="number" min={7} max={180} value={values.alert_days} onChange={(e) => set('alert_days', e.target.value)} invalid={!!errors.alert_days} />
        </Field>
        <Field label="Transport invoice due in (days)" htmlFor="ts-due" error={errors.fee_due_days} hint="Default due date of invoices raised from allocations.">
          <Input id="ts-due" type="number" min={0} max={90} value={values.fee_due_days} onChange={(e) => set('fee_due_days', e.target.value)} invalid={!!errors.fee_due_days} />
        </Field>
        <Field label="Faculty / staff fee (% of stop fare)" htmlFor="ts-staff" error={errors.staff_fee_percent} hint="Default annual fee for faculty and staff riders; 0 = free." className="sm:col-span-2">
          <Input id="ts-staff" type="number" min={0} max={100} value={values.staff_fee_percent} onChange={(e) => set('staff_fee_percent', e.target.value)} invalid={!!errors.staff_fee_percent} />
        </Field>
        <div className="sm:col-span-2 rounded-xl border border-dashed border-slate-300 p-4 dark:border-slate-700">
          <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">GPS / live bus tracking</p>
          <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">Future scope: once a provider is connected, vehicle positions can be pulled from its API using each vehicle's GPS device ID.</p>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Provider" htmlFor="ts-prov" error={errors.gps_provider}>
              <Select id="ts-prov" value={values.gps_provider} onChange={(e) => set('gps_provider', e.target.value)} options={settings.providers} invalid={!!errors.gps_provider} />
            </Field>
            <Field label="Refresh interval (seconds)" htmlFor="ts-refresh" error={errors.gps_refresh_seconds}>
              <Input id="ts-refresh" type="number" min={10} max={600} value={values.gps_refresh_seconds} onChange={(e) => set('gps_refresh_seconds', e.target.value)} invalid={!!errors.gps_refresh_seconds} />
            </Field>
            <Field label="API endpoint" htmlFor="ts-url" error={errors.gps_api_url} className="sm:col-span-2">
              <Input id="ts-url" type="url" value={values.gps_api_url} onChange={(e) => set('gps_api_url', e.target.value)} placeholder="https://tracking.example.com/api" invalid={!!errors.gps_api_url} />
            </Field>
            <div className="sm:col-span-2 space-y-3">
              <Toggle checked={!!values.gps_enabled} onChange={(v) => set('gps_enabled', v)} label="Enable live tracking integration" description="Requires a provider and an API endpoint." />
              {errors.gps_enabled && <p className="form-error">{errors.gps_enabled}</p>}
              <Toggle checked={!!values.parent_alerts} onChange={(v) => set('parent_alerts', v)} label="Parent arrival alerts (driver app)" description="Planned: notify parents when the bus is near their stop." />
            </div>
          </div>
        </div>
      </form>
    </Modal>
  );
}
