import { BadgeCheck, Bus, IdCard, Phone, ShieldAlert, ShieldQuestion, UserCog, Users } from 'lucide-react';
import { Badge, PageHeader, PersonCell, StatTile, Stagger } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useCrudList } from '@/lib/queries';
import clsx from 'clsx';
import { formatNumber, labelize } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ExpiryCell, SummaryStrip, useTransportRefresh } from './transport/shared';

const ROLES: Record<string, string> = { driver: 'Driver', conductor: 'Conductor', helper: 'Attendant' };
const ROLE_TONE: Record<string, string> = {
  driver: 'text-brand-700 dark:text-brand-300',
  conductor: 'text-sky-700 dark:text-sky-300',
  helper: 'text-cyan-700 dark:text-cyan-300',
};

interface DriverSummary {
  total: number;
  drivers: number;
  crew: number;
  expired: number;
  due: number;
  unverified: number;
  unassigned: number;
  alert_days: number;
}

export default function DriversPage() {
  const refresh = useTransportRefresh();
  const summaryQ = useCrudList('drivers', { per_page: 1 });
  const s = summaryQ.data?.summary as DriverSummary | undefined;
  const alertDays = s?.alert_days ?? 30;

  const rowMenu = (r: Row) => [
    r.phone && { label: `Call ${r.phone}`, icon: Phone, href: `tel:${String(r.phone).replace(/\s/g, '')}` },
    r.emergency_phone && { label: 'Call emergency contact', icon: Phone, href: `tel:${String(r.emergency_phone).replace(/\s/g, '')}` },
    r.vehicle_no && { label: `Open vehicle ${r.vehicle_no}`, icon: Bus, to: `/transport/vehicles?q=${encodeURIComponent(String(r.vehicle_no))}` },
  ];

  return (
    <>
      <PageHeader
        title="Drivers & Crew"
        description="Drivers, conductors and lady attendants — licences, police verification and vehicle assignment."
        breadcrumbs={[{ label: 'Transport', to: '/transport' }, { label: 'Drivers' }]}
      />

      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4" step={60}>
        {[
          <StatTile key="d" label="Active drivers" value={s?.drivers ?? 0} icon={IdCard} tone="navy" loading={summaryQ.isLoading} sub={s ? `${s.unassigned} without a vehicle (relief)` : undefined} />,
          <StatTile key="c" label="Conductors & attendants" value={s?.crew ?? 0} icon={Users} tone="blue" loading={summaryQ.isLoading} sub="On buses, mini buses and vans" />,
          <StatTile key="l" label="Licences" value={s ? `${s.expired} expired` : '—'} icon={ShieldAlert} tone={s?.expired ? 'red' : 'green'} loading={summaryQ.isLoading}
            sub={s ? `${s.due} expiring within ${s.alert_days} days` : undefined} />,
          <StatTile key="p" label="Police verification" value={s ? `${s.unverified} pending` : '—'} icon={ShieldQuestion} tone={s?.unverified ? 'amber' : 'green'} loading={summaryQ.isLoading}
            sub="Mandatory for school/college transport" />,
        ]}
      </Stagger>

      <CrudTable
        module="drivers"
        urlState
        title="Crew directory"
        addLabel="Add driver / crew"
        rowMenu={rowMenu}
        onSaved={() => refresh('drivers', 'vehicles')}
        emptyText="Register drivers, conductors and attendants with their licence details."
        renderers={{
          name: (r) => (
            <div className="whitespace-nowrap">
              <PersonCell
                name={String(r.name)}
                src={r.photo}
                sub={
                  <span className="inline-flex items-center gap-1.5">
                    <span className={clsx('font-semibold', ROLE_TONE[String(r.type)] ?? 'text-slate-600 dark:text-slate-300')}>{ROLES[String(r.type)] ?? labelize(String(r.type))}</span>
                    {r.phone && <span>· {r.phone}</span>}
                  </span>
                }
              />
            </div>
          ),
          license_no: (r) => (
            <div className="whitespace-nowrap leading-tight">
              <span className="font-mono text-xs font-semibold text-slate-800 dark:text-slate-100">{r.license_no}</span>
              <div className="text-xs text-slate-500 dark:text-slate-400">
                {[r.license_type === 'conductor' ? 'Conductor' : r.license_type === 'badge' ? 'Attendant badge' : r.license_type, r.experience_years !== null && r.experience_years !== undefined && r.experience_years !== '' ? `${r.experience_years} yrs exp.` : null].filter(Boolean).join(' · ') || '—'}
              </div>
            </div>
          ),
          license_expiry: (r) => <ExpiryCell date={r.license_expiry} alertDays={alertDays} />,
          vehicle_no: (r) =>
            r.vehicle_no ? (
              <div className="whitespace-nowrap leading-tight">
                <span className="font-semibold text-slate-900 dark:text-white">{r.vehicle_no}</span>
                {r.route_code && <div className="text-xs text-slate-500 dark:text-slate-400">Route {r.route_code}</div>}
              </div>
            ) : r.status === 'active' ? (
              <Badge color="amber"><UserCog className="h-3 w-3" /> Relief</Badge>
            ) : (
              <span className="text-slate-400">—</span>
            ),
          police_verified: (r) =>
            Number(r.police_verified) ? (
              <Badge color="green"><BadgeCheck className="h-3 w-3" /> Verified</Badge>
            ) : (
              <Badge color="amber">Pending</Badge>
            ),
        }}
        header={({ summary }) => {
          const sm = summary as DriverSummary | null | undefined;
          return sm ? (
            <SummaryStrip
              items={[
                { label: 'People', value: formatNumber(sm.total) },
                { label: 'Licences expired', value: formatNumber(sm.expired), tone: sm.expired ? 'red' : 'green' },
                { label: `Expiring in ${sm.alert_days} days`, value: formatNumber(sm.due), tone: sm.due ? 'amber' : 'green' },
                { label: 'Verification pending', value: formatNumber(sm.unverified), tone: sm.unverified ? 'amber' : 'green' },
              ]}
            />
          ) : null;
        }}
      />
    </>
  );
}
