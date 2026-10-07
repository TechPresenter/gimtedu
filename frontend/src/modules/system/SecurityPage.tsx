import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import {
  ArrowRight, Ban, FileText, Globe, KeyRound, Lock, LockOpen, LogIn, LogOut, Monitor, Printer, Save, Settings, ShieldAlert, ShieldCheck, ShieldX, Smartphone, UserX,
} from 'lucide-react';
import { CrudTable } from '@/components/crud';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, DataTable, EmptyState, Field, IconButton, Input, PageHeader, Pagination, Reveal, SearchInput, Skeleton, Stagger, StatTile, Tabs,
  Toggle, toneClasses, useConfirm, useToast, type Column,
} from '@/components/ui';
import { BarChart } from '@/components/charts';
import { api, ApiError, apiUrl } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { useApi } from '@/lib/queries';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import type { Paginated } from '@/lib/types';
import { checkMeta, type CheckStatus } from './components';
import type { TokenRow } from './types';
import './system.css';

interface Overview {
  kpis: { success_24h: number; failed_24h: number; failed_7d: number; failed_prev_7d: number; ips_7d: number; locked: number; blocked_ips: number; tokens: number };
  series: { labels: string[]; series: Record<string, number[]> };
  reasons: { reason: string; count: number }[];
  locked: { id: number; name: string; email: string; username: string; avatar: string | null; locked_until: string; failed_attempts: number; last_failed_login_at: string | null }[];
  at_risk: { id: number; name: string; email: string; avatar: string | null; attempts: number; last_attempt: string }[];
  top_ips: { ip_address: string; attempts: number; accounts: number; last_seen: string; blocked: boolean }[];
  policy: Record<string, string>;
  your_ip: string;
  can: { edit: boolean; settings: boolean };
}
interface Checklist {
  items: { key: string; title: string; status: CheckStatus; detail: string; action: { label: string; to: string } | null }[];
  score: number;
  counts: Record<CheckStatus, number>;
}

const TABS = ['logins', 'locked', 'blocked', 'sessions', 'policy'] as const;
const isMobileUa = (b: string) => /android|ios|ipados/i.test(b);

export default function SecurityPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as (typeof TABS)[number]) : 'logins';
  const { data: ov, isLoading, error, refetch } = useApi<Overview>(['security-overview'], 'security/overview');
  const { data: checklist, isLoading: clLoading } = useApi<Checklist>(['security-checklist'], 'security/checklist', undefined, { staleTime: 60_000 });
  const [blockKey, setBlockKey] = useState(0);

  // Deep links such as /security?tab=logins (used by notifications) land on the tab panel.
  useEffect(() => {
    if (!params.get('tab')) return;
    const t = setTimeout(() => document.getElementById('security-tabs')?.scrollIntoView({ block: 'start', behavior: 'auto' }), 300);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refreshAll = () => Promise.all([refetch(), qc.invalidateQueries({ queryKey: ['security-checklist'] }), qc.invalidateQueries({ queryKey: ['crud', 'login_logs'] }), qc.invalidateQueries({ queryKey: ['crud', 'blocked_ips'] }), qc.invalidateQueries({ queryKey: ['security-tokens'] })]);
  const goTab = (t: string) => setParams({ tab: t }, { replace: true });
  const unlock = async (u: { id: number; name: string }) => {
    try {
      const res = await api.post(`security/unlock/${u.id}`);
      toast.success(res.message);
      await refreshAll();
      void qc.invalidateQueries({ queryKey: ['crud', 'users'] });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const blockIp = async (ip: string, attempts: number) => {
    if (!(await confirm({ title: `Block ${ip}?`, message: <>Sign-in attempts from <strong className="font-mono">{ip}</strong> will be refused permanently ({attempts} failed attempts in the last 7 days). You can unblock it later.</>, confirmText: 'Block IP', danger: true }))) return;
    try {
      const res = await api.post('crud/blocked_ips', { ip_address: ip, reason: `Blocked from Security Center: ${attempts} failed sign-ins in 7 days`, expires_at: '' });
      toast.success(res.message || `${ip} blocked.`);
      setBlockKey((k) => k + 1);
      await refreshAll();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const k = ov?.kpis;
  const failTrend = k ? (k.failed_prev_7d ? Math.round(((k.failed_7d - k.failed_prev_7d) / k.failed_prev_7d) * 100) : null) : null;
  const score = checklist?.score ?? 0;
  const scoreTone = score >= 80 ? 'green' : score >= 55 ? 'amber' : 'red';
  const circumference = 2 * Math.PI * 45;

  return (
    <>
      <PageHeader
        title="Security Center"
        description="Sign-in activity, lockouts, blocked addresses, remembered devices and the hardening checklist."
        breadcrumbs={[{ label: 'System' }, { label: 'Security' }]}
        actions={
          <>
            <Button variant="secondary" icon={Printer} href={apiUrl('security/report')} target="_blank">
              Security report
            </Button>
            {can('settings', 'view') && (
              <Button variant="secondary" icon={Settings} to="/settings?tab=security">
                Security settings
              </Button>
            )}
          </>
        }
      />

      {error && <Alert variant="error" className="mb-6" title="Unable to load security data">{(error as ApiError).message}</Alert>}

      <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)]">
        {/* Score */}
        <Reveal>
          <Card className="flex h-full items-center gap-5 p-5">
            {clLoading || !checklist ? (
              <>
                <Skeleton className="h-28 w-28 rounded-full" />
                <div className="flex-1 space-y-2"><Skeleton className="h-5 w-2/3" /><Skeleton className="h-4 w-full" /></div>
              </>
            ) : (
              <>
                <div className="relative h-28 w-28 shrink-0">
                  <svg viewBox="0 0 100 100" className="h-full w-full -rotate-90" aria-hidden>
                    <circle cx="50" cy="50" r="45" fill="none" strokeWidth="9" className="stroke-slate-100 dark:stroke-slate-800" />
                    <circle
                      cx="50" cy="50" r="45" fill="none" strokeWidth="9" strokeLinecap="round"
                      stroke={toneClasses[scoreTone].hex}
                      strokeDasharray={circumference}
                      strokeDashoffset={circumference * (1 - score / 100)}
                      className="sys-ring"
                      style={{ ['--sys-ring-from' as string]: String(circumference) }}
                    />
                  </svg>
                  <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="font-display text-2xl font-extrabold text-slate-900 dark:text-white">{score}</span>
                    <span className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">score</span>
                  </div>
                </div>
                <div className="min-w-0">
                  <h2 className="font-display text-base font-bold text-slate-900 dark:text-white">{score >= 80 ? 'Well protected' : score >= 55 ? 'Needs attention' : 'At risk'}</h2>
                  <p className="text-xs text-slate-500">Based on {checklist.items.length - checklist.counts.info} hardening checks.</p>
                  <div className="mt-2.5 flex flex-wrap gap-1.5">
                    <Badge color="green">{checklist.counts.pass} passed</Badge>
                    {checklist.counts.warn > 0 && <Badge color="amber">{checklist.counts.warn} warnings</Badge>}
                    {checklist.counts.fail > 0 && <Badge color="red">{checklist.counts.fail} critical</Badge>}
                  </div>
                </div>
              </>
            )}
          </Card>
        </Reveal>
        <Stagger className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 sm:grid-cols-3" step={50}>
          {[
            <StatTile key="s" label="Successful sign-ins (24 h)" value={k?.success_24h ?? 0} icon={LogIn} tone="green" sub="All accounts" loading={isLoading} />,
            <StatTile key="f" label="Failed attempts (24 h)" value={k?.failed_24h ?? 0} icon={ShieldAlert} tone="red" sub={failTrend !== null ? `${failTrend > 0 ? '▲' : failTrend < 0 ? '▼' : '•'} ${Math.abs(failTrend)}% week on week` : `${k?.failed_7d ?? 0} in 7 days`} loading={isLoading} />,
            <StatTile key="l" label="Locked accounts" value={k?.locked ?? 0} icon={Lock} tone="amber" sub="Temporarily locked" loading={isLoading} />,
            <StatTile key="b" label="Blocked IPs" value={k?.blocked_ips ?? 0} icon={Ban} tone="navy" sub="Active blocks" loading={isLoading} />,
            <StatTile key="t" label="Remembered devices" value={k?.tokens ?? 0} icon={Monitor} tone="purple" sub="Active remember-me" loading={isLoading} />,
            <StatTile key="i" label="Unique IPs (7 days)" value={k?.ips_7d ?? 0} icon={Globe} tone="cyan" sub={ov ? `You: ${ov.your_ip}` : undefined} loading={isLoading} />,
          ]}
        </Stagger>
      </div>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Sign-in attempts" subtitle="Last 14 days — successful vs failed vs locked / blocked" icon={LogIn} />
            <div className="p-5">
              {isLoading || !ov ? (
                <Skeleton className="h-[250px] w-full" />
              ) : (
                <BarChart labels={ov.series.labels} stacked height={250} series={Object.entries(ov.series.series).map(([label, data], i) => ({ label, data, color: ['#22943F', '#EF4444', '#F59E0B'][i] }))} />
              )}
              {ov && ov.reasons.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                  <span className="text-xs font-medium text-slate-500">Failure reasons (30 days):</span>
                  {ov.reasons.map((r) => (
                    <span key={r.reason} className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                      {r.reason} <strong className="tabular-nums">{formatNumber(r.count)}</strong>
                    </span>
                  ))}
                </div>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Top failing sources" subtitle="IP addresses with the most failed sign-ins (7 days)" icon={ShieldX} />
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {isLoading && Array.from({ length: 4 }).map((_, i) => <li key={i} className="p-4"><Skeleton className="h-10 w-full" /></li>)}
              {ov?.top_ips.length === 0 && (
                <li className="px-5 py-10 text-center text-sm text-slate-500">
                  <ShieldCheck className="mx-auto mb-2 h-8 w-8 text-emerald-500" />
                  No failed sign-ins in the last 7 days.
                </li>
              )}
              {ov?.top_ips.map((ip) => (
                <li key={ip.ip_address} className="flex items-center gap-3 px-5 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="font-mono text-sm font-semibold text-slate-800 dark:text-slate-100">{ip.ip_address}</p>
                    <p className="text-xs text-slate-500">
                      <strong className="text-red-600 dark:text-red-400">{ip.attempts}</strong> attempts · {ip.accounts} account{ip.accounts === 1 ? '' : 's'} · {timeAgo(ip.last_seen)}
                    </p>
                  </div>
                  {ip.blocked ? (
                    <Badge color="red">Blocked</Badge>
                  ) : ip.ip_address === ov.your_ip ? (
                    <Badge color="blue">Your IP</Badge>
                  ) : (
                    ov.can.edit && (
                      <Button size="xs" variant="secondary" icon={Ban} onClick={() => blockIp(ip.ip_address, ip.attempts)}>
                        Block
                      </Button>
                    )
                  )}
                </li>
              ))}
            </ul>
          </Card>
        </Reveal>
      </div>

      {/* Checklist */}
      <Reveal>
        <Card className="mb-6">
          <CardHeader title="Security checklist" subtitle="Hardening checks for this installation — fix warnings before going live" icon={ShieldCheck} />
          <div className="grid grid-cols-1 gap-px bg-slate-100 dark:bg-slate-800 md:grid-cols-2">
            {clLoading && Array.from({ length: 6 }).map((_, i) => <div key={i} className="bg-white p-4 dark:bg-slate-900"><Skeleton className="h-10 w-full" /></div>)}
            {checklist?.items.map((c) => {
              const m = checkMeta[c.status];
              return (
                <div key={c.key} className="flex items-start gap-3 bg-white p-4 dark:bg-slate-900">
                  <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full', toneClasses[m.tone].icon)}>
                    <m.icon className="h-4 w-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                      {c.title}
                      <span className={clsx('rounded-full px-2 py-px text-[10px] font-bold uppercase tracking-wide', toneClasses[m.tone].icon)}>{m.label}</span>
                    </p>
                    <p className="mt-0.5 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{c.detail}</p>
                    {c.action && (
                      <Link to={c.action.to} className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">
                        {c.action.label} <ArrowRight className="h-3 w-3" />
                      </Link>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </Card>
      </Reveal>

      {/* Tabs */}
      <Card id="security-tabs" className="scroll-mt-20 overflow-hidden">
        <div className="px-4 pt-2">
          <Tabs
            value={tab}
            onChange={goTab}
            tabs={[
              { key: 'logins', label: 'Login activity', icon: LogIn },
              { key: 'locked', label: 'Locked accounts', icon: Lock, count: (ov?.locked.length ?? 0) || undefined },
              { key: 'blocked', label: 'Blocked IPs', icon: Ban, count: k?.blocked_ips || undefined },
              { key: 'sessions', label: 'Remembered devices', icon: Monitor, count: k?.tokens || undefined },
              { key: 'policy', label: 'Security policy', icon: KeyRound },
            ]}
          />
        </div>
        <div key={tab} className="motion-safe:animate-fade-in">
          {tab === 'logins' && (
            <CrudTable
              module="login_logs"
              bare
              urlState
              title={false}
              noRowActions
              className="[&>div:first-child]:px-4 [&>div:first-child]:pt-4"
              emptyTitle="No sign-in activity yet"
              emptyText="Sign-in attempts will appear here as users log in."
              renderers={{
                status: (r) => {
                  const ok = r.status === 'success';
                  const out = r.status === 'logout';
                  return (
                    <span className={clsx('inline-flex items-center gap-1.5 text-xs font-semibold', ok ? 'text-emerald-700 dark:text-emerald-300' : out ? 'text-slate-500' : 'text-red-600 dark:text-red-400')}>
                      {ok ? <LogIn className="h-3.5 w-3.5" /> : out ? <LogOut className="h-3.5 w-3.5" /> : <ShieldAlert className="h-3.5 w-3.5" />}
                      {ok ? 'Success' : out ? 'Sign-out' : r.status === 'locked' ? 'Locked' : r.status === 'blocked' ? 'Blocked' : 'Failed'}
                    </span>
                  );
                },
                user_name: (r) =>
                  r.user_name ? (
                    <div className="flex items-center gap-2.5">
                      <Avatar name={r.user_name} src={r.user_avatar} size="sm" />
                      <div className="min-w-0 leading-tight">
                        <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{r.user_name}</p>
                        <p className="truncate text-xs text-slate-500">{r.identifier}</p>
                      </div>
                    </div>
                  ) : (
                    <div className="leading-tight">
                      <p className="text-sm text-slate-500">Unknown account</p>
                      <p className="font-mono text-xs text-slate-400">{r.identifier}</p>
                    </div>
                  ),
                ip_address: (r) => <span className="font-mono text-xs">{r.ip_address}</span>,
                browser: (r) => (
                  <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-xs text-slate-600 dark:text-slate-300">
                    {isMobileUa(r.browser) ? <Smartphone className="h-3.5 w-3.5 text-slate-400" /> : <Monitor className="h-3.5 w-3.5 text-slate-400" />}
                    {r.browser}
                  </span>
                ),
              }}
            />
          )}
          {tab === 'locked' && <LockedPanel ov={ov} loading={isLoading} onUnlock={unlock} />}
          {tab === 'blocked' && (
            <div className="p-4">
              {ov && (
                <Alert variant="info" className="mb-4">
                  Your current IP address is <code className="font-mono font-semibold">{ov.your_ip}</code> — it cannot be blocked. Expired blocks stop applying automatically.
                </Alert>
              )}
              <CrudTable key={blockKey} module="blocked_ips" bare title={false} addLabel="Block an IP" emptyTitle="No blocked IP addresses" emptyText="Block an address to refuse all sign-in attempts from it." onSaved={() => void refreshAll()} />
            </div>
          )}
          {tab === 'sessions' && <SessionsPanel onChanged={() => void refreshAll()} />}
          {tab === 'policy' && <PolicyPanel ov={ov} onSaved={() => void refreshAll()} />}
        </div>
      </Card>
    </>
  );
}

function LockedPanel({ ov, loading, onUnlock }: { ov?: Overview; loading: boolean; onUnlock: (u: { id: number; name: string }) => void }) {
  const { can } = useAuth();
  if (loading || !ov) return <div className="space-y-3 p-5">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-14 w-full" />)}</div>;
  return (
    <div className="grid grid-cols-1 gap-6 p-5 lg:grid-cols-2">
      <div>
        <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Locked accounts</h3>
        {ov.locked.length === 0 ? (
          <div className="rounded-xl border border-dashed border-slate-200 dark:border-slate-700">
            <EmptyState icon={LockOpen} title="No locked accounts" description={`Accounts lock for ${ov.policy.lockout_minutes} minutes after ${ov.policy.max_login_attempts} failed attempts.`} className="!py-8" />
          </div>
        ) : (
          <ul className="space-y-2">
            {ov.locked.map((u) => (
              <li key={u.id} className="flex items-center gap-3 rounded-xl border border-red-100 bg-red-50/40 p-3 dark:border-red-500/20 dark:bg-red-500/5">
                <Avatar name={u.name} src={u.avatar} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{u.name}</p>
                  <p className="truncate text-xs text-slate-500">{u.email}</p>
                  <p className="text-xs text-red-600 dark:text-red-400">Locked until {formatDateTime(u.locked_until)}{u.last_failed_login_at ? ` · last attempt ${timeAgo(u.last_failed_login_at)}` : ''}</p>
                </div>
                {can('security', 'edit') && (
                  <Button size="sm" variant="secondary" icon={LockOpen} onClick={() => onUnlock(u)}>
                    Unlock
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
      <div>
        <h3 className="mb-1 text-sm font-semibold text-slate-900 dark:text-white">At risk (last 24 hours)</h3>
        <p className="mb-3 text-xs text-slate-500">Active accounts with 2 or more failed attempts that are not locked yet.</p>
        {ov.at_risk.length === 0 ? (
          <p className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700">No accounts at risk.</p>
        ) : (
          <ul className="space-y-2">
            {ov.at_risk.map((u) => (
              <li key={u.id} className="flex items-center gap-3 rounded-xl border border-amber-100 bg-amber-50/40 p-3 dark:border-amber-500/20 dark:bg-amber-500/5">
                <Avatar name={u.name} src={u.avatar} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{u.name}</p>
                  <p className="text-xs text-amber-700 dark:text-amber-300">{u.attempts} failed attempts · last {timeAgo(u.last_attempt)}</p>
                </div>
                <UserX className="h-4 w-4 text-amber-500" />
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

function SessionsPanel({ onChanged }: { onChanged: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const dq = useDebounce(q, 300);
  useEffect(() => setPage(1), [dq]);
  const { data, isLoading, isFetching, refetch } = useApi<Paginated<TokenRow>>(['security-tokens', dq, page], 'security/tokens', { q: dq || undefined, page, per_page: 10 });
  const revoke = async (t: TokenRow) => {
    if (!(await confirm({ title: 'Revoke this device?', message: `${t.user_name} will need to sign in again on ${t.browser} (${t.ip_address}).`, confirmText: 'Revoke', danger: true }))) return;
    try {
      const res = await api.del(`security/tokens/${t.id}`);
      toast.success(res.message);
      await refetch();
      onChanged();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const revokeUser = async (t: TokenRow) => {
    if (!(await confirm({ title: `Sign ${t.user_name} out everywhere?`, message: 'Every remembered device of this user will be signed out.', confirmText: 'Sign out all', danger: true }))) return;
    try {
      const res = await api.post('security/tokens/revoke-user', { user_id: t.user_id });
      toast.success(res.message);
      await refetch();
      onChanged();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const columns: Column<TokenRow>[] = [
    {
      key: 'user', header: 'User',
      render: (t) => (
        <div className="flex items-center gap-2.5">
          <Avatar name={t.user_name} src={t.user_avatar} size="sm" />
          <div className="min-w-0 leading-tight">
            <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{t.user_name}</p>
            <p className="truncate text-xs text-slate-500">{t.user_email}</p>
          </div>
        </div>
      ),
    },
    {
      key: 'device', header: 'Device',
      render: (t) => (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-sm">
          {isMobileUa(t.browser) ? <Smartphone className="h-4 w-4 text-slate-400" /> : <Monitor className="h-4 w-4 text-slate-400" />}
          {t.browser}
          {t.current && <Badge color="green">This device</Badge>}
        </span>
      ),
    },
    { key: 'ip', header: 'IP address', render: (t) => <span className="font-mono text-xs">{t.ip_address}</span> },
    { key: 'created', header: 'Signed in', render: (t) => <span className="whitespace-nowrap text-sm" title={formatDateTime(t.created_at)}>{timeAgo(t.created_at)}</span> },
    { key: 'expires', header: 'Expires', render: (t) => <span className="whitespace-nowrap text-sm">{formatDateTime(t.expires_at)}</span> },
  ];
  return (
    <div>
      <div className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-slate-500">Devices where users ticked “Remember me”. Revoking forces a fresh sign-in.</p>
        <SearchInput value={q} onChange={setQ} placeholder="Search user or IP…" className="sm:w-64" size="sm" />
      </div>
      <DataTable<TokenRow>
        columns={columns}
        rows={data?.rows ?? []}
        loading={isLoading || isFetching}
        skeletonRows={4}
        caption="Remembered devices"
        empty={<EmptyState icon={Monitor} title="No remembered devices" description={dq ? 'No devices match your search.' : 'No user has an active “Remember me” session.'} />}
        actions={
          can('security', 'edit')
            ? (t) => (
                <div className="flex justify-end gap-0.5">
                  <IconButton size="sm" icon={LogOut} label="Revoke this device" tone="danger" onClick={() => revoke(t)} />
                  <IconButton size="sm" icon={Ban} label="Sign out all of this user's devices" tone="danger" onClick={() => revokeUser(t)} />
                </div>
              )
            : undefined
        }
      />
      {data && data.total > data.per_page && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} />}
    </div>
  );
}

const policyFields: { key: string; label: string; type: 'number' | 'toggle'; suffix?: string; help?: string; min?: number; max?: number }[] = [
  { key: 'password_min_length', label: 'Minimum password length', type: 'number', suffix: 'chars', min: 6, max: 64 },
  { key: 'password_expiry_days', label: 'Password expiry', type: 'number', suffix: 'days', help: '0 = never', min: 0, max: 365 },
  { key: 'max_login_attempts', label: 'Failed attempts before lockout', type: 'number', min: 1, max: 20 },
  { key: 'lockout_minutes', label: 'Lockout duration', type: 'number', suffix: 'min', min: 1, max: 1440 },
  { key: 'max_ip_attempts', label: 'Max failed attempts per IP', type: 'number', min: 3, max: 500 },
  { key: 'session_timeout', label: 'Idle session timeout', type: 'number', suffix: 'min', min: 5, max: 720 },
  { key: 'remember_me_days', label: '“Remember me” duration', type: 'number', suffix: 'days', min: 1, max: 365 },
];
const policyToggles: { key: string; label: string; help: string }[] = [
  { key: 'password_require_uppercase', label: 'Require an uppercase letter', help: 'A–Z' },
  { key: 'password_require_number', label: 'Require a number', help: '0–9' },
  { key: 'password_require_special', label: 'Require a special character', help: 'e.g. @ # $ %' },
  { key: 'two_factor_enabled', label: 'Two-factor authentication (2FA-ready)', help: 'Prepare accounts for TOTP enrolment' },
];

function PolicyPanel({ ov, onSaved }: { ov?: Overview; onSaved: () => void }) {
  const { refresh } = useAuth();
  const toast = useToast();
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (ov) setValues(ov.policy);
  }, [ov]);
  if (!ov) return <div className="space-y-3 p-5">{Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div>;
  const readOnly = !ov.can.edit;
  const dirty = Object.keys(ov.policy).some((key) => (values[key] ?? '') !== (ov.policy[key] ?? ''));
  const set = (key: string, v: string) => {
    setValues((s) => ({ ...s, [key]: v }));
    if (errors[key]) setErrors((e) => ({ ...e, [key]: '' }));
  };
  const save = async () => {
    setSaving(true);
    try {
      const res = await api.post('security/policy', values);
      toast.success(res.message);
      setErrors({});
      onSaved();
      void refresh();
    } catch (e) {
      const ex = e as ApiError;
      setErrors(ex.errors ?? {});
      toast.error(ex.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <div className="p-5">
      {readOnly && <Alert variant="info" className="mb-4">You can view the policy but your role cannot change it.</Alert>}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_340px]">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {policyFields.map((f) => (
            <Field key={f.key} label={f.label} htmlFor={`p-${f.key}`} error={errors[f.key]} hint={f.help}>
              <Input id={`p-${f.key}`} type="number" min={f.min} max={f.max} value={values[f.key] ?? ''} onChange={(e) => set(f.key, e.target.value)} suffix={f.suffix} disabled={readOnly} invalid={!!errors[f.key]} />
            </Field>
          ))}
        </div>
        <div className="space-y-3">
          {policyToggles.map((t) => (
            <div key={t.key} className="rounded-xl border border-slate-200 px-3.5 py-3 dark:border-slate-700">
              <Toggle checked={values[t.key] === '1'} onChange={(v) => set(t.key, v ? '1' : '0')} label={t.label} description={t.help} disabled={readOnly} />
            </div>
          ))}
        </div>
      </div>
      {!readOnly && (
        <div className="mt-5 flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
          <p className="flex items-center gap-1.5 text-xs text-slate-500">
            <FileText className="h-3.5 w-3.5" /> Same settings as <Link to="/settings?tab=security" className="link">Settings › Security</Link>. New rules apply to the next password change.
          </p>
          <div className="flex gap-2">
            <Button size="sm" variant="ghost" onClick={() => setValues(ov.policy)} disabled={!dirty || saving}>
              Reset
            </Button>
            <Button size="sm" icon={Save} onClick={save} loading={saving} disabled={!dirty}>
              Save policy
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
