import { useState } from 'react';
import clsx from 'clsx';
import { BadgeIndianRupee, Briefcase, CalendarMinus, Eye, EyeOff, Landmark, ReceiptText, WalletCards } from 'lucide-react';
import { Alert } from '@/components/ui';
import { formatDate, formatMoney, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, InfoGrid, PageHead, Section } from '../../../components/Ui';
import { usePortalQuery } from '../../../lib/hooks';
import type { PayrollData } from '../../shared/types';
import { fpath } from '../../shared/ui';

export default function PayrollPage() {
  const { data, isLoading, error, refetch } = usePortalQuery<PayrollData>('faculty', ['payroll'], 'payroll');
  const [show, setShow] = useState(false);
  const days = (n: number) => `${Number.isInteger(n) ? n : n.toFixed(1)} day${n === 1 ? '' : 's'}`;

  return (
    <div>
      <PageHead area="faculty" title="Payroll" subtitle="Your salary reference and bank details as recorded by the HR office." crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'Payroll' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
          <div className="skeleton h-56 rounded-3xl lg:col-span-2" />
          <div className="skeleton h-56 rounded-2xl" />
          <div className="skeleton h-48 rounded-2xl lg:col-span-3" />
        </div>
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <section className="hero-navy relative isolate flex flex-col justify-between gap-6 overflow-hidden rounded-3xl p-5 text-white shadow-soft sm:p-6 lg:col-span-2">
              <div className="pattern-dots absolute inset-0 -z-10 opacity-40" aria-hidden />
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-[12px] font-semibold uppercase tracking-[0.16em] text-flame-300">Monthly gross salary</p>
                  <p className={clsx('mt-2 font-display text-4xl font-extrabold tracking-tight transition sm:text-5xl', !show && data.salary !== null && 'select-none blur-md')} aria-hidden={!show}>
                    {data.salary !== null ? formatMoney(data.salary) : 'Not recorded'}
                  </p>
                  {!show && data.salary !== null && <span className="sr-only">Hidden. Use the Show button to reveal.</span>}
                  {data.annual !== null && (
                    <p className={clsx('mt-1 text-sm text-white/75 transition', !show && 'select-none blur-sm')}>{formatMoney(data.annual)} per year (gross × 12)</p>
                  )}
                </div>
                {data.salary !== null && (
                  <button type="button" onClick={() => setShow((v) => !v)} className="glass inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-white" aria-pressed={show}>
                    {show ? <EyeOff className="h-3.5 w-3.5" aria-hidden /> : <Eye className="h-3.5 w-3.5" aria-hidden />} {show ? 'Hide' : 'Show'}
                  </button>
                )}
              </div>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {[
                  ['Employee ID', data.employee_id],
                  ['Employment', data.employment_type ? labelize(data.employment_type) : null],
                  ['Joined', data.joining_date ? formatDate(data.joining_date) : null],
                  ['Service', data.tenure_years !== null ? `${data.tenure_years} years` : null],
                ].map(([label, value]) => (
                  <div key={label} className="glass rounded-xl px-3 py-2">
                    <p className="text-[10.5px] font-semibold uppercase tracking-wider text-white/60">{label}</p>
                    <p className="truncate text-sm font-bold text-white">{value ?? '—'}</p>
                  </div>
                ))}
              </div>
            </section>

            <Section title="Bank & tax" icon={Landmark}>
              <InfoGrid
                cols={2}
                items={[
                  { label: 'Bank', value: data.bank.name, full: true },
                  { label: 'Account', value: data.bank.account ? <span className="font-mono tracking-wider">{data.bank.account}</span> : null },
                  { label: 'IFSC', value: data.bank.ifsc ? <span className="font-mono">{data.bank.ifsc}</span> : null },
                  { label: 'PAN', value: data.pan ? <span className="font-mono tracking-wider">{data.pan}</span> : null, full: true },
                ]}
              />
              <p className="mt-4 text-xs text-slate-500 dark:text-slate-400">Numbers are masked for your safety. To change bank or PAN details, send a correction request from My Profile.</p>
            </Section>
          </div>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <Section title="Leave this session" subtitle={`${formatDate(data.session.start)} – ${formatDate(data.session.end)}`} icon={Briefcase}>
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">Paid leave</p>
                  <p className="font-display text-2xl font-extrabold text-emerald-800 dark:text-emerald-200">{days(data.leave.paid)}</p>
                </div>
                <div className="rounded-xl bg-red-50 p-3 dark:bg-red-500/10">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-red-700 dark:text-red-300">Loss of pay</p>
                  <p className="font-display text-2xl font-extrabold text-red-700 dark:text-red-200">{days(data.leave.unpaid)}</p>
                </div>
              </div>
            </Section>

            <Section title="Loss of pay by month" icon={CalendarMinus} className="lg:col-span-2" bodyClassName="p-2 sm:p-3">
              {data.lop.length === 0 ? (
                <EmptyBlock compact icon={BadgeIndianRupee} title="No loss of pay this session" text="Approved Leave Without Pay days would be listed here month by month." />
              ) : (
                <div className="table-wrap">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Month</th>
                        <th className="text-right">LOP days</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.lop.map((m) => (
                        <tr key={m.month}>
                          <td className="font-semibold">{m.label}</td>
                          <td className="text-right tabular-nums">{days(m.days)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Section>
          </div>

          <Alert variant="info" title="About payslips">
            <span>
              The ERP keeps your monthly gross salary as a payroll reference only — the salary component breakup (basic, allowances, deductions) and payslips are issued by the accounts office. Contact them for a payslip or salary certificate.
            </span>
          </Alert>
          <div className="flex flex-wrap gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">
              <ReceiptText className="h-3.5 w-3.5" aria-hidden /> Only your own record is shown here.
            </span>
            <span className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 dark:border-slate-700">
              <WalletCards className="h-3.5 w-3.5" aria-hidden /> Figures are as recorded by HR; contact HR for corrections.
            </span>
          </div>
        </div>
      )}
    </div>
  );
}
