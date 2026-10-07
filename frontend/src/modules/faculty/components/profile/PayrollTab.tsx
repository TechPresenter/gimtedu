import { useState } from 'react';
import { Banknote, Building, CalendarMinus, Eye, EyeOff, IndianRupee, Landmark, Pencil, ShieldCheck, Wallet } from 'lucide-react';
import { Alert, Button, Card, CardHeader, CountUp, DataTable, DescriptionList, EmptyState, Reveal, StatTile, Stagger } from '@/components/ui';
import { formatMoney, formatMoneyShort, labelize, toNumber } from '@/lib/format';
import { daysLabel, type EmployeeType, type ProfilePayload } from '../../hr';

/** Payroll reference: salary, bank (masked), PAN (masked) and loss-of-pay days from unpaid leave. */
export default function PayrollTab({ type, data, onEdit }: { type: EmployeeType; data: ProfilePayload; onEdit?: () => void }) {
  const p = data.person;
  const [reveal, setReveal] = useState(false);
  const salary = toNumber(p.salary);
  const lopDays = data.lop.reduce((a, r) => a + r.days, 0);
  const perDay = salary ? salary / 30 : 0;
  const show = (raw: string | null, masked: string | null) => (reveal && data.can.sensitive ? raw : masked);
  return (
    <div className="space-y-4">
      <Alert variant="info" title="Payroll reference only">
        Salary processing and payslips are handled by Accounts. Figures below are the monthly gross on record and leave-without-pay days for session {data.session.name}.
      </Alert>
      <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatTile label="Monthly gross" value={salary ? <CountUp value={salary} format={(n) => formatMoney(n)} /> : '—'} icon={IndianRupee} tone="navy" sub="Gross on record" />
        <StatTile label="Annual (12 months)" value={salary ? <CountUp value={salary * 12} format={formatMoneyShort} /> : '—'} icon={Wallet} tone="green" sub="Before deductions" />
        <StatTile label="LOP days (session)" value={<CountUp value={lopDays} />} icon={CalendarMinus} tone={lopDays ? 'red' : 'slate'} sub={lopDays && perDay ? `≈ ${formatMoney(lopDays * perDay)} deduction` : 'No unpaid leave'} />
        <StatTile label="Employment" value={labelize(p.employment_type)} icon={Building} tone="purple" sub={p.joining_date ? `Since ${new Date(`${p.joining_date}T00:00:00`).getFullYear()}` : undefined} />
      </Stagger>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Reveal>
          <Card className="h-full">
            <CardHeader
              title="Bank & tax details"
              icon={Landmark}
              subtitle={data.can.sensitive ? 'Masked by default — reveal only when needed.' : 'Masked for your role.'}
              actions={
                <div className="flex gap-1">
                  {data.can.sensitive && (p.bank_account || p.pan_no) && (
                    <Button size="sm" variant="secondary" icon={reveal ? EyeOff : Eye} onClick={() => setReveal((v) => !v)}>
                      {reveal ? 'Hide' : 'Reveal'}
                    </Button>
                  )}
                  {onEdit && <Button size="sm" variant="secondary" icon={Pencil} onClick={onEdit}>Edit</Button>}
                </div>
              }
            />
            <div className="card-body">
              {!p.bank_account && !p.pan_no && !p.bank_name ? (
                <EmptyState icon={Banknote} title="No bank details" description={type === 'faculty' ? 'Visiting faculty may be paid by honorarium.' : 'Add bank details for salary credit.'} className="!py-6" />
              ) : (
                <DescriptionList
                  items={[
                    { label: 'Bank', value: p.bank_name },
                    { label: 'IFSC', value: p.bank_ifsc ? <code className="font-semibold">{p.bank_ifsc}</code> : null },
                    { label: 'Account number', value: p.bank_account ? <code className="font-semibold tracking-wider">{show(p.bank_account, p.bank_account_masked)}</code> : null },
                    { label: 'PAN', value: p.pan_no ? <code className="font-semibold tracking-wider">{show(p.pan_no, p.pan_masked)}</code> : null },
                  ]}
                />
              )}
              <p className="mt-5 flex items-center gap-1.5 text-xs text-slate-500"><ShieldCheck className="h-3.5 w-3.5 text-emerald-600" /> Sensitive values are masked in lists, exports and for roles without edit rights.</p>
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full overflow-hidden">
            <CardHeader title="Loss of pay by month" subtitle="Approved leave without pay" icon={CalendarMinus} />
            <DataTable
              columns={[
                { key: 'label', header: 'Month' },
                { key: 'days', header: 'LOP days', align: 'right', render: (r) => daysLabel(r.days) },
                { key: 'amt', header: 'Approx. deduction', align: 'right', render: (r) => (perDay ? formatMoney(r.days * perDay) : '—') },
              ]}
              rows={data.lop.map((r) => ({ ...r, id: r.month }))}
              rowKey={(r) => r.month}
              dense
              caption="Loss of pay by month"
              empty={<EmptyState icon={CalendarMinus} title="No loss of pay" description="No unpaid leave approved this session." className="!py-8" />}
            />
          </Card>
        </Reveal>
      </div>
    </div>
  );
}
