import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react';
import clsx from 'clsx';
import { Check, Laptop, Moon, Save, Sun, UserRound } from 'lucide-react';
import { Alert, Button, Card, CardHeader, Field, Input, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useTheme } from '@/lib/theme';
import { useAuth } from '@/lib/auth';
import type { ProfileUser } from './types';

interface FormState {
  name: string;
  phone: string;
  designation: string;
  email: string;
  current_password: string;
}

const fromUser = (u: ProfileUser): FormState => ({ name: u.name, phone: u.phone ?? '', designation: u.designation ?? '', email: u.email, current_password: '' });

/** Personal details form + appearance (theme) preference. */
export function AccountTab({ user, onUpdated }: { user: ProfileUser; onUpdated: (u: ProfileUser) => void }) {
  const toast = useToast();
  const [form, setForm] = useState<FormState>(() => fromUser(user));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => setForm(fromUser(user)), [user]);

  const emailChanged = form.email.trim().toLowerCase() !== user.email.toLowerCase();
  const dirty = form.name !== user.name || form.phone !== (user.phone ?? '') || form.designation !== (user.designation ?? '') || emailChanged;
  const set = (k: keyof FormState) => (e: ChangeEvent<HTMLInputElement>) => {
    setForm((f) => ({ ...f, [k]: e.target.value }));
    setErrors((er) => ({ ...er, [k]: '' }));
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!form.name.trim()) local.name = 'Full name is required.';
    else if (form.name.trim().length < 2) local.name = 'Full name must be at least 2 characters.';
    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) local.email = 'Enter a valid email address.';
    if (emailChanged && !form.current_password) local.current_password = 'Enter your current password to change your email address.';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    setSaving(true);
    try {
      const res = await api.post<{ user: ProfileUser }>('profile', { ...form, current_password: emailChanged ? form.current_password : undefined });
      onUpdated(res.data.user);
      setErrors({});
      toast.success(res.message || 'Profile updated successfully.');
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors ?? {});
      toast.error(ae.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader title="Personal details" subtitle="Shown to colleagues in messages, activity logs and approvals." icon={UserRound} />
        <form onSubmit={submit} noValidate className="card-body space-y-5">
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Full name" required htmlFor="pf-name" error={errors.name}>
              <Input id="pf-name" value={form.name} onChange={set('name')} invalid={!!errors.name} maxLength={150} autoComplete="name" />
            </Field>
            <Field label="Username" htmlFor="pf-username" hint="Usernames are managed by the administrator.">
              <Input id="pf-username" value={user.username} disabled readOnly />
            </Field>
            <Field label="Email address" required htmlFor="pf-email" error={errors.email} hint="Used to sign in and to receive password reset links.">
              <Input id="pf-email" type="email" value={form.email} onChange={set('email')} invalid={!!errors.email} maxLength={190} autoComplete="email" />
            </Field>
            <Field label="Mobile number" htmlFor="pf-phone" error={errors.phone}>
              <Input id="pf-phone" type="tel" value={form.phone} onChange={set('phone')} invalid={!!errors.phone} maxLength={30} placeholder="+91 98765 43210" autoComplete="tel" />
            </Field>
            <Field label="Designation" htmlFor="pf-designation" error={errors.designation} className="sm:col-span-2">
              <Input id="pf-designation" value={form.designation} onChange={set('designation')} invalid={!!errors.designation} maxLength={120} placeholder="e.g. Admission Officer" />
            </Field>
            {emailChanged && (
              <Field label="Current password" required htmlFor="pf-current" error={errors.current_password} hint="Required to confirm a change of sign-in email." className="sm:col-span-2">
                <Input id="pf-current" type="password" value={form.current_password} onChange={set('current_password')} invalid={!!errors.current_password} autoComplete="current-password" />
              </Field>
            )}
          </div>
          {errors.avatar && <Alert variant="error">{errors.avatar}</Alert>}
          <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
            <Button variant="secondary" disabled={!dirty || saving} onClick={() => { setForm(fromUser(user)); setErrors({}); }}>
              Discard changes
            </Button>
            <Button type="submit" icon={Save} loading={saving} disabled={!dirty}>
              Save changes
            </Button>
          </div>
        </form>
      </Card>
      <AppearanceCard />
    </div>
  );
}

function AppearanceCard() {
  const { theme, setTheme } = useTheme();
  const { session, patch } = useAuth();
  const toast = useToast();
  const options = [
    { value: 'light' as const, label: 'Light', desc: 'Bright and crisp, ideal for daytime work.', icon: Sun, preview: 'bg-white border-slate-200', bar: 'bg-slate-200' },
    { value: 'dark' as const, label: 'Dark', desc: 'Easy on the eyes in low light.', icon: Moon, preview: 'bg-slate-900 border-slate-700', bar: 'bg-slate-700' },
    { value: 'system' as const, label: 'System', desc: 'Follows your device setting automatically.', icon: Laptop, preview: 'bg-gradient-to-r from-white to-slate-900 border-slate-300', bar: 'bg-slate-400' },
  ];
  const choose = (t: 'light' | 'dark' | 'system') => {
    setTheme(t);
    if (session?.user) patch({ user: { ...session.user, theme: t } });
    toast.success(`Theme set to ${t}. Saved to your account.`);
  };
  return (
    <Card>
      <CardHeader title="Appearance" subtitle="Your theme is saved to your account and follows you on every device." icon={Sun} />
      <div className="card-body">
        <div className="grid gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Theme">
          {options.map((o) => {
            const active = theme === o.value;
            return (
              <button
                key={o.value}
                type="button"
                role="radio"
                aria-checked={active}
                onClick={() => choose(o.value)}
                className={clsx(
                  'relative flex flex-col rounded-xl border p-3 text-left transition hover:-translate-y-0.5 hover:shadow-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 motion-reduce:hover:translate-y-0',
                  active ? 'border-brand-600 ring-1 ring-brand-600 dark:border-brand-400 dark:ring-brand-400' : 'border-slate-200 dark:border-slate-700',
                )}
              >
                <span className={clsx('block h-16 rounded-lg border p-2', o.preview)} aria-hidden>
                  <span className={clsx('block h-2 w-1/2 rounded', o.bar)} />
                  <span className={clsx('mt-1.5 block h-2 w-3/4 rounded', o.bar)} />
                  <span className="mt-1.5 block h-2 w-1/3 rounded bg-accent-500/70" />
                </span>
                <span className="mt-3 flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                  <o.icon className="h-4 w-4" aria-hidden /> {o.label}
                </span>
                <span className="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{o.desc}</span>
                {active && (
                  <span className="absolute right-2.5 top-2.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-brand-700 text-white" aria-hidden>
                    <Check className="h-3 w-3" />
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </div>
    </Card>
  );
}
