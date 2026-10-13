import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, CheckCircle2, Link2, LockOpen, MoreHorizontal, RotateCcw, UserPlus, Users } from 'lucide-react';
import { Alert, Button, Card, Combobox, DataTable, Dropdown, EmptyState, Field, Input, Modal, Pagination, PersonCell, SearchInput, Select, Toggle, useConfirm, useToast, type Column, type SortState } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, timeAgo } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { useLookup } from '@/lib/queries';
import { CredentialsModal, FACULTY_STATE_OPTIONS, FacultyStateBadge, IssueConfirmModal } from './components';
import type { FacultyRow, IssuedCredential, Listing } from './types';

interface IssuedFaculty {
  faculty_id: number;
  user_id: number;
  name: string;
  username: string;
  email: string;
  password: string;
  emailed: boolean;
}

const toCredential = (r: IssuedFaculty): IssuedCredential => ({ name: r.name, login: r.username, alt: r.email, password: r.password, emailed: r.emailed, email: r.email });

function CreateLoginModal({ row, onClose, onCreated }: { row: FacultyRow | null; onClose: () => void; onCreated: (r: IssuedFaculty, message: string) => void }) {
  const [username, setUsername] = useState('');
  const [send, setSend] = useState(true);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    setUsername(row?.email ? row.email.split('@')[0].toLowerCase().replace(/[^a-z0-9.]/g, '') : '');
    setErrors({});
    setError(null);
    setSend(true);
  }, [row]);
  const submit = async () => {
    if (!row) return;
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      const res = await api.post<IssuedFaculty>(`portal-access/faculty/${row.id}/create`, { username: username.trim(), send_email: send });
      onCreated(res.data, res.message);
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors);
        setError(e.message);
      } else setError('Unable to create the login. Please try again.');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      open={!!row}
      onClose={onClose}
      static={busy}
      title="Create faculty login"
      description={row ? `${row.name} · ${row.employee_id}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button icon={UserPlus} loading={busy} onClick={() => void submit()} disabled={!row?.email}>
            Create login
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        {!row?.email && <Alert variant="warning">This faculty profile has no email address. Add one under Faculty &amp; Staff first.</Alert>}
        <Field label="Login email" hint="Taken from the faculty profile.">
          <Input value={row?.email ?? ''} readOnly disabled />
        </Field>
        <Field label="Username" htmlFor="fac-username" error={errors.username} hint="Optional — leave blank to derive it from the email. Faculty can sign in with either.">
          <Input id="fac-username" value={username} onChange={(e) => setUsername(e.target.value)} invalid={!!errors.username} maxLength={60} autoComplete="off" />
        </Field>
        <Toggle checked={send} onChange={setSend} label="Email the login details" description="Sends the faculty portal link and the temporary password to the email above." />
        <p className="text-xs text-slate-500 dark:text-slate-400">The account gets the Faculty role. A temporary password is generated and must be changed at first sign-in.</p>
      </div>
    </Modal>
  );
}

function LinkUserModal({ row, onClose, onLinked }: { row: FacultyRow | null; onClose: () => void; onLinked: (message: string) => void }) {
  const [userId, setUserId] = useState<string | number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    setUserId(row?.user_id ?? null);
    setError(null);
  }, [row]);
  const submit = async () => {
    if (!row || !userId) return;
    setBusy(true);
    setError(null);
    try {
      const res = await api.post(`portal-access/faculty/${row.id}/link`, { user_id: userId });
      onLinked(res.message);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Unable to link the account.');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      open={!!row}
      onClose={onClose}
      static={busy}
      title="Link an existing user account"
      description={row ? `${row.name} · ${row.employee_id}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button icon={Link2} loading={busy} onClick={() => void submit()} disabled={!userId}>
            Link account
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        <Field label="User account" required htmlFor="link-user" hint="Admin-panel users not yet linked to a faculty profile. The Faculty role is added automatically.">
          <Combobox id="link-user" optionsUrl="portal-access/users" value={userId} onChange={(v) => setUserId(v)} placeholder="Search by name, email or username…" initialOptions={row?.user_id && row.username ? [{ value: row.user_id, label: row.username }] : []} />
        </Field>
      </div>
    </Modal>
  );
}

export function FacultyTab({ portalUrl, onChanged }: { portalUrl: string; onChanged: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { data: departments = [] } = useLookup('departments');
  const [q, setQ] = useState('');
  const search = useDebounce(q, 300);
  const [department, setDepartment] = useState('');
  const [login, setLogin] = useState('');
  const [sort, setSort] = useState<SortState | null>({ key: 'employee_id', dir: 'asc' });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [createFor, setCreateFor] = useState<FacultyRow | null>(null);
  const [linkFor, setLinkFor] = useState<FacultyRow | null>(null);
  const [resetFor, setResetFor] = useState<FacultyRow | null>(null);
  const [busy, setBusy] = useState(false);
  const [creds, setCreds] = useState<{ title: string; items: IssuedCredential[] } | null>(null);

  const params = useMemo(() => ({ q: search || undefined, department_id: department || undefined, login: login || undefined, sort: sort?.key, dir: sort?.dir, page, per_page: perPage }), [search, department, login, sort, page, perPage]);
  useEffect(() => setPage(1), [search, department, login]);
  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['portal-access', 'faculty', params],
    queryFn: ({ signal }) => api.get<Listing<FacultyRow>>('portal-access/faculty', params, { signal }),
    placeholderData: keepPreviousData,
  });
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['portal-access'] });
    onChanged();
  };
  const run = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn();
      toast.success(res.message);
      refresh();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Something went wrong. Please try again.');
    }
  };
  const doReset = async (send: boolean) => {
    if (!resetFor) return;
    setBusy(true);
    try {
      const res = await api.post<IssuedFaculty>(`portal-access/faculty/${resetFor.id}/reset`, { send_email: send });
      toast.success(res.message);
      setCreds({ title: 'Password reset', items: [toCredential(res.data)] });
      setResetFor(null);
      refresh();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Unable to reset the password.');
    } finally {
      setBusy(false);
    }
  };
  const setStatus = async (r: FacultyRow, status: 'active' | 'inactive') => {
    if (status === 'inactive' && !(await confirm({ title: 'Disable this login?', message: `${r.name} will not be able to sign in to the faculty portal or the admin panel until the login is enabled again.`, confirmText: 'Disable login', danger: true }))) return;
    await run(() => api.post(`portal-access/faculty/${r.id}/status`, { status }));
  };

  const columns: Column<FacultyRow>[] = [
    { key: 'name', header: 'Faculty', sortable: true, render: (r) => <PersonCell name={r.name} sub={<span><span className="font-mono">{r.employee_id}</span>{r.designation ? ` · ${r.designation}` : ''}</span>} src={r.photo} /> },
    { key: 'department', header: 'Department', sortable: true, render: (r) => <span className="text-slate-700 dark:text-slate-200">{r.department ?? '—'}</span> },
    { key: 'login', header: 'Login', render: (r) => (r.user_id ? <span className="whitespace-nowrap"><span className="font-mono text-[13px]">{r.username}</span><span className="block text-[11px] text-slate-400">{r.login_email}</span></span> : <span className="text-slate-400">—</span>) },
    { key: 'state', header: 'Portal access', render: (r) => (
      <div className="space-y-1">
        <FacultyStateBadge state={r.state} />
        {r.state === 'locked' && r.locked_until && <p className="text-[11px] text-red-600 dark:text-red-400">until {formatDateTime(r.locked_until)}</p>}
      </div>
    ) },
    { key: 'last_login', header: 'Last sign-in', sortable: true, render: (r) => (r.last_login_at ? <span title={formatDateTime(r.last_login_at)} className="whitespace-nowrap">{timeAgo(r.last_login_at)}<span className="block text-[11px] text-slate-400">{r.last_login_ip}</span></span> : <span className="text-slate-400">{r.user_id ? 'Never' : '—'}</span>) },
  ];

  return (
    <>
      <Card>
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800 lg:flex-row lg:items-center">
          <SearchInput value={q} onChange={setQ} placeholder="Search name, employee ID, email, username…" className="lg:w-80" />
          <div className="grid flex-1 grid-cols-2 gap-2 lg:max-w-md">
            <Select inputSize="sm" aria-label="Department" value={department} onChange={(e) => setDepartment(e.target.value)} options={departments} placeholder="All departments" />
            <Select inputSize="sm" aria-label="Login state" value={login} onChange={(e) => setLogin(e.target.value)} options={FACULTY_STATE_OPTIONS} placeholder="Any login state" />
          </div>
        </div>
        {error ? (
          <div className="p-4">
            <Alert variant="error" action={<Button size="xs" variant="secondary" onClick={() => void refetch()}>Retry</Button>}>
              {error instanceof ApiError ? error.message : 'Unable to load faculty.'}
            </Alert>
          </div>
        ) : (
          <DataTable
            columns={columns}
            rows={data?.rows ?? []}
            loading={isLoading || isFetching}
            sort={sort}
            onSort={setSort}
            empty={<EmptyState icon={Users} title="No faculty match these filters" description="Clear the filters or search for a different name." action={<Button size="sm" variant="secondary" onClick={() => { setQ(''); setDepartment(''); setLogin(''); }}>Clear filters</Button>} />}
            actions={(r) =>
              r.state === 'none' ? (
                <div className="flex justify-end gap-1.5">
                  <Button size="xs" variant="soft" icon={UserPlus} onClick={() => setCreateFor(r)}>
                    Create login
                  </Button>
                  <Button size="xs" variant="secondary" icon={Link2} onClick={() => setLinkFor(r)} aria-label={`Link an existing user to ${r.name}`}>
                    Link
                  </Button>
                </div>
              ) : r.state === 'unlinked' ? (
                <Button size="xs" variant="soft" icon={Link2} onClick={() => setLinkFor(r)}>
                  Repair link
                </Button>
              ) : (
                <Dropdown
                  trigger={<MoreHorizontal className="h-4 w-4" />}
                  triggerClassName="btn-icon !h-8 !w-8 !rounded-lg"
                  label={`Actions for ${r.name}`}
                  items={[
                    { label: 'Reset password', icon: RotateCcw, onClick: () => setResetFor(r) },
                    r.state === 'locked' && { label: 'Unlock account', icon: LockOpen, onClick: () => void run(() => api.post(`portal-access/faculty/${r.id}/unlock`)) },
                    r.state === 'inactive'
                      ? { label: 'Enable login', icon: CheckCircle2, onClick: () => void setStatus(r, 'active') }
                      : { label: 'Disable login', icon: Ban, danger: true, onClick: () => void setStatus(r, 'inactive') },
                  ]}
                />
              )
            }
          />
        )}
        {data && data.pagination.total > 0 && (
          <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={data.pagination.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} className="border-t border-slate-100 dark:border-slate-800" />
        )}
      </Card>

      <CreateLoginModal
        row={createFor}
        onClose={() => setCreateFor(null)}
        onCreated={(r, message) => {
          toast.success(message);
          setCreateFor(null);
          setCreds({ title: 'Faculty login created', items: [toCredential(r)] });
          refresh();
        }}
      />
      <LinkUserModal
        row={linkFor}
        onClose={() => setLinkFor(null)}
        onLinked={(message) => {
          toast.success(message);
          setLinkFor(null);
          refresh();
        }}
      />
      <IssueConfirmModal
        open={!!resetFor}
        onClose={() => !busy && setResetFor(null)}
        busy={busy}
        canEmail={!!resetFor?.login_email}
        onConfirm={(send) => void doReset(send)}
        title="Reset faculty password"
        confirmText="Reset password"
        message={resetFor ? <>Issue a new temporary password for <strong>{resetFor.name}</strong> ({resetFor.username}). The current password stops working and any lockout is cleared.</> : null}
      />
      <CredentialsModal open={!!creds} onClose={() => setCreds(null)} title={creds?.title ?? ''} items={creds?.items ?? []} portalUrl={portalUrl} />
    </>
  );
}
