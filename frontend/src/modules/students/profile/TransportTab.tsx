import { Bus, History, MapPin } from 'lucide-react';
import { Button, Card, CardBody, CardHeader, DescriptionList, Reveal, StatusBadge, type Column } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDate, formatMoney, formatTime } from '@/lib/format';
import { TabEmpty, TabError, TabLoading, TableCard, type TabProps } from './shared';

interface Allocation extends Record<string, unknown> {
  id: number; route_name: string; route_code: string; stop_name: string | null; pickup_time: string | null; vehicle_no: string | null; session_name: string | null;
  start_date: string; end_date: string | null; fee_amount: string | null; status: string;
}
interface TransportData { current: Allocation | null; rows: Allocation[] }

export default function TransportTab({ profile, studentId }: TabProps) {
  const q = useApi<TransportData>(['students', studentId, 'transport'], `students/${studentId}/transport`);
  if (q.isLoading) return <TabLoading />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const { current: c, rows } = q.data!;
  if (!rows.length) {
    return (
      <TabEmpty icon={Bus} title={profile.student.uses_transport ? 'Bus requested — no route allotted yet' : 'Not using college transport'}
        description={profile.student.uses_transport ? 'Allocate a route and stop from Transport › Allocations.' : 'The student commutes on their own. Allocations will appear here if they opt in.'}
        action={<Button variant="secondary" to="/transport/allocations">Transport allocations</Button>} />
    );
  }
  const cols: Column<Allocation>[] = [
    { key: 'route_name', header: 'Route', render: (r) => <div className="leading-tight"><p className="font-semibold text-slate-900 dark:text-white">{r.route_code} — {r.route_name}</p><p className="text-xs text-slate-500">{r.stop_name ?? 'No stop'}{r.pickup_time ? ` · ${formatTime(r.pickup_time)}` : ''}</p></div> },
    { key: 'vehicle_no', header: 'Vehicle', render: (r) => <span className="font-mono text-xs">{r.vehicle_no ?? '—'}</span> },
    { key: 'session_name', header: 'Session', render: (r) => r.session_name ?? '—' },
    { key: 'start_date', header: 'Period', render: (r) => <span className="whitespace-nowrap">{formatDate(r.start_date)} – {r.end_date ? formatDate(r.end_date) : 'present'}</span> },
    { key: 'fee_amount', header: 'Fee', align: 'right', render: (r) => (r.fee_amount ? formatMoney(r.fee_amount) : '—') },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];
  return (
    <div className="space-y-5">
      {c && (
        <Reveal>
          <Card>
            <CardHeader title="Current route" icon={MapPin} subtitle={`Since ${formatDate(c.start_date)}`} />
            <CardBody>
              <DescriptionList columns={3} items={[
                { label: 'Route', value: `${c.route_code} — ${c.route_name}` },
                { label: 'Pickup stop', value: c.stop_name },
                { label: 'Pickup time', value: c.pickup_time ? formatTime(c.pickup_time) : null },
                { label: 'Vehicle', value: c.vehicle_no },
                { label: 'Annual fee', value: c.fee_amount ? formatMoney(c.fee_amount) : null },
                { label: 'Session', value: c.session_name },
              ]} />
            </CardBody>
          </Card>
        </Reveal>
      )}
      <TableCard<Allocation> title="Transport history" icon={History} columns={cols} rows={rows} empty="No allocations" emptyIcon={History} delay={60} />
    </div>
  );
}
