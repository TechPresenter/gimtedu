import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, CheckCircle2, GraduationCap, KeyRound, LockOpen, MoreHorizontal, RotateCcw, UserPlus, UsersRound } from 'lucide-react';
import { Alert, Button, Card, Combobox, DataTable, Dropdown, EmptyState, Field, Modal, PersonCell, Pagination, SearchInput, Select, Toggle, useConfirm, useToast, type Column, type SortState } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatNumber, labelize, timeAgo } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { useLookup } from '@/lib/queries';
import { CredentialsModal, IssueConfirmModal, STUDENT_STATE_OPTIONS, StudentStateBadge } from './components';
import type { IssuedCredential, Listing, StudentRow } from './types';

interface GeneratedStudent {
  student_id: number;
  name: string;
  student_uid: string;
  username: string;
  password: string;
  emailed: boolean;
  email: string | null;
}

const toCredential = (g: GeneratedStudent): IssuedCredential => ({ name: g.name, login: g.username, password: g.password, emailed: g.emailed, email: g.email });
const SEMESTERS = Array.from({ length: 10 }, (_, i) => ({ value: i + 1, label: `Semester ${i + 1}` }));

function BulkGenerateModal({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: (items: GeneratedStudent[], message: string) => void }) {
  const toast = useToast();
  const { data: programs = [] } = useLookup('programs');
  const [programId, setProgramId] = useState('');
  const [semester, setSemester] = useState('');
  const [sectionId, setSectionId] = useState<string | number | null>(null);
  const [send, setSend] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const params = { program_id: programId || undefined, semester: semester || undefined, section_id: sectionId || undefined };
  const preview = useQuery({
    queryKey: ['portal-access', 'bulk-preview', params],
    queryFn: () => api.get<{ count: number; with_email: number; limit: number }>('portal-access/students/bulk-preview', params),
    enabled: open && !!programId,
  });
  useEffect(() => {
    if (!open) {
      setError(null);
      setBusy(false);
    }
  }, [open]);
  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await api.post<{ created: GeneratedStudent[]; emailed: number }>('portal-access/students/bulk-generate', { ...params, send_email: send });
      toast.success(res.message);
      onDone(res.data.created, res.message);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Unable to generate accounts. Please try again.');
    } finally {
      setBusy(false);
    }
  };
  const count = preview.data?.count ?? 0;
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={busy}
      size="md"
      title="Bulk generate student accounts"
      description="Creates portal logins with temporary passwords for every active student in the selection who does not have one yet."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button icon={UserPlus} onClick={() => void submit()} loading={busy} disabled={!programId || !count}>
            {count ? `Generate ${formatNumber(Math.min(count, preview.data?.limit ?? count))} accounts` : 'Generate accounts'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        <Field label="Program" required htmlFor="bulk-program">
          <Select
            id="bulk-program"
            value={programId}
            onChange={(e) => {
              setProgramId(e.target.value);
              setSectionId(null);
            }}
            options={programs}
            placeholder="Select a program"
          />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Semester" htmlFor="bulk-semester">
            <Select id="bulk-semester" value={semester} onChange={(e) => { setSemester(e.target.value); setSectionId(null); }} options={SEMESTERS} placeholder="All semesters" />
          </Field>
          <Field label="Section" htmlFor="bulk-section">
            <Combobox id="bulk-section" source="sections" params={{ program_id: programId || undefined, semester_no: semester || undefined }} value={sectionId} onChange={(v) => setSectionId(v)} placeholder="All sections" disabled={!programId} />
          </Field>
        </div>
        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-800/50">
          {!programId ? (
            <span className="text-slate-500">Choose a program to see how many students will get an account.</span>
          ) : preview.isLoading ? (
            <span className="text-slate-500">Counting students…</span>
          ) : (
            <span className="text-slate-700 dark:text-slate-200">
              <strong>{formatNumber(count)}</strong> active student{count === 1 ? '' : 's'} without a portal account
              {count ? (
                <>
                  {' '}
                  · <strong>{formatNumber(preview.data?.with_email ?? 0)}</strong> with an email on record
                </>
              ) : null}
              {count > (preview.data?.limit ?? Infinity) && <span className="block text-xs text-amber-700">Only the first {preview.data?.limit} are generated per run — run it again for the rest.</span>}
            </span>
          )}
        </div>
        <Toggle checked={send} onChange={setSend} label="Email login details to each student" description="Students without an email on record get none — download the credentials sheet afterwards." />
      </div>
    </Modal>
  );
}

export function StudentsTab({ portalUrl, onChanged }: { portalUrl: string; onChanged: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { data: programs = [] } = useLookup('programs');
  const [q, setQ] = useState('');
  const search = useDebounce(q, 300);
  const [programId, setProgramId] = useState('');
  const [semester, setSemester] = useState('');
  const [sectionId, setSectionId] = useState<string | number | null>(null);
  const [account, setAccount] = useState('');
  const [studentStatus, setStudentStatus] = useState('active');
  const [sort, setSort] = useState<SortState | null>({ key: 'student_uid', dir: 'asc' });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [issue, setIssue] = useState<{ row: StudentRow; mode: 'generate' | 'reset' } | null>(null);
  const [busy, setBusy] = useState(false);
  const [creds, setCreds] = useState<{ title: string; items: IssuedCredential[] } | null>(null);
  const [bulkOpen, setBulkOpen] = useState(false);

  const params = useMemo(
    () => ({ q: search || undefined, program_id: programId || undefined, semester: semester || undefined, section_id: sectionId || undefined, account: account || undefined, student_status: studentStatus, sort: sort?.key, dir: sort?.dir, page, per_page: perPage }),
    [search, programId, semester, sectionId, account, studentStatus, sort, page, perPage],
  );
  useEffect(() => setPage(1), [search, programId, semester, sectionId, account, studentStatus]);
  useEffect(() => setSelected(new Set()), [params]);
  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['portal-access', 'students', params],
    queryFn: ({ signal }) => api.get<Listing<StudentRow>>('portal-access/students', params, { signal }),
    placeholderData: keepPreviousData,
  });
  const rows = data?.rows ?? [];
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

  const doIssue = async (sendEmail: boolean) => {
    if (!issue) return;
    setBusy(true);
    try {
      const res = await api.post<GeneratedStudent>(`portal-access/students/${issue.row.id}/${issue.mode}`, { send_email: sendEmail });
      toast.success(res.message);
      setCreds({ title: issue.mode === 'generate' ? 'Portal account created' : 'Password reset', items: [toCredential(res.data)] });
      setIssue(null);
      refresh();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Something went wrong. Please try again.');
    } finally {
      setBusy(false);
    }
  };

  const setStatus = async (row: StudentRow, status: 'active' | 'disabled') => {
    if (status === 'disabled' && !(await confirm({ title: 'Disable portal access?', message: `${row.name} (${row.student_uid}) will be signed out and cannot sign in until access is enabled again.`, confirmText: 'Disable access', danger: true }))) return;
    await run(() => api.post(`portal-access/students/${row.id}/status`, { status }));
  };

  const bulk = async (action: 'generate' | 'enable' | 'disable' | 'unlock') => {
    const ids = [...selected].map(Number);
    if (!ids.length) return;
    const labels = { generate: 'Generate accounts', enable: 'Enable access', disable: 'Disable access', unlock: 'Unlock accounts' };
    if (!(await confirm({ title: `${labels[action]} for ${ids.length} student(s)?`, message: action === 'generate' ? 'Students who already have an account are skipped. Login details are emailed where an email is on record, and the temporary passwords are shown once.' : action === 'disable' ? 'The selected students are signed out and cannot sign in until access is enabled again.' : 'This applies to the selected students who have a portal account.', confirmText: labels[action], danger: action === 'disable' }))) return;
    try {
      const res = await api.post<{ created?: GeneratedStudent[] }>('portal-access/students/bulk', { action, ids, send_email: action === 'generate' });
      toast.success(res.message);
      if (action === 'generate' && res.data.created?.length) setCreds({ title: `${res.data.created.length} portal account(s) created`, items: res.data.created.map(toCredential) });
      setSelected(new Set());
      refresh();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Bulk action failed.');
    }
  };

  const columns: Column<StudentRow>[] = [
    { key: 'name', header: 'Student', sortable: true, render: (r) => <PersonCell name={r.name} sub={<span className="font-mono">{r.student_uid}</span>} src={r.photo} /> },
    { key: 'program', header: 'Class', sortable: true, render: (r) => <span className="whitespace-nowrap text-slate-700 dark:text-slate-200">{r.program} · Sem {r.semester}{r.section ? ` · ${r.section}` : ''}</span> },
    { key: 'state', header: 'Portal account', render: (r) => (
      <div className="space-y-1">
        <StudentStateBadge state={r.state} />
        {r.state === 'locked' && r.locked_until && <p className="text-[11px] text-red-600 dark:text-red-400">until {formatDateTime(r.locked_until)}</p>}
        {r.student_status !== 'active' && <p className="text-[11px] text-slate-500">Student: {labelize(r.student_status)}</p>}
      </div>
    ) },
    { key: 'last_login', header: 'Last sign-in', sortable: true, render: (r) => (r.last_login_at ? <span title={formatDateTime(r.last_login_at)} className="whitespace-nowrap">{timeAgo(r.last_login_at)}<span className="block text-[11px] text-slate-400">{r.login_count} sign-ins · {r.last_login_ip}</span></span> : <span className="text-slate-400">{r.account_id ? 'Never' : '—'}</span>) },
    { key: 'activated', header: 'Activated', render: (r) => (r.activated_at ? <span className="whitespace-nowrap">{timeAgo(r.activated_at)}<span className="block text-[11px] text-slate-400">{labelize(r.activation_method === 'self' ? 'self service' : r.activation_method ?? '')}</span></span> : <span className="text-slate-400">—</span>) },
  ];

  return (
    <>
      <Card>
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <SearchInput value={q} onChange={setQ} placeholder="Search name, Student ID, email, mobile…" className="lg:w-80" />
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" icon={UsersRound} onClick={() => setBulkOpen(true)}>
                Bulk generate
              </Button>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-5">
            <Select inputSize="sm" aria-label="Program" value={programId} onChange={(e) => { setProgramId(e.target.value); setSectionId(null); }} options={programs} placeholder="All programs" />
            <Select inputSize="sm" aria-label="Semester" value={semester} onChange={(e) => setSemester(e.target.value)} options={SEMESTERS} placeholder="All semesters" />
            <Combobox size="sm" source="sections" params={{ program_id: programId || undefined, semester_no: semester || undefined }} value={sectionId} onChange={(v) => setSectionId(v)} placeholder="All sections" />
            <Select inputSize="sm" aria-label="Account state" value={account} onChange={(e) => setAccount(e.target.value)} options={STUDENT_STATE_OPTIONS} placeholder="Any account state" />
            <Select inputSize="sm" aria-label="Student status" value={studentStatus} onChange={(e) => setStudentStatus(e.target.value)} options={[{ value: 'active', label: 'Active students' }, { value: 'graduated', label: 'Graduated' }, { value: 'alumni', label: 'Alumni' }, { value: 'all', label: 'All students' }]} />
          </div>
        </div>
        {selected.size > 0 && (
          <div className="flex flex-wrap items-center gap-2 border-b border-brand-100 bg-brand-50/70 px-4 py-2.5 text-sm dark:border-brand-500/20 dark:bg-brand-500/10">
            <span className="mr-2 font-semibold text-brand-900 dark:text-brand-100">{selected.size} selected</span>
            <Button size="xs" icon={UserPlus} onClick={() => void bulk('generate')}>Generate accounts</Button>
            <Button size="xs" variant="secondary" icon={CheckCircle2} onClick={() => void bulk('enable')}>Enable</Button>
            <Button size="xs" variant="secondary" icon={Ban} onClick={() => void bulk('disable')}>Disable</Button>
            <Button size="xs" variant="secondary" icon={LockOpen} onClick={() => void bulk('unlock')}>Unlock</Button>
            <Button size="xs" variant="ghost" onClick={() => setSelected(new Set())}>Clear</Button>
          </div>
        )}
        {error ? (
          <div className="p-4">
            <Alert variant="error" action={<Button size="xs" variant="secondary" onClick={() => void refetch()}>Retry</Button>}>
              {error instanceof ApiError ? error.message : 'Unable to load students.'}
            </Alert>
          </div>
        ) : (
          <DataTable
            columns={columns}
            rows={rows}
            loading={isLoading || isFetching}
            sort={sort}
            onSort={setSort}
            selectable
            selected={selected}
            onSelectedChange={setSelected}
            empty={<EmptyState icon={GraduationCap} title="No students match these filters" description="Clear the filters or search for a different name or Student ID." action={<Button size="sm" variant="secondary" onClick={() => { setQ(''); setProgramId(''); setSemester(''); setSectionId(null); setAccount(''); setStudentStatus('active'); }}>Clear filters</Button>} />}
            actions={(r) =>
              r.account_id ? (
                <Dropdown
                  trigger={<MoreHorizontal className="h-4 w-4" />}
                  triggerClassName="btn-icon !h-8 !w-8 !rounded-lg"
                  label={`Actions for ${r.name}`}
                  items={[
                    { label: 'Reset password', icon: RotateCcw, onClick: () => setIssue({ row: r, mode: 'reset' }) },
                    r.state === 'locked' && { label: 'Unlock account', icon: LockOpen, onClick: () => void run(() => api.post(`portal-access/students/${r.id}/unlock`)) },
                    r.state === 'disabled'
                      ? { label: 'Enable access', icon: CheckCircle2, onClick: () => void setStatus(r, 'active') }
                      : { label: 'Disable access', icon: Ban, danger: true, onClick: () => void setStatus(r, 'disabled') },
                  ]}
                />
              ) : (
                <Button size="xs" variant="soft" icon={KeyRound} onClick={() => setIssue({ row: r, mode: 'generate' })}>
                  Generate
                </Button>
              )
            }
          />
        )}
        {data && data.pagination.total > 0 && (
          <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={data.pagination.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} className="border-t border-slate-100 dark:border-slate-800" />
        )}
      </Card>

      <IssueConfirmModal
        open={!!issue}
        onClose={() => !busy && setIssue(null)}
        busy={busy}
        canEmail={!!issue?.row.email}
        onConfirm={(send) => void doIssue(send)}
        title={issue?.mode === 'generate' ? 'Generate portal account' : 'Reset portal password'}
        confirmText={issue?.mode === 'generate' ? 'Generate account' : 'Reset password'}
        message={
          issue ? (
            issue.mode === 'generate' ? (
              <>Create a student portal login for <strong>{issue.row.name}</strong> ({issue.row.student_uid}) with a temporary password. They will set their own password at first sign-in.</>
            ) : (
              <>Issue a new temporary password for <strong>{issue.row.name}</strong> ({issue.row.student_uid}). Their current password stops working, any lockout is cleared and they are signed out everywhere.</>
            )
          ) : null
        }
      />
      <CredentialsModal open={!!creds} onClose={() => setCreds(null)} title={creds?.title ?? ''} items={creds?.items ?? []} portalUrl={portalUrl} />
      <BulkGenerateModal
        open={bulkOpen}
        onClose={() => setBulkOpen(false)}
        onDone={(items) => {
          setBulkOpen(false);
          refresh();
          if (items.length) setCreds({ title: `${items.length} portal account(s) created`, items: items.map(toCredential) });
        }}
      />
    </>
  );
}
