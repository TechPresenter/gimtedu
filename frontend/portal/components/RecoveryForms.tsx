import { useState, type FormEvent } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, KeyRound, LinkIcon, MailCheck, Send } from 'lucide-react';
import { Alert, Button, Field, Skeleton } from '@/components/ui';
import { api } from '@/lib/api';
import { areaPath, type Area } from '../lib/config';
import { authErrorTone, errorMessage, fieldErrors } from '../lib/errors';
import type { PasswordPolicy } from '../lib/types';
import { AuthHeading, AuthLink } from './AuthLayout';
import { PasswordInput, PasswordRules, policyChecks } from './PasswordFields';

/* ------------------------------------------------------------------ Forgot password */

interface ForgotProps {
  area: Area;
  /** POST endpoint, e.g. portal/student/forgot */
  endpoint: string;
  /** request body key: identifier (students) | email (faculty) */
  field: 'identifier' | 'email';
  label: string;
  placeholder: string;
  subtitle: string;
}

export function ForgotForm({ area, endpoint, field, label, placeholder, subtitle }: ForgotProps) {
  const [value, setValue] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState<string | null>(null);
  const [error, setError] = useState<{ message: string; tone: 'warning' | 'error' } | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    const v = value.trim();
    if (!v) return setFieldError(`Enter your ${label.toLowerCase()}.`);
    if (field === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return setFieldError('Enter a valid email address.');
    setFieldError(undefined);
    setBusy(true);
    try {
      const res = await api.post(endpoint, { [field]: v });
      setSent(res.message);
    } catch (err) {
      setFieldError(fieldErrors(err)[field]);
      setError({ message: errorMessage(err), tone: authErrorTone(err) });
    } finally {
      setBusy(false);
    }
  };

  if (sent) {
    return (
      <div>
        <AuthHeading icon={MailCheck} title="Check your inbox" subtitle={sent} tone="navy" />
        <ul className="space-y-2 rounded-2xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-900 dark:text-slate-300">
          <li>• The email comes from the institute and contains a one-time link.</li>
          <li>• Not there after a few minutes? Check your spam / promotions folder.</li>
          <li>• Still nothing? Your email may not be on record — contact the {area === 'student' ? 'academic office' : 'administration office'}.</li>
        </ul>
        <div className="mt-6 flex flex-col gap-2 sm:flex-row">
          <Button to={areaPath(area, 'login')} icon={ArrowLeft} variant="secondary" className="flex-1">
            Back to sign in
          </Button>
          <Button variant="ghost" onClick={() => setSent(null)} className="flex-1">
            Try a different {field === 'email' ? 'email' : 'ID'}
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div>
      <AuthHeading icon={KeyRound} title="Forgot your password?" subtitle={subtitle} />
      {error && (
        <Alert variant={error.tone} className="mb-4">
          {error.message}
        </Alert>
      )}
      <form onSubmit={submit} noValidate className="space-y-5">
        <Field label={label} htmlFor={`${area}-forgot`} error={fieldError}>
          <input
            id={`${area}-forgot`}
            type={field === 'email' ? 'email' : 'text'}
            autoComplete={field === 'email' ? 'email' : 'username'}
            autoCapitalize="none"
            spellCheck={false}
            autoFocus
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder={placeholder}
            aria-invalid={!!fieldError || undefined}
            className={`form-input form-input-lg ${fieldError ? 'form-input-error' : ''}`}
            maxLength={190}
          />
        </Field>
        <Button type="submit" size="lg" loading={busy} icon={Send} className="w-full">
          Email me a reset link
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
        Remembered it? <AuthLink to={areaPath(area, 'login')}>Back to sign in</AuthLink>
      </p>
    </div>
  );
}

/* ------------------------------------------------------------------ Reset password (from the emailed link) */

interface ResetProps {
  area: Area;
  /** GET validity endpoint prefix, e.g. portal/student/reset  (-> /{selector}?token=) */
  checkEndpoint: string;
  /** POST endpoint */
  submitEndpoint: string;
  policy?: PasswordPolicy;
}

const DEFAULT_POLICY: PasswordPolicy = { min_length: 8, uppercase: true, number: true, special: true };

export function ResetForm({ area, checkEndpoint, submitEndpoint, policy = DEFAULT_POLICY }: ResetProps) {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const selector = params.get('selector') ?? '';
  const token = params.get('token') ?? '';
  const linkOk = /^[a-f0-9]{8,40}$/i.test(selector) && /^[a-f0-9]{32,128}$/i.test(token);
  const check = useQuery({
    queryKey: ['portal-reset-check', area, selector, token],
    queryFn: () => api.get<{ valid: boolean }>(`${checkEndpoint}/${selector}`, { token }),
    enabled: linkOk,
    retry: false,
    staleTime: Infinity,
  });
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    const failed = policyChecks(password, policy).find((c) => !c.ok);
    if (!password) local.password = 'Choose a new password.';
    else if (failed) local.password = `Your new password needs: ${failed.label.toLowerCase()}.`;
    if (password && confirm !== password) local.password_confirmation = 'Passwords do not match.';
    setErrors(local);
    setError(null);
    if (Object.keys(local).length) return;
    setBusy(true);
    try {
      await api.post(submitEndpoint, { selector, token, password, password_confirmation: confirm });
      navigate(areaPath(area, 'login'), { replace: true, state: { reset: true } });
    } catch (err) {
      setErrors(fieldErrors(err));
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  if (linkOk && check.isLoading) {
    return (
      <div className="space-y-4" aria-busy="true">
        <Skeleton className="h-12 w-12 rounded-2xl" />
        <Skeleton className="h-7 w-2/3" />
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-12 w-full rounded-2xl" />
        <Skeleton className="h-12 w-full rounded-2xl" />
      </div>
    );
  }
  if (!linkOk || check.isError || !check.data?.valid) {
    return (
      <div>
        <AuthHeading icon={LinkIcon} title="This link has expired" subtitle="Password reset links work once and only for 60 minutes. Request a new link and use the latest email." tone="navy" />
        <Button to={areaPath(area, 'forgot')} size="lg" icon={Send} className="w-full">
          Request a new link
        </Button>
        <p className="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
          <AuthLink to={areaPath(area, 'login')}>Back to sign in</AuthLink>
        </p>
      </div>
    );
  }

  return (
    <div>
      <AuthHeading icon={KeyRound} title="Choose a new password" subtitle="Pick something you have not used here before. You will sign in with it straight after." />
      {error && (
        <Alert variant="error" className="mb-4">
          {error}
        </Alert>
      )}
      <form onSubmit={submit} noValidate className="space-y-5">
        <Field label="New password" htmlFor="reset-password" required error={errors.password}>
          <PasswordInput id="reset-password" autoComplete="new-password" large autoFocus value={password} onChange={(e) => setPassword(e.target.value)} invalid={!!errors.password} />
        </Field>
        <PasswordRules password={password} policy={policy} />
        <Field label="Confirm new password" htmlFor="reset-confirm" required error={errors.password_confirmation}>
          <PasswordInput id="reset-confirm" autoComplete="new-password" large value={confirm} onChange={(e) => setConfirm(e.target.value)} invalid={!!errors.password_confirmation} />
        </Field>
        <Button type="submit" size="lg" loading={busy} icon={KeyRound} className="w-full">
          Reset password
        </Button>
      </form>
    </div>
  );
}
