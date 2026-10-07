import { Briefcase, Building2, FileSignature, Send, TrendingUp } from 'lucide-react';
import { Button, StatusBadge, Stagger, type Column } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDate, formatDateTime, labelize } from '@/lib/format';
import { MiniStat, TabEmpty, TabError, TabLoading, TableCard, type TabProps } from './shared';

interface AppRow extends Record<string, unknown> { id: number; drive_title: string; job_role: string; drive_date: string | null; job_type: string; company_name: string; status: string; current_round: string | null; applied_at: string }
interface OfferRow extends Record<string, unknown> { id: number; company_name: string; job_role: string; package_lpa: string; offer_date: string; joining_date: string | null; location: string | null; status: string }
interface PlacementData { stats: { applications: number; offers: number; best_package: number | null }; applications: AppRow[]; offers: OfferRow[] }

export default function PlacementTab({ studentId }: TabProps) {
  const q = useApi<PlacementData>(['students', studentId, 'placement'], `students/${studentId}/placement`);
  if (q.isLoading) return <TabLoading />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const { stats, applications, offers } = q.data!;
  if (!applications.length && !offers.length) {
    return <TabEmpty icon={Briefcase} title="No placement activity yet" description="Drive applications, interview rounds and offers for this student will be tracked here." action={<Button variant="secondary" to="/placement/drives">Placement drives</Button>} />;
  }
  const appCols: Column<AppRow>[] = [
    { key: 'company_name', header: 'Company / role', render: (r) => <div className="leading-tight"><p className="font-semibold text-slate-900 dark:text-white">{r.company_name}</p><p className="text-xs text-slate-500">{r.job_role} · {labelize(r.job_type)}</p></div> },
    { key: 'drive_date', header: 'Drive date', render: (r) => (r.drive_date ? formatDate(r.drive_date) : '—') },
    { key: 'current_round', header: 'Round', render: (r) => r.current_round ?? '—' },
    { key: 'applied_at', header: 'Applied', render: (r) => <span className="whitespace-nowrap text-xs">{formatDateTime(r.applied_at)}</span> },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];
  const offerCols: Column<OfferRow>[] = [
    { key: 'company_name', header: 'Company', render: (r) => <div className="leading-tight"><p className="font-semibold text-slate-900 dark:text-white">{r.company_name}</p><p className="text-xs text-slate-500">{r.job_role}{r.location ? ` · ${r.location}` : ''}</p></div> },
    { key: 'package_lpa', header: 'Package', align: 'right', render: (r) => <span className="font-semibold tabular-nums text-accent-700 dark:text-accent-300">{Number(r.package_lpa).toFixed(2)} LPA</span> },
    { key: 'offer_date', header: 'Offered', render: (r) => formatDate(r.offer_date) },
    { key: 'joining_date', header: 'Joining', render: (r) => (r.joining_date ? formatDate(r.joining_date) : '—') },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];
  return (
    <div className="space-y-5">
      <Stagger className="grid grid-cols-1 gap-3 sm:grid-cols-3" itemClassName="h-full" step={50}>
        {[
          <MiniStat key="a" label="Applications" value={stats.applications} icon={Send} tone="blue" />,
          <MiniStat key="o" label="Offers" value={stats.offers} icon={FileSignature} tone="green" />,
          <MiniStat key="b" label="Best package" value={stats.best_package ? `${stats.best_package.toFixed(2)} LPA` : '—'} icon={TrendingUp} tone="amber" />,
        ]}
      </Stagger>
      <TableCard<OfferRow> title="Offers" icon={FileSignature} columns={offerCols} rows={offers} empty="No offers yet" emptyIcon={FileSignature} />
      <TableCard<AppRow> title="Drive applications" icon={Building2} columns={appCols} rows={applications} empty="No applications" emptyIcon={Building2} delay={60} />
    </div>
  );
}
