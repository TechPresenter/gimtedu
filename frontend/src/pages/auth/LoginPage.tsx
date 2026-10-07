import { useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { Eye, EyeOff, LockKeyhole, LogIn, Mail } from 'lucide-react';
import { Alert, Button, Checkbox, Field, Input, useToast } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import { ApiError } from '@/lib/api';
import { useDocumentTitle } from '@/lib/hooks';
import { AuthLayout } from './AuthLayout';

export default function LoginPage() {
  useDocumentTitle('Sign in');
  const { session, login } = useAuth();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const toast = useToast();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const redirect = params.get('redirect');
  const target = redirect && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/';

  if (session?.authenticated) return <Navigate to={target} replace />;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setErrors({});
    if (!identifier.trim() || !password) {
      setErrors({ ...(identifier.trim() ? {} : { identifier: 'Enter your email or username.' }), ...(password ? {} : { password: 'Enter your password.' }) });
      return;
    }
    setBusy(true);
    try {
      const msg = await login(identifier.trim(), password, remember);
      toast.success(msg || 'Signed in successfully.');
      navigate(target, { replace: true });
    } catch (err) {
      const e2 = err as ApiError;
      setError(e2.message);
      setErrors(e2.errors ?? {});
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout title="Welcome back" subtitle="Sign in to GIMT SmartCampus Admin to continue.">
      {params.get('timeout') && !error && <Alert variant="warning" className="mb-5">Your session expired due to inactivity. Please sign in again.</Alert>}
      {params.get('reset') && !error && <Alert variant="success" className="mb-5">Password updated. Sign in with your new password.</Alert>}
      {error && <Alert variant="error" className="mb-5">{error}</Alert>}
      <form onSubmit={submit} className="space-y-5" noValidate>
        <Field label="Email or username" htmlFor="identifier" error={errors.identifier} required>
          <Input id="identifier" autoComplete="username" autoFocus value={identifier} onChange={(e) => setIdentifier(e.target.value)} placeholder="admin@gimt.ac.in" prefix={<Mail className="h-4 w-4" />} invalid={!!errors.identifier} />
        </Field>
        <Field label="Password" htmlFor="password" error={errors.password} required aside={<Link to="/forgot-password" className="link text-xs">Forgot password?</Link>}>
          <div className="relative">
            <Input id="password" type={show ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" prefix={<LockKeyhole className="h-4 w-4" />} invalid={!!errors.password} className="pr-11" />
            <button type="button" onClick={() => setShow((s) => !s)} className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 hover:text-slate-600" aria-label={show ? 'Hide password' : 'Show password'}>
              {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
        </Field>
        <Checkbox label="Keep me signed in" description="Only on a private, trusted device." checked={remember} onChange={(e) => setRemember(e.target.checked)} />
        <Button type="submit" className="w-full" size="lg" loading={busy} icon={LogIn}>
          Sign in
        </Button>
      </form>
      <p className="mt-8 text-center text-xs text-slate-400">Protected area. All sign-in attempts are logged with IP address.</p>
    </AuthLayout>
  );
}
