import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Check, KeyRound, X } from 'lucide-react';
import clsx from 'clsx';
import { Alert, Button, Field, Input, PageLoader } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDocumentTitle } from '@/lib/hooks';
import { AuthLayout } from './AuthLayout';

export function PasswordRules({ password }: { password: string }) {
  const { session } = useAuth();
  const p = session?.app.password_policy ?? { min_length: 8, uppercase: true, number: true, special: true };
  const rules = [
    { ok: password.length >= p.min_length, label: `At least ${p.min_length} characters` },
    p.uppercase && { ok: /[A-Z]/.test(password), label: 'An uppercase letter' },
    p.number && { ok: /\d/.test(password), label: 'A number' },
    p.special && { ok: /[^A-Za-z0-9]/.test(password), label: 'A special character' },
  ].filter(Boolean) as { ok: boolean; label: string }[];
  return (
    <ul className="mt-2 grid grid-cols-2 gap-1 text-xs">
      {rules.map((r) => (
        <li key={r.label} className={clsx('flex items-center gap-1', r.ok ? 'text-emerald-600' : 'text-slate-400')}>
          {r.ok ? <Check className="h-3.5 w-3.5" /> : <X className="h-3.5 w-3.5" />}
          {r.label}
        </li>
      ))}
    </ul>
  );
}

export default function ResetPasswordPage() {
  useDocumentTitle('Choose a new password');
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const selector = params.get('selector') ?? '';
  const token = params.get('token') ?? '';
  const [valid, setValid] = useState<boolean | null>(null);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!selector || !token) {
      setValid(false);
      return;
    }
    api.get<{ valid: boolean }>(`auth/reset/${selector}`, { token }).then((r) => setValid(r.valid)).catch(() => setValid(false));
  }, [selector, token]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      await api.post('auth/reset', { selector, token, password, password_confirmation: confirm });
      navigate('/login?reset=1', { replace: true });
    } catch (err) {
      setError((err as ApiError).message);
      setErrors((err as ApiError).errors);
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout title="Choose a new password" subtitle="Your new password must be different from previously used passwords.">
      {valid === null ? (
        <PageLoader label="Checking link…" />
      ) : !valid ? (
        <Alert variant="error" title="Link expired or invalid">This password reset link is invalid or has expired. Request a new link to continue.</Alert>
      ) : (
        <form onSubmit={submit} className="space-y-5" noValidate>
          {error && <Alert variant="error">{error}</Alert>}
          <Field label="New password" htmlFor="password" required error={errors.password}>
            <Input id="password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} invalid={!!errors.password} />
            <PasswordRules password={password} />
          </Field>
          <Field label="Confirm password" htmlFor="confirm" required error={errors.password_confirmation}>
            <Input id="confirm" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} invalid={!!errors.password_confirmation} />
          </Field>
          <Button type="submit" className="w-full" size="lg" loading={busy} icon={KeyRound}>
            Update password
          </Button>
        </form>
      )}
      <Link to={valid === false ? '/forgot-password' : '/login'} className="link mt-8 inline-flex items-center gap-1.5 text-sm">
        <ArrowLeft className="h-4 w-4" /> {valid === false ? 'Request a new link' : 'Back to sign in'}
      </Link>
    </AuthLayout>
  );
}
