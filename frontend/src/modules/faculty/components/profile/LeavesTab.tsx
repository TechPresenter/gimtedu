import { useState } from 'react';
import { CalendarPlus } from 'lucide-react';
import { Button, Card, CardHeader, Reveal, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { daysLabel, employeeRef, type EmployeeType, type Person } from '../../hr';
import { LeaveBalanceCards } from '../LeaveBalance';
import { LeaveDecisionModal, LeaveDetailModal, leaveCellRenderers, leaveRowMenu, type LeaveDecision } from '../LeaveActions';
import { LeaveFormModal } from '../LeaveFormModal';

/** Leave balance + this employee's leave history with apply / approve / reject / cancel. */
export default function LeavesTab({ type, person, canApply }: { type: EmployeeType; person: Person; canApply: boolean }) {
  const { can } = useAuth();
  const toast = useToast();
  const [form, setForm] = useState<{ open: boolean; id: number | null }>({ open: false, id: null });
  const [viewId, setViewId] = useState<number | null>(null);
  const [decision, setDecision] = useState<{ d: LeaveDecision; row: Row } | null>(null);
  const preset = { ref: employeeRef(type, person.id), label: `${person.full_name} (${person.employee_id})`, sub: person.designation };
  return (
    <div className="space-y-4">
      <Reveal>
        <Card>
          <CardHeader
            title="Leave balance"
            subtitle="Current academic session · approved leave counts against the quota"
            actions={canApply ? <Button size="sm" icon={CalendarPlus} onClick={() => setForm({ open: true, id: null })}>Apply leave</Button> : undefined}
          />
          <div className="p-4">
            <LeaveBalanceCards type={type} id={person.id} />
          </div>
        </Card>
      </Reveal>
      <Reveal delay={60}>
        <CrudTable
          module="employee_leaves"
          title="Leave history"
          scope={{ employee_type: type, employee_id: person.id }}
          hideColumns={['employee_name', 'employee_type', 'department_name']}
          hideFilters={['employee_type', 'department_id']}
          addLabel="Apply leave"
          emptyTitle="No leave applications"
          emptyText="Leave applied by or for this employee will appear here."
          onCreate={() => setForm({ open: true, id: null })}
          onView={(r) => setViewId(Number(r.id))}
          onEdit={(r) => (r.status === 'pending' ? setForm({ open: true, id: Number(r.id) }) : toast.warning(`Only pending applications can be edited (this one is ${r.status}).`))}
          renderers={leaveCellRenderers}
          rowMenu={(r) => leaveRowMenu(r, (a) => can('faculty', a), (d, row) => setDecision({ d, row }))}
        />
      </Reveal>
      <LeaveFormModal open={form.open} leaveId={form.id} preset={form.id ? null : preset} onClose={() => setForm({ open: false, id: null })} />
      <LeaveDetailModal id={viewId} onClose={() => setViewId(null)} onEdit={(id) => { setViewId(null); setForm({ open: true, id }); }} />
      {decision && (
        <LeaveDecisionModal
          open
          decision={decision.d}
          ids={[Number(decision.row.id)]}
          subject={`${decision.row.leave_type_name} · ${formatDate(decision.row.from_date)} – ${formatDate(decision.row.to_date)} (${daysLabel(decision.row.days)})`}
          onClose={() => setDecision(null)}
        />
      )}
    </div>
  );
}
