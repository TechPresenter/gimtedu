import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle, Barcode, BellRing, BookOpen, BookUp, CheckCircle2, Eye, History, IndianRupee, Printer, RefreshCw, RotateCcw, ScanLine, SearchX, UserRound, X,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardBody, CardHeader, Combobox, EmptyState, Field, Input, PageHeader, Skeleton, Tabs, useToast,
} from '@/components/ui';
import { CrudTable, type CrudBulkAction } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatNumber, isoDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { CollectFinesDialog, useLoanActions } from './library/dialogs';
import { MemberPicker } from './library/MemberPicker';
import { BookCover, COPY_STATUS_COLORS, LoanRow, LoanStateBadge, MemberSummary, libCrumbs, openPrint, useLibraryRefresh } from './library/shared';
import type { CopyLookup, LibMember, Loan, MemberCardPayload } from './library/types';

const TABS = [
  { key: 'issue', label: 'Issue', icon: BookUp },
  { key: 'return', label: 'Return / Renew', icon: RotateCcw },
  { key: 'history', label: 'History', icon: History },
];

export default function CirculationPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? (params.get('tab') as string) : can('library', 'create') ? 'issue' : 'return';
  const go = (key: string, extra: Record<string, string> = {}) => {
    const next = new URLSearchParams();
    next.set('tab', key);
    Object.entries(extra).forEach(([a, b]) => next.set(a, b));
    setParams(next);
  };
  const dash = useApi<{ kpis: { issued: number; overdue: number; due_today: number; issued_today: number; returned_today: number } }>(['library', 'dashboard'], 'library/dashboard');
  const k = dash.data?.kpis;

  return (
    <>
      <PageHeader
        title="Issue / Return"
        description="Circulation desk: issue books to members, take returns with fines, renew loans and report lost books."
        breadcrumbs={libCrumbs('Issue / Return')}
        actions={
          <>
            <Button variant="secondary" icon={Printer} onClick={() => openPrint('overdue')}>
              Overdue report
            </Button>
            <Button variant="secondary" icon={IndianRupee} to="/library/fines">
              Fines
            </Button>
          </>
        }
      >
        <div className="flex flex-wrap gap-2 text-xs">
          <DeskChip label="On loan" value={k?.issued} tone="blue" onClick={() => go('history', { 'f.state': 'issued' })} />
          <DeskChip label="Overdue" value={k?.overdue} tone="red" onClick={() => go('history', { 'f.state': 'overdue' })} />
          <DeskChip label="Due today" value={k?.due_today} tone="amber" onClick={() => go('history', { 'f.state': 'due_today' })} />
          <DeskChip label="Issued today" value={k?.issued_today} tone="green" />
          <DeskChip label="Returned today" value={k?.returned_today} tone="slate" />
        </div>
      </PageHeader>

      <Tabs tabs={TABS.filter((t) => t.key !== 'issue' || can('library', 'create'))} value={tab} onChange={(t) => go(t)} className="mb-5" />

      {tab === 'issue' && <IssueDesk initialMemberId={Number(params.get('member')) || null} />}
      {tab === 'return' && <ReturnDesk initialCode={params.get('code') ?? ''} />}
      {tab === 'history' && <HistoryTable />}
    </>
  );
}

function DeskChip({ label, value, tone, onClick }: { label: string; value?: number; tone: 'blue' | 'red' | 'amber' | 'green' | 'slate'; onClick?: () => void }) {
  const dot = { blue: 'bg-blue-500', red: 'bg-red-500', amber: 'bg-amber-500', green: 'bg-emerald-500', slate: 'bg-slate-400' }[tone];
  const cls = 'inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300';
  const body = (
    <>
      <span className={clsx('h-2 w-2 rounded-full', dot)} />
      {label}
      <strong className="tabular-nums text-slate-900 dark:text-white">{value === undefined ? '…' : formatNumber(value)}</strong>
    </>
  );
  return onClick ? (
    <button type="button" onClick={onClick} className={clsx(cls, 'transition hover:border-brand-300 hover:text-brand-800 dark:hover:border-brand-500/50 dark:hover:text-white')}>
      {body}
    </button>
  ) : (
    <span className={cls}>{body}</span>
  );
}

/* ================================================================ Issue */

function IssueDesk({ initialMemberId }: { initialMemberId: number | null }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const [memberId, setMemberId] = useState<number | null>(initialMemberId);
  const [picked, setPicked] = useState<LibMember | null>(null);
  const card = useApi<MemberCardPayload>(['library', 'member', memberId], `library/members/${memberId}`, undefined, { enabled: !!memberId });
  const member = card.data?.member ?? picked;
  const [code, setCode] = useState('');
  const [copy, setCopy] = useState<CopyLookup | null>(null);
  const [lookupError, setLookupError] = useState<string | null>(null);
  const [looking, setLooking] = useState(false);
  const [due, setDue] = useState('');
  const [remarks, setRemarks] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [session, setSession] = useState<Loan[]>([]);
  const [fineSel, setFineSel] = useState<{ ids: number[]; total: number; members: number } | null>(null);
  const loanActions = useLoanActions(() => void card.refetch());

  useEffect(() => {
    if (member) setDue(isoDate(new Date(Date.now() + member.loan_days * 86400000)));
  }, [member?.id, member?.loan_days]); // eslint-disable-line react-hooks/exhaustive-deps

  const lookup = async (c = code) => {
    const v = c.trim();
    if (!v) {
      setLookupError('Scan or type the accession number of the copy.');
      return;
    }
    setLooking(true);
    setLookupError(null);
    setErrors({});
    try {
      setCopy(await api.get<CopyLookup>('library/copies/lookup', { code: v }));
    } catch (e) {
      setCopy(null);
      setLookupError((e as ApiError).message);
    } finally {
      setLooking(false);
    }
  };

  const blocks = card.data?.blocks ?? [];
  const copyProblem = copy
    ? copy.copy.status !== 'available'
      ? `This copy is ${copy.copy.status}${copy.loan ? ` to ${copy.loan.member.name} (due ${formatDate(copy.loan.due_date)})` : ''}.`
      : copy.book.type === 'reference'
        ? 'Reference books are for in-library use only.'
        : copy.book.status !== 'active'
          ? 'This title is inactive in the catalogue.'
          : null
    : null;

  const issue = async (e: FormEvent) => {
    e.preventDefault();
    if (!member) return setFormError('Select the member who is borrowing the book.');
    if (!copy) return setFormError('Look up the copy to issue.');
    setSaving(true);
    setErrors({});
    setFormError(null);
    try {
      const res = await api.post<Loan>('library/issue', { member_id: member.id, accession_no: copy.copy.accession_no, due_date: due, remarks });
      toast.success(res.message);
      setSession((s) => [res.data, ...s].slice(0, 8));
      setCopy(null);
      setCode('');
      setRemarks('');
      await refresh();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(Object.keys(ex.errors ?? {}).length ? null : ex.message);
    } finally {
      setSaving(false);
    }
  };

  const unpaid = card.data?.fines ?? [];
  return (
    <div className="grid grid-cols-1 gap-5 lg:grid-cols-5">
      <Card className="lg:col-span-3">
        <CardHeader title="1 · Member" subtitle="Search by name, membership number, student or employee ID" icon={UserRound} />
        <CardBody className="space-y-5 pt-0">
          {!memberId && (
            <MemberPicker
              value={null}
              autoFocus
              onChange={(m) => {
                setPicked(m);
                setMemberId(m?.id ?? null);
                setFormError(null);
              }}
            />
          )}
          {memberId && !card.data && (card.error ? <Alert variant="error">{(card.error as ApiError).message}</Alert> : <Skeleton className="h-40 w-full" />)}
          {card.data && (
            <>
              <MemberSummary
                member={card.data.member}
                blocks={blocks}
                compact
                actions={
                  <Button
                    size="sm"
                    variant="secondary"
                    icon={X}
                    onClick={() => {
                      setPicked(null);
                      setMemberId(null);
                      setFormError(null);
                    }}
                    aria-label="Change member"
                  >
                    <span className="hidden sm:inline">Change</span>
                  </Button>
                }
              />
              <div>
                <div className="mb-2 flex items-center justify-between">
                  <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Books with this member</h3>
                  <Link to={`/library/members?member=${card.data.member.id}`} className="link text-xs">
                    Member card
                  </Link>
                </div>
                {card.data.loans.length ? (
                  <ul className="space-y-2">
                    {card.data.loans.map((l) => (
                      <LoanRow
                        key={l.id}
                        loan={l}
                        busy={loanActions.busy}
                        onReturn={loanActions.canEdit ? loanActions.openReturn : undefined}
                        onRenew={loanActions.canEdit ? loanActions.renew : undefined}
                        onLost={loanActions.canEdit ? loanActions.openLost : undefined}
                      />
                    ))}
                  </ul>
                ) : (
                  <p className="rounded-xl border border-dashed border-slate-200 px-4 py-5 text-center text-sm text-slate-500 dark:border-slate-700">No books issued right now.</p>
                )}
              </div>
              {unpaid.length > 0 && (
                <div className="flex flex-col gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-amber-500/30 dark:bg-amber-500/10">
                  <p className="text-sm text-amber-800 dark:text-amber-200">
                    <strong>{formatMoney(card.data.member.unpaid_fines, 2)}</strong> unpaid in {unpaid.length} fine{unpaid.length === 1 ? '' : 's'}
                  </p>
                  {loanActions.canEdit && (
                    <Button size="sm" variant="success" icon={IndianRupee} onClick={() => setFineSel({ ids: unpaid.map((f) => f.id), total: unpaid.reduce((a, f) => a + f.amount, 0), members: 1 })}>
                      Collect now
                    </Button>
                  )}
                </div>
              )}
            </>
          )}
          {!memberId && (
            <EmptyState icon={ScanLine} title="Start with the member" description="Scan the library card or search the member. Their limits, current loans and dues appear here." className="py-8" />
          )}
        </CardBody>
      </Card>

      <div className="space-y-5 lg:col-span-2">
        <Card>
          <CardHeader title="2 · Book" subtitle="Scan the barcode or search an available copy" icon={Barcode} />
          <CardBody className="pt-0">
            <form onSubmit={issue} noValidate className="space-y-4">
              <Field label="Accession number" htmlFor="issue-code" error={errors.accession_no ?? lookupError}>
                <div className="flex gap-2">
                  <Input
                    id="issue-code"
                    prefix={<Barcode className="h-4 w-4" />}
                    value={code}
                    onChange={(e) => setCode(e.target.value.toUpperCase())}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        void lookup();
                      }
                    }}
                    placeholder="ACC000123"
                    className="font-mono"
                    invalid={!!(errors.accession_no ?? lookupError)}
                  />
                  <Button variant="secondary" onClick={() => lookup()} loading={looking}>
                    Look up
                  </Button>
                </div>
              </Field>
              <Field label="…or search by title" htmlFor="issue-search">
                <Combobox
                  id="issue-search"
                  value={null}
                  optionsUrl="library/copies/available"
                  placeholder="Title, author or ISBN…"
                  onChange={(v) => {
                    if (v) {
                      setCode(String(v));
                      void lookup(String(v));
                    }
                  }}
                />
              </Field>
              {copy && (
                <div className={clsx('flex gap-3 rounded-xl border p-3', copyProblem ? 'border-amber-200 bg-amber-50/50 dark:border-amber-500/30 dark:bg-amber-500/5' : 'border-emerald-200 bg-emerald-50/40 dark:border-emerald-500/30 dark:bg-emerald-500/5')}>
                  <BookCover title={copy.book.title} src={copy.book.cover_image} size="md" />
                  <div className="min-w-0 flex-1 text-sm">
                    <p className="line-clamp-2 font-semibold text-slate-900 dark:text-white">{copy.book.title}</p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">{copy.book.authors}</p>
                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                      <code className="rounded bg-white px-1.5 py-0.5 font-semibold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">{copy.copy.accession_no}</code>
                      <Badge color={COPY_STATUS_COLORS[copy.copy.status] ?? 'slate'}>{copy.copy.status}</Badge>
                      {copy.copy.rack_no && <span className="text-slate-500">Rack {copy.copy.rack_no}{copy.copy.shelf_no ? ` / ${copy.copy.shelf_no}` : ''}</span>}
                    </div>
                    {copyProblem && (
                      <p className="mt-2 flex items-start gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-300">
                        <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" /> {copyProblem}
                      </p>
                    )}
                  </div>
                </div>
              )}
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                <Field label="Due date" htmlFor="issue-due" error={errors.due_date} hint={member ? `${member.loan_days}-day loan period of this member` : 'Set from the member’s loan period'}>
                  <Input id="issue-due" type="date" min={isoDate(new Date(Date.now() + 86400000))} value={due} onChange={(e) => setDue(e.target.value)} invalid={!!errors.due_date} />
                </Field>
                <Field label="Remarks" htmlFor="issue-remarks">
                  <Input id="issue-remarks" value={remarks} onChange={(e) => setRemarks(e.target.value)} maxLength={255} placeholder="Optional" />
                </Field>
              </div>
              {formError && <Alert variant="error">{formError}</Alert>}
              <Button type="submit" icon={BookUp} loading={saving} disabled={!member || !copy || !!copyProblem || blocks.length > 0} className="w-full">
                Issue book
              </Button>
              {member && blocks.length > 0 && <p className="text-center text-xs text-red-600 dark:text-red-400">Resolve the member’s blocks to issue new books.</p>}
            </form>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Issued in this session" subtitle="Loans you created from this desk" icon={CheckCircle2} />
          <CardBody className="pt-0">
            {session.length === 0 ? (
              <p className="py-4 text-center text-sm text-slate-500 dark:text-slate-400">Nothing issued yet.</p>
            ) : (
              <ul className="space-y-2.5">
                {session.map((l) => (
                  <li key={l.id} className="flex items-center gap-3 text-sm">
                    <BookCover title={l.title} src={l.cover_image} size="xs" />
                    <div className="min-w-0 flex-1 leading-tight">
                      <p className="truncate font-medium text-slate-900 dark:text-white">{l.title}</p>
                      <p className="truncate text-xs text-slate-500">
                        {l.member.name} · due {formatDate(l.due_date)}
                      </p>
                    </div>
                    <code className="shrink-0 text-xs text-slate-500">{l.accession_no}</code>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>
      <CollectFinesDialog selection={fineSel} onClose={() => setFineSel(null)} onDone={() => void card.refetch()} />
      {loanActions.dialogs}
    </div>
  );
}

/* ================================================================ Return */

function ReturnDesk({ initialCode }: { initialCode: string }) {
  const [code, setCode] = useState(initialCode);
  const [result, setResult] = useState<CopyLookup | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [looking, setLooking] = useState(false);
  const lookup = async (c = code) => {
    const v = c.trim();
    if (!v) {
      setError('Scan or type the accession number of the returned copy.');
      return;
    }
    setLooking(true);
    setError(null);
    try {
      setResult(await api.get<CopyLookup>('library/copies/lookup', { code: v }));
    } catch (e) {
      setResult(null);
      setError((e as ApiError).message);
    } finally {
      setLooking(false);
    }
  };
  const loanActions = useLoanActions(() => {
    if (result) void lookup(result.copy.accession_no);
  });
  useEffect(() => {
    if (initialCode) void lookup(initialCode);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const loan = result?.loan ?? null;
  return (
    <div className="space-y-5">
      <Card>
        <CardBody className="grid grid-cols-1 gap-5 lg:grid-cols-5">
          <form
            className="lg:col-span-2"
            noValidate
            onSubmit={(e) => {
              e.preventDefault();
              void lookup();
            }}
          >
            <h2 className="flex items-center gap-2 font-display text-base font-semibold text-slate-900 dark:text-white">
              <ScanLine className="h-5 w-5 text-brand-600 dark:text-brand-300" /> Scan returned book
            </h2>
            <p className="mb-3 text-sm text-slate-500 dark:text-slate-400">The loan, due date and any overdue fine appear instantly.</p>
            <Field label="Accession number" htmlFor="ret-code" error={error}>
              <div className="flex gap-2">
                <Input id="ret-code" autoFocus prefix={<Barcode className="h-4 w-4" />} value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} placeholder="ACC000123" className="font-mono" invalid={!!error} />
                <Button type="submit" variant="secondary" loading={looking}>
                  Find
                </Button>
              </div>
            </Field>
          </form>
          <div className="lg:col-span-3">
            {!result ? (
              <div className="flex h-full min-h-[8rem] items-center justify-center rounded-xl border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500 dark:border-slate-700">
                Scan a copy to see its loan — or pick a book from the list below.
              </div>
            ) : !loan ? (
              <Alert variant="info" title={result.book.title}>
                Copy <strong>{result.copy.accession_no}</strong> is <strong>{result.copy.status}</strong> and not on loan. {result.copy.status === 'available' && 'It can go back on the shelf.'}
              </Alert>
            ) : (
              <div className={clsx('rounded-xl border p-4', loan.state === 'overdue' ? 'border-red-200 bg-red-50/40 dark:border-red-500/30 dark:bg-red-500/5' : 'border-slate-200 dark:border-slate-800')}>
                <div className="flex gap-4">
                  <BookCover title={loan.title} src={loan.cover_image} size="md" />
                  <div className="min-w-0 flex-1">
                    <p className="line-clamp-2 font-semibold text-slate-900 dark:text-white">{loan.title}</p>
                    <p className="text-sm text-slate-600 dark:text-slate-300">
                      {loan.member.name} · <span className="font-mono text-xs">{loan.member.membership_no}</span>
                    </p>
                    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                      <span>Issued {formatDate(loan.issue_date)}</span>
                      <span>Due {formatDate(loan.due_date)}</span>
                      <LoanStateBadge state={loan.state} />
                      {loan.renew_count > 0 && <span>Renewed ×{loan.renew_count}</span>}
                    </div>
                    {loan.accrued_fine > 0 && (
                      <p className="mt-2 text-sm font-semibold text-red-600 dark:text-red-400">
                        {loan.days_overdue} days overdue · fine {formatMoney(loan.accrued_fine, 2)}
                      </p>
                    )}
                  </div>
                </div>
                {loanActions.canEdit && (
                  <div className="mt-4 flex flex-wrap gap-2">
                    <Button icon={RotateCcw} onClick={() => loanActions.openReturn(loan)}>
                      Return book
                    </Button>
                    <Button variant="secondary" icon={RefreshCw} disabled={!loan.can_renew || loanActions.busy} onClick={() => loanActions.renew(loan)} title={loan.can_renew ? undefined : 'Overdue loans or loans at the renewal limit cannot be renewed'}>
                      Renew
                    </Button>
                    <Button variant="ghost" icon={SearchX} onClick={() => loanActions.openLost(loan)} className="text-red-600 dark:text-red-400">
                      Report lost
                    </Button>
                  </div>
                )}
              </div>
            )}
          </div>
        </CardBody>
      </Card>

      <LoansTable
        title="Books on loan"
        description="Every copy currently with a member. Use the Status filter to narrow to overdue or due today."
        defaultFilters={{ state: 'issued' }}
        actions={loanActions}
        showAccruedNote
      />
      {loanActions.dialogs}
    </div>
  );
}

/* ================================================================ History */

function HistoryTable() {
  const loanActions = useLoanActions();
  return (
    <>
      <LoansTable title="Circulation history" description="Every issue, return, renewal and lost book." urlState actions={loanActions} />
      {loanActions.dialogs}
    </>
  );
}

function LoansTable({
  title, description, defaultFilters, urlState, actions, showAccruedNote,
}: {
  title: string; description: string; defaultFilters?: Record<string, unknown>; urlState?: boolean; actions: ReturnType<typeof useLoanActions>; showAccruedNote?: boolean;
}) {
  const refresh = useLibraryRefresh();
  const toast = useToast();
  const rowMenu = (r: Row) => {
    const open = r.status === 'issued';
    const loan = { id: Number(r.id), title: String(r.book_title), due_date: String(r.due_date) };
    return [
      { label: 'Open book', icon: BookOpen, to: `/library/books/${r.book_id}` },
      { label: 'Member card', icon: Eye, to: `/library/members?member=${r.member_id}` },
      open && actions.canEdit && { label: '', divider: true },
      open && actions.canEdit && { label: 'Return…', icon: RotateCcw, onClick: () => actions.openReturn(loan) },
      open && actions.canEdit && r.can_renew && { label: 'Renew', icon: RefreshCw, onClick: () => actions.renew(loan) },
      open && actions.canEdit && { label: 'Send reminder', icon: BellRing, onClick: () => actions.remind([loan.id]) },
      open && actions.canEdit && { label: 'Report lost…', icon: SearchX, danger: true, onClick: () => actions.openLost(loan) },
    ];
  };
  const bulk: CrudBulkAction[] = actions.canEdit
    ? [
        {
          label: 'Send reminders',
          icon: BellRing,
          onClick: async (ids, clear) => {
            try {
              const res = await api.post('library/remind', { ids });
              toast.success(res.message);
              clear();
              await refresh();
            } catch (e) {
              toast.error((e as ApiError).message);
            }
          },
        },
      ]
    : [];
  const renderers = useMemo(
    () => ({
      book_title: (r: Row) => (
        <div className="min-w-[12rem] max-w-xs leading-tight">
          <Link to={`/library/books/${r.book_id}`} className="line-clamp-1 font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.book_title}</Link>
          <span className="font-mono text-xs text-slate-500 dark:text-slate-400">{r.accession_no}</span>
        </div>
      ),
      member_name: (r: Row) => (
        <Link to={`/library/members?member=${r.member_id}`} className="flex min-w-[11rem] items-center gap-2.5">
          <Avatar name={String(r.member_name)} src={r.member_photo} size="sm" />
          <span className="min-w-0 leading-tight">
            <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.member_name}</span>
            <span className="block text-xs text-slate-500 dark:text-slate-400">{r.membership_no}</span>
          </span>
        </Link>
      ),
      state: (r: Row) =>
        showAccruedNote && actions.canEdit && r.status === 'issued' ? (
          <span className="inline-flex items-center gap-2 whitespace-nowrap">
            <LoanStateBadge state={String(r.state)} />
            <Button size="xs" variant="secondary" icon={RotateCcw} onClick={() => actions.openReturn({ id: Number(r.id) })}>
              Return
            </Button>
          </span>
        ) : (
          <LoanStateBadge state={String(r.state)} />
        ),
      due_date: (r: Row) => {
        const late = Number(r.days_late);
        return (
          <span className="block whitespace-nowrap leading-tight">
            {formatDate(r.due_date)}
            {late > 0 && (
              <span className={clsx('block text-xs font-semibold', r.status === 'issued' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400')}>
                {late} day{late === 1 ? '' : 's'} late
              </span>
            )}
          </span>
        );
      },
      days_late: (r: Row) => (Number(r.days_late) > 0 ? <span className={clsx('font-semibold tabular-nums', r.status === 'issued' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400')}>{r.days_late}</span> : <span className="text-slate-400">—</span>),
      fine_amount: (r: Row) =>
        r.status === 'issued' && Number(r.accrued_fine) > 0 ? (
          <span className="whitespace-nowrap text-red-600 dark:text-red-400" title="Accruing — charged on return">
            {formatMoney(r.accrued_fine)}*
          </span>
        ) : Number(r.fine_amount) > 0 ? (
          <span className="whitespace-nowrap font-medium tabular-nums">{formatMoney(r.fine_amount)}</span>
        ) : (
          <span className="text-slate-400">—</span>
        ),
    }),
    [showAccruedNote, actions],
  );
  return (
    <CrudTable
      module="library_transactions"
      title={title}
      description={description}
      defaultFilters={defaultFilters}
      urlState={urlState}
      rowMenu={rowMenu}
      bulkActions={bulk}
      renderers={renderers}
      hideColumns={showAccruedNote ? ['return_date'] : undefined}
      viewTo={(r) => `/library/books/${r.book_id}`}
      header={({ summary }) =>
        summary ? (
          <div className="flex flex-wrap gap-x-5 gap-y-1 border-b border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
            <span>
              <strong className="text-slate-900 dark:text-white">{formatNumber(summary.total)}</strong> loans
            </span>
            <span>
              <strong className="text-blue-700 dark:text-blue-300">{formatNumber(summary.on_loan)}</strong> on loan
            </span>
            <span>
              <strong className="text-red-600 dark:text-red-400">{formatNumber(summary.overdue)}</strong> overdue
            </span>
            <span>
              <strong className="text-emerald-700 dark:text-emerald-300">{formatNumber(summary.returned)}</strong> returned
            </span>
            <span>
              <strong className="text-slate-700 dark:text-slate-200">{formatNumber(summary.lost)}</strong> lost
            </span>
            <span>
              Fines charged <strong className="text-slate-900 dark:text-white">{formatMoney(summary.fines)}</strong>
            </span>
            {showAccruedNote && <span className="sm:ml-auto">* fine still accruing — charged on return</span>}
          </div>
        ) : null
      }
    />
  );
}
