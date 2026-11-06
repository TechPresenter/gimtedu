import clsx from 'clsx';
import { Link } from 'react-router-dom';
import { Ban, CircleCheck, CircleDot, CircleX, ClipboardCheck, ClipboardX, Hourglass, RotateCcw, SkipForward, type LucideIcon } from 'lucide-react';
import { Badge } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import { formatDateTime } from '@/lib/format';
import { NODE_SPECS } from './nodeTypes';
import type { NodeType, Step } from './types';

const STATUS: Record<Step['status'], { label: string; icon: LucideIcon; ring: string; text: string }> = {
  ok: { label: 'Done', icon: CircleCheck, ring: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300', text: 'text-emerald-700 dark:text-emerald-300' },
  waiting: { label: 'Waiting', icon: Hourglass, ring: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300', text: 'text-amber-700 dark:text-amber-300' },
  skipped: { label: 'Skipped', icon: SkipForward, ring: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', text: 'text-slate-500 dark:text-slate-400' },
  failed: { label: 'Failed', icon: CircleX, ring: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300', text: 'text-red-700 dark:text-red-300' },
};

function stepIcon(type: string): LucideIcon {
  if (type === 'retry') return RotateCcw;
  if (type === 'cancel') return Ban;
  return NODE_SPECS[type as NodeType]?.icon ?? CircleDot;
}

/**
 * ERP admission enquiry of a Create / Link Contact step ("Also create an ERP admission enquiry"): created / linked
 * with its number and a link to Admissions › Enquiries, or "not created" when that part failed (the run went on).
 */
function EnquiryOutcome({ output, phone }: { output: Step['output']; phone?: string | null }) {
  const { can } = useAuth();
  const id = Number(output.enquiry_id) || 0;
  const no = typeof output.enquiry_no === 'string' && output.enquiry_no ? output.enquiry_no : id ? `#${id}` : '';
  const outcome = typeof output.enquiry === 'string' ? output.enquiry : null;
  if (outcome === 'failed') {
    return (
      <div className="mt-1.5 flex flex-wrap items-center gap-2">
        <Badge color="red">
          <ClipboardX className="h-3 w-3" aria-hidden /> ERP enquiry not created
        </Badge>
        <span className="text-[11px] text-slate-500 dark:text-slate-400">The contact is linked and the run continued.</span>
      </div>
    );
  }
  if (!id) return null;
  const digits = String(phone ?? '').replace(/\D/g, '').slice(-10);
  const created = outcome === 'ok' && output.enquiry_created === true;
  const label = outcome === 'ok' ? `ERP enquiry ${no} ${created ? 'created' : 'linked'}` : `Enquiry ${no}`;
  return (
    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
      <Badge color={outcome !== 'ok' ? 'slate' : created ? 'green' : 'blue'}>
        <ClipboardCheck className="h-3 w-3" aria-hidden /> {label}
      </Badge>
      {can('enquiries', 'view') && digits.length === 10 && (
        <Link to={`/enquiries?q=${digits}`} className="text-[11px] font-medium text-brand-700 hover:underline dark:text-brand-300">
          Open in Enquiries
        </Link>
      )}
    </div>
  );
}

/** Step-by-step trace of a run (execution history drawer and test results). `phone` = the run's contact (enquiry link). */
export function RunTimeline({ steps, compact, phone }: { steps: Step[]; compact?: boolean; phone?: string | null }) {
  if (!steps.length) return <p className="py-6 text-center text-sm text-slate-500">No steps recorded yet.</p>;
  return (
    <ol className="relative space-y-4 before:absolute before:bottom-3 before:left-[15px] before:top-3 before:w-px before:bg-slate-200 dark:before:bg-slate-800">
      {steps.map((s) => {
        const st = STATUS[s.status] ?? STATUS.ok;
        const Icon = stepIcon(s.node_type);
        const checks = Array.isArray(s.output.checks) ? (s.output.checks as string[]) : [];
        const facts: [string, string][] = [];
        if (s.output.template) facts.push(['Template', String(s.output.template)]);
        // a skipped repeat points at the message that was already sent (by this run or, within 10 minutes, another sender)
        if (s.output.message_id) facts.push([s.output.code === 'duplicate' || s.output.code === 'duplicate_recent' ? 'Earlier message' : 'Message', `#${s.output.message_id}`]);
        if (s.output.until && s.status === 'waiting') facts.push(['Until', formatDateTime(s.output.until)]);
        if (s.output.resumed_at) facts.push(['Resumed', formatDateTime(s.output.resumed_at)]);
        if (s.output.url) facts.push(['Request', `${s.output.method ?? ''} ${s.output.url}`]);
        if (s.output.status && typeof s.output.status === 'number') facts.push(['HTTP', String(s.output.status)]);
        return (
          <li key={s.id} className="relative flex gap-3">
            <span className={clsx('relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-slate-900', st.ring)}>
              <Icon className="h-4 w-4" aria-hidden />
            </span>
            <div className="min-w-0 flex-1 pt-0.5">
              <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <p className="text-sm font-semibold text-slate-900 dark:text-white">{s.title}</p>
                <span className={clsx('text-[11px] font-semibold uppercase tracking-wide', st.text)}>{st.label}</span>
                <span className="ml-auto text-[11px] text-slate-400">{formatDateTime(s.created_at)}</span>
              </div>
              {s.summary && <p className="text-[13px] text-slate-600 dark:text-slate-300">{s.summary}</p>}
              {s.node_type === 'contact' && <EnquiryOutcome output={s.output} phone={phone} />}
              {s.error &&<p className="mt-1 rounded-lg bg-red-50 px-2.5 py-1.5 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-200">{s.error}</p>}
              {checks.length > 0 && (
                <ul className="mt-1 space-y-0.5">
                  {checks.map((c, i) => (
                    <li key={i} className="font-mono text-[11px] text-slate-500 dark:text-slate-400">
                      {c}
                    </li>
                  ))}
                </ul>
              )}
              {!compact && facts.length > 0 && (
                <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                  {facts.map(([k, v]) => (
                    <div key={k} className="flex gap-1">
                      <dt className="font-medium">{k}:</dt>
                      <dd className="break-all">{v}</dd>
                    </div>
                  ))}
                </dl>
              )}
              {!compact && (s.output.body_preview || s.output.response_preview) ? (
                <details className="mt-1">
                  <summary className="cursor-pointer text-[11px] font-medium text-brand-700 dark:text-brand-300">{s.output.response_preview ? 'Response' : 'Request body'}</summary>
                  <pre className="mt-1 max-h-40 overflow-auto rounded-lg bg-slate-50 p-2 text-[11px] text-slate-700 dark:bg-slate-800 dark:text-slate-200">{String(s.output.response_preview ?? s.output.body_preview)}</pre>
                </details>
              ) : null}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
