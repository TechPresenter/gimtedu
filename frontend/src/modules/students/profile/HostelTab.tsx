import { BedDouble, Building2, History, Phone } from 'lucide-react';
import { Button, Card, CardBody, CardHeader, DescriptionList, Reveal, StatusBadge, type Column } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDate, formatMoney, labelize } from '@/lib/format';
import { TabEmpty, TabError, TabLoading, TableCard, type TabProps } from './shared';

interface Allocation extends Record<string, unknown> {
  id: number; hostel_name: string; hostel_type: string; room_no: string; room_type: string; floor_no: number; bed_no: string | null; session_name: string | null;
  allocated_on: string; vacated_on: string | null; fee_amount: string | null; status: string; remarks: string | null; warden_name: string | null; warden_phone: string | null;
}
interface HostelData { current: Allocation | null; rows: Allocation[] }

export default function HostelTab({ profile, studentId }: TabProps) {
  const q = useApi<HostelData>(['students', studentId, 'hostel'], `students/${studentId}/hostel`);
  if (q.isLoading) return <TabLoading />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const { current: c, rows } = q.data!;
  if (!rows.length) {
    return (
      <TabEmpty icon={Building2} title={profile.student.is_hosteller ? 'Hostel requested — no room allotted yet' : 'Day scholar'}
        description={profile.student.is_hosteller ? 'The student opted for hostel accommodation. Allocate a room from Hostel › Room Allocation.' : 'This student has no hostel allocation history.'}
        action={<Button variant="secondary" to="/hostel/allocations">Room allocation</Button>} />
    );
  }
  const cols: Column<Allocation>[] = [
    { key: 'hostel_name', header: 'Hostel', render: (r) => <div className="leading-tight"><p className="font-semibold text-slate-900 dark:text-white">{r.hostel_name}</p><p className="text-xs text-slate-500">{labelize(r.hostel_type)}</p></div> },
    { key: 'room_no', header: 'Room / bed', render: (r) => <span className="whitespace-nowrap">Room {r.room_no}{r.bed_no ? ` · Bed ${r.bed_no}` : ''}</span> },
    { key: 'session_name', header: 'Session', render: (r) => r.session_name ?? '—' },
    { key: 'allocated_on', header: 'Period', render: (r) => <span className="whitespace-nowrap">{formatDate(r.allocated_on)} – {r.vacated_on ? formatDate(r.vacated_on) : 'present'}</span> },
    { key: 'fee_amount', header: 'Fee', align: 'right', render: (r) => (r.fee_amount ? formatMoney(r.fee_amount) : '—') },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];
  return (
    <div className="space-y-5">
      {c && (
        <Reveal>
          <Card>
            <CardHeader title="Current accommodation" icon={BedDouble} subtitle={`Since ${formatDate(c.allocated_on)}`} />
            <CardBody>
              <DescriptionList columns={3} items={[
                { label: 'Hostel', value: `${c.hostel_name} (${labelize(c.hostel_type)})` },
                { label: 'Room', value: `${c.room_no} · ${labelize(c.room_type)} · Floor ${c.floor_no}` },
                { label: 'Bed', value: c.bed_no },
                { label: 'Warden', value: c.warden_name ? <span className="inline-flex items-center gap-1.5">{c.warden_name}{c.warden_phone && <a href={`tel:${c.warden_phone}`} className="link inline-flex items-center gap-1 text-xs"><Phone className="h-3 w-3" />{c.warden_phone}</a>}</span> : null },
                { label: 'Annual fee', value: c.fee_amount ? formatMoney(c.fee_amount) : null },
                { label: 'Session', value: c.session_name },
              ]} />
            </CardBody>
          </Card>
        </Reveal>
      )}
      <TableCard<Allocation> title="Allocation history" icon={History} columns={cols} rows={rows} empty="No allocations" emptyIcon={History} delay={60} />
    </div>
  );
}
