import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Mail, Send } from 'lucide-react';
import { Alert, Button, Field, Input } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useDocumentTitle } from '@/lib/hooks';
import { AuthLayout } from './AuthLayout';

export default function ForgotPasswordPage() {
  useDocumentTitle('Forgot password');
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const res = await api.post('auth/forgot', { email });
      setDone(res.message);
    } catch (err) {
      setError((err as ApiError).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <AuthLayout title="Reset your password" subtitle="Enter the email linked to your account and we'll send you a secure reset link.">
      {done ? (
        <Alert variant="success" title="Check your inbox">{done}</Alert>
      ) : (
        <form onSubmit={submit} className="space-y-5" noValidate>
          {error && <Alert variant="error">{error}</Alert>}
          <Field label="Email address" htmlFor="email" required>
            <Input id="email" type="email" autoComplete="email" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@gimt.ac.in" prefix={<Mail className="h-4 w-4" />} />
          </Field>
          <Button type="submit" className="w-full" size="lg" loading={busy} icon={Send}>
            Send reset link
          </Button>
        </form>
      )}
      <Link to="/login" className="link mt-8 inline-flex items-center gap-1.5 text-sm">
        <ArrowLeft className="h-4 w-4" /> Back to sign in
      </Link>
    </AuthLayout>
  );
}
