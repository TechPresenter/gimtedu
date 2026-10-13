import { useEffect, useState } from 'react';
import { Check, Copy, KeyRound, MailCheck, MailWarning, UserPlus } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, Select, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useLookup } from '@/lib/queries';
import type { EmployeeType, NewAccount } from '../hr';

function CopyField({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      setCopied(false);
    }
  };
  return (
    <div>
      <p className="form-label">{label}</p>
      <div className="flex items-center gap-2">
        <code className={`flex-1 truncate rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-white ${mono ? 'font-mono tracking-wide' : ''}`}>{value}</code>
        <Button variant="secondary" size="sm" icon={copied ? Check : Copy} onClick={copy} aria-label={`Copy ${label.toLowerCase()}`}>
          {copied ? 'Copied' : 'Copy'}
        </Button>
      </div>
    </div>
  );
}

/** Shows the one-time credentials of a newly created login account. */
export function CredentialsModal({ account, name, onClose }: { account: NewAccount | null; name?: string; onClose: () => void }) {
  return (
    <Modal
      open={!!account}
      onClose={onClose}
      size="md"
      title="Login account created"
      description={name ? `SmartCampus access for ${name} (${account?.role ?? ''} role).` : undefined}
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
          <KeyRound className="h-5 w-5" />
        </span>
      }
      footer={<Button onClick={onClose}>Done</Button>}
    >
      {account && (
        <div className="space-y-4">
          {account.emailed ? (
            <Alert variant="success" title="Credentials emailed">
              <span className="inline-flex items-center gap-1"><MailCheck className="h-3.5 w-3.5" /> Sent to {account.email}.</span>
            </Alert>
          ) : (
            <Alert variant="warning" title="Email could not be sent">
              <span className="inline-flex items-center gap-1"><MailWarning className="h-3.5 w-3.5" /> Share these credentials securely with the employee.</span>
            </Alert>
          )}
          <CopyField label="Username" value={account.username} />
          <CopyField label="Temporary password" value={account.password} mono />
          <p className="text-xs text-slate-500 dark:text-slate-400">The password is shown only once. The employee must set a new password at the first sign-in.</p>
        </div>
      )}
    </Modal>
  );
}

/** Create a login for an existing employee (profile action). */
export function CreateAccountModal({ open, onClose, type, id, name, email, onCreated }: {
  open: boolean;
  onClose: () => void;
  type: EmployeeType;
  id: number;
  name: string;
  email: string | null;
  onCreated: (a: NewAccount) => void;
}) {
  const toast = useToast();
  const [username, setUsername] = useState('');
  const [roleId, setRoleId] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const { data: roles = [] } = useLookup('roles', {}, open && type === 'staff');
  useEffect(() => {
    if (open) {
      setUsername(email ? email.split('@')[0].toLowerCase().replace(/[^a-z0-9.]/g, '') : '');
      setRoleId('');
      setErrors({});
      setFormError(null);
    }
  }, [open, email]);
  const submit = async () => {
    setSaving(true);
    setFormError(null);
    try {
      const res = await api.post<NewAccount>(`${type}/${id}/account`, { username: username.trim(), role_id: roleId || undefined });
      toast.success(res.message);
      onCreated(res.data);
      onClose();
    } catch (e) {
      const err = e as ApiError;
      const errs = { ...(err.errors ?? {}) };
      if (errs.login_username) errs.username = errs.login_username;
      if (errs.login_role_id) errs.role_id = errs.login_role_id;
      setErrors(errs);
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  };
  const roleOptions = roles.filter((r) => !/super/i.test(r.label)).map((r) => ({ value: String(r.value), label: r.label }));
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="md"
      title="Create login account"
      description={`${name} will receive a temporary password at ${email ?? 'their email'}.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button icon={UserPlus} loading={saving} onClick={submit} disabled={!email}>
            Create account
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {!email && <Alert variant="warning">Add an email address to this profile before creating a login.</Alert>}
        {formError && <Alert variant="error">{errors.email ?? formError}</Alert>}
        <Field label="Username" htmlFor="acc-username" error={errors.username} hint="Letters, numbers, dots, dashes and underscores. Leave blank to auto-generate.">
          <Input id="acc-username" value={username} onChange={(e) => setUsername(e.target.value)} invalid={!!errors.username} autoComplete="off" />
        </Field>
        {type === 'staff' ? (
          <Field label="Role" htmlFor="acc-role" error={errors.role_id} hint="Defaults to the Staff role.">
            <Select id="acc-role" value={roleId} onChange={(e) => setRoleId(e.target.value)} options={roleOptions} placeholder="Staff (default)" invalid={!!errors.role_id} />
          </Field>
        ) : (
          <p className="text-sm text-slate-600 dark:text-slate-300">
            Role: <span className="badge badge-navy">Faculty</span>
          </p>
        )}
      </div>
    </Modal>
  );
}
