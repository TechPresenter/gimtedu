import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowLeft, ArrowRight, BadgeCheck, Check, IdCard, KeyRound, ShieldCheck } from 'lucide-react';
import { Alert, Button, Field, useToast } from '@/components/ui';
import { api } from '@/lib/api';
import { isoDate } from '@/lib/format';
import { AuthHeading, AuthLink } from '../components/AuthLayout';
import { PasswordInput, PasswordRules, policyChecks } from '../components/PasswordFields';
import { authErrorTone, errorMessage, errorStatus, fieldErrors } from '../lib/errors';
import { usePortalTitle } from '../lib/hooks';
import { useApplySession, useSession } from '../lib/session';
import type { StudentSession } from '../lib/types';
import { StudentAuthLayout } from './StudentAuthLayout';

interface Verified {
  first_name: string;
  student_uid: string;
  email_masked: string | null;
  expires_in: number;
}

const STEPS = [
  { key: 'verify', label: 'Verify identity', icon: IdCard },
  { key: 'password', label: 'Set password', icon: KeyRound },
];

function Steps({ step }: { step: number }) {
  return (
    <ol className="mb-7 flex items-center gap-2" aria-label="Activation steps">
      {STEPS.map((s, i) => {
        const done = i < step;
        const active = i === step;
        return (
          <li key={s.key} className="flex flex-1 items-center gap-2" aria-current={active ? 'step' : undefined}>
            <span
              className={clsx(
                'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold transition-colors',
                done ? 'bg-emerald-600 text-white' : active ? 'bg-brand-900 text-white ring-4 ring-brand-100 dark:bg-white dark:text-brand-900 dark:ring-white/10' : 'bg-slate-100 text-slate-500 dark:bg-slate-800',
              )}
            >
              {done ? <Check className="h-4 w-4" aria-hidden /> : i + 1}
            </span>
            <span className={clsx('truncate text-[13px] font-semibold', active ? 'text-slate-900 dark:text-white' : 'text-slate-500 dark:text-slate-400')}>{s.label}</span>
            {i < STEPS.length - 1 && <span className={clsx('mx-1 h-0.5 flex-1 rounded-full', done ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-800')} aria-hidden />}
          </li>
        );
      })}
    </ol>
  );
}

export default function StudentActivatePage() {
  usePortalTitle('Activate your account', 'student');
  const navigate = useNavigate();
  const toast = useToast();
  const applySession = useApplySession();
  const { data: session } = useSession('student');
  const policy = session?.app.password_policy ?? { min_length: 8, uppercase: true, number: true, special: true };

  const [step, setStep] = useState(0);
  const [uid, setUid] = useState('');
  const [dob, setDob] = useState('');
  const [contact, setContact] = useState('');
  const [verified, setVerified] = useState<Verified | null>(null);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<{ message: string; tone: 'warning' | 'error' | 'info'; status: number } | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setErrors({});
    setError(null);
  }, [step]);

  const verify = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!uid.trim()) local.student_uid = 'Enter your Student ID.';
    if (!dob) local.dob = 'Enter your date of birth.';
    if (!contact.trim()) local.contact = 'Enter your registered email or mobile number.';
    setErrors(local);
    setError(null);
    if (Object.keys(local).length) return;
    setBusy(true);
    try {
      const res = await api.post<Verified>('portal/student/activate/verify', { student_uid: uid.trim(), dob, contact: contact.trim() });
      setVerified(res.data);
      setStep(1);
    } catch (err) {
      setErrors(fieldErrors(err));
      const status = errorStatus(err);
      setError({ message: errorMessage(err), tone: status === 409 ? 'info' : authErrorTone(err), status });
    } finally {
      setBusy(false);
    }
  };

  const activate = async (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    const failed = policyChecks(password, policy).find((c) => !c.ok);
    if (!password) local.password = 'Choose a password.';
    else if (failed) local.password = `Your password needs: ${failed.label.toLowerCase()}.`;
    if (password && confirm !== password) local.password_confirmation = 'Passwords do not match.';
    setErrors(local);
    setError(null);
    if (Object.keys(local).length) return;
    setBusy(true);
    try {
      const res = await api.post<StudentSession>('portal/student/activate', { password, password_confirmation: confirm });
      applySession('student', res.data);
      toast.success(res.message || 'Your account is ready.');
      navigate('/student', { replace: true });
    } catch (err) {
      if (errorStatus(err) === 410) {
        setStep(0);
        setPassword('');
        setConfirm('');
      }
      setErrors(fieldErrors(err));
      setError({ message: errorMessage(err), tone: 'error', status: errorStatus(err) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <StudentAuthLayout>
      <AuthHeading
        icon={step === 0 ? BadgeCheck : ShieldCheck}
        title={step === 0 ? 'Activate your account' : `Hi ${verified?.first_name ?? ''}, set your password`}
        subtitle={
          step === 0
            ? 'First time on the portal? Confirm a few details from your admission record and choose your own password.'
            : `Student ID ${verified?.student_uid ?? ''} verified. This password will be used every time you sign in.`
        }
      />
      <Steps step={step} />
      {error && (
        <Alert variant={error.tone} className="mb-5">
          {error.message}
          {error.status === 409 && (
            <span className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
              <AuthLink to="/student/login">Sign in</AuthLink>
              <AuthLink to="/student/forgot">Reset password</AuthLink>
            </span>
          )}
        </Alert>
      )}
      {session?.app.features.self_activation === false ? (
        <Alert variant="info" title="Online activation is off">
          Please collect your portal login from the academic office.
        </Alert>
      ) : step === 0 ? (
        <form onSubmit={verify} noValidate className="space-y-5">
          <Field label="Student ID" htmlFor="act-uid" required error={errors.student_uid}>
            <input
              id="act-uid"
              autoComplete="username"
              autoCapitalize="characters"
              spellCheck={false}
              autoFocus
              value={uid}
              onChange={(e) => setUid(e.target.value.toUpperCase())}
              placeholder="e.g. GIMT26BBA0001"
              aria-invalid={!!errors.student_uid || undefined}
              className={clsx('form-input form-input-lg font-semibold tracking-wide', errors.student_uid && 'form-input-error')}
              maxLength={40}
            />
          </Field>
          <Field label="Date of birth" htmlFor="act-dob" required error={errors.dob} hint="As recorded in your admission form.">
            <input
              id="act-dob"
              type="date"
              autoComplete="bday"
              value={dob}
              max={isoDate()}
              min="1950-01-01"
              onChange={(e) => setDob(e.target.value)}
              aria-invalid={!!errors.dob || undefined}
              className={clsx('form-input form-input-lg', errors.dob && 'form-input-error')}
            />
          </Field>
          <Field label="Registered email or mobile number" htmlFor="act-contact" required error={errors.contact} hint="The email or mobile number you gave at admission.">
            <input
              id="act-contact"
              autoComplete="email"
              autoCapitalize="none"
              spellCheck={false}
              value={contact}
              onChange={(e) => setContact(e.target.value)}
              placeholder="you@example.com or 98765 43210"
              aria-invalid={!!errors.contact || undefined}
              className={clsx('form-input form-input-lg', errors.contact && 'form-input-error')}
              maxLength={190}
            />
          </Field>
          <Button type="submit" size="lg" loading={busy} iconRight={ArrowRight} className="w-full">
            Verify my details
          </Button>
          <p className="text-center text-sm text-slate-500 dark:text-slate-400">
            Already activated? <AuthLink to="/student/login">Sign in</AuthLink>
          </p>
        </form>
      ) : (
        <form onSubmit={activate} noValidate className="space-y-5">
          {verified?.email_masked && (
            <p className="rounded-xl bg-slate-50 px-3.5 py-2.5 text-[13px] text-slate-600 dark:bg-slate-900 dark:text-slate-300">
              Password reset emails will go to <strong className="font-semibold text-slate-900 dark:text-white">{verified.email_masked}</strong>.
            </p>
          )}
          <Field label="Choose a password" htmlFor="act-password" required error={errors.password}>
            <PasswordInput id="act-password" autoComplete="new-password" large autoFocus value={password} onChange={(e) => setPassword(e.target.value)} invalid={!!errors.password} />
          </Field>
          <PasswordRules password={password} policy={policy} />
          <Field label="Confirm password" htmlFor="act-confirm" required error={errors.password_confirmation}>
            <PasswordInput id="act-confirm" autoComplete="new-password" large value={confirm} onChange={(e) => setConfirm(e.target.value)} invalid={!!errors.password_confirmation} />
          </Field>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button variant="secondary" size="lg" icon={ArrowLeft} onClick={() => setStep(0)} disabled={busy}>
              Back
            </Button>
            <Button type="submit" size="lg" loading={busy} icon={ShieldCheck} className="flex-1">
              Activate &amp; sign in
            </Button>
          </div>
        </form>
      )}
    </StudentAuthLayout>
  );
}
