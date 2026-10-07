import { useState } from 'react';
import clsx from 'clsx';
import { Ban, KeyRound, LockOpen, LogIn, LogOut, Monitor, MousePointerClick, Pencil, Printer, ShieldAlert, Smartphone, UserCheck, UserX } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Avatar, Badge, Button, Drawer, IconButton, Skeleton, StatusBadge, Tabs, Timeline, useConfirm, useToast } from '@/components/ui';
import { api, ApiError, apiUrl } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useApi } from '@/lib/queries';
import { formatDateTime, timeAgo } from '@/lib/format';
import { actionMeta, InfoRow, RoleBadges } from '../components';
import type { UserDetail, UserRow } from '../types';

interface Props {
  id: number | null;
  onClose: () => void;
  onEdit: (id: number) => void;
  onResetPassword: (u: UserRow) => void;
}

const isMobileUa = (b: string) => /android|ios|ipados/i.test(b);

/** Slide-over with a user's profile, sign-in history, recent activity and remembered devices. */
export function UserDrawer({ id, onClose, onEdit, onResetPassword }: Props) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [tab, setTab] = useState('overview');
  const { data, isLoading, error, refetch } = useApi<UserDetail>(['user-detail', id], `users/${id}/detail`, undefined, { enabled: !!id });
  const u = data?.user;
  const canEdit = can('users', 'edit') && !!data?.can_manage;

  const refresh = async () => {
    await Promise.all([refetch(), qc.invalidateQueries({ queryKey: ['crud', 'users'] }), qc.invalidateQueries({ queryKey: ['users-stats'] })]);
  };
  const run = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const toggleStatus = async () => {
    if (!u) return;
    const deactivate = u.status === 'active';
    if (deactivate && !(await confirm({ title: 'Deactivate user?', message: <>{u.name} will be signed out of remembered devices and won't be able to sign in until reactivated.</>, confirmText: 'Deactivate', danger: true }))) return;
    await run(() => api.post(`users/${u.id}/status`, { status: deactivate ? 'inactive' : 'active' }));
  };
  const revokeAll = async () => {
    if (!u || !(await confirm({ title: 'Sign out all devices?', message: `${u.name} will need to sign in again on every remembered device.`, confirmText: 'Sign out devices', danger: true }))) return;
    await run(() => api.post(`users/${u.id}/revoke-sessions`));
  };

  return (
    <Drawer open={!!id} onClose={onClose} title="User details" description={u ? `@${u.username}` : undefined} width="max-w-2xl">
      {error ? (
        <Alert variant="error" title="Unable to load this user">
          {(error as ApiError).message}
        </Alert>
      ) : isLoading || !u || !data ? (
        <div className="space-y-4">
          <div className="flex items-center gap-4">
            <Skeleton className="h-20 w-20 rounded-full" />
            <div className="flex-1 space-y-2">
              <Skeleton className="h-5 w-1/2" />
              <Skeleton className="h-4 w-2/3" />
            </div>
          </div>
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-48 w-full" />
        </div>
      ) : (
        <div className="space-y-5">
          {/* Identity */}
          <div className="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-brand-50 via-white to-white p-5 dark:border-slate-800 dark:from-brand-500/10 dark:via-slate-900 dark:to-slate-900">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
              <Avatar name={u.name} src={u.avatar} size="xl" className="ring-4 ring-white dark:ring-slate-900" />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="font-display text-lg font-bold text-slate-900 dark:text-white">{u.name}</h3>
                  {u.is_self && <Badge color="navy">You</Badge>}
                  <StatusBadge status={u.status} />
                  {u.is_locked && <Badge color="red">Locked</Badge>}
                  {u.must_change_password && <Badge color="amber">Must change password</Badge>}
                </div>
                <p className="truncate text-sm text-slate-500 dark:text-slate-400">
                  {u.email}
                  {u.designation ? ` · ${u.designation}` : ''}
                </p>
                <div className="mt-2">
                  <RoleBadges roles={u.role_list} max={4} />
                </div>
              </div>
            </div>
            <div className="mt-4 flex flex-wrap gap-2">
              {canEdit && (
                <Button size="sm" variant="secondary" icon={Pencil} onClick={() => onEdit(u.id)}>
                  Edit
                </Button>
              )}
              {canEdit && (
                <Button size="sm" variant="secondary" icon={KeyRound} onClick={() => onResetPassword(u)}>
                  Reset password
                </Button>
              )}
              {canEdit && u.is_locked && (
                <Button size="sm" variant="soft" icon={LockOpen} onClick={() => run(() => api.post(`users/${u.id}/unlock`))}>
                  Unlock
                </Button>
              )}
              {canEdit && !u.is_self && (
                <Button size="sm" variant={u.status === 'active' ? 'ghost' : 'success'} icon={u.status === 'active' ? UserX : UserCheck} onClick={toggleStatus}>
                  {u.status === 'active' ? 'Deactivate' : 'Activate'}
                </Button>
              )}
              <Button size="sm" variant="ghost" icon={Printer} href={apiUrl(`users/${u.id}/print`)} target="_blank">
                Print
              </Button>
            </div>
          </div>

          {u.is_locked && (
            <Alert variant="warning" title="Account temporarily locked">
              Locked until {formatDateTime(u.locked_until)} after repeated failed sign-ins{u.last_failed_login_at ? ` (last attempt ${timeAgo(u.last_failed_login_at)})` : ''}.
            </Alert>
          )}

          {/* Stats */}
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              { label: 'Sign-ins (30 days)', value: data.stats.logins, icon: LogIn, cls: 'text-cyan-600' },
              { label: 'Failed attempts', value: data.stats.failed, icon: ShieldAlert, cls: data.stats.failed ? 'text-red-600' : 'text-slate-400' },
              { label: 'Actions (30 days)', value: data.stats.actions, icon: MousePointerClick, cls: 'text-brand-700 dark:text-brand-300' },
              { label: 'Remembered devices', value: data.tokens.length, icon: Monitor, cls: 'text-violet-600' },
            ].map((s) => (
              <div key={s.label} className="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                <s.icon className={clsx('h-4 w-4', s.cls)} />
                <p className="mt-1.5 font-display text-xl font-bold tabular-nums text-slate-900 dark:text-white">{s.value}</p>
                <p className="text-[11px] text-slate-500">{s.label}</p>
              </div>
            ))}
          </div>

          <Tabs
            value={tab}
            onChange={setTab}
            tabs={[
              { key: 'overview', label: 'Overview' },
              { key: 'logins', label: 'Sign-in history', count: data.logins.length },
              { key: 'activity', label: 'Recent activity', count: data.activity.length },
            ]}
          />

          {tab === 'overview' && (
            <div className="space-y-5 motion-safe:animate-fade-in">
              <div className="divide-y divide-slate-100 dark:divide-slate-800">
                <InfoRow label="Username">@{u.username}</InfoRow>
                <InfoRow label="Phone">{u.phone}</InfoRow>
                <InfoRow label="Department">{u.department_name}</InfoRow>
                <InfoRow label="Last sign-in">{u.last_login_at ? `${formatDateTime(u.last_login_at)} · ${u.last_login_ip ?? ''}` : 'Never signed in'}</InfoRow>
                <InfoRow label="Password last changed">{u.password_changed_at ? formatDateTime(u.password_changed_at) : null}</InfoRow>
                <InfoRow label="Failed attempts (current)">{u.failed_attempts}</InfoRow>
                <InfoRow label="Effective permissions">{data.permissions_count === null ? 'All modules (Super Admin)' : `${data.permissions_count} permissions`}</InfoRow>
                <InfoRow label="Account created">{`${formatDateTime(u.created_at)}${u.created_by_name ? ` by ${u.created_by_name}` : ''}`}</InfoRow>
              </div>
              <div>
                <div className="mb-2 flex items-center justify-between">
                  <h4 className="text-sm font-semibold text-slate-900 dark:text-white">Remembered devices</h4>
                  {data.tokens.length > 0 && (can('users', 'edit') || can('security', 'edit')) && data.can_manage && (
                    <Button size="xs" variant="ghost" icon={Ban} onClick={revokeAll}>
                      Sign out all
                    </Button>
                  )}
                </div>
                {data.tokens.length === 0 ? (
                  <p className="rounded-xl border border-dashed border-slate-200 px-4 py-5 text-center text-sm text-slate-500 dark:border-slate-700">No devices are remembered for this user.</p>
                ) : (
                  <ul className="space-y-2">
                    {data.tokens.map((t) => (
                      <li key={t.id} className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5 dark:border-slate-800">
                        <span className="kpi-icon !h-9 !w-9 bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">{isMobileUa(t.browser) ? <Smartphone className="h-4 w-4" /> : <Monitor className="h-4 w-4" />}</span>
                        <div className="min-w-0 flex-1 text-sm">
                          <p className="font-medium text-slate-800 dark:text-slate-100">{t.browser}</p>
                          <p className="text-xs text-slate-500">
                            {t.ip_address} · since {formatDateTime(t.created_at)}
                          </p>
                        </div>
                        {can('security', 'edit') && (
                          <IconButton
                            size="sm"
                            tone="danger"
                            icon={LogOut}
                            label="Revoke this device"
                            onClick={async () => {
                              if (await confirm({ title: 'Revoke device?', message: `Sign ${u.name} out of ${t.browser}?`, confirmText: 'Revoke', danger: true })) {
                                await run(() => api.del(`security/tokens/${t.id}`));
                              }
                            }}
                          />
                        )}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </div>
          )}

          {tab === 'logins' && (
            <div className="motion-safe:animate-fade-in">
              {data.logins.length === 0 ? (
                <p className="py-10 text-center text-sm text-slate-500">No sign-in activity recorded yet.</p>
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.logins.map((l) => {
                    const ok = l.status === 'success';
                    const out = l.status === 'logout';
                    return (
                      <li key={l.id} className="flex items-center gap-3 py-2.5">
                        <span className={clsx('inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full', ok ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : out ? 'bg-slate-100 text-slate-500 dark:bg-slate-800' : 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300')}>
                          {ok ? <LogIn className="h-4 w-4" /> : out ? <LogOut className="h-4 w-4" /> : <ShieldAlert className="h-4 w-4" />}
                        </span>
                        <div className="min-w-0 flex-1 text-sm">
                          <p className="font-medium text-slate-800 dark:text-slate-100">
                            {ok ? 'Signed in' : out ? 'Signed out' : l.reason ?? 'Failed attempt'}
                            <span className="ml-2 text-xs font-normal text-slate-500">{l.browser}</span>
                          </p>
                          <p className="text-xs text-slate-500">
                            {l.ip_address} · {formatDateTime(l.created_at)}
                          </p>
                        </div>
                        <span className="hidden text-xs text-slate-400 sm:block">{timeAgo(l.created_at)}</span>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          )}

          {tab === 'activity' && (
            <div className="motion-safe:animate-fade-in">
              <Timeline
                empty="No recorded activity yet."
                items={data.activity.map((a) => {
                  const m = actionMeta(a.action, a.status);
                  return {
                    icon: m.icon,
                    tone: m.tone,
                    title: a.description ?? `${a.action} ${a.module_label}`,
                    description: `${a.module_label}${a.record_id ? ` · #${a.record_id}` : ''}${a.ip_address ? ` · ${a.ip_address}` : ''}`,
                    time: `${formatDateTime(a.created_at)} · ${timeAgo(a.created_at)}`,
                  };
                })}
              />
            </div>
          )}
        </div>
      )}
    </Drawer>
  );
}
