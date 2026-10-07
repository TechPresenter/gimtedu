import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { Ban, KeyRound, Lock, LockOpen, LogIn, Printer, ShieldCheck, UserCheck, UserPlus, Users, UserX } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { CrudTable } from '@/components/crud';
import { Avatar, Badge, Button, Card, PageHeader, Skeleton, StatCard, Stagger, StatusBadge, useConfirm, useToast } from '@/components/ui';
import { api, ApiError, apiUrl } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useApi } from '@/lib/queries';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { RoleBadges, roleDotClass } from './components';
import { UserFormModal } from './users/UserFormModal';
import { UserDrawer } from './users/UserDrawer';
import { ResetPasswordModal } from './users/ResetPasswordModal';
import type { UserRow, UserStats } from './types';

export default function UsersPage() {
  const { can, session } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [, setParams] = useSearchParams();
  const [tableKey, setTableKey] = useState(0);
  const [formOpen, setFormOpen] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [resetUser, setResetUser] = useState<UserRow | null>(null);
  const { data: stats, isLoading: statsLoading } = useApi<UserStats>(['users-stats'], 'users/stats');

  const refresh = () => Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'users'] }), qc.invalidateQueries({ queryKey: ['users-stats'] }), qc.invalidateQueries({ queryKey: ['user-detail'] })]);
  const run = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  /** Apply a quick filter from a KPI card or role chip (re-mounts the table so it reads the URL). */
  const quickFilter = (key: 'status' | 'role', value: string) => {
    setParams(value ? { [`f.${key}`]: value } : {}, { replace: true });
    setTableKey((k) => k + 1);
  };
  const openCreate = () => {
    setEditId(null);
    setFormOpen(true);
  };
  const openEdit = (id: number) => {
    setEditId(id);
    setFormOpen(true);
  };
  const setStatus = async (u: Row, status: 'active' | 'inactive') => {
    if (status === 'inactive' && !(await confirm({ title: 'Deactivate user?', message: <><strong>{u.name}</strong> will be signed out of remembered devices and won't be able to sign in until reactivated.</>, confirmText: 'Deactivate', danger: true }))) return;
    await run(() => api.post(`users/${u.id}/status`, { status }));
  };

  const kpis = [
    { label: 'Total users', value: stats?.total, icon: Users, tone: 'navy' as const, filter: '', hint: `${stats?.week ?? 0} active this week` },
    { label: 'Active accounts', value: stats?.active, icon: UserCheck, tone: 'green' as const, filter: 'active', hint: 'Can sign in' },
    { label: 'Signed in today', value: stats?.today, icon: LogIn, tone: 'cyan' as const, filter: '', hint: 'Unique users' },
    { label: 'Locked accounts', value: stats?.locked, icon: Lock, tone: 'red' as const, filter: 'locked', hint: 'Too many failed attempts' },
    { label: 'Inactive', value: stats?.inactive, icon: UserX, tone: 'slate' as const, filter: 'inactive', hint: `${stats?.never ?? 0} never signed in` },
    { label: 'Must change password', value: stats?.must_change, icon: KeyRound, tone: 'amber' as const, filter: 'must_change', hint: 'At next sign-in' },
  ];

  return (
    <>
      <PageHeader
        title="Users"
        description="Admin panel accounts, their roles and sign-in status."
        breadcrumbs={[{ label: 'System' }, { label: 'Users' }]}
        actions={
          <>
            {can('roles', 'view') && (
              <Button variant="secondary" icon={ShieldCheck} to="/roles">
                Roles & permissions
              </Button>
            )}
            {can('users', 'create') && (
              <Button icon={UserPlus} onClick={openCreate}>
                Add user
              </Button>
            )}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 2xl:grid-cols-6">
        {kpis.map((k) => (
          <div
            key={k.label}
            role={k.filter ? 'button' : undefined}
            tabIndex={k.filter ? 0 : undefined}
            onClick={k.filter ? () => quickFilter('status', k.filter) : undefined}
            onKeyDown={k.filter ? (e) => (e.key === 'Enter' || e.key === ' ') && quickFilter('status', k.filter) : undefined}
            className={clsx(k.filter && 'cursor-pointer rounded-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500')}
            aria-label={k.filter ? `Show ${k.label.toLowerCase()}` : undefined}
          >
            <StatCard label={k.label} value={k.value ?? 0} icon={k.icon} tone={k.tone} loading={statsLoading} hint={k.hint} />
          </div>
        ))}
      </Stagger>

      {/* Users per role */}
      <Card className="mb-6 px-4 py-3.5">
        <p className="mb-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Users by role · click to filter</p>
        <div className="flex flex-wrap gap-2">
          {statsLoading && Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-7 w-28 rounded-full" />)}
          {stats?.roles.map((r) => (
            <button
              key={r.id}
              type="button"
              onClick={() => quickFilter('role', String(r.id))}
              className="group inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-2.5 pr-1.5 text-xs font-medium text-slate-700 transition duration-200 hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-soft dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
              title={`Show users with the ${r.name} role`}
            >
              <span className={clsx('h-2 w-2 rounded-full', roleDotClass(r.color))} />
              {r.name}
              <span className="rounded-full bg-slate-100 px-1.5 text-[10px] font-semibold tabular-nums text-slate-600 transition group-hover:bg-brand-50 group-hover:text-brand-800 dark:bg-slate-800 dark:text-slate-300">{r.users}</span>
            </button>
          ))}
        </div>
      </Card>

      <CrudTable
        key={tableKey}
        module="users"
        urlState
        title="All users"
        addLabel="Add user"
        emptyTitle="No users yet"
        emptyText="Create the first admin account and assign it a role."
        onCreate={openCreate}
        onEdit={(r) => openEdit(Number(r.id))}
        onView={(r) => setDetailId(Number(r.id))}
        renderers={{
          name: (r) => (
            <button type="button" className="group flex min-w-0 items-center gap-3 text-left" onClick={() => setDetailId(Number(r.id))}>
              <Avatar name={r.name} src={r.avatar} />
              <span className="min-w-0 leading-tight">
                <span className="flex items-center gap-1.5 font-semibold text-slate-900 transition group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">
                  {r.name}
                  {r.is_self && <Badge color="navy" className="!px-1.5 !py-0 text-[10px]">You</Badge>}
                </span>
                <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{r.email}</span>
              </span>
            </button>
          ),
          role_names: (r) => <RoleBadges roles={r.role_list ?? []} max={2} />,
          status: (r) => (
            <div className="flex flex-wrap items-center gap-1">
              <StatusBadge status={r.status} />
              {r.is_locked && (
                <Badge color="red">
                  <Lock className="h-3 w-3" /> Locked
                </Badge>
              )}
              {r.must_change_password && <Badge color="amber" className="!px-1.5" dot={false}><KeyRound className="h-3 w-3" /></Badge>}
            </div>
          ),
          last_login_at: (r) =>
            r.last_login_at ? (
              <div className="leading-tight" title={formatDateTime(r.last_login_at)}>
                <div className="whitespace-nowrap text-sm text-slate-700 dark:text-slate-200">{timeAgo(r.last_login_at)}</div>
                <div className="text-xs text-slate-500">{r.last_login_ip}</div>
              </div>
            ) : (
              <span className="text-xs text-slate-400">Never</span>
            ),
        }}
        rowMenu={(r) => {
          const manage = can('users', 'edit') && (!r.is_super || r.is_self || !!session?.is_super);
          return [
            manage && !r.is_self && r.status === 'active' && { label: 'Deactivate', icon: UserX, onClick: () => setStatus(r, 'inactive') },
            manage && r.status === 'inactive' && { label: 'Activate', icon: UserCheck, onClick: () => setStatus(r, 'active') },
            manage && r.is_locked && { label: 'Unlock account', icon: LockOpen, onClick: () => run(() => api.post(`users/${r.id}/unlock`)) },
            manage && { label: 'Reset password', icon: KeyRound, onClick: () => setResetUser(r as UserRow) },
            manage && {
              label: 'Sign out devices',
              icon: Ban,
              onClick: async () => {
                if (await confirm({ title: 'Sign out remembered devices?', message: `${r.name} will need to sign in again on every remembered device.`, confirmText: 'Sign out', danger: true })) {
                  await run(() => api.post(`users/${r.id}/revoke-sessions`));
                }
              },
            },
            { label: 'Print access profile', icon: Printer, href: apiUrl(`users/${r.id}/print`), target: '_blank' },
          ];
        }}
        bulkActions={
          can('users', 'edit')
            ? [
                { label: 'Activate', icon: UserCheck, onClick: (ids, clear) => run(() => api.post('users/bulk-status', { ids, status: 'active' })).then(clear) },
                {
                  label: 'Deactivate',
                  icon: UserX,
                  onClick: async (ids, clear) => {
                    if (await confirm({ title: `Deactivate ${ids.length} user(s)?`, message: 'They will be signed out and blocked from signing in. Your own account and the last Super Admin are skipped automatically.', confirmText: 'Deactivate', danger: true })) {
                      await run(() => api.post('users/bulk-status', { ids, status: 'inactive' }));
                      clear();
                    }
                  },
                },
              ]
            : []
        }
      />

      <UserFormModal open={formOpen} onClose={() => setFormOpen(false)} id={editId} onSaved={() => void refresh()} />
      <UserDrawer
        id={detailId}
        onClose={() => setDetailId(null)}
        onEdit={(id) => openEdit(id)}
        onResetPassword={(u) => setResetUser(u)}
      />
      <ResetPasswordModal user={resetUser} onClose={() => setResetUser(null)} />
    </>
  );
}
