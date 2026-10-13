import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Link, useLocation, useNavigationType } from 'react-router-dom';
import { Alert, Avatar, Button, Modal, PageLoader, prefersReducedMotion, type ModalSize } from '@/components/ui';
import { CrudFormFields, CrudTable, useCrudForm, type CrudTableProps } from '@/components/crud';
import type { BadgeColor } from '@/lib/status';
import { useCrudMeta } from '@/lib/queries';

/* ------------------------------------------------------------------ labels & colours (mirror app/services/alumni.php) */

export const EMPLOYMENT_LABELS: Record<string, string> = {
  employed: 'Employed', self_employed: 'Self-employed', entrepreneur: 'Entrepreneur', higher_studies: 'Higher studies', seeking: 'Seeking opportunities', other: 'Other',
};
export const EMPLOYMENT_COLORS: Record<string, BadgeColor> = {
  employed: 'green', self_employed: 'cyan', entrepreneur: 'purple', higher_studies: 'blue', seeking: 'amber', other: 'slate',
};
export const EVENT_TYPE_LABELS: Record<string, string> = {
  reunion: 'Batch reunion', homecoming: 'Homecoming', webinar: 'Webinar', chapter_meet: 'Chapter meet', networking: 'Networking evening', guest_lecture: 'Guest lecture',
  mentorship: 'Mentorship session', other: 'Other',
};
export const EVENT_TYPE_COLORS: Record<string, BadgeColor> = {
  reunion: 'blue', homecoming: 'navy', webinar: 'cyan', chapter_meet: 'purple', networking: 'green', guest_lecture: 'amber', mentorship: 'green', other: 'slate',
};
export const EVENT_MODE_LABELS: Record<string, string> = { in_person: 'In person', online: 'Online', hybrid: 'Hybrid' };
export const REG_STATUS_LABELS: Record<string, string> = { registered: 'Registered', attended: 'Attended', no_show: 'No show', cancelled: 'Cancelled' };
export const REG_STATUS_COLORS: Record<string, BadgeColor> = { registered: 'blue', attended: 'green', no_show: 'amber', cancelled: 'red' };
export const JOB_TYPE_LABELS: Record<string, string> = { full_time: 'Full time', part_time: 'Part time', internship: 'Internship', contract: 'Contract', remote: 'Remote' };
export const JOB_STATUS_LABELS: Record<string, string> = { pending: 'Pending approval', open: 'Open', expired: 'Expired', closed: 'Closed', rejected: 'Rejected' };
export const JOB_STATUS_COLORS: Record<string, BadgeColor> = { pending: 'amber', open: 'green', expired: 'red', closed: 'purple', rejected: 'red' };
export const PAYMENT_MODE_LABELS: Record<string, string> = { upi: 'UPI', neft: 'NEFT / RTGS', cheque: 'Cheque / DD', card: 'Card', online: 'Online gateway', cash: 'Cash' };
export const DONATION_STATUS_COLORS: Record<string, BadgeColor> = { received: 'green', pledged: 'amber', cancelled: 'red' };

/** Query keys live under ['crud', module, …] so CrudTable's own invalidation (save / delete / bulk) refreshes the stats too. */
export const statsKey = (module: string, name = 'stats') => ['crud', module, `__${name}`];

export function useRefreshModules() {
  const qc = useQueryClient();
  return (...modules: string[]) => Promise.all(modules.map((m) => qc.invalidateQueries({ queryKey: ['crud', m] })));
}

/**
 * urlState CrudTable that re-applies the URL when it changes through a link, notification, global search or back/forward
 * (e.g. "Top companies" → `/alumni?f.company=TCS` on the same page) and scrolls to the table. The table's own
 * replace-updates of the query string do not remount it. Only this wrapper subscribes to the location, so the page
 * (and its charts) do not re-render on every keystroke in the table search.
 */
export function LinkedCrudTable(props: Omit<CrudTableProps, 'urlState'>) {
  const location = useLocation();
  const navType = useNavigationType();
  const keyRef = useRef(location.key);
  if (navType !== 'REPLACE') keyRef.current = location.key;
  const key = keyRef.current;
  const wrapRef = useRef<HTMLDivElement>(null);
  const mounted = useRef(false);
  useEffect(() => {
    if (!mounted.current) {
      mounted.current = true;
      return;
    }
    if (/[?&](f\.|q=)/.test(window.location.search)) {
      wrapRef.current?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
    }
  }, [key]);
  return (
    <div ref={wrapRef} className="scroll-mt-20">
      <CrudTable key={key} {...props} urlState />
    </div>
  );
}

/** Width-bounded person cell for tables with many columns (keeps long company / program lines from stretching the table). */
export function BoundedPerson({ name, photo, sub, to, badge, className = 'min-w-[10rem] max-w-[12.5rem]' }: { name: string; photo?: string | null; sub?: ReactNode; to?: string; badge?: ReactNode; className?: string }) {
  return (
    <div className={clsx('flex items-center gap-3', className)}>
      <Avatar name={name} src={photo ?? null} />
      <div className="min-w-0 leading-tight">
        <div className="flex min-w-0 items-center gap-1.5">
          {to ? (
            <Link to={to} className="truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{name}</Link>
          ) : (
            <span className="truncate font-semibold text-slate-900 dark:text-white">{name}</span>
          )}
          {badge}
        </div>
        {sub && <div className="truncate text-xs text-slate-500 dark:text-slate-400" title={typeof sub === 'string' ? sub : undefined}>{sub}</div>}
      </div>
    </div>
  );
}

/** Two-line text cell (title + muted sub line), truncated to a max width. */
export function TwoLine({ title, sub, className = 'min-w-[9rem] max-w-[14rem]' }: { title: ReactNode; sub?: ReactNode; className?: string }) {
  if (!title && !sub) return <span className="text-slate-400">—</span>;
  return (
    <div className={clsx('leading-tight', className)}>
      <div className="truncate font-semibold text-slate-900 dark:text-white" title={typeof title === 'string' ? title : undefined}>{title || '—'}</div>
      {sub && <div className="truncate text-xs text-slate-500 dark:text-slate-400" title={typeof sub === 'string' ? sub : undefined}>{sub}</div>}
    </div>
  );
}

/** Tiny horizontal bar list used by several cards (top companies, cities, purposes …). */
export function BarList({ items, format, tone = 'bg-brand-600', max }: { items: { label: ReactNode; value: number; sub?: ReactNode; key?: string }[]; format?: (n: number) => string; tone?: string; max?: number }) {
  const top = max ?? Math.max(1, ...items.map((i) => i.value));
  return (
    <ul className="space-y-3">
      {items.map((it, i) => (
        <li key={it.key ?? i}>
          <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
            <span className="min-w-0 truncate font-medium text-slate-700 dark:text-slate-200">{it.label}</span>
            <span className="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{format ? format(it.value) : it.value.toLocaleString('en-IN')}</span>
          </div>
          <div className="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
            <div className={`h-full rounded-full ${tone} transition-[width] duration-700 ease-out`} style={{ width: `${Math.max(2, (it.value / top) * 100)}%` }} />
          </div>
          {it.sub && <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{it.sub}</p>}
        </li>
      ))}
    </ul>
  );
}

/**
 * Create form for a CRUD module with some fields fixed (e.g. the donor when adding a donation from an alumni profile).
 * Fixed fields are hidden and sent with the payload; `context` shows what they are fixed to.
 */
export function FixedCrudFormModal({
  open, onClose, module, fixed, context, title, size, onSaved,
}: {
  open: boolean;
  onClose: () => void;
  module: string;
  fixed: Record<string, unknown>;
  context?: ReactNode;
  title?: string;
  size?: ModalSize;
  onSaved?: (id: number) => void;
}) {
  const { data: meta } = useCrudMeta(module);
  const [defaults, setDefaults] = useState<Record<string, unknown>>(fixed);
  useEffect(() => {
    if (open) setDefaults({ ...fixed });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);
  const state = useCrudForm({
    meta: open ? meta : undefined,
    module,
    defaults,
    onSaved: (_row, id) => {
      onSaved?.(id);
      onClose();
    },
  });
  const formId = `fixed-form-${module}`;
  const fields = state.visibleFields.filter((f) => !(f.name in fixed));
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={state.saving}
      size={size ?? meta?.form.size ?? 'lg'}
      title={title ?? `Add ${meta?.singular?.toLowerCase() ?? 'record'}`}
      description="Fill in the details below. Fields marked * are required."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={state.saving}>Cancel</Button>
          <Button type="submit" form={formId} loading={state.saving}>Save</Button>
        </>
      }
    >
      {!meta ? (
        <PageLoader />
      ) : (
        <form id={formId} onSubmit={(e: FormEvent) => void state.submit(e)} noValidate className="space-y-4">
          {context}
          {state.formError && <Alert variant="error">{state.formError}</Alert>}
          {Object.keys(fixed).map((k) => state.errors[k] && <Alert key={k} variant="error">{state.errors[k]}</Alert>)}
          <CrudFormFields state={state} fields={fields} />
        </form>
      )}
    </Modal>
  );
}
