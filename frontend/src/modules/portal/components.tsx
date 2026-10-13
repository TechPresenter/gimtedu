import { useState, type ReactNode } from 'react';
import { Check, ClipboardCopy, Download, KeyRound } from 'lucide-react';
import { Alert, Badge, Button, Modal, Toggle, useToast } from '@/components/ui';
import type { BadgeColor } from '@/lib/status';
import type { FacultyState, IssuedCredential, StudentState } from './types';

/* ---------------------------------------------------------------- State badges */
const STUDENT_STATE: Record<StudentState, { label: string; color: BadgeColor }> = {
  none: { label: 'No account', color: 'slate' },
  pending: { label: 'Not activated', color: 'blue' },
  active: { label: 'Active', color: 'green' },
  must_change: { label: 'Temporary password', color: 'amber' },
  locked: { label: 'Locked', color: 'red' },
  disabled: { label: 'Disabled', color: 'purple' },
};

const FACULTY_STATE: Record<FacultyState, { label: string; color: BadgeColor }> = {
  none: { label: 'No login', color: 'slate' },
  unlinked: { label: 'Link broken', color: 'amber' },
  inactive: { label: 'Disabled', color: 'purple' },
  locked: { label: 'Locked', color: 'red' },
  must_change: { label: 'Temporary password', color: 'amber' },
  active: { label: 'Active', color: 'green' },
};

export function StudentStateBadge({ state }: { state: StudentState }) {
  const s = STUDENT_STATE[state] ?? STUDENT_STATE.none;
  return (
    <Badge color={s.color} dot>
      {s.label}
    </Badge>
  );
}

export function FacultyStateBadge({ state }: { state: FacultyState }) {
  const s = FACULTY_STATE[state] ?? FACULTY_STATE.none;
  return (
    <Badge color={s.color} dot>
      {s.label}
    </Badge>
  );
}

export const STUDENT_STATE_OPTIONS = [
  { value: 'none', label: 'No account' },
  { value: 'has_account', label: 'Has account' },
  { value: 'active', label: 'Active' },
  { value: 'must_change', label: 'Temporary password' },
  { value: 'locked', label: 'Locked' },
  { value: 'disabled', label: 'Disabled' },
  { value: 'never', label: 'Never signed in' },
  { value: 'recent', label: 'Signed in last 7 days' },
];

export const FACULTY_STATE_OPTIONS = [
  { value: 'none', label: 'No login' },
  { value: 'has_login', label: 'Has login' },
  { value: 'active', label: 'Active' },
  { value: 'must_change', label: 'Temporary password' },
  { value: 'locked', label: 'Locked' },
  { value: 'inactive', label: 'Disabled' },
  { value: 'unlinked', label: 'Link broken' },
];

/* ---------------------------------------------------------------- Copy helper */
export function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
  const [done, setDone] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text);
      setDone(true);
      setTimeout(() => setDone(false), 1500);
    } catch {
      /* clipboard blocked - the value is visible and selectable */
    }
  };
  return (
    <button type="button" onClick={copy} className="btn-icon !h-8 !w-8 !rounded-lg" aria-label={`${label}: ${text}`} title={label}>
      {done ? <Check className="h-4 w-4 text-emerald-600" /> : <ClipboardCopy className="h-4 w-4" />}
    </button>
  );
}

function csvCell(v: string): string {
  return /[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
}

/* ---------------------------------------------------------------- Issued credentials (shown once) */
export function CredentialsModal({ open, onClose, title, items, portalUrl }: { open: boolean; onClose: () => void; title: string; items: IssuedCredential[]; portalUrl: string }) {
  const toast = useToast();
  const download = () => {
    const rows = [['Name', 'Login', 'Alternate login', 'Temporary password', 'Emailed', 'Portal URL'], ...items.map((c) => [c.name, c.login, c.alt ?? '', c.password, c.emailed ? 'yes' : 'no', portalUrl])];
    const blob = new Blob([`﻿${rows.map((r) => r.map((x) => csvCell(String(x))).join(',')).join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `portal-credentials-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    toast.success('Credentials downloaded. Store the file securely and delete it after distribution.');
  };
  const copyAll = async () => {
    try {
      await navigator.clipboard.writeText(items.map((c) => `${c.name}\t${c.login}\t${c.password}`).join('\n'));
      toast.success('Copied to clipboard.');
    } catch {
      toast.error('Clipboard is not available in this browser.');
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static
      size="lg"
      title={title}
      description="Temporary passwords are shown only once. Users must choose their own password at first sign-in."
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
          <KeyRound className="h-5 w-5" />
        </span>
      }
      footer={
        <>
          {items.length > 1 && (
            <Button variant="secondary" icon={ClipboardCopy} onClick={() => void copyAll()}>
              Copy all
            </Button>
          )}
          <Button variant="secondary" icon={Download} onClick={download}>
            Download CSV
          </Button>
          <Button onClick={onClose}>Done</Button>
        </>
      }
    >
      <Alert variant="warning" className="mb-4">
        Hand these over privately. Anyone with a temporary password can sign in until it is changed.
      </Alert>
      <div className="max-h-[50vh] overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700">
        <table className="w-full text-left text-sm">
          <thead className="sticky top-0 bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500 dark:bg-slate-800 dark:text-slate-400">
            <tr>
              <th className="px-3 py-2">Name</th>
              <th className="px-3 py-2">Login</th>
              <th className="px-3 py-2">Temporary password</th>
              <th className="px-3 py-2 text-right">Email</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
            {items.map((c) => (
              <tr key={c.login}>
                <td className="px-3 py-2 font-medium text-slate-800 dark:text-slate-100">{c.name}</td>
                <td className="px-3 py-2">
                  <span className="font-mono text-[13px]">{c.login}</span>
                  {c.alt && <span className="block text-xs text-slate-500">{c.alt}</span>}
                </td>
                <td className="px-3 py-2">
                  <span className="inline-flex items-center gap-1">
                    <code className="select-all rounded-md bg-amber-50 px-2 py-1 font-mono text-[13px] font-semibold text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">{c.password}</code>
                    <CopyButton text={c.password} label="Copy password" />
                  </span>
                </td>
                <td className="px-3 py-2 text-right text-xs">{c.emailed ? <Badge color="green" className="!whitespace-normal">Emailed{c.email ? ` to ${c.email}` : ''}</Badge> : <span className="text-slate-400">Not sent</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
        Sign-in page: <span className="font-mono">{portalUrl}</span>
      </p>
    </Modal>
  );
}

/* ---------------------------------------------------------------- Confirm with "send email" option */
export function IssueConfirmModal({
  open,
  onClose,
  onConfirm,
  title,
  message,
  confirmText,
  busy,
  canEmail = true,
}: {
  open: boolean;
  onClose: () => void;
  onConfirm: (sendEmail: boolean) => void;
  title: string;
  message: ReactNode;
  confirmText: string;
  busy?: boolean;
  canEmail?: boolean;
}) {
  const [send, setSend] = useState(true);
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="sm"
      title={title}
      static={busy}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={() => onConfirm(canEmail && send)} loading={busy} icon={KeyRound}>
            {confirmText}
          </Button>
        </>
      }
    >
      <div className="space-y-4 text-sm text-slate-600 dark:text-slate-300">
        <div>{message}</div>
        {canEmail ? (
          <Toggle checked={send} onChange={setSend} label="Email the login details" description="Sends the sign-in link and temporary password to the registered email address." />
        ) : (
          <p className="text-xs text-slate-500">No valid email on record — hand the temporary password over in person.</p>
        )}
      </div>
    </Modal>
  );
}
