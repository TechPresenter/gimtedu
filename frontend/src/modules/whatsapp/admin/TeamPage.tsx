import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { Activity, Check, Hourglass, Lock, MessagesSquare, Minus, Reply, RotateCcw, Save, ShieldCheck, UserPlus, Users, UserRoundCheck, UsersRound } from 'lucide-react';
import {
  Alert,
  Avatar,
  Badge,
  Button,
  Card,
  CardHeader,
  DataTable,
  EmptyState,
  Input,
  PageHeader,
  Pagination,
  Select,
  Skeleton,
  Stagger,
  StatCard,
  Tabs,
  useToast,
  type Column,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatDateTime, formatNumber, labelize, timeAgo } from '@/lib/format';
import { useWaRefresh } from '../shared';
import { formatSeconds, type MatrixData, type TeamActivity, type TeamData, type TeamMember } from './lib';
import { clean, usePaged } from './apiTabs/common';

const LEVEL = { admin: { label: 'Admin', color: 'navy' }, agent: { label: 'Agent', color: 'green' }, viewer: { label: 'Viewer', color: 'slate' } } as const;

/**
 * Team Management (Module 13): WhatsApp team members with their roles and live workload, the WhatsApp permission
 * matrix per role (only whatsapp* permissions are changed), agent performance and per-member activity logs.
 */
export default function TeamPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = ['members', 'permissions', 'activity'].includes(params.get('tab') ?? '') ? (params.get('tab') as string) : 'members';
  const team = useQuery({ queryKey: ['wa', 'admin', 'team'], queryFn: ({ signal }) => api.get<TeamData>('whatsapp/team', undefined, { signal }) });
  const s = team.data?.summary;
  const setTab = (t: string, extra: Record<string, string> = {}) => {
    const next = new URLSearchParams();
    if (t !== 'members') next.set('tab', t);
    Object.entries(extra).forEach(([k, v]) => next.set(k, v));
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader
        title="Team Management"
        description="Who works on WhatsApp, what they may do, how fast they reply and what they changed."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: '/whatsapp' }, { label: 'Team Management' }]}
        actions={
          <div className="flex flex-wrap gap-2">
            {can('roles', 'view') && (
              <Button variant="secondary" icon={ShieldCheck} to="/roles">
                All roles
              </Button>
            )}
            {can('users', 'create') && (
              <Button icon={UserPlus} to="/users">
                Add team member
              </Button>
            )}
          </div>
        }
      />
      {team.error ? (
        <Alert variant="error" title="Could not load the team" action={<Button size="sm" variant="secondary" onClick={() => team.refetch()}>Retry</Button>}>
          {(team.error as ApiError).message}
        </Alert>
      ) : (
        <Stagger className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={40}>
          {[
            <StatCard key="m" label="Team members" icon={UsersRound} tone="navy" loading={team.isLoading} value={s?.members ?? 0} hint="Users with WhatsApp access" />,
            <StatCard key="a" label="Agents" icon={UserRoundCheck} tone="green" loading={team.isLoading} value={s?.agents ?? 0} hint="Can reply in the inbox" />,
            <StatCard key="o" label="Active today" icon={Activity} tone="cyan" loading={team.isLoading} value={s?.online_today ?? 0} hint="Did something in WhatsApp" />,
            <StatCard key="c" label="Open chats" icon={MessagesSquare} tone="blue" loading={team.isLoading} value={s?.open ?? 0} to={can('whatsapp', 'view') ? '/whatsapp/inbox' : undefined} hint="Assigned to the team" />,
            <StatCard key="u" label="Unassigned" icon={Hourglass} tone="orange" loading={team.isLoading} value={s?.unassigned ?? 0} to={can('whatsapp', 'view') ? '/whatsapp/inbox?filter=unassigned' : undefined} hint="Need an owner" />,
            <StatCard key="r" label="Replies today" icon={Reply} tone="purple" loading={team.isLoading} value={s?.replies_today ?? 0} />,
          ]}
        </Stagger>
      )}

      <Tabs
        className="mb-4"
        tabs={[
          { key: 'members', label: 'Members & performance', icon: Users, count: team.data?.members.length },
          { key: 'permissions', label: 'WhatsApp permissions', icon: ShieldCheck },
          { key: 'activity', label: 'Activity log', icon: Activity },
        ]}
        value={tab}
        onChange={(t) => setTab(t)}
      />
      {tab === 'members' && <Members data={team.data} loading={team.isLoading} onActivity={(id) => setTab('activity', { user: String(id) })} />}
      {tab === 'permissions' && <PermissionMatrix />}
      {tab === 'activity' && <ActivityLog members={team.data?.members ?? []} />}
    </>
  );
}

function Members({ data, loading, onActivity }: { data?: TeamData; loading: boolean; onActivity: (id: number) => void }) {
  const columns: Column<TeamMember>[] = [
    {
      key: 'name',
      header: 'Member',
      render: (m) => (
        <span className="flex min-w-0 items-center gap-3">
          <Avatar name={m.name} src={m.avatar ? appUrl(m.avatar) : null} size="sm" />
          <span className="min-w-0">
            <span className="block truncate font-medium text-slate-800 dark:text-slate-100">{m.name}</span>
            <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{m.designation || m.email}</span>
          </span>
        </span>
      ),
    },
    {
      key: 'roles',
      header: 'Role',
      render: (m) => (
        <span className="flex flex-wrap items-center gap-1">
          <Badge color={LEVEL[m.level].color}>{LEVEL[m.level].label}</Badge>
          {m.roles.slice(0, 2).map((r) => (
            <span key={r.id} className="text-xs text-slate-500 dark:text-slate-400">{r.name}</span>
          ))}
        </span>
      ),
    },
    { key: 'open', header: 'Open chats', align: 'right', render: (m) => <span className="tabular-nums">{formatNumber(m.open)}{m.waiting > 0 && <span className="ml-1 text-xs text-amber-600 dark:text-amber-400">({m.waiting} waiting)</span>}</span> },
    { key: 'leads', header: 'Active leads', align: 'right', render: (m) => <span className="tabular-nums">{formatNumber(m.leads)}</span> },
    { key: 'replies', header: 'Replies today / 7 d', align: 'right', render: (m) => <span className="tabular-nums">{formatNumber(m.replies_today)} / {formatNumber(m.replies)}</span> },
    { key: 'frt', header: 'Avg first response', align: 'right', render: (m) => <span className="tabular-nums" title={m.median_first_response_sec !== null ? `Median ${formatSeconds(m.median_first_response_sec)} over ${m.first_responses} chats` : undefined}>{formatSeconds(m.avg_first_response_sec)}</span> },
    { key: 'resolved', header: 'Resolved 7 d', align: 'right', render: (m) => <span className="tabular-nums">{formatNumber(m.resolved)}</span> },
    { key: 'last', header: 'Last activity', render: (m) => (m.last_activity_at ? <span title={formatDateTime(m.last_activity_at)}>{timeAgo(m.last_activity_at)}</span> : <span className="text-slate-400">—</span>) },
  ];
  return (
    <Card>
      <CardHeader title="Members & agent performance" subtitle="Workload now · replies, first response and resolved chats over the last 7 days" icon={Users} />
      <DataTable<TeamMember>
        columns={columns}
        rows={data?.members ?? []}
        loading={loading}
        rowKey={(m) => m.id}
        onRowClick={(m) => onActivity(m.id)}
        actions={(m) => (
          <Button size="xs" variant="ghost" icon={Activity} onClick={(e) => { e.stopPropagation(); onActivity(m.id); }}>
            Activity
          </Button>
        )}
        empty={<EmptyState icon={Users} title="Nobody has WhatsApp access yet" description="Give a role WhatsApp permissions in the Permissions tab, then add users to that role." action={data?.can_manage_users ? <Button size="sm" to="/users">Add users</Button> : undefined} />}
      />
    </Card>
  );
}

function PermissionMatrix() {
  const toast = useToast();
  const refresh = useWaRefresh();
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['wa', 'admin', 'matrix'], queryFn: ({ signal }) => api.get<MatrixData>('whatsapp/team/matrix', undefined, { signal }) });
  const roles = useMemo(() => data?.roles ?? [], [data]);
  const [roleId, setRoleId] = useState<number | null>(null);
  const role = roles.find((r) => r.id === roleId) ?? roles.find((r) => !r.is_super) ?? roles[0];
  const [draft, setDraft] = useState<Set<string>>(new Set());
  const [saving, setSaving] = useState(false);
  useEffect(() => setDraft(new Set(role?.permissions ?? [])), [role]);

  if (error) return <Alert variant="error" title="Could not load permissions" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>;
  if (isLoading || !data || !role) return <Skeleton className="h-96 w-full rounded-2xl" />;

  const allActions = ['view', 'create', 'edit', 'delete', 'export', 'import', 'approve', 'publish', 'manage'].filter((a) => data.modules.some((m) => m.actions.includes(a)));
  const dirty = role.permissions.length !== draft.size || role.permissions.some((p) => !draft.has(p));
  const editable = data.can_edit && !role.is_super;
  const toggle = (k: string) =>
    setDraft((d) => {
      const n = new Set(d);
      if (n.has(k)) n.delete(k);
      else n.add(k);
      return n;
    });
  const save = async () => {
    setSaving(true);
    try {
      const r = await api.put(`whatsapp/team/roles/${role.id}/permissions`, { permissions: [...draft] });
      toast.success(r.message);
      await refresh('admin');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-5">
    <RoleOverview roles={roles} activeId={role.id} onPick={setRoleId} />
    <div className="grid grid-cols-1 gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
      <Card className="p-2">
        <div className="lg:hidden">
          <Select aria-label="Role" value={String(role.id)} onChange={(e) => setRoleId(Number(e.target.value))} options={roles.map((r) => ({ value: String(r.id), label: `${r.name} (${r.users})` }))} />
        </div>
        <ul className="hidden max-h-[36rem] space-y-0.5 overflow-y-auto lg:block">
          {roles.map((r) => {
            const n = r.is_super ? 'all' : r.permissions.length;
            return (
              <li key={r.id}>
                <button
                  type="button"
                  onClick={() => setRoleId(r.id)}
                  aria-current={r.id === role.id ? 'true' : undefined}
                  className={clsx(
                    'flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-left text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                    r.id === role.id ? 'bg-brand-900 font-semibold text-white dark:bg-brand-700' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                  )}
                >
                  <span className="truncate">{r.name}</span>
                  <span className={clsx('shrink-0 rounded-full px-1.5 text-[11px] tabular-nums', r.id === role.id ? 'bg-white/20' : n === 0 ? 'text-slate-400' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300')}>{n}</span>
                </button>
              </li>
            );
          })}
        </ul>
      </Card>
      <Card>
        <CardHeader
          title={role.name}
          subtitle={`${formatNumber(role.users)} user(s)${role.description ? ` · ${role.description}` : ''}`}
          icon={role.is_super ? Lock : ShieldCheck}
          actions={
            editable ? (
              <div className="flex gap-2">
                <Button size="sm" variant="ghost" icon={RotateCcw} disabled={!dirty || saving} onClick={() => setDraft(new Set(role.permissions))}>
                  Reset
                </Button>
                <Button size="sm" icon={Save} loading={saving} disabled={!dirty} onClick={save}>
                  Save
                </Button>
              </div>
            ) : undefined
          }
        />
        {role.is_super ? (
          <div className="card-body">
            <Alert variant="info" title="Super Admin always has every permission">This role cannot be changed.</Alert>
          </div>
        ) : (
          <>
            {!data.can_edit && (
              <div className="px-4 pt-4">
                <Alert variant="info">You can view the matrix. Changing it needs WhatsApp settings “manage” and Roles “edit”.</Alert>
              </div>
            )}
            <div className="table-wrap overflow-x-auto">
              <table className="w-full min-w-[42rem] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <th className="px-4 py-2.5 font-semibold">Area</th>
                    {allActions.map((a) => (
                      <th key={a} className="px-2 py-2.5 text-center font-semibold" title={data.actions[a]?.description}>
                        {data.actions[a]?.label ?? labelize(a)}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.modules.map((m) => (
                    <tr key={m.key} className="border-b border-slate-100 last:border-0 dark:border-slate-800">
                      <th scope="row" className="max-w-[16rem] px-4 py-2.5 text-left font-medium text-slate-700 dark:text-slate-200">
                        {m.label}
                        <code className="mt-0.5 block text-[11px] font-normal text-slate-400">{m.key}</code>
                      </th>
                      {allActions.map((a) => {
                        const k = `${m.key}.${a}`;
                        const has = m.actions.includes(a);
                        return (
                          <td key={a} className="px-2 py-2.5 text-center">
                            {has ? (
                              <input
                                type="checkbox"
                                className="form-checkbox"
                                checked={draft.has(k)}
                                disabled={!editable}
                                onChange={() => toggle(k)}
                                aria-label={`${m.label}: ${a}`}
                              />
                            ) : (
                              <span className="text-slate-300 dark:text-slate-600" aria-hidden>·</span>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="border-t border-slate-100 px-4 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
              “Manage” includes every other action of that area. Only WhatsApp permissions are changed here — the role’s other permissions stay as they are.
            </p>
          </>
        )}
      </Card>
    </div>
    </div>
  );
}

/** The eight WhatsApp team roles of the brief, mapped to the ERP roles that implement them. */
const BRIEF_ROLES: { slug: string; label: string }[] = [
  { slug: 'super-admin', label: 'Super Admin' },
  { slug: 'administrator', label: 'Institute Admin' },
  { slug: 'admission-manager', label: 'Admission Manager' },
  { slug: 'admission-counsellor', label: 'Admission Counsellor' },
  { slug: 'accountant', label: 'Accounts Department' },
  { slug: 'exam-controller', label: 'Examination Department' },
  { slug: 'student-support', label: 'Student Support' },
  { slug: 'marketing-executive', label: 'Marketing Executive' },
];
/** The eight permissions of the brief, each satisfied by any of the listed grants (".manage" includes every action). */
const BRIEF_PERMS: { label: string; any: string[] }[] = [
  { label: 'View conversations', any: ['whatsapp.view'] },
  { label: 'Send messages', any: ['whatsapp.create'] },
  { label: 'Manage templates', any: ['whatsapp_templates.create', 'whatsapp_templates.edit'] },
  { label: 'Create campaigns', any: ['whatsapp_campaigns.create'] },
  { label: 'Manage automations', any: ['whatsapp_automation.create', 'whatsapp_automation.edit', 'whatsapp_automation.publish'] },
  { label: 'View student details', any: ['whatsapp_contacts.view'] },
  { label: 'Access reports', any: ['whatsapp_reports.view'] },
  { label: 'Manage API settings', any: ['whatsapp_settings.manage'] },
];

function RoleOverview({ roles, activeId, onPick }: { roles: MatrixData['roles']; activeId: number; onPick: (id: number) => void }) {
  const has = (r: MatrixData['roles'][number], key: string) => r.is_super || r.permissions.includes(key) || r.permissions.includes(`${key.split('.')[0]}.manage`);
  return (
    <Card>
      <CardHeader title="WhatsApp roles at a glance" subtitle="The eight team roles and what each may do — pick a role to edit its detailed permissions below" icon={UsersRound} />
      <div className="table-wrap overflow-x-auto">
        <table className="w-full min-w-[54rem] text-sm">
          <thead>
            <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
              <th className="px-4 py-2.5 font-semibold">Role</th>
              {BRIEF_PERMS.map((p) => (
                <th key={p.label} className="px-2 py-2.5 text-center font-semibold">
                  {p.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {BRIEF_ROLES.map((b) => {
              const r = roles.find((x) => x.slug === b.slug);
              return (
                <tr key={b.slug} className={clsx('border-b border-slate-100 last:border-0 dark:border-slate-800', r && r.id === activeId && 'bg-brand-50/60 dark:bg-brand-500/10')}>
                  <th scope="row" className="min-w-[14rem] px-4 py-2 text-left font-medium">
                    {r ? (
                      <button type="button" onClick={() => onPick(r.id)} className="text-left text-slate-800 hover:text-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-slate-100 dark:hover:text-brand-300">
                        {b.label}
                        <span className="block text-xs font-normal text-slate-500 dark:text-slate-400">
                          {r.name} · {formatNumber(r.users)} user{r.users === 1 ? '' : 's'}
                        </span>
                      </button>
                    ) : (
                      <span className="text-slate-500">
                        {b.label}
                        <span className="block text-xs font-normal text-amber-600 dark:text-amber-400">Role not set up</span>
                      </span>
                    )}
                  </th>
                  {BRIEF_PERMS.map((p) => {
                    const ok = !!r && p.any.some((k) => has(r, k));
                    return (
                      <td key={p.label} className="px-2 py-2 text-center">
                        {ok ? <Check className="mx-auto h-4 w-4 text-emerald-600 dark:text-emerald-400" aria-label="Allowed" /> : <Minus className="mx-auto h-4 w-4 text-slate-300 dark:text-slate-600" aria-label="Not allowed" />}
                      </td>
                    );
                  })}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

function ActivityLog({ members }: { members: TeamMember[] }) {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const user = params.get('user') ?? '';
  const [source, setSource] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const { data, isLoading, error, refetch } = usePaged<TeamActivity>('team-activity', 'whatsapp/team/activity', clean({ user_id: user, source, from, to, page, per_page: perPage }));
  const setUser = (v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set('user', v);
    else next.delete('user');
    setParams(next, { replace: true });
    setPage(1);
  };

  const columns: Column<TeamActivity>[] = [
    { key: 'created_at', header: 'When', render: (r) => <span className="whitespace-nowrap" title={formatDateTime(r.created_at)}>{formatDateTime(r.created_at)}</span> },
    {
      key: 'user',
      header: 'Member',
      render: (r) => (
        <span className="flex items-center gap-2">
          <Avatar name={r.user_name ?? 'System'} src={r.avatar ? appUrl(r.avatar) : null} size="xs" />
          <span className="truncate">{r.user_name ?? 'System'}</span>
        </span>
      ),
    },
    { key: 'action', header: 'Action', render: (r) => <Badge color={r.source === 'inbox' ? 'green' : 'blue'}>{labelize(r.action)}</Badge> },
    {
      key: 'description',
      header: 'Details',
      render: (r) => (
        <span className="block min-w-0">
          <span className="block max-w-[32rem] truncate" title={r.description ?? ''}>
            {r.description || (r.contact_name ? `Contact ${r.contact_name}` : '—')}
          </span>
          <span className="text-xs text-slate-500 dark:text-slate-400">
            {r.source === 'inbox' ? 'Inbox' : r.module_label}
            {r.conversation_id && can('whatsapp', 'view') && (
              <>
                {' · '}
                <Link to={`/whatsapp/inbox?c=${r.conversation_id}`} className="text-brand-700 hover:underline dark:text-brand-300">
                  {r.contact_name ? `Chat with ${r.contact_name}` : 'Open chat'}
                </Link>
              </>
            )}
          </span>
        </span>
      ),
    },
  ];

  return (
    <Card>
      <div className="flex flex-col gap-2 border-b border-slate-100 p-3 sm:flex-row sm:flex-wrap sm:items-center dark:border-slate-800">
        <Select inputSize="sm" aria-label="Member" className="sm:w-56" value={user} onChange={(e) => setUser(e.target.value)} placeholder="All members" options={members.map((m) => ({ value: String(m.id), label: m.name }))} />
        <Select inputSize="sm" aria-label="Source" className="sm:w-48" value={source} onChange={(e) => { setSource(e.target.value); setPage(1); }} placeholder="Inbox + audit log" options={{ inbox: 'Inbox actions', audit: 'Settings & records (audit)' }} />
        <Input type="date" inputSize="sm" aria-label="From date" className="sm:w-40" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
        <Input type="date" inputSize="sm" aria-label="To date" className="sm:w-40" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />
      </div>
      {error ? (
        <div className="p-4">
          <Alert variant="error" title="Could not load activity" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
        </div>
      ) : (
        <DataTable<TeamActivity>
          columns={columns}
          rows={data?.rows ?? []}
          loading={isLoading}
          rowKey={(r) => r.uid}
          dense
          empty={<EmptyState icon={Activity} title="No activity found" description="Replies, assignments, notes and settings changes of WhatsApp members appear here." />}
        />
      )}
      {data && data.pagination.total > 0 && (
        <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
          <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={perPage} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />
        </div>
      )}
    </Card>
  );
}
