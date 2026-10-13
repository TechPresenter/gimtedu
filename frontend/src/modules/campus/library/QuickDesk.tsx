import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, BookUp, Barcode, RefreshCw, RotateCcw, ScanLine } from 'lucide-react';
import { Alert, Button, Card, CardBody, CardHeader, Combobox, Field, Input, Tabs, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney } from '@/lib/format';
import { useLoanActions } from './dialogs';
import { MemberPicker } from './MemberPicker';
import { BookCover, LoanStateBadge, useLibraryRefresh } from './shared';
import type { CopyLookup, LibMember, MemberCardPayload } from './types';

/** Compact issue / return box for the library dashboard. */
export function QuickDesk() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const [tab, setTab] = useState<'issue' | 'return'>(can('library', 'create') ? 'issue' : 'return');
  const [member, setMember] = useState<LibMember | null>(null);
  const [copy, setCopy] = useState<string | number | null>(null);
  const [issueError, setIssueError] = useState<string | null>(null);
  const [issuing, setIssuing] = useState(false);
  const [code, setCode] = useState('');
  const [lookup, setLookup] = useState<CopyLookup | null>(null);
  const [lookupError, setLookupError] = useState<string | null>(null);
  const [finding, setFinding] = useState(false);

  const find = async (c = code) => {
    if (!c.trim()) {
      setLookupError('Scan or type an accession number.');
      return;
    }
    setFinding(true);
    setLookupError(null);
    try {
      setLookup(await api.get<CopyLookup>('library/copies/lookup', { code: c.trim() }));
    } catch (e) {
      setLookup(null);
      setLookupError((e as ApiError).message);
    } finally {
      setFinding(false);
    }
  };
  const loanActions = useLoanActions(() => {
    if (lookup) void find(lookup.copy.accession_no);
  });

  const issue = async (e: FormEvent) => {
    e.preventDefault();
    if (!member) return setIssueError('Select the member first.');
    if (!copy) return setIssueError('Choose the copy to issue.');
    setIssuing(true);
    setIssueError(null);
    try {
      const res = await api.post('library/issue', { member_id: member.id, accession_no: copy });
      toast.success(res.message);
      setCopy(null);
      await refresh();
      const card = await api.get<MemberCardPayload>(`library/members/${member.id}`);
      setMember(card.member);
    } catch (err) {
      const ex = err as ApiError;
      setIssueError(Object.values(ex.errors ?? {})[0] || ex.message);
    } finally {
      setIssuing(false);
    }
  };

  const loan = lookup?.loan ?? null;
  return (
    <Card className="flex h-full flex-col">
      <CardHeader title="Quick issue / return" subtitle="Scan a card or accession number" icon={ScanLine} actions={<Button size="xs" variant="ghost" iconRight={ArrowRight} to="/library/circulation">Desk</Button>} />
      <CardBody className="flex flex-1 flex-col gap-4 pt-0">
        <Tabs
          variant="pills"
          value={tab}
          onChange={(k) => setTab(k as 'issue' | 'return')}
          tabs={[
            ...(can('library', 'create') ? [{ key: 'issue', label: 'Issue', icon: BookUp }] : []),
            { key: 'return', label: 'Return', icon: RotateCcw },
          ]}
        />
        {tab === 'issue' ? (
          <form onSubmit={issue} className="flex flex-1 flex-col gap-3" noValidate>
            <Field label="Member" htmlFor="qd-member">
              <MemberPicker id="qd-member" value={member} onChange={(m) => { setMember(m); setIssueError(null); }} size="sm" />
            </Field>
            <Field label="Copy" htmlFor="qd-copy" hint="Search by title or accession number (available copies only)">
              <Combobox id="qd-copy" size="sm" value={copy} onChange={(v) => { setCopy(v); setIssueError(null); }} optionsUrl="library/copies/available" placeholder="Title or ACC number…" />
            </Field>
            {issueError && <Alert variant="error">{issueError}</Alert>}
            <Button type="submit" icon={BookUp} loading={issuing} className="mt-auto w-full">
              Issue book
            </Button>
          </form>
        ) : (
          <div className="flex flex-1 flex-col gap-3">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                void find();
              }}
              className="flex gap-2"
              noValidate
            >
              <label htmlFor="qd-code" className="sr-only">
                Accession number
              </label>
              <Input id="qd-code" inputSize="sm" prefix={<Barcode className="h-4 w-4" />} value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} placeholder="ACC000123" className="font-mono" />
              <Button type="submit" size="sm" variant="secondary" loading={finding}>
                Find
              </Button>
            </form>
            {lookupError && <Alert variant="error">{lookupError}</Alert>}
            {lookup && !loan && (
              <Alert variant="info" title={lookup.book.title}>
                Copy {lookup.copy.accession_no} is <strong>{lookup.copy.status}</strong> — it is not on loan.
              </Alert>
            )}
            {loan && (
              <div className="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                <div className="flex gap-3">
                  <BookCover title={loan.title} src={loan.cover_image} size="sm" />
                  <div className="min-w-0 flex-1">
                    <p className="line-clamp-1 text-sm font-semibold text-slate-900 dark:text-white">{loan.title}</p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                      {loan.member.name} · <span className="font-mono">{loan.member.membership_no}</span>
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <span>Due {formatDate(loan.due_date)}</span>
                      <LoanStateBadge state={loan.state} />
                    </div>
                  </div>
                </div>
                {loan.accrued_fine > 0 && (
                  <p className="mt-2 rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 dark:bg-red-500/10 dark:text-red-300">
                    {loan.days_overdue} days overdue · fine {formatMoney(loan.accrued_fine, 2)}
                  </p>
                )}
                {loanActions.canEdit && (
                  <div className="mt-3 flex gap-2">
                    <Button size="sm" icon={RotateCcw} className="flex-1" onClick={() => loanActions.openReturn(loan)}>
                      Return
                    </Button>
                    <Button size="sm" variant="secondary" icon={RefreshCw} disabled={!loan.can_renew || loanActions.busy} onClick={() => loanActions.renew(loan)}>
                      Renew
                    </Button>
                  </div>
                )}
              </div>
            )}
            {!lookup && !lookupError && (
              <p className="mt-auto text-xs text-slate-500 dark:text-slate-400">
                Need more? Open the full <Link to="/library/circulation?tab=return" className="link">return desk</Link> for the list of books on loan.
              </p>
            )}
          </div>
        )}
      </CardBody>
      {loanActions.dialogs}
    </Card>
  );
}
