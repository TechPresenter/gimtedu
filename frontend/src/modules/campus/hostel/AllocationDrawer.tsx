import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRightLeft, BedDouble, History, LogOut, MessageSquareWarning, MoreHorizontal, Printer, ReceiptIndianRupee, UserCheck, Users, XCircle } from 'lucide-react';
import { Alert, Avatar, Badge, Button, DescriptionList, Drawer, Dropdown, PageLoader, PersonCell, StatusBadge, Timeline, type TimelineItem } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatMoney, labelize } from '@/lib/format';
import type { Row } from '@/lib/types';
import { CancelModal, InvoiceModal, TransferModal, VacateModal } from './AllocationDialogs';
import { ALLOCATION_COLORS, ALLOCATION_STATUS, COMPLAINT_STATUS_COLORS, INVOICE_COLORS, INVOICE_LABELS, openHostelPrint } from './shared';

interface AllocationDetail {
  allocation: Row;
  history: Row[];
  roommates: Row[];
  complaints: Row[];
  visitors: Row[];
  default_invoice_amount: number;
}

type Action = 'vacate' | 'transfer' | 'cancel' | 'invoice' | null;

/** Slide-over with everything about one allocation: student, room, invoice, roommates, stay history, complaints, visitors + actions. */
export function AllocationDrawer({ id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged?: () => void }) {
  const { can } = useAuth();
  const { data, isLoading, error } = useApi<AllocationDetail>(['hostel', 'allocation', id], `hostel/allocations/${id}`, undefined, { enabled: !!id });
  const [action, setAction] = useState<Action>(null);
  const a = data?.allocation;
  const active = a?.status === 'active';
  const canEdit = can('hostel', 'edit');
  const invoiceable = active && (!a?.student_fee_id || a?.invoice_status === 'cancelled');

  const historyItems: TimelineItem[] = (data?.history ?? []).map((h) => ({
    icon: h.status === 'active' ? BedDouble : h.status === 'transferred' ? ArrowRightLeft : h.status === 'cancelled' ? XCircle : LogOut,
    tone: h.status === 'active' ? 'green' : h.status === 'transferred' ? 'purple' : h.status === 'cancelled' ? 'red' : 'slate',
    title: (
      <span>
        <strong>{h.hostel_code} Room {h.room_no}</strong>
        {h.bed_no ? ` · Bed ${h.bed_no}` : ''} <span className="text-slate-400">({h.allocation_no})</span>
      </span>
    ),
    description: h.vacate_reason ? String(h.vacate_reason) : undefined,
    time: `${formatDate(h.allocated_on)} → ${h.vacated_on ? formatDate(h.vacated_on) : 'present'} · ${ALLOCATION_STATUS[String(h.status)] ?? labelize(String(h.status))}`,
  }));

  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width="max-w-2xl"
        title={a ? `Allocation ${a.allocation_no ?? ''}` : 'Allocation'}
        description={a ? `${a.hostel_name} · ${a.session_name ?? ''}` : undefined}
        footer={
          a && (
            <div className="flex w-full flex-wrap items-center justify-end gap-2">
              <Button variant="secondary" size="sm" icon={Printer} onClick={() => openHostelPrint(`allotment/${a.id}`)}>Allotment letter</Button>
              {canEdit && active && (
                <>
                  <Button variant="secondary" size="sm" icon={ArrowRightLeft} onClick={() => setAction('transfer')}>Transfer</Button>
                  <Button size="sm" icon={LogOut} onClick={() => setAction('vacate')}>Vacate</Button>
                  <Dropdown
                    label="More actions"
                    triggerClassName="btn-icon"
                    trigger={<MoreHorizontal className="h-5 w-5" />}
                    items={[
                      invoiceable && { label: 'Raise hostel fee invoice', icon: ReceiptIndianRupee, onClick: () => setAction('invoice') },
                      { label: 'Cancel allocation', icon: XCircle, danger: true, onClick: () => setAction('cancel') },
                    ]}
                  />
                </>
              )}
            </div>
          )
        }
      >
        {error ? (
          <Alert variant="error" title="Unable to load the allocation">{(error as Error).message}</Alert>
        ) : isLoading || !a ? (
          <PageLoader label="Loading allocation…" />
        ) : (
          <div className="space-y-6">
            <div className="flex flex-wrap items-start gap-4">
              <div className="min-w-0 flex-1">
                <PersonCell
                  name={String(a.student_name)}
                  src={a.photo as string | null}
                  sub={`${a.student_uid} · ${[a.program_short, a.current_semester ? `Sem ${a.current_semester}` : null].filter(Boolean).join(' · ')}`}
                  to={can('students', 'view') ? `/students/${a.student_id}` : undefined}
                />
              </div>
              <div className="flex flex-wrap gap-1.5">
                <StatusBadge status={String(a.status)} label={ALLOCATION_STATUS[String(a.status)]} colors={ALLOCATION_COLORS} />
                {a.invoice_status ? (
                  <StatusBadge status={String(a.invoice_status)} label={`Fee ${INVOICE_LABELS[String(a.invoice_status)]?.toLowerCase() ?? a.invoice_status}`} colors={INVOICE_COLORS} />
                ) : (
                  active && <Badge color="slate">Fee not invoiced</Badge>
                )}
              </div>
            </div>

            {invoiceable && canEdit && (
              <Alert variant="warning" title="Hostel fee not invoiced" action={<Button size="sm" variant="secondary" icon={ReceiptIndianRupee} onClick={() => setAction('invoice')}>Raise invoice</Button>}>
                Default amount {formatMoney(data.default_invoice_amount)} (rent + mess).
              </Alert>
            )}

            <DescriptionList
              items={[
                { label: 'Hostel', value: `${a.hostel_name} (${a.hostel_code})` },
                { label: 'Room / bed', value: <span className="font-semibold">Room {a.room_no}{a.bed_no ? ` · Bed ${a.bed_no}` : ''}</span> },
                { label: 'Block / floor', value: [a.block_name, a.floor_label].filter(Boolean).join(' · ') },
                { label: 'Room type', value: `${a.room_type_label}${Number(a.is_ac) ? ' · AC' : ''}` },
                { label: 'Allotted from', value: formatDate(a.allocated_on) },
                { label: 'Allotted until', value: a.valid_until ? formatDate(a.valid_until) : null },
                ...(a.vacated_on ? [{ label: a.status === 'transferred' ? 'Transferred on' : a.status === 'cancelled' ? 'Cancelled on' : 'Vacated on', value: formatDate(a.vacated_on) }] : []),
                ...(a.vacate_reason ? [{ label: 'Reason', value: String(a.vacate_reason), full: true }] : []),
                { label: 'Room rent / year', value: a.fee_amount !== null ? formatMoney(a.fee_amount) : null },
                { label: 'Mess charges / year', value: a.mess_fee !== null ? formatMoney(a.mess_fee) : null },
                {
                  label: 'Hostel fee invoice',
                  value: a.invoice_no ? (
                    <span>
                      {can('fees', 'view') ? <Link to={`/fees/invoices?q=${encodeURIComponent(String(a.invoice_no))}`} className="link">{String(a.invoice_no)}</Link> : String(a.invoice_no)}
                      <span className="block text-xs text-slate-500">{formatMoney(a.invoice_amount)} · balance {formatMoney(a.invoice_balance)}{a.invoice_due ? ` · due ${formatDate(a.invoice_due)}` : ''}</span>
                    </span>
                  ) : null,
                },
                ...(a.transferred_from_no ? [{ label: 'Transferred from', value: String(a.transferred_from_no) }] : []),
                { label: 'Allotted by', value: a.allocated_by_name ? `${a.allocated_by_name} · ${formatDateTime(a.created_at)}` : formatDateTime(a.created_at) },
                ...(a.remarks ? [{ label: 'Remarks', value: String(a.remarks), full: true }] : []),
              ]}
            />

            <section>
              <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><Users className="h-4 w-4 text-slate-400" /> Roommates</h3>
              {data.roommates.length ? (
                <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {data.roommates.map((r) => (
                    <li key={String(r.id)} className="flex items-center gap-2.5 rounded-xl border border-slate-200 px-3 py-2 dark:border-slate-700">
                      <Avatar name={String(r.student_name)} src={r.photo as string | null} size="sm" />
                      <div className="min-w-0 text-sm">
                        <p className="truncate font-medium text-slate-800 dark:text-slate-100">{String(r.student_name)}</p>
                        <p className="truncate text-xs text-slate-500">Bed {String(r.bed_no ?? '—')} · {String(r.program_short ?? r.student_uid)}</p>
                      </div>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-slate-500">{active ? 'No roommates at present.' : '—'}</p>
              )}
            </section>

            <section>
              <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><History className="h-4 w-4 text-slate-400" /> Stay history</h3>
              <Timeline items={historyItems} empty="No hostel history." />
            </section>

            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
              <section>
                <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><MessageSquareWarning className="h-4 w-4 text-slate-400" /> Complaints</h3>
                {data.complaints.length ? (
                  <ul className="space-y-2">
                    {data.complaints.map((c) => (
                      <li key={String(c.id)} className="text-sm">
                        <Link to={`/hostel/complaints?view=${c.id}`} className="font-medium text-slate-800 hover:text-brand-700 dark:text-slate-100">{String(c.subject)}</Link>
                        <div className="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                          <StatusBadge status={String(c.status)} colors={COMPLAINT_STATUS_COLORS} dot={false} /> {formatDate(c.created_at)}
                        </div>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-sm text-slate-500">No complaints raised.</p>
                )}
              </section>
              <section>
                <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><UserCheck className="h-4 w-4 text-slate-400" /> Recent visitors</h3>
                {data.visitors.length ? (
                  <ul className="space-y-2">
                    {data.visitors.map((v) => (
                      <li key={String(v.id)} className="text-sm">
                        <p className="font-medium text-slate-800 dark:text-slate-100">{String(v.visitor_name)} <span className="font-normal text-slate-500">· {String(v.relation ?? '')}</span></p>
                        <p className="text-xs text-slate-500">{formatDateTime(v.check_in)}{v.check_out ? '' : ' · inside now'}</p>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-sm text-slate-500">No visitors recorded.</p>
                )}
              </section>
            </div>
          </div>
        )}
      </Drawer>
      <VacateModal allocation={action === 'vacate' ? a ?? null : null} onClose={() => setAction(null)} onDone={onChanged} />
      <TransferModal allocation={action === 'transfer' ? a ?? null : null} onClose={() => setAction(null)} onDone={onChanged} />
      <CancelModal allocation={action === 'cancel' ? a ?? null : null} onClose={() => setAction(null)} onDone={onChanged} />
      <InvoiceModal allocation={action === 'invoice' ? a ?? null : null} onClose={() => setAction(null)} onDone={onChanged} />
    </>
  );
}
