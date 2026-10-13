import { Link } from 'react-router-dom';
import { ArrowRight, Sparkles } from 'lucide-react';
import { LoginForm, AuthNote } from '../components/LoginForm';
import { usePortalTitle } from '../lib/hooks';
import { useSession } from '../lib/session';
import { StudentAuthLayout } from './StudentAuthLayout';

export default function StudentLoginPage() {
  usePortalTitle('Sign in', 'student');
  const { data } = useSession('student');
  const features = data?.app.features;
  return (
    <StudentAuthLayout>
      <LoginForm
        area="student"
        title="Student sign in"
        subtitle="Welcome back! Sign in to see your attendance, results, fees and more."
        identifierLabel="Student ID or email"
        identifierPlaceholder="e.g. GIMT26BBA0001"
        identifierHint="Your Student ID is printed on your ID card and fee receipts."
        disabled={features ? !features.student_portal : false}
        below={
          features?.self_activation !== false ? (
            <Link
              to="/student/activate"
              className="group mt-5 flex items-center gap-3 rounded-2xl border border-dashed border-flame-300/70 bg-flame-50/60 px-4 py-3.5 transition hover:border-flame-400 hover:bg-flame-50 dark:border-flame-500/30 dark:bg-flame-500/10 dark:hover:bg-flame-500/15"
            >
              <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-flame-600 shadow-sm dark:bg-slate-900 dark:text-flame-300">
                <Sparkles className="h-[18px] w-[18px]" aria-hidden />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-bold text-slate-900 dark:text-white">First time here? Activate your account</span>
                <span className="block text-xs text-slate-600 dark:text-slate-300">Verify with your Student ID and date of birth, then set a password.</span>
              </span>
              <ArrowRight className="h-4 w-4 shrink-0 text-flame-600 transition group-hover:translate-x-0.5 dark:text-flame-300" aria-hidden />
            </Link>
          ) : (
            <AuthNote>New students receive their portal login from the academic office.</AuthNote>
          )
        }
      />
    </StudentAuthLayout>
  );
}
