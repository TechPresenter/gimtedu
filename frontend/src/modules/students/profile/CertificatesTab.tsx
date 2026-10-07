import { Award, ExternalLink, FilePlus2 } from 'lucide-react';
import { Button, StatusBadge, type Column } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, labelize } from '@/lib/format';
import { TabEmpty, TabError, TabLoading, TableCard, type TabProps } from './shared';

interface CertRow extends Record<string, unknown> {
  id: number; certificate_no: string; type: string; title: string; purpose: string | null; issue_date: string; valid_until: string | null; status: string;
  revoked_reason: string | null; verified_count: number; issued_by_name: string | null; verify_url: string;
}

export default function CertificatesTab({ studentId }: TabProps) {
  const { can } = useAuth();
  const q = useApi<{ rows: CertRow[] }>(['students', studentId, 'certificates'], `students/${studentId}/certificates`);
  if (q.isLoading) return <TabLoading cards={1} />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const rows = q.data!.rows;
  const generate = can('certificates', 'create') ? <Button size="sm" icon={FilePlus2} to={`/certificates?student_id=${studentId}`}>Generate certificate</Button> : undefined;
  if (!rows.length) {
    return <TabEmpty icon={Award} title="No certificates issued" description="Bonafide, character, transfer and other certificates issued to this student will be listed here with their verification links." action={generate} />;
  }
  const cols: Column<CertRow>[] = [
    { key: 'title', header: 'Certificate', render: (r) => <div className="leading-tight"><p className="font-semibold text-slate-900 dark:text-white">{r.title}</p><p className="text-xs text-slate-500">{labelize(r.type)}{r.purpose ? ` · ${r.purpose}` : ''}</p></div> },
    { key: 'certificate_no', header: 'Number', render: (r) => <span className="font-mono text-xs font-semibold">{r.certificate_no}</span> },
    { key: 'issue_date', header: 'Issued', render: (r) => <span className="whitespace-nowrap">{formatDate(r.issue_date)}{r.issued_by_name ? <span className="block text-xs text-slate-500">{r.issued_by_name}</span> : null}</span> },
    { key: 'valid_until', header: 'Valid until', render: (r) => (r.valid_until ? formatDate(r.valid_until) : 'Lifetime') },
    { key: 'verified_count', header: 'Verifications', align: 'center', render: (r) => r.verified_count },
    { key: 'status', header: 'Status', render: (r) => <span title={r.revoked_reason ?? ''}><StatusBadge status={r.status} /></span> },
    { key: 'verify', header: '', align: 'right', render: (r) => <a href={r.verify_url} target="_blank" rel="noopener noreferrer" className="link inline-flex items-center gap-1 whitespace-nowrap text-xs">Verify <ExternalLink className="h-3 w-3" /></a> },
  ];
  return <TableCard<CertRow> title="Issued certificates" subtitle={`${rows.length} certificate${rows.length === 1 ? '' : 's'} · QR-verifiable`} icon={Award} columns={cols} rows={rows} empty="No certificates" emptyIcon={Award} actions={generate} />;
}
