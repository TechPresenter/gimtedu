import { useMemo, useState } from 'react';
import { CalendarPlus, CheckCircle2, Wrench } from 'lucide-react';
import { Alert, Button, Card, DataTable, EmptyState, Select, StatusBadge, type Column } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatNumber, isoDate } from '@/lib/format';
import { MAINTENANCE_TYPES, daysText, useTransportRefresh } from '../shared';
import type { UpcomingService } from '../types';
import { CompleteJobModal, type JobTarget } from './CompleteJobModal';

const WINDOWS = [
  { value: '30', label: 'Next 30 days' },
  { value: '60', label: 'Next 60 days' },
  { value: '90', label: 'Next 90 days' },
];

/** Workshop jobs (scheduled / in progress) and services falling due by date or odometer. */
export function UpcomingServicesPanel() {
  const { can } = useAuth();
  const refresh = useTransportRefresh();
  const [days, setDays] = useState('30');
  const { data, isLoading, isFetching, error } = useApi<{ rows: UpcomingService[]; days: number }>(['transport', 'upcoming', days], 'transport/maintenance/upcoming', { days });
  const meta = useCrudMeta('transport_maintenance');
  const [job, setJob] = useState<JobTarget | null>(null);
  const [schedule, setSchedule] = useState<UpcomingService | null>(null);
  const scheduleDefaults = useMemo(
    () => (schedule ? { vehicle_id: schedule.vehicle_id, type: 'service', status: 'scheduled', service_date: schedule.due_date && schedule.due_date > isoDate() ? schedule.due_date : isoDate(), cost: 0, vendor: schedule.vendor ?? '' } : undefined),
    [schedule],
  );
  const rows = data?.rows ?? [];
  const counts = {
    workshop: rows.filter((r) => r.status === 'in_progress').length,
    overdue: rows.filter((r) => r.status === 'overdue').length,
    scheduled: rows.filter((r) => r.status === 'scheduled').length,
    due: rows.filter((r) => r.status === 'due').length,
  };

  const columns: Column<UpcomingService>[] = [
    {
      key: 'vehicle',
      header: 'Vehicle',
      render: (r) => (
        <div className="whitespace-nowrap leading-tight">
          <span className="font-semibold text-slate-900 dark:text-white">{r.vehicle_no}</span>
          <div className="max-w-[10rem] truncate text-xs text-slate-500 dark:text-slate-400" title={[r.model, r.route_code].filter(Boolean).join(' · ')}>{[r.model, r.route_code].filter(Boolean).join(' · ')}</div>
        </div>
      ),
    },
    {
      key: 'work',
      header: 'Work',
      render: (r) => (
        <div className="max-w-[14rem] leading-tight">
          <span className="font-medium text-slate-800 dark:text-slate-100">{MAINTENANCE_TYPES[r.type] ?? r.type}</span>
          {r.description && <div className="truncate text-xs text-slate-500 dark:text-slate-400" title={r.description}>{r.description}</div>}
        </div>
      ),
    },
    {
      key: 'due',
      header: 'Due',
      render: (r) => (
        <div className="whitespace-nowrap leading-tight">
          <span className="font-medium">{r.due_date ? formatDate(r.due_date) : '—'}</span>
          <div className={r.status === 'overdue' ? 'text-xs text-red-600 dark:text-red-400' : 'text-xs text-slate-500 dark:text-slate-400'}>
            {r.status === 'in_progress' ? `In workshop since ${daysText(r.days).replace(' ago', '')}` : r.due_date ? daysText(r.days) : ''}
            {r.due_km ? ` · at ${formatNumber(r.due_km)} km` : ''}
          </div>
        </div>
      ),
    },
    { key: 'odometer', header: 'Odometer', align: 'right', render: (r) => (r.odometer ? <span className="whitespace-nowrap tabular-nums">{formatNumber(r.odometer)} km</span> : '—') },
    { key: 'cost', header: 'Estimate', align: 'right', render: (r) => (r.cost ? <span className="whitespace-nowrap tabular-nums">{formatMoney(r.cost)}</span> : '—') },
    {
      key: 'status',
      header: 'Status',
      render: (r) => <StatusBadge status={r.status} colors={{ in_progress: 'purple', overdue: 'red', scheduled: 'blue', due: 'amber' }} label={r.status === 'in_progress' ? 'In workshop' : undefined} />,
    },
  ];

  return (
    <>
      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
          <div>
            <h2 className="card-title">Upcoming services</h2>
            <p className="card-subtitle">
              {counts.workshop} in workshop · {counts.overdue} overdue · {counts.scheduled} scheduled · {counts.due} falling due
            </p>
          </div>
          <Select inputSize="sm" value={days} onChange={(e) => setDays(e.target.value)} options={WINDOWS} aria-label="Look-ahead window" className="!w-40" />
        </div>
        {error ? (
          <div className="p-6"><Alert variant="error">Unable to load upcoming services.</Alert></div>
        ) : (
          <DataTable<UpcomingService>
            columns={columns}
            rows={rows}
            rowKey={(r) => `${r.source}-${r.id}`}
            loading={isLoading || isFetching}
            skeletonRows={5}
            caption="Upcoming services"
            empty={<EmptyState icon={Wrench} title="Nothing due" description={`No service or workshop job in the next ${days} days.`} />}
            actions={(r) =>
              r.source === 'record' && can('transport', 'edit') ? (
                <Button size="xs" variant="secondary" icon={CheckCircle2}
                  onClick={() => setJob({ id: r.id, vehicle_no: r.vehicle_no, type: r.type, service_date: r.due_date ?? isoDate(), cost: r.cost, vendor: r.vendor, description: r.description, odometer: r.odometer })}>
                  Complete
                </Button>
              ) : r.source === 'due' && can('transport', 'create') ? (
                <Button size="xs" variant="secondary" icon={CalendarPlus} onClick={() => setSchedule(r)}>Schedule</Button>
              ) : null
            }
          />
        )}
      </Card>
      <CompleteJobModal job={job} onClose={() => setJob(null)} />
      <CrudFormModal open={!!schedule} onClose={() => setSchedule(null)} meta={meta.data} module="transport_maintenance" title={`Schedule service — ${schedule?.vehicle_no ?? ''}`}
        defaults={scheduleDefaults} onSaved={() => refresh('transport_maintenance', 'vehicles')} />
    </>
  );
}
