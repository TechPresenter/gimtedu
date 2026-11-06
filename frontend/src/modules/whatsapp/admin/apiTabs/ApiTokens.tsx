import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Ban, KeyRound, Plus, ShieldCheck } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, DataTable, EmptyState, Field, Input, Modal, useConfirm, useToast, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { useWaRefresh } from '../../shared';
import type { ApiToken } from '../lib';
import { CopyField } from '../ui';

type Scopes = Record<string, { label: string; description: string }>;

/** API tokens for external systems (X-GIMT-Token): create (shown once), list, revoke. */
export default function ApiTokens() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const canManage = can('whatsapp_settings', 'manage');
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['wa', 'admin', 'tokens'],
    queryFn: ({ signal }) => api.get<{ tokens: ApiToken[]; scopes: Scopes }>('whatsapp/tokens', undefined, { signal }),
  });
  const [open, setOpen] = useState(false);

  const revoke = async (t: ApiToken) => {
    const ok = await confirm({ title: `Revoke "${t.name}"?`, message: 'Systems using this token are rejected immediately. This cannot be undone.', confirmText: 'Revoke token', danger: true });
    if (!ok) return;
    try {
      const r = await api.post(`whatsapp/tokens/${t.id}/revoke`);
      toast.success(r.message);
      await refresh('admin');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const columns: Column<ApiToken>[] = [
    {
      key: 'name',
      header: 'Token',
      render: (t) => (
        <span className="block min-w-0">
          <span className="block font-medium text-slate-800 dark:text-slate-100">{t.name}</span>
          <code className="text-xs text-slate-500 dark:text-slate-400">gimt_wa_…{t.last4}</code>
        </span>
      ),
    },
    { key: 'scopes', header: 'Scopes', render: (t) => <span className="flex flex-wrap gap-1">{t.scopes.map((s) => <Badge key={s} color="blue">{data?.scopes[s]?.label ?? s}</Badge>)}</span> },
    { key: 'events', header: 'Events received', align: 'right', render: (t) => <span className="tabular-nums">{formatNumber(t.events)}</span> },
    { key: 'last_used_at', header: 'Last used', render: (t) => (t.last_used_at ? <span title={formatDateTime(t.last_used_at)}>{timeAgo(t.last_used_at)}{t.last_used_ip ? <span className="block text-xs text-slate-500">{t.last_used_ip}</span> : null}</span> : <span className="text-slate-400">Never</span>) },
    { key: 'created_at', header: 'Created', render: (t) => <span className="whitespace-nowrap">{formatDateTime(t.created_at)}<span className="block text-xs text-slate-500 dark:text-slate-400">{t.created_by_name ?? '—'}</span></span> },
    { key: 'active', header: 'Status', render: (t) => (t.active ? <Badge color="green" dot>Active</Badge> : <Badge color="slate">Revoked {t.revoked_at ? timeAgo(t.revoked_at) : ''}</Badge>) },
  ];

  return (
    <Card>
      <CardHeader
        title="API tokens"
        subtitle="Let the admission portal, payment gateway or a CRM fire GIMT WhatsApp events (header X-GIMT-Token)"
        icon={KeyRound}
        actions={canManage ? <Button size="sm" icon={Plus} onClick={() => setOpen(true)}>New token</Button> : undefined}
      />
      {error ? (
        <div className="p-4">
          <Alert variant="error" title="Could not load tokens" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
        </div>
      ) : (
        <DataTable<ApiToken>
          columns={columns}
          rows={data?.tokens ?? []}
          loading={isLoading}
          rowKey={(t) => t.id}
          rowClassName={(t) => (t.active ? undefined : 'opacity-60')}
          actions={(t) => (canManage && t.active ? <Button size="xs" variant="ghost" icon={Ban} onClick={() => revoke(t)} className="text-red-600 dark:text-red-400">Revoke</Button> : null)}
          empty={<EmptyState icon={KeyRound} title="No API tokens yet" description="Create a token for each external system so you can revoke them one by one." action={canManage ? <Button size="sm" icon={Plus} onClick={() => setOpen(true)}>Create a token</Button> : undefined} />}
        />
      )}
      <div className="flex items-start gap-2 border-t border-slate-100 px-4 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
        <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden />
        Tokens are stored as SHA-256 hashes — GIMT shows a token only once, right after it is created. See the API docs tab for request examples.
      </div>
      <CreateTokenModal open={open} onClose={() => setOpen(false)} scopes={data?.scopes ?? {}} />
    </Card>
  );
}

function CreateTokenModal({ open, onClose, scopes }: { open: boolean; onClose: () => void; scopes: Scopes }) {
  const toast = useToast();
  const refresh = useWaRefresh();
  const [name, setName] = useState('');
  const [picked, setPicked] = useState<string[]>(['events']);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [token, setToken] = useState<string | null>(null);

  const close = () => {
    if (busy) return;
    setName('');
    setPicked(['events']);
    setErrors({});
    setToken(null);
    onClose();
  };
  const create = async () => {
    setBusy(true);
    setErrors({});
    try {
      const r = await api.post<{ token: string }>('whatsapp/tokens', { name, scopes: picked });
      setToken(r.data.token);
      toast.success(r.message);
      await refresh('admin');
    } catch (e) {
      const ae = e as ApiError;
      setErrors(ae.errors ?? {});
      if (!ae.errors) toast.error(ae.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={close}
      static={busy}
      title={token ? 'Copy your new token' : 'New API token'}
      description={token ? 'This is the only time the token is shown. Store it in the other system’s secret settings.' : 'Name the system that will use it, e.g. “Admission portal”.'}
      footer={
        token ? (
          <Button onClick={close}>Done</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={close} disabled={busy}>
              Cancel
            </Button>
            <Button icon={KeyRound} loading={busy} onClick={create} disabled={!name.trim() || !picked.length}>
              Create token
            </Button>
          </>
        )
      }
    >
      {token ? (
        <div className="space-y-3">
          <CopyField value={token} label="API token" />
          <Alert variant="warning">Anyone with this token can fire WhatsApp events for GIMT. Never put it in website JavaScript.</Alert>
        </div>
      ) : (
        <div className="space-y-4">
          <Field label="Token name" htmlFor="wa-token-name" required error={errors.name}>
            <Input id="wa-token-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} placeholder="Admission portal" invalid={!!errors.name} autoFocus />
          </Field>
          <Field label="Scopes" error={errors.scopes}>
            <div className="space-y-2">
              {Object.entries(scopes).map(([k, s]) => (
                <Checkbox
                  key={k}
                  label={s.label}
                  description={s.description}
                  checked={picked.includes(k)}
                  onChange={(e) => setPicked((p) => (e.target.checked ? [...p, k] : p.filter((x) => x !== k)))}
                />
              ))}
            </div>
          </Field>
        </div>
      )}
    </Modal>
  );
}
