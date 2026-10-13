import type { ReactNode } from 'react';
import { Globe, Star } from 'lucide-react';
import { Badge, StatusBadge } from '@/components/ui';
import { formatDate, formatMoney } from '@/lib/format';
import type { Row } from '@/lib/types';
import { BoundedPerson, JOB_TYPE_LABELS, PAYMENT_MODE_LABELS, TwoLine } from './shared';

type Renderers = Record<string, (row: Row) => ReactNode>;

/** Compact cell renderers shared by the module pages and the scoped tables on the alumni profile. */
export const donationRenderers: Renderers = {
  receipt_no: (r) => (
    <div className="whitespace-nowrap leading-tight">
      <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{String(r.receipt_no)}</code>
      <div className="mt-1 text-xs text-slate-500">{formatDate(r.donation_date)}</div>
    </div>
  ),
  donor_name: (r) => (
    <BoundedPerson name={Number(r.is_anonymous) ? `${r.donor_name} (anonymous)` : String(r.donor_name)} photo={r.donor_photo} sub={r.donor_sub} to={`/alumni/${r.alumni_id}`} />
  ),
  amount: (r) => <TwoLine title={<span className="tabular-nums">{formatMoney(r.amount)}</span>} sub={PAYMENT_MODE_LABELS[String(r.payment_mode)] ?? r.payment_mode} className="min-w-[6rem] text-right" />,
  purpose: (r) => <TwoLine title={<span className="font-medium">{String(r.purpose ?? '—')}</span>} sub={r.campaign} className="min-w-[9rem] max-w-[13rem]" />,
};

export const jobRenderers: Renderers = {
  title: (r) => <TwoLine title={String(r.title)} sub={[r.company, JOB_TYPE_LABELS[String(r.job_type)]].filter(Boolean).join(' · ')} className="min-w-[11rem] max-w-[15rem]" />,
  poster_name: (r) => (r.poster_name ? <BoundedPerson name={String(r.poster_name)} photo={r.poster_photo} sub={r.poster_sub} to={`/alumni/${r.posted_by_alumni_id}`} /> : <span className="text-xs text-slate-500">Alumni cell</span>),
  location: (r) => <span className="block max-w-[7rem]">{r.location ?? '—'}</span>,
};

export const storyRenderers: Renderers = {
  title: (r) => <TwoLine title={String(r.title)} sub={r.role_line} className="min-w-[12rem] max-w-[16rem]" />,
  alumni_name: (r) => <BoundedPerson name={String(r.alumni_name)} photo={r.alumni_photo} sub={r.alumni_sub} to={`/alumni/${r.alumni_id}`} />,
  status: (r) => (
    <div className="flex flex-wrap items-center gap-1">
      <StatusBadge status={String(r.status)} />
      {Number(r.show_on_website) === 1 && <Badge color="blue"><Globe className="h-3 w-3" /> Website</Badge>}
      {Number(r.is_featured) === 1 && <Badge color="amber"><Star className="h-3 w-3" /> Featured</Badge>}
    </div>
  ),
};

export const mentorshipRenderers: Renderers = {
  mentor_name: (r) => <BoundedPerson name={String(r.mentor_name)} photo={r.mentor_photo} sub={r.mentor_sub} to={`/alumni/${r.alumni_id}`} />,
  student_name: (r) => <BoundedPerson name={String(r.student_name)} photo={r.student_photo} sub={r.student_sub} />,
  area: (r) => <span className="block min-w-[7rem] max-w-[10rem]">{r.area ?? '—'}</span>,
};

/** Print-menu label for a donation: receipt, pledge acknowledgement or the void copy of a cancelled receipt. */
export const receiptLabel = (status: unknown) =>
  status === 'pledged' ? 'Print pledge acknowledgement' : status === 'cancelled' ? 'Print void receipt' : 'Print receipt';
