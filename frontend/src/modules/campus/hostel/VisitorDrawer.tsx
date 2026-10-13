import { useState } from 'react';
import { Clock, LogIn, LogOut, Pencil, Printer, ShieldCheck } from 'lucide-react';
import { Alert, Avatar, Badge, Button, DescriptionList, Drawer, PageLoader, PersonCell, StatusBadge, Timeline, useConfirm, useToast, type TimelineItem } from '@/components/ui';
import { CrudFormModal, type CrudRecordPayload } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { useHostelRefresh } from './AllocationDialogs';
import { VISITOR_RELATIONS, minutesLabel, openHostelPrint } from './shared';

const ID_PROOFS: Record<string, string> = { aadhaar: 'Aadhaar card', driving_licence: 'Driving licence', voter_id: 'Voter ID', pan: 'PAN card', passport: 'Passport', other: 'Other' };
const VISIT_STATUS: Record<string, string> = { inside: 'Inside campus', left: 'Checked out', overstay: 'Overstaying (6h+)' };

/**
 * Visitor gate-pass detail (opened from the register, the "currently inside" list or the QR code on the printed pass):
 * visitor, resident, ID proof, timings and the gate actions — check out, print pass, edit.
 */
export function VisitorDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useHostelRefresh();
  const { data, isLoading, error } = useApi<CrudRecordPayload>(['hostel', 'visitor', id], `crud/hostel_visitors/${id}`, undefined, { enabled: !!id });
  const { data: meta } = useCrudMeta('hostel_visitors');
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const v = data?.row as Row | undefined;
  const canEdit = can('hostel', 'edit');
  const inside = !!v && !v.check_out;

  const checkout = async () => {
    if (!v) return;
    const ok = await confirm({ title: 'Check out visitor?', message: <>Record check-out of <strong>{String(v.visitor_name)}</strong> now?</>, confirmText: 'Check out' });
    if (!ok) return;
    setBusy(true);
    try {
      const res = await api.post(`hostel/visitors/${v.id}/checkout`, {});
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  const timeline: TimelineItem[] = [];
  if (v) {
    timeline.push({ icon: LogIn, tone: 'blue', title: <>Checked in{v.approved_by_name ? <> by <strong>{String(v.approved_by_name)}</strong></> : null}</>, time: formatDateTime(v.check_in) });
    if (v.check_out) timeline.push({ icon: LogOut, tone: 'green', title: 'Checked out', time: `${formatDateTime(v.check_out)} · visit of ${minutesLabel(Number(v.duration_minutes))}` });
    else timeline.push({ icon: Clock, tone: v.visit_status === 'overstay' ? 'red' : 'amber', title: 'Still inside', time: `${minutesLabel(Number(v.duration_minutes))} so far` });
  }

  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        title={v ? String(v.visitor_name) : 'Visitor'}
        description={v ? `${v.pass_no ?? `Entry #${v.id}`} · ${v.hostel_name}` : undefined}
        footer={
          v && (
            <div className="flex w-full flex-wrap justify-end gap-2">
              <Button variant="secondary" size="sm" icon={Printer} onClick={() => openHostelPrint(`visitor-pass/${v.id}`)}>Gate pass</Button>
              {canEdit && <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(true)}>Edit</Button>}
              {canEdit && inside && <Button size="sm" icon={LogOut} loading={busy} onClick={() => void checkout()}>Check out now</Button>}
            </div>
          )
        }
      >
        {error ? (
          <Alert variant="error" title="Unable to load the visitor entry">{(error as Error).message}</Alert>
        ) : isLoading || !v ? (
          <PageLoader label="Loading visitor…" />
        ) : (
          <div className="space-y-6">
            <div className="flex flex-wrap items-center gap-3">
              <Avatar name={String(v.visitor_name)} size="lg" />
              <div className="min-w-0 flex-1">
                <p className="font-semibold text-slate-900 dark:text-white">{String(v.visitor_name)}</p>
                <p className="text-sm text-slate-500 dark:text-slate-400">
                  {VISITOR_RELATIONS[String(v.relation)] ?? String(v.relation ?? 'Visitor')}
                  {Number(v.visitors_count) > 1 ? ` · ${v.visitors_count} persons` : ''}
                </p>
              </div>
              <StatusBadge status={String(v.visit_status)} label={VISIT_STATUS[String(v.visit_status)]} colors={{ inside: 'amber', left: 'green', overstay: 'red' }} />
            </div>
            {v.visit_status === 'overstay' && (
              <Alert variant="warning" title="Visitor has been inside for more than 6 hours">Confirm with the warden and record the check-out at the gate.</Alert>
            )}
            <section>
              <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><ShieldCheck className="h-4 w-4 text-slate-400" /> Visiting</h3>
              <div className="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <PersonCell name={String(v.student_name)} src={v.student_photo as string | null} sub={`${v.student_uid ?? ''} · ${v.resident_sub ?? v.hostel_code}`} />
              </div>
            </section>
            <DescriptionList
              items={[
                { label: 'Mobile', value: v.phone ? <a href={`tel:${String(v.phone).replace(/\s+/g, '')}`} className="link">{String(v.phone)}</a> : null },
                { label: 'ID proof', value: v.id_proof_type ? `${ID_PROOFS[String(v.id_proof_type)] ?? v.id_proof_type}${v.id_proof ? ` · ${v.id_proof}` : ''}` : (v.id_proof as string | null) },
                { label: 'Vehicle', value: v.vehicle_no ? <Badge color="slate">{String(v.vehicle_no)}</Badge> : null },
                { label: 'Gate pass', value: v.pass_no ? <span className="font-mono text-sm">{String(v.pass_no)}</span> : null },
                { label: 'Purpose of visit', value: (v.purpose as string | null) ?? null, full: true },
                ...(v.remarks ? [{ label: 'Remarks', value: String(v.remarks), full: true }] : []),
              ]}
            />
            <section>
              <h3 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Visit timeline</h3>
              <Timeline items={timeline} />
            </section>
          </div>
        )}
      </Drawer>
      <CrudFormModal open={editing && !!v} onClose={() => setEditing(false)} meta={meta} module="hostel_visitors" id={v ? Number(v.id) : null} onSaved={() => void refresh()} />
    </>
  );
}
