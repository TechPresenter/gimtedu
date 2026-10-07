import { useState } from 'react';
import clsx from 'clsx';
import { Copy, Fingerprint, KeyRound, PlugZap, Power, QrCode, RefreshCw } from 'lucide-react';
import { Alert, Badge, Button, Modal, Skeleton, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { useApi, useInvalidate } from '@/lib/queries';
import { formatDateTime } from '@/lib/format';
import type { DevicePayload } from '../types';

function copy(text: string, done: () => void) {
  if (navigator.clipboard?.writeText) navigator.clipboard.writeText(text).then(done, done);
  else done();
}

export function DeviceIntegrationModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const q = useApi<DevicePayload>(['attendance', 'device'], 'attendance/device', undefined, { enabled: open });
  const d = q.data;
  const [token, setToken] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const generate = async () => {
    if (d?.configured) {
      const ok = await confirm({ title: 'Rotate device token?', message: 'Devices using the current token will stop syncing until they are updated with the new token.', confirmText: 'Generate new token', danger: true });
      if (!ok) return;
    }
    setBusy(true);
    try {
      const res = await api.post<DevicePayload>('attendance/device/token');
      setToken(res.data.token ?? null);
      toast.success(res.message);
      await invalidate('attendance');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };
  const disable = async () => {
    const ok = await confirm({ title: 'Disable device integration?', message: 'All biometric and QR devices will be rejected until a new token is generated.', confirmText: 'Disable', danger: true });
    if (!ok) return;
    try {
      const res = await api.del('attendance/device/token');
      setToken(null);
      toast.success(res.message);
      await invalidate('attendance');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const sample = `curl -X POST ${d?.endpoint ?? '/api/attendance/punch'} \\
  -H "Content-Type: application/json" \\
  -H "X-Device-Token: <device token>" \\
  -d '{"device_id":"GATE-01","punch_id":"8812731","identifier":"GIMT26BBA0001","punched_at":"2026-10-07 09:02:11","method":"biometric","direction":"in"}'`;

  return (
    <Modal open={open} onClose={() => { setToken(null); onClose(); }} size="xl" title="Biometric & QR device integration" description="Devices post each punch to a token-protected endpoint. Punches are idempotent — retries never double-mark." icon={<span className="kpi-icon bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300"><Fingerprint className="h-5 w-5" /></span>}>
      {q.isLoading || !d ? (
        <div className="space-y-3">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-32 w-full" />
        </div>
      ) : (
        <div className="space-y-5">
          <div className="flex flex-col gap-3 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-center dark:border-slate-700">
            <span className={clsx('kpi-icon', d.configured ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
              <PlugZap className="h-5 w-5" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-slate-900 dark:text-white">{d.configured ? 'Integration active' : 'Not configured'}</p>
              <p className="text-sm text-slate-500 dark:text-slate-400">
                {d.configured ? <>Token {d.token_hint} · {d.stats.today} punches today ({d.stats.recorded_today} recorded) · {d.stats.devices} device(s) in the last 30 days</> : 'Generate a token to let devices post punches.'}
              </p>
            </div>
            {d.can_manage && (
              <div className="flex gap-2">
                <Button size="sm" icon={d.configured ? RefreshCw : KeyRound} loading={busy} onClick={generate}>{d.configured ? 'Rotate token' : 'Generate token'}</Button>
                {d.configured && <Button size="sm" variant="secondary" icon={Power} onClick={disable}>Disable</Button>}
              </div>
            )}
          </div>

          {token && (
            <Alert variant="success" title="Copy the new token now — it will not be shown again">
              <div className="mt-2 flex items-center gap-2">
                <code className="block min-w-0 flex-1 truncate rounded-lg bg-white px-3 py-2 font-mono text-xs text-slate-800 ring-1 ring-emerald-200 dark:bg-slate-900 dark:text-slate-100 dark:ring-emerald-500/30">{token}</code>
                <Button size="sm" variant="secondary" icon={Copy} onClick={() => copy(token, () => toast.success('Token copied to clipboard.'))}>Copy</Button>
              </div>
            </Alert>
          )}

          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <p className="form-label">Endpoint</p>
              <div className="flex items-center gap-2">
                <code className="block min-w-0 flex-1 truncate rounded-lg bg-slate-100 px-3 py-2 font-mono text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-200">POST {d.endpoint}</code>
                <Button size="sm" variant="secondary" icon={Copy} onClick={() => copy(d.endpoint, () => toast.success('Endpoint copied.'))} aria-label="Copy endpoint" />
              </div>
              <ul className="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                <li><strong>Auth:</strong> header <code className="text-xs">X-Device-Token</code> or <code className="text-xs">Authorization: Bearer</code></li>
                <li><strong>Identifier:</strong> Student ID / roll no / admission no, or employee ID. QR codes may encode <code className="text-xs">GIMT:STU:&lt;id&gt;</code>.</li>
                <li><strong>Students:</strong> marked in the class running at punch time (from the timetable); late after the grace period.</li>
                <li><strong>Employees:</strong> first punch = in time (late after {d.late_after}), last punch = out time.</li>
                <li><strong>Idempotent:</strong> resend with the same <code className="text-xs">device_id</code> + <code className="text-xs">punch_id</code> safely.</li>
              </ul>
            </div>
            <div>
              <p className="form-label">Example request</p>
              <pre className="max-h-56 overflow-auto rounded-xl bg-brand-950 p-3 text-[11px] leading-relaxed text-slate-100">{sample}</pre>
            </div>
          </div>

          <div>
            <p className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100"><QrCode className="h-4 w-4" /> Recent punches</p>
            {d.recent.length === 0 ? (
              <p className="rounded-xl bg-slate-50 px-4 py-6 text-center text-sm text-slate-500 dark:bg-slate-800/40">No punches received yet.</p>
            ) : (
              <div className="table-wrap rounded-xl border border-slate-200 dark:border-slate-700">
                <table className="data-table">
                  <thead>
                    <tr><th>Time</th><th>Device</th><th>Person</th><th>Result</th><th className="hidden md:table-cell">Message</th></tr>
                  </thead>
                  <tbody>
                    {d.recent.map((r) => (
                      <tr key={r.id}>
                        <td className="whitespace-nowrap !py-2 text-xs">{formatDateTime(r.punched_at)}</td>
                        <td className="!py-2 text-xs"><Badge color="slate">{r.device_id}</Badge></td>
                        <td className="!py-2 text-xs"><span className="font-medium text-slate-800 dark:text-slate-100">{r.person_name ?? r.identifier}</span>{r.person_type && <span className="text-slate-400"> · {r.person_type}</span>}</td>
                        <td className="!py-2"><StatusBadge status={r.result} colors={{ recorded: 'green', no_class: 'amber', holiday: 'purple', unknown_person: 'red', ignored: 'slate' }} /></td>
                        <td className="hidden max-w-xs truncate !py-2 text-xs text-slate-500 md:table-cell">{r.message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}
