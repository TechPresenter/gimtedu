import { useState, type FormEvent } from 'react';
import { KeyRound, LifeBuoy, ShieldCheck, ShieldOff, ShieldPlus, Smartphone } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Field, Input, Modal, useToast } from '@/components/ui';
import { cleanTotp, RecoveryCodes, TwoFactorSetup } from '@/components/auth';
import { api, ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import type { ProfileUser } from './types';

type Dialog = null | 'setup' | 'disable' | 'regenerate';

/**
 * My Profile › Security: two-factor authentication with an authenticator app (Google / Microsoft Authenticator).
 * Turn on (QR code + first code + recovery codes), new recovery codes (password), turn off (password + code; not while the
 * institute requires it). Endpoints: POST /api/profile/2fa/{setup,enable,disable,recovery-codes}.
 */
export function TwoFactorCard({ user, onChanged }: { user: ProfileUser; onChanged: () => void }) {
  const toast = useToast();
  const [dialog, setDialog] = useState<Dialog>(null);
  /** recovery codes just generated (shown once) */
  const [codes, setCodes] = useState<string[] | null>(null);
  const [saved, setSaved] = useState(false);
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const on = user.two_factor_enabled;
  const left = user.recovery_codes_left ?? 0;

  const close = () => {
    if (busy) return;
    setDialog(null);
    setCodes(null);
    setSaved(false);
    setPassword('');
    setCode('');
    setErrors({});
  };
  const finish = () => {
    close();
    onChanged();
  };

  const submitPassword = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!password) local.password = 'Enter your password.';
    if (dialog === 'disable' && !code.trim()) local.code = 'Enter the current code from your authenticator app.';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    setBusy(true);
    setErrors({});
    try {
      if (dialog === 'disable') {
        const res = await api.post('profile/2fa/disable', { password, code: code.trim() });
        toast.success(res.message || 'Two-factor authentication is off.');
        setBusy(false);
        finish();
      } else {
        const res = await api.post<{ recovery_codes: string[] }>('profile/2fa/recovery-codes', { password });
        setCodes(res.data.recovery_codes);
        setSaved(false);
        setPassword('');
        toast.success(res.message || 'New recovery codes generated.');
      }
    } catch (err) {
      const ae = err as ApiError;
      setErrors(ae.errors && Object.keys(ae.errors).length ? ae.errors : { form: ae.message });
    } finally {
      setBusy(false);
    }
  };

  const codesView = codes && (
    <div className="space-y-4">
      <RecoveryCodes codes={codes} account={user.email} acknowledged={saved} onAcknowledge={setSaved} />
      <div className="flex justify-end">
        <Button onClick={finish} disabled={!saved}>
          Done
        </Button>
      </div>
    </div>
  );

  return (
    <Card>
      <CardHeader
        title="Two-factor authentication"
        subtitle="A 6-digit code from your phone at every sign-in, on top of your password."
        icon={ShieldCheck}
        actions={
          <div className="flex flex-wrap items-center gap-1.5">
            {user.two_factor_required && <Badge color="blue">Required by the institute</Badge>}
            <Badge color={on ? 'green' : 'amber'} dot>
              {on ? 'On' : 'Off'}
            </Badge>
          </div>
        }
      />
      <div className="card-body">
        {on ? (
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
              <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                <Smartphone className="h-5 w-5" aria-hidden />
              </span>
              <div className="text-sm">
                <p className="font-medium text-slate-900 dark:text-white">Authenticator app is set up</p>
                <p className="text-slate-500 dark:text-slate-400">
                  {user.two_factor_enabled_at ? `Since ${formatDateTime(user.two_factor_enabled_at)} · ` : ''}
                  <span className={left <= 3 ? 'font-medium text-amber-700 dark:text-amber-400' : undefined}>
                    {left} recovery code{left === 1 ? '' : 's'} left
                  </span>
                </p>
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" icon={LifeBuoy} onClick={() => setDialog('regenerate')}>
                New recovery codes
              </Button>
              {!user.two_factor_required && (
                <Button variant="ghost" size="sm" icon={ShieldOff} onClick={() => setDialog('disable')}>
                  Turn off
                </Button>
              )}
            </div>
          </div>
        ) : (
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="max-w-xl text-sm text-slate-600 dark:text-slate-300">
              Protect your account even if someone learns your password. You need <strong>Google Authenticator</strong> or <strong>Microsoft Authenticator</strong> on your phone; setup
              takes about a minute.
            </p>
            <Button icon={ShieldPlus} onClick={() => setDialog('setup')}>
              Turn on
            </Button>
          </div>
        )}
      </div>

      <Modal
        open={dialog === 'setup'}
        onClose={close}
        static={!!codes}
        size="lg"
        icon={<ShieldPlus className="h-5 w-5" />}
        title={codes ? 'Save your recovery codes' : 'Set up your authenticator app'}
        description={codes ? 'Two-factor authentication is on. Each code signs you in once if you cannot use your phone.' : 'Scan the QR code with Google Authenticator or Microsoft Authenticator, then enter the code it shows.'}
      >
        {codes ? (
          codesView
        ) : dialog === 'setup' ? (
          <TwoFactorSetup<{ recovery_codes: string[] }>
            setupPath="profile/2fa/setup"
            confirmPath="profile/2fa/enable"
            layout="split"
            onEnrolled={(data, message) => {
              setCodes(data.recovery_codes);
              setSaved(false);
              toast.success(message || 'Two-factor authentication is on.');
            }}
          />
        ) : null}
      </Modal>

      <Modal
        open={dialog === 'disable' || dialog === 'regenerate'}
        onClose={close}
        static={busy || !!codes}
        size={codes ? 'lg' : 'sm'}
        icon={dialog === 'disable' ? <ShieldOff className="h-5 w-5" /> : <LifeBuoy className="h-5 w-5" />}
        title={codes ? 'Your new recovery codes' : dialog === 'disable' ? 'Turn off two-factor authentication' : 'Generate new recovery codes'}
        description={codes ? 'Your old recovery codes no longer work.' : dialog === 'disable' ? 'Sign-in will ask only for your password again.' : 'All your current recovery codes stop working.'}
      >
        {codes ? (
          codesView
        ) : (
          <form onSubmit={submitPassword} noValidate className="space-y-4">
            {errors.form && <Alert variant="error">{errors.form}</Alert>}
            <Field label="Your password" htmlFor="tf-password" error={errors.password} required>
              <Input id="tf-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} invalid={!!errors.password} autoFocus />
            </Field>
            {dialog === 'disable' && (
              <Field label="Code from your authenticator app" htmlFor="tf-code" error={errors.code} required hint="Or one of your recovery codes.">
                <Input
                  id="tf-code"
                  inputMode="text"
                  autoComplete="one-time-code"
                  value={code}
                  onChange={(e) => setCode(/^\d/.test(e.target.value) ? cleanTotp(e.target.value) : e.target.value.toUpperCase().slice(0, 9))}
                  invalid={!!errors.code}
                  placeholder="123456"
                  prefix={<KeyRound className="h-4 w-4" />}
                />
              </Field>
            )}
            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
              <Button variant="secondary" onClick={close} disabled={busy}>
                Cancel
              </Button>
              <Button type="submit" variant={dialog === 'disable' ? 'danger' : 'primary'} loading={busy}>
                {dialog === 'disable' ? 'Turn off' : 'Generate new codes'}
              </Button>
            </div>
          </form>
        )}
      </Modal>
    </Card>
  );
}
