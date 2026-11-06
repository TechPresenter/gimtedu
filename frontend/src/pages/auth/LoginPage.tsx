import { useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowRight, Eye, EyeOff, GraduationCap, LockKeyhole, LogIn, Mail, UserRound } from 'lucide-react';
import { Alert, Button, Checkbox, Field, Input, useToast } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import { ApiError } from '@/lib/api';
import { useDocumentTitle } from '@/lib/hooks';
import { appUrl } from '@/lib/config';
import { AuthLayout } from './AuthLayout';

/** Student IDs (GIMT26BBA0001) and admission numbers (ADM/2026/00001) belong to the Student Portal, not the admin panel. */
const STUDENT_ID = /^(GIMT\d{2}[A-Z]{2,}\d{3,}|ADM\/\d{4}\/\d+)$/i;

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
  // a failed sign-in with a Student ID: point the student to the Student Portal
  const [studentHint, setStudentHint] = useState(false);
  const redirect = params.get('redirect');
  const target = redirect && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/';

  if (session?.authenticated) return <Navigate to={target} replace />;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setErrors({});
    setStudentHint(false);
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
      setStudentHint(STUDENT_ID.test(identifier.trim()));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout title="Welcome back" subtitle="Sign in to GIMT SmartCampus Admin to continue.">
      {params.get('timeout') && !error && <Alert variant="warning" className="mb-5">Your session expired due to inactivity. Please sign in again.</Alert>}
      {params.get('reset') && !error && <Alert variant="success" className="mb-5">Password updated. Sign in with your new password.</Alert>}
      {error && !studentHint && <Alert variant="error" className="mb-5">{error}</Alert>}
      {error && studentHint && (
        <div role="alert" className="mb-5">
          <Alert variant="info" title="Students sign in at the Student Portal">
            <p>This looks like a Student ID. The admin panel is for GIMT staff only; use the same Student ID and password on the Student Portal.</p>
            <Button size="sm" className="mt-2.5" href={appUrl('portal/student/login')} iconRight={ArrowRight}>
              Go to the Student Portal
            </Button>
          </Alert>
        </div>
      )}
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
      <div className="mt-8 rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 dark:border-slate-800 dark:bg-slate-900/60">
        <p className="text-xs font-semibold text-slate-700 dark:text-slate-200">Not an administrator?</p>
        <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Students and faculty members have their own portals.</p>
        <div className="mt-2.5 grid grid-cols-1 gap-2 sm:grid-cols-2">
          <a href={appUrl('portal/student/login')} className="group inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-brand-300 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-brand-500/40 dark:hover:text-white">
            <GraduationCap className="h-4 w-4 shrink-0 text-brand-600 dark:text-brand-300" aria-hidden />
            <span className="flex-1">Student Portal</span>
            <ArrowRight className="h-3.5 w-3.5 shrink-0 text-slate-400 transition group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden />
          </a>
          <a href={appUrl('portal/faculty/login')} className="group inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-brand-300 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-brand-500/40 dark:hover:text-white">
            <UserRound className="h-4 w-4 shrink-0 text-brand-600 dark:text-brand-300" aria-hidden />
            <span className="flex-1">Faculty Portal</span>
            <ArrowRight className="h-3.5 w-3.5 shrink-0 text-slate-400 transition group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden />
          </a>
        </div>
      </div>
      <p className="mt-6 text-center text-xs text-slate-400">Protected area. All sign-in attempts are logged with IP address.</p>
    </AuthLayout>
  );
}
