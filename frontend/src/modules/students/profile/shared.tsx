import type { ReactNode } from 'react';
import clsx from 'clsx';
import type { LucideIcon } from 'lucide-react';
import { Alert, Button, Card, CardHeader, CardSkeleton, DataTable, EmptyState, Reveal, toneClasses, type Column, type Tone } from '@/components/ui';
import type { ApiError } from '@/lib/api';
import type { StudentProfile } from '../types';

/** Props every profile tab receives. */
export interface TabProps {
  profile: StudentProfile;
  studentId: number;
  onTab: (tab: string) => void;
  refresh: () => void;
}

export function TabLoading({ cards = 2 }: { cards?: number }) {
  return (
    <div className="grid gap-5 lg:grid-cols-2 [&>*]:min-w-0">
      {Array.from({ length: cards }).map((_, i) => (
        <CardSkeleton key={i} lines={5} />
      ))}
    </div>
  );
}

export function TabError({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <Alert variant="error" title="Unable to load this section" action={onRetry && <Button size="sm" variant="secondary" onClick={onRetry}>Retry</Button>}>
      {(error as ApiError)?.message ?? 'Something went wrong. Please try again.'}
    </Alert>
  );
}

/** Small KPI tile used inside tabs. */
export function MiniStat({ label, value, sub, icon: Icon, tone = 'blue' }: { label: string; value: ReactNode; sub?: ReactNode; icon: LucideIcon; tone?: Tone }) {
  return (
    <div className={clsx('h-full rounded-2xl border border-slate-200/70 p-4 dark:border-slate-800', toneClasses[tone].bg)}>
      <div className="flex items-center gap-3">
        <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', toneClasses[tone].icon)}>
          <Icon className="h-[18px] w-[18px]" aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="truncate text-xs font-medium text-slate-500 dark:text-slate-400">{label}</p>
          <p className="truncate font-display text-xl font-bold text-slate-900 dark:text-white">{value}</p>
        </div>
      </div>
      {sub && <div className="mt-2 truncate text-xs text-slate-500 dark:text-slate-400">{sub}</div>}
    </div>
  );
}

/** Card with a table (or an empty state when there are no rows). */
export function TableCard<T extends Record<string, unknown>>({ title, subtitle, icon, columns, rows, empty, emptyIcon, actions, delay = 0 }: {
  title: string; subtitle?: ReactNode; icon?: LucideIcon; columns: Column<T>[]; rows: T[]; empty: string; emptyIcon: LucideIcon; actions?: ReactNode; delay?: number;
}) {
  return (
    <Reveal delay={delay}>
      <Card className="overflow-hidden">
        <CardHeader title={title} subtitle={subtitle} icon={icon} actions={actions} />
        {rows.length ? (
          <DataTable<T> columns={columns} rows={rows} dense caption={title} />
        ) : (
          <EmptyState icon={emptyIcon} title={empty} className="!py-10" />
        )}
      </Card>
    </Reveal>
  );
}

/** Empty state for a whole tab whose source module has no data for this student yet. */
export function TabEmpty({ icon, title, description, action }: { icon: LucideIcon; title: string; description: string; action?: ReactNode }) {
  return (
    <Card>
      <EmptyState icon={icon} title={title} description={description} action={action} />
    </Card>
  );
}

export const dash = <span className="text-slate-400">—</span>;
