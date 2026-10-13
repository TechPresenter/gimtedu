import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import {
  CheckCheck, Copy, CopyPlus, Eraser, KeyRound, Lock, Pencil, Plus, Printer, RotateCcw, Save, Search, ShieldCheck, ShieldPlus, Trash2, Users,
} from 'lucide-react';
import { CrudFormModal } from '@/components/crud';
import {
  Alert, Avatar, Badge, Button, Card, CardSkeleton, Dropdown, EmptyState, Field, Input, Modal, PageHeader, Reveal, Select, Skeleton, Stagger, StatTile, useConfirm, useToast,
} from '@/components/ui';
import { api, ApiError, apiUrl } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useApi, useCrudMeta } from '@/lib/queries';
import { ACTION_ORDER, PermissionMatrix } from './roles/PermissionMatrix';
import { roleDotClass } from './components';
import type { MatrixPayload, MatrixRole } from './types';

const setEq = (a: Set<string>, b: Set<string>) => a.size === b.size && [...a].every((x) => b.has(x));

export default function RolesPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const { data, isLoading, error, refetch } = useApi<MatrixPayload>(['roles-matrix'], 'roles/matrix');
  const { data: roleMeta } = useCrudMeta('roles');
  const [q, setQ] = useState('');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [saving, setSaving] = useState(false);
  const [formOpen, setFormOpen] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [dupOf, setDupOf] = useState<MatrixRole | null>(null);

  const roles = data?.roles ?? [];
  const activeId = Number(params.get('role')) || roles.find((r) => !r.is_super)?.id || roles[0]?.id || 0;
  const role = roles.find((r) => r.id === activeId) ?? null;
  const original = useMemo(() => new Set(role?.permissions ?? []), [role]);
  const dirty = !!role && !role.is_super && !setEq(selected, original);
  const changes = useMemo(() => {
    let n = 0;
    selected.forEach((k) => !original.has(k) && n++);
    original.forEach((k) => !selected.has(k) && n++);
    return n;
  }, [selected, original]);
  const readOnly = !can('roles', 'edit') || !!role?.is_super;
  const allKeys = useMemo(() => (data?.groups ?? []).flatMap((g) => g.modules.flatMap((m) => m.actions.map((a) => `${m.key}.${a}`))), [data]);

  useEffect(() => setSelected(new Set(role?.permissions ?? [])), [role]);

  // Warn before leaving the page with unsaved permission changes.
  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  const selectRole = async (id: number) => {
    if (id === activeId) return;
    if (dirty && !(await confirm({ title: 'Discard unsaved changes?', message: `You have ${changes} unsaved permission change(s) for ${role?.name}.`, confirmText: 'Discard changes', danger: true }))) return;
    setParams({ role: String(id) }, { replace: true });
    document.getElementById('role-editor')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  };

  const save = async () => {
    if (!role) return;
    setSaving(true);
    try {
      const res = await api.put<{ permissions: string[] }>(`roles/${role.id}/permissions`, { permissions: [...selected] });
      qc.setQueryData<MatrixPayload>(['roles-matrix'], (old) => (old ? { ...old, roles: old.roles.map((r) => (r.id === role.id ? { ...r, permissions: res.data.permissions } : r)) } : old));
      toast.success(res.message);
      void qc.invalidateQueries({ queryKey: ['crud', 'roles'] });
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSaving(false);
    }
  };

  const copyFrom = async (src: MatrixRole) => {
    if (!role) return;
    if (!(await confirm({ title: `Copy permissions from ${src.name}?`, message: `The matrix for ${role.name} will be replaced with ${src.is_super ? 'every permission' : `the ${src.permissions.length} permissions of ${src.name}`}. Nothing is saved until you click “Save permissions”.`, confirmText: 'Copy permissions' }))) return;
    setSelected(new Set(src.is_super ? allKeys : src.permissions));
    toast.info(`Copied from ${src.name}. Review and save to apply.`);
  };

  const remove = async (r: MatrixRole) => {
    if (!(await confirm({ title: 'Delete role?', message: <>Delete the <strong>{r.name}</strong> role? Users must not be assigned to it. This cannot be undone.</>, confirmText: 'Delete role', danger: true }))) return;
    try {
      const res = await api.del(`crud/roles/${r.id}`);
      toast.success(res.message || 'Role deleted.');
      setParams({}, { replace: true });
      await refetch();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const filtered = roles.filter((r) => !q || `${r.name} ${r.description ?? ''}`.toLowerCase().includes(q.toLowerCase()));
  const custom = roles.filter((r) => !r.is_system).length;
  const totalUsers = roles.reduce((s, r) => s + r.users_count, 0);

  return (
    <>
      <PageHeader
        title="Roles & Permissions"
        description="Control what each role can see and do in every module."
        breadcrumbs={[{ label: 'System' }, { label: 'Roles & Permissions' }]}
        actions={
          <>
            {can('users', 'view') && (
              <Button variant="secondary" icon={Users} to="/users">
                Users
              </Button>
            )}
            {can('roles', 'create') && (
              <Button
                icon={ShieldPlus}
                onClick={() => {
                  setEditId(null);
                  setFormOpen(true);
                }}
              >
                New role
              </Button>
            )}
          </>
        }
      />

      {error ? (
        <Alert variant="error" title="Unable to load roles" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <>
          <Stagger className="mb-6 grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 sm:gap-4 lg:grid-cols-4">
            {[
              <StatTile key="r" label="Roles" value={roles.length} icon={ShieldCheck} tone="navy" sub={`${custom} custom`} loading={isLoading} />,
              <StatTile key="u" label="Assignments" value={totalUsers} icon={Users} tone="green" sub="Users × roles" loading={isLoading} />,
              <StatTile key="p" label="Permissions" value={data?.total_permissions ?? 0} icon={KeyRound} tone="purple" sub={`${data?.groups.reduce((s, g) => s + g.modules.length, 0) ?? 0} modules × 9 actions`} loading={isLoading} />,
              <StatTile key="s" label="Super Admins" value={roles.find((r) => r.is_super)?.users_count ?? 0} icon={Lock} tone="red" sub="Unrestricted access" loading={isLoading} />,
            ]}
          </Stagger>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[260px_minmax(0,1fr)] 2xl:grid-cols-[300px_minmax(0,1fr)]">
            {/* Role list */}
            <Card className="h-fit overflow-hidden lg:sticky lg:top-20">
              <div className="border-b border-slate-100 p-4 dark:border-slate-800">
                <div className="flex items-center justify-between">
                  <h2 className="card-title">Roles</h2>
                  <span className="text-xs text-slate-500">{roles.length} total</span>
                </div>
                <div className="relative mt-3 hidden lg:block">
                  <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
                  <Input inputSize="sm" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search roles…" aria-label="Search roles" className="pl-9" />
                </div>
              </div>
              {/* Mobile: compact select */}
              <div className="p-3 lg:hidden">
                <Select value={String(activeId)} onChange={(e) => void selectRole(Number(e.target.value))} options={filtered.map((r) => ({ value: String(r.id), label: `${r.name} (${r.users_count} users)` }))} aria-label="Choose role" />
              </div>
              <ul className="hidden max-h-[calc(100vh-15rem)] overflow-y-auto p-2 lg:block" role="listbox" aria-label="Roles">
                {isLoading && Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="mb-2 h-14 w-full rounded-xl" />)}
                {filtered.map((r) => (
                  <li key={r.id}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={r.id === activeId}
                      onClick={() => void selectRole(r.id)}
                      className={clsx(
                        'group mb-1 flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition duration-150',
                        r.id === activeId ? 'bg-brand-800 text-white shadow-soft dark:bg-brand-600' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60',
                      )}
                    >
                      <span className={clsx('mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ring-2', roleDotClass(r.color), r.id === activeId ? 'ring-white/40' : 'ring-white dark:ring-slate-900')} />
                      <span className="min-w-0 flex-1">
                        <span className="flex items-center gap-1.5 text-sm font-semibold">
                          <span className="truncate">{r.name}</span>
                          {r.is_super && <Lock className="h-3 w-3 shrink-0 opacity-70" aria-label="Locked" />}
                        </span>
                        <span className={clsx('block truncate text-xs', r.id === activeId ? 'text-white/70' : 'text-slate-500 dark:text-slate-400')}>
                          {r.is_super ? 'All permissions' : `${r.permissions.length} permissions`} · {r.users_count} user{r.users_count === 1 ? '' : 's'}
                        </span>
                      </span>
                      {!r.is_system && <Badge color={r.id === activeId ? 'slate' : 'cyan'} dot={false} className="!px-1.5 text-[10px]">Custom</Badge>}
                    </button>
                  </li>
                ))}
                {!isLoading && filtered.length === 0 && <li className="px-3 py-8 text-center text-sm text-slate-500">No roles match “{q}”.</li>}
              </ul>
            </Card>

            {/* Editor */}
            <div id="role-editor" className="min-w-0 scroll-mt-20 space-y-6">
              {isLoading || !data ? (
                <CardSkeleton lines={10} />
              ) : !role ? (
                <Card>
                  <EmptyState icon={ShieldCheck} title="No roles yet" description="Create a role to start assigning permissions." action={can('roles', 'create') ? <Button icon={Plus} onClick={() => setFormOpen(true)}>New role</Button> : undefined} />
                </Card>
              ) : (
                <Reveal key={role.id}>
                  <Card className="overflow-hidden">
                    <div className="flex flex-col gap-4 border-b border-slate-100 p-5 dark:border-slate-800 2xl:flex-row 2xl:items-start 2xl:justify-between">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className={clsx('h-3 w-3 rounded-full', roleDotClass(role.color))} />
                          <h2 className="font-display text-lg font-bold text-slate-900 dark:text-white">{role.name}</h2>
                          {role.is_super ? <Badge color="red"><Lock className="h-3 w-3" /> Locked</Badge> : role.is_system ? <Badge color="navy">Built-in</Badge> : <Badge color="cyan">Custom</Badge>}
                          <code className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500 dark:bg-slate-800">{role.slug}</code>
                        </div>
                        {role.description && <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{role.description}</p>}
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                          <div className="flex -space-x-2">
                            {role.members.map((m) => (
                              <Avatar key={m.id} name={m.name} src={m.avatar} size="sm" className="ring-2 ring-white dark:ring-slate-900" />
                            ))}
                          </div>
                          <Link to={`/users?f.role=${role.id}`} className="link text-xs">
                            {role.users_count ? `${role.users_count} user${role.users_count === 1 ? '' : 's'} with this role →` : 'No users yet — assign from Users →'}
                          </Link>
                        </div>
                      </div>
                      <div className="flex shrink-0 flex-wrap gap-2">
                        {can('roles', 'edit') && !role.is_super && (
                          <Button size="sm" variant="secondary" icon={Pencil} onClick={() => { setEditId(role.id); setFormOpen(true); }}>
                            Edit
                          </Button>
                        )}
                        {can('roles', 'create') && !role.is_super && (
                          <Button size="sm" variant="secondary" icon={CopyPlus} onClick={() => setDupOf(role)}>
                            Duplicate
                          </Button>
                        )}
                        <Button size="sm" variant="ghost" icon={Printer} href={apiUrl(`roles/${role.id}/print`)} target="_blank">
                          Print
                        </Button>
                        {can('roles', 'delete') && !role.is_system && (
                          <Button size="sm" variant="ghost" icon={Trash2} className="!text-red-600 hover:!bg-red-50 dark:hover:!bg-red-500/10" onClick={() => remove(role)}>
                            Delete
                          </Button>
                        )}
                      </div>
                    </div>

                    <div className="p-5">
                      {role.is_super ? (
                        <Alert variant="info" title="Super Admin is locked" className="mb-4">
                          This role bypasses every permission check and cannot be edited or deleted. Assign it only to trusted administrators.
                        </Alert>
                      ) : !can('roles', 'edit') ? (
                        <Alert variant="info" className="mb-4">You can view this matrix but your role cannot change permissions.</Alert>
                      ) : null}
                      {!readOnly && (
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                          <Dropdown
                            label="Copy permissions from another role"
                            width="w-64"
                            trigger={<><Copy className="h-4 w-4" />Copy from role</>}
                            items={roles.filter((r) => r.id !== role.id).map((r) => ({ label: r.name, hint: r.is_super ? 'all' : String(r.permissions.length), onClick: () => void copyFrom(r) }))}
                          />
                          <Button size="sm" variant="secondary" icon={CheckCheck} onClick={() => setSelected(new Set(allKeys))}>
                            Select all
                          </Button>
                          <Button size="sm" variant="secondary" icon={Eraser} onClick={() => setSelected(new Set())}>
                            Clear all
                          </Button>
                          <span className="ml-auto text-xs text-slate-500">
                            <strong className="tabular-nums text-slate-800 dark:text-slate-100">{selected.size}</strong> of {data.total_permissions} granted
                          </span>
                        </div>
                      )}
                      <PermissionMatrix groups={data.groups} actions={data.actions} selected={selected} original={original} onChange={setSelected} readOnly={readOnly} all={role.is_super} />
                    </div>

                    {/* Save bar */}
                    <div
                      className={clsx(
                        'sticky bottom-0 z-10 flex flex-col gap-3 border-t border-slate-200 bg-white/95 px-5 py-3 backdrop-blur transition-all duration-300 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900/95',
                        dirty ? 'translate-y-0 opacity-100' : 'pointer-events-none max-h-0 translate-y-2 overflow-hidden !py-0 opacity-0',
                      )}
                      aria-hidden={!dirty}
                    >
                      <p className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                        <span className="relative flex h-2.5 w-2.5">
                          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-60 motion-reduce:animate-none" />
                          <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-500" />
                        </span>
                        <strong>{changes}</strong> unsaved change{changes === 1 ? '' : 's'} — highlighted in the matrix
                      </p>
                      <div className="flex gap-2">
                        <Button size="sm" variant="secondary" icon={RotateCcw} onClick={() => setSelected(new Set(original))} disabled={saving}>
                          Discard
                        </Button>
                        <Button size="sm" variant="success" icon={Save} onClick={save} loading={saving}>
                          Save permissions
                        </Button>
                      </div>
                    </div>
                  </Card>
                </Reveal>
              )}

              {data && (
                <Reveal>
                  <Card className="p-5">
                    <h3 className="card-title">What each permission means</h3>
                    <p className="card-subtitle">“Manage” grants every action of a module. A dash means the action does not apply to that module.</p>
                    <dl className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                      {ACTION_ORDER.map((a) => (
                        <div key={a} className="rounded-xl border border-slate-100 bg-slate-50/60 p-3 dark:border-slate-800 dark:bg-slate-800/30">
                          <dt className="text-sm font-semibold text-slate-900 dark:text-white">{data.actions[a]?.label}</dt>
                          <dd className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{data.actions[a]?.description}</dd>
                        </div>
                      ))}
                    </dl>
                  </Card>
                </Reveal>
              )}
            </div>
          </div>
        </>
      )}

      <CrudFormModal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        meta={roleMeta}
        module="roles"
        id={editId}
        title={editId ? 'Edit role' : 'New role'}
        onSaved={async (_row, id) => {
          await refetch();
          if (!editId) setParams({ role: String(id) }, { replace: true });
        }}
      />
      <DuplicateRoleModal
        role={dupOf}
        onClose={() => setDupOf(null)}
        onDone={async (id) => {
          await refetch();
          setParams({ role: String(id) }, { replace: true });
        }}
      />
    </>
  );
}

function DuplicateRoleModal({ role, onClose, onDone }: { role: MatrixRole | null; onClose: () => void; onDone: (id: number) => void }) {
  const toast = useToast();
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (role) {
      setName(`${role.name} (copy)`);
      setError('');
    }
  }, [role]);
  const submit = async () => {
    if (!role) return;
    if (!name.trim()) return setError('Role name is required.');
    setSaving(true);
    try {
      const res = await api.post<{ id: number }>(`roles/${role.id}/duplicate`, { name: name.trim() });
      toast.success(res.message);
      onDone(res.data.id);
      onClose();
    } catch (e) {
      const ex = e as ApiError;
      setError(ex.errors?.name ?? ex.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={!!role}
      onClose={onClose}
      size="sm"
      title="Duplicate role"
      description={role ? `Creates a new custom role with the ${role.permissions.length} permissions of ${role.name}.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button icon={CopyPlus} onClick={submit} loading={saving}>
            Duplicate
          </Button>
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          void submit();
        }}
      >
        <Field label="New role name" required htmlFor="dup-name" error={error}>
          <Input id="dup-name" value={name} onChange={(e) => { setName(e.target.value); setError(''); }} maxLength={80} invalid={!!error} autoFocus />
        </Field>
      </form>
    </Modal>
  );
}
