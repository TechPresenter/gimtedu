import { useEffect, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { Check, Lock, ShieldCheck } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Field, FileUpload, Input, Modal, PageLoader, Select, Skeleton, Toggle, useToast } from '@/components/ui';
import { api, ApiError, toFormData } from '@/lib/api';
import { useApi } from '@/lib/queries';
import type { Option } from '@/lib/types';
import { PasswordInput, passwordChecks, roleDotClass, usePasswordPolicy } from '../components';

interface RoleOption {
  id: number;
  name: string;
  description: string | null;
  color: string | null;
  is_super: boolean;
  users_count: number;
  assignable: boolean;
}

interface FormOptions {
  roles: RoleOption[];
  departments: Option[];
}

interface Values {
  name: string;
  username: string;
  email: string;
  phone: string;
  designation: string;
  department_id: string;
  roles: number[];
  status: string;
  must_change_password: boolean;
  password: string;
}

const empty: Values = { name: '', username: '', email: '', phone: '', designation: '', department_id: '', roles: [], status: 'active', must_change_password: true, password: '' };

const suggestUsername = (name: string) =>
  name
    .toLowerCase()
    .replace(/^(dr|prof|mr|ms|mrs)\.?\s+/, '')
    .trim()
    .replace(/[^a-z0-9]+/g, '.')
    .replace(/^\.|\.$/g, '')
    .slice(0, 40);

interface Props {
  open: boolean;
  onClose: () => void;
  id: number | null;
  onSaved: (id: number) => void;
}

/** Create / edit a user: details, photo, roles (with descriptions), status and password policy meter. */
export function UserFormModal({ open, onClose, id, onSaved }: Props) {
  const toast = useToast();
  const qc = useQueryClient();
  const policy = usePasswordPolicy();
  const { data: opts, isLoading: optsLoading } = useApi<FormOptions>(['users-form-options'], 'users/form-options', undefined, { enabled: open, staleTime: 60_000 });
  const [values, setValues] = useState<Values>(empty);
  const [avatar, setAvatar] = useState<File | null>(null);
  const [currentAvatar, setCurrentAvatar] = useState<string | null>(null);
  const [removeAvatar, setRemoveAvatar] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [usernameTouched, setUsernameTouched] = useState(false);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFormError(null);
    setAvatar(null);
    setRemoveAvatar(false);
    setUsernameTouched(!!id);
    if (!id) {
      setValues(empty);
      setCurrentAvatar(null);
      return;
    }
    setLoading(true);
    api
      .get<{ values: Record<string, unknown>; row: Record<string, unknown> }>(`crud/users/${id}`)
      .then(({ values: v }) => {
        setValues({
          name: String(v.name ?? ''),
          username: String(v.username ?? ''),
          email: String(v.email ?? ''),
          phone: String(v.phone ?? ''),
          designation: String(v.designation ?? ''),
          department_id: v.department_id ? String(v.department_id) : '',
          roles: Array.isArray(v.roles) ? (v.roles as (string | number)[]).map(Number) : [],
          status: String(v.status ?? 'active'),
          must_change_password: !!v.must_change_password,
          password: '',
        });
        setCurrentAvatar((v.avatar as string) || null);
      })
      .catch((e: ApiError) => setFormError(e.message))
      .finally(() => setLoading(false));
  }, [open, id]);

  const set = <K extends keyof Values>(k: K, v: Values[K]) => {
    setValues((s) => {
      const next = { ...s, [k]: v };
      if (k === 'name' && !usernameTouched) next.username = suggestUsername(String(v));
      return next;
    });
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };

  const toggleRole = (r: RoleOption) => {
    if (!r.assignable && !values.roles.includes(r.id)) return;
    set('roles', values.roles.includes(r.id) ? values.roles.filter((x) => x !== r.id) : [...values.roles, r.id]);
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (!values.name.trim()) errs.name = 'Full name is required.';
    if (!values.username.trim()) errs.username = 'Username is required.';
    else if (!/^[A-Za-z0-9._-]{3,60}$/.test(values.username)) errs.username = 'Use 3–60 letters, numbers, dots, dashes or underscores.';
    if (!values.email.trim()) errs.email = 'Email is required.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) errs.email = 'Enter a valid email address.';
    if (!values.roles.length) errs.roles = 'Assign at least one role.';
    if (!id && !values.password) errs.password = 'Set an initial password (use the wand to generate one).';
    if (values.password && passwordChecks(values.password, policy).some((c) => !c.ok)) errs.password = 'The password does not meet the policy below.';
    if (Object.keys(errs).length) {
      setErrors(errs);
      setFormError('Please fix the highlighted fields.');
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const payload: Record<string, unknown> = {
        name: values.name, username: values.username, email: values.email, phone: values.phone, designation: values.designation,
        department_id: values.department_id, roles: values.roles, status: values.status, must_change_password: values.must_change_password,
      };
      if (values.password) payload.password = values.password;
      if (avatar) payload.avatar = avatar;
      if (removeAvatar && !avatar) payload.avatar__remove = 1;
      const body = avatar ? toFormData(payload) : payload;
      const res = await api.post<{ id: number }>(id ? `crud/users/${id}` : 'crud/users', body);
      toast.success(res.message || (id ? 'User updated successfully.' : 'User added successfully.'));
      await Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'users'] }), qc.invalidateQueries({ queryKey: ['users-stats'] }), qc.invalidateQueries({ queryKey: ['user-detail'] })]);
      onSaved(res.data.id);
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(ex.message || 'Unable to save the user. Please try again.');
      if (!Object.keys(ex.errors ?? {}).length) toast.error(ex.message || 'Unable to save the user. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="xl"
      title={id ? 'Edit user' : 'Add user'}
      description={id ? 'Update account details, roles and access.' : 'Create an admin panel account. Fields marked * are required.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="user-form" loading={saving} disabled={loading}>
            {id ? 'Save changes' : 'Create user'}
          </Button>
        </>
      }
    >
      {loading ? (
        <PageLoader label="Loading user…" />
      ) : (
        <form id="user-form" onSubmit={submit} noValidate className="space-y-5">
          {formError && <Alert variant="error">{formError}</Alert>}
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_1.05fr]">
            {/* Left: identity */}
            <div className="space-y-4">
              <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">Account details</h3>
              <Field label="Full name" required htmlFor="u-name" error={errors.name}>
                <Input id="u-name" value={values.name} onChange={(e) => set('name', e.target.value)} invalid={!!errors.name} maxLength={150} placeholder="e.g. Kiran Joshi" autoFocus />
              </Field>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Username" required htmlFor="u-username" error={errors.username}>
                  <Input
                    id="u-username"
                    value={values.username}
                    onChange={(e) => {
                      setUsernameTouched(true);
                      set('username', e.target.value);
                    }}
                    invalid={!!errors.username}
                    maxLength={60}
                    prefix="@"
                    autoComplete="off"
                  />
                </Field>
                <Field label="Email" required htmlFor="u-email" error={errors.email}>
                  <Input id="u-email" type="email" value={values.email} onChange={(e) => set('email', e.target.value)} invalid={!!errors.email} maxLength={190} placeholder="name@gimt.ac.in" autoComplete="off" />
                </Field>
                <Field label="Phone" htmlFor="u-phone" error={errors.phone}>
                  <Input id="u-phone" type="tel" value={values.phone} onChange={(e) => set('phone', e.target.value)} invalid={!!errors.phone} maxLength={30} placeholder="+91 98xxxxxxxx" />
                </Field>
                <Field label="Designation" htmlFor="u-desig" error={errors.designation}>
                  <Input id="u-desig" value={values.designation} onChange={(e) => set('designation', e.target.value)} maxLength={120} placeholder="e.g. Accounts Officer" />
                </Field>
              </div>
              <Field label="Department" htmlFor="u-dept" error={errors.department_id}>
                <Select id="u-dept" value={values.department_id} onChange={(e) => set('department_id', e.target.value)} options={opts?.departments ?? []} placeholder="— Not linked —" />
              </Field>
              <Field label="Profile photo" error={errors.avatar}>
                <FileUpload
                  file={avatar}
                  onFile={(f) => {
                    setAvatar(f);
                    if (f) setRemoveAvatar(false);
                  }}
                  currentPath={removeAvatar ? null : currentAvatar}
                  onRemoveCurrent={() => setRemoveAvatar(true)}
                  accept=".jpg,.jpeg,.png,.webp"
                  maxSize={5 * 1024 * 1024}
                  image
                  compact
                  onError={(m) => setErrors((e) => ({ ...e, avatar: m }))}
                />
              </Field>
            </div>

            {/* Right: access */}
            <div className="space-y-4">
              <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">Access</h3>
              <Field label="Roles" required error={errors.roles} hint="Users get the combined permissions of all their roles.">
                <div role="group" aria-label="Roles" className={clsx('max-h-64 space-y-1.5 overflow-y-auto rounded-xl border p-1.5', errors.roles ? 'border-red-300' : 'border-slate-200 dark:border-slate-700')}>
                  {optsLoading &&
                    Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-12 w-full rounded-lg" />)}
                  {opts?.roles.map((r) => {
                    const on = values.roles.includes(r.id);
                    const disabled = !r.assignable && !on;
                    return (
                      <button
                        key={r.id}
                        type="button"
                        role="checkbox"
                        aria-checked={on}
                        disabled={disabled}
                        onClick={() => toggleRole(r)}
                        title={disabled ? (r.is_super ? 'Only a Super Admin can assign this role' : 'This role includes permissions you do not have') : undefined}
                        className={clsx(
                          'flex w-full items-start gap-3 rounded-lg px-3 py-2 text-left transition',
                          on ? 'bg-brand-50 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/30' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60',
                          disabled && 'cursor-not-allowed opacity-50',
                        )}
                      >
                        <span className={clsx('mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded border transition', on ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 dark:border-slate-600')}>
                          {on && <Check className="h-3 w-3" strokeWidth={3} />}
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                            <span className={clsx('h-2 w-2 rounded-full', roleDotClass(r.color))} />
                            {r.name}
                            {r.is_super && <ShieldCheck className="h-3.5 w-3.5 text-red-500" />}
                            {disabled && <Lock className="h-3 w-3 text-slate-400" />}
                          </span>
                          {r.description && <span className="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{r.description}</span>}
                        </span>
                        <span className="shrink-0 text-[11px] text-slate-400">{r.users_count} user{r.users_count === 1 ? '' : 's'}</span>
                      </button>
                    );
                  })}
                </div>
              </Field>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Status" htmlFor="u-status" error={errors.status}>
                  <Select id="u-status" value={values.status} onChange={(e) => set('status', e.target.value)} options={{ active: 'Active — can sign in', inactive: 'Inactive — sign-in blocked' }} invalid={!!errors.status} />
                </Field>
                <div className="flex items-end pb-1.5">
                  <Toggle checked={values.must_change_password} onChange={(v) => set('must_change_password', v)} label="Force password change" description="At next sign-in" />
                </div>
              </div>
              <Field label={id ? 'Set a new password' : 'Initial password'} required={!id} htmlFor="u-password" error={errors.password} hint={id ? 'Leave blank to keep the current password.' : undefined}>
                <PasswordInput id="u-password" value={values.password} onChange={(v) => set('password', v)} invalid={!!errors.password} placeholder={id ? '••••••••' : 'Type or generate a password'} />
              </Field>
            </div>
          </div>
        </form>
      )}
    </Modal>
  );
}
