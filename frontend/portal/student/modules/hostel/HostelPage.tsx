import { useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { BedDouble, Building2, CheckCircle2, Clock, Layers, MapPin, MessageSquareWarning, Phone, Plus, Snowflake, Sparkles, UserRound, Users, Wrench } from 'lucide-react';
import { Button, Field, Input, Modal, Select, Stagger, Textarea } from '@/components/ui';
import { formatDate, labelize, timeAgo } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead, PortalAvatar, Section } from '../../../components/Ui';
import { fieldErrors } from '../../../lib/errors';
import { Chip, ListSkeleton, MiniStat, StatusBadge, money, useStudent, useStudentPost } from '../../shared/ui';
import type { StudentHostel } from '../../shared/types';

function ComplaintModal({ open, onClose, data }: { open: boolean; onClose: () => void; data: StudentHostel }) {
  const [form, setForm] = useState({ category: '', priority: 'medium', subject: '', description: '' });
  const post = useStudentPost<typeof form>('hostel/complaints', {
    invalidate: [['hostel'], ['overview']],
    onSuccess: () => {
      setForm({ category: '', priority: 'medium', subject: '', description: '' });
      onClose();
    },
  });
  const errors = fieldErrors(post.error);
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const submit = (e: FormEvent) => {
    e.preventDefault();
    post.mutate(form);
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Raise a hostel complaint"
      description={data.allocation ? `${data.allocation.hostel} · Room ${data.allocation.room_no}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="hostel-complaint" loading={post.isPending} icon={MessageSquareWarning}>
            Submit complaint
          </Button>
        </>
      }
    >
      <form id="hostel-complaint" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2" noValidate>
        <Field label="Category" required error={errors.category} htmlFor="hc-cat">
          <Select id="hc-cat" value={form.category} onChange={set('category')} options={data.categories} placeholder="Choose…" invalid={!!errors.category} />
        </Field>
        <Field label="Priority" required error={errors.priority} htmlFor="hc-pri" hint="Use Urgent only for safety or security issues.">
          <Select id="hc-pri" value={form.priority} onChange={set('priority')} options={data.priorities} invalid={!!errors.priority} />
        </Field>
        <Field label="What's the problem?" required error={errors.subject} htmlFor="hc-sub" className="sm:col-span-2" aside={`${form.subject.length}/190`}>
          <Input id="hc-sub" value={form.subject} onChange={set('subject')} maxLength={190} placeholder="e.g. Tap leaking in the washroom" invalid={!!errors.subject} />
        </Field>
        <Field label="Details" required error={errors.description} htmlFor="hc-desc" className="sm:col-span-2" aside={`${form.description.length}/2000`}>
          <Textarea id="hc-desc" rows={4} value={form.description} onChange={set('description')} maxLength={2000} placeholder="Where exactly, since when, anything the warden should know…" invalid={!!errors.description} />
        </Field>
      </form>
    </Modal>
  );
}

export default function StudentHostelPage() {
  const { data, isLoading, error, refetch } = useStudent<StudentHostel>(['hostel'], 'hostel');
  const [open, setOpen] = useState(false);
  const a = data?.allocation;

  return (
    <div>
      <PageHead
        area="student"
        title="Hostel"
        subtitle="Your room, roommates, warden and complaints."
        crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Hostel' }]}
        actions={
          a ? (
            <button type="button" className="btn btn-primary btn-sm" onClick={() => setOpen(true)}>
              <Plus className="h-4 w-4" aria-hidden /> Raise complaint
            </button>
          ) : undefined
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-5">
          <div className="skeleton h-44 rounded-3xl" />
          <ListSkeleton rows={3} />
        </div>
      ) : !a ? (
        <div className="space-y-5">
          <div className="card">
            <EmptyBlock icon={BedDouble} title="Hostel not allotted" text="You don't have a hostel room this session. To apply for accommodation, contact the hostel office or the warden." />
          </div>
          {data.history.length > 0 && (
            <Section title="Previous stays" icon={Clock} bodyClassName="p-2 sm:p-3">
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.history.map((h) => (
                  <li key={h.allocation_no} className="flex items-center justify-between gap-3 px-2 py-2.5 text-sm">
                    <span className="font-semibold text-slate-800 dark:text-slate-100">
                      {h.hostel_name} · Room {h.room_no}
                    </span>
                    <span className="text-xs text-slate-500">
                      {formatDate(h.allocated_on)} – {h.vacated_on ? formatDate(h.vacated_on) : labelize(h.status)}
                    </span>
                  </li>
                ))}
              </ul>
            </Section>
          )}
        </div>
      ) : (
        <div className="space-y-5">
          <section className="hero-navy relative isolate overflow-hidden rounded-3xl p-5 text-white shadow-soft sm:p-7">
            <div className="pattern-grid absolute inset-0 -z-10 opacity-50 [mask-image:radial-gradient(120%_100%_at_100%_0%,black,transparent_70%)]" aria-hidden />
            <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
              <div>
                <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-flame-300">
                  {labelize(a.hostel_type)} hostel · {a.allocation_no}
                </p>
                <h2 className="mt-1.5 font-display text-2xl font-extrabold text-white sm:text-[28px]">{a.hostel}</h2>
                <p className="mt-1 text-sm text-white/75">
                  {[a.block, a.floor, `Room ${a.room_no}`, a.bed_no ? `Bed ${a.bed_no}` : null].filter(Boolean).join(' · ')}
                </p>
                <div className="mt-4 flex flex-wrap gap-2">
                  <span className="glass inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold">
                    <Users className="h-3.5 w-3.5 text-flame-300" aria-hidden /> {labelize(a.room_type)} · {a.capacity} beds
                  </span>
                  {a.is_ac && (
                    <span className="glass inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold">
                      <Snowflake className="h-3.5 w-3.5 text-flame-300" aria-hidden /> AC room
                    </span>
                  )}
                  <span className="glass inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold">
                    <Clock className="h-3.5 w-3.5 text-flame-300" aria-hidden /> Since {formatDate(a.allocated_on)}
                  </span>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2.5 sm:w-[360px]">
                <div className="glass rounded-2xl p-3">
                  <p className="text-[10px] font-semibold uppercase tracking-wider text-white/60">Room</p>
                  <p className="font-display text-2xl font-extrabold">{a.room_no}</p>
                </div>
                <div className="glass rounded-2xl p-3">
                  <p className="text-[10px] font-semibold uppercase tracking-wider text-white/60">Valid till</p>
                  <p className="font-display text-lg font-extrabold">{formatDate(a.valid_until)}</p>
                </div>
              </div>
            </div>
          </section>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div className="space-y-5 lg:col-span-2">
              <Section title="Roommates" subtitle="First names only, for privacy" icon={Users}>
                {data.roommates.length === 0 ? (
                  <EmptyBlock compact icon={UserRound} title="You have the room to yourself" text="No one else is allotted to this room right now." />
                ) : (
                  <Stagger className="grid grid-cols-1 gap-3 sm:grid-cols-2" itemClassName="h-full">
                    {data.roommates.map((r, i) => (
                      <div key={i} className="flex items-center gap-3 rounded-2xl border border-slate-200/80 p-3 dark:border-slate-800">
                        <PortalAvatar name={r.first_name} />
                        <div className="min-w-0">
                          <p className="font-semibold text-slate-900 dark:text-white">{r.first_name}</p>
                          <p className="text-xs text-slate-500">
                            {r.program} · Sem {r.semester}
                            {r.bed_no ? ` · Bed ${r.bed_no}` : ''}
                          </p>
                        </div>
                      </div>
                    ))}
                  </Stagger>
                )}
              </Section>

              <Section
                title="My complaints"
                subtitle="Track maintenance and other requests"
                icon={Wrench}
                bodyClassName="p-2 sm:p-3"
                action={
                  <button type="button" className="btn btn-soft btn-sm" onClick={() => setOpen(true)}>
                    <Plus className="h-4 w-4" aria-hidden /> New
                  </button>
                }
              >
                {data.complaints.length === 0 ? (
                  <EmptyBlock compact icon={CheckCircle2} title="No complaints raised" text="Something not working in your room? Raise a complaint and track it here." />
                ) : (
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.complaints.map((c) => (
                      <li key={c.id} className="px-2 py-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <div className="min-w-0">
                            <p className="text-sm font-semibold text-slate-900 dark:text-white">{c.subject}</p>
                            <p className="text-xs text-slate-500">
                              {c.complaint_no} · {c.category_label} · {c.location} · {timeAgo(c.created_at)}
                            </p>
                          </div>
                          <div className="flex items-center gap-1.5">
                            <StatusBadge status={c.priority} />
                            <StatusBadge status={c.status} />
                          </div>
                        </div>
                        {c.description && <p className="mt-1.5 line-clamp-2 text-[13px] text-slate-600 dark:text-slate-300">{c.description}</p>}
                        {c.resolution && (
                          <p className="mt-2 rounded-xl bg-emerald-50 px-3 py-2 text-[13px] text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
                            <strong>Resolution:</strong> {c.resolution}
                          </p>
                        )}
                        {!c.resolution && c.assigned && c.status !== 'resolved' && <p className="mt-1.5 text-xs text-slate-500">Assigned to hostel staff.</p>}
                      </li>
                    ))}
                  </ul>
                )}
              </Section>
            </div>

            <div className="space-y-5">
              <Section title="Warden & office" icon={Building2}>
                <div className="space-y-3 text-sm">
                  {a.warden && (
                    <div className="flex items-center gap-3">
                      <PortalAvatar name={a.warden} size="sm" />
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-900 dark:text-white">{a.warden}</p>
                        <p className="text-xs text-slate-500">Warden</p>
                      </div>
                    </div>
                  )}
                  {a.warden_phone && (
                    <a href={`tel:${a.warden_phone.replace(/[^\d+]/g, '')}`} className="btn btn-secondary btn-sm w-full">
                      <Phone className="h-4 w-4" aria-hidden /> Call {a.warden_phone}
                    </a>
                  )}
                  {a.visiting_hours && (
                    <p className="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                      <Clock className="h-4 w-4 text-slate-400" aria-hidden /> Visiting hours: {a.visiting_hours}
                    </p>
                  )}
                  {a.address && (
                    <p className="flex items-start gap-2 text-slate-600 dark:text-slate-300">
                      <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden /> {a.address}
                    </p>
                  )}
                </div>
              </Section>
              <Section title="Fees" icon={Layers}>
                <div className="grid grid-cols-2 gap-2.5">
                  <MiniStat label="Room rent" value={a.fee_amount !== null ? money(a.fee_amount) : '—'} sub={a.session ? `Session ${a.session}` : undefined} />
                  <MiniStat label="Mess" value={a.mess_fee !== null ? money(a.mess_fee) : '—'} sub="per year" />
                </div>
                <p className="mt-3 text-xs text-slate-500">Hostel invoices are listed under Fees &amp; Payments.</p>
              </Section>
              {(a.amenities.length > 0 || a.room_amenities.length > 0) && (
                <Section title="Amenities" icon={Sparkles}>
                  {a.room_amenities.length > 0 && (
                    <>
                      <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">In your room</p>
                      <div className="mb-4 flex flex-wrap gap-1.5">
                        {a.room_amenities.map((x) => (
                          <Chip key={x}>{x}</Chip>
                        ))}
                      </div>
                    </>
                  )}
                  <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">In the hostel</p>
                  <div className={clsx('flex flex-wrap gap-1.5')}>
                    {a.amenities.map((x) => (
                      <Chip key={x}>{x}</Chip>
                    ))}
                  </div>
                </Section>
              )}
            </div>
          </div>
        </div>
      )}
      {data && a && <ComplaintModal open={open} onClose={() => setOpen(false)} data={data} />}
    </div>
  );
}
