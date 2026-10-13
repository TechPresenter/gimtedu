import { BookMarked, BookOpen, Clock, IndianRupee, Library } from 'lucide-react';
import { Badge, Button, StatusBadge, Stagger, type Column } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDate, formatMoney } from '@/lib/format';
import { MiniStat, TabEmpty, TabError, TabLoading, TableCard, type TabProps } from './shared';

interface Txn extends Record<string, unknown> {
  id: number; title: string; isbn: string | null; accession_no: string; issue_date: string; due_date: string; return_date: string | null; renew_count: number; status: string; fine_amount: string; days_overdue: number;
}
interface LibraryData { member: { id: number; membership_no: string; max_books: number; status: string; valid_until: string | null } | null; issued: number; overdue: number; total: number; fines: number; transactions: Txn[] }

export default function LibraryTab({ studentId }: TabProps) {
  const q = useApi<LibraryData>(['students', studentId, 'library'], `students/${studentId}/library`);
  if (q.isLoading) return <TabLoading />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data!;
  if (!d.member) {
    return <TabEmpty icon={Library} title="Not a library member" description="Register the student as a library member to issue books. Issued and returned books will appear here." action={<Button variant="secondary" to="/library/members">Library members</Button>} />;
  }
  const cols: Column<Txn>[] = [
    { key: 'title', header: 'Book', render: (r) => <div className="leading-tight"><p className="max-w-[18rem] truncate font-semibold text-slate-900 dark:text-white">{r.title}</p><p className="text-xs text-slate-500">Acc. {r.accession_no}{r.isbn ? ` · ISBN ${r.isbn}` : ''}</p></div> },
    { key: 'issue_date', header: 'Issued', render: (r) => <span className="whitespace-nowrap">{formatDate(r.issue_date)}</span> },
    { key: 'due_date', header: 'Due', render: (r) => <span className="whitespace-nowrap">{formatDate(r.due_date)}{r.days_overdue > 0 && <Badge color="red" className="ml-1.5">{r.days_overdue}d late</Badge>}</span> },
    { key: 'return_date', header: 'Returned', render: (r) => <span className="whitespace-nowrap">{r.return_date ? formatDate(r.return_date) : '—'}</span> },
    { key: 'renew_count', header: 'Renewals', align: 'center', render: (r) => r.renew_count },
    { key: 'fine_amount', header: 'Fine', align: 'right', render: (r) => (Number(r.fine_amount) ? formatMoney(r.fine_amount) : '—') },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];
  return (
    <div className="space-y-5">
      <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full" step={50}>
        {[
          <MiniStat key="m" label="Membership" value={d.member.membership_no} icon={Library} tone="navy" sub={<span className="inline-flex items-center gap-1.5"><StatusBadge status={d.member.status} />{d.member.valid_until ? ` till ${formatDate(d.member.valid_until)}` : ''}</span>} />,
          <MiniStat key="i" label="Books issued" value={`${d.issued} / ${d.member.max_books}`} icon={BookOpen} tone="cyan" sub="Currently with the student" />,
          <MiniStat key="o" label="Overdue" value={d.overdue} icon={Clock} tone={d.overdue ? 'red' : 'slate'} sub={d.overdue ? 'Return pending past due date' : 'Nothing overdue'} />,
          <MiniStat key="f" label="Fines" value={formatMoney(d.fines)} icon={IndianRupee} tone={d.fines ? 'amber' : 'slate'} sub={`${d.total} transaction${d.total === 1 ? '' : 's'} in total`} />,
        ]}
      </Stagger>
      <TableCard<Txn> title="Borrowing history" icon={BookMarked} columns={cols} rows={d.transactions} empty="No books borrowed yet" emptyIcon={BookMarked} actions={<Button size="sm" variant="secondary" to="/library/circulation">Issue / return</Button>} />
    </div>
  );
}
