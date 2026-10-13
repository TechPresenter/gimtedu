import clsx from 'clsx';
import { Check } from 'lucide-react';
import type { WorkflowStep } from './types';

/** Exam workflow: Create -> Assign Subjects -> Schedule -> Allocate Students -> Enter Marks -> Verify -> Publish (clickable). */
export function WorkflowSteps({ steps, current, onSelect }: { steps: WorkflowStep[]; current: string; onSelect?: (key: string) => void }) {
  return (
    <ol className="flex gap-1 overflow-x-auto pb-1 scrollbar-none lg:grid lg:grid-cols-7 lg:gap-2 lg:overflow-visible lg:pb-0" aria-label="Exam workflow">
      {steps.map((s, i) => {
        const active = s.key === current;
        return (
          <li key={s.key} className="relative min-w-[7.25rem] flex-1 lg:min-w-0">
            {i < steps.length - 1 && (
              <span aria-hidden className={clsx('absolute left-[calc(50%+18px)] right-[calc(-50%+18px)] top-4 hidden h-0.5 rounded lg:block', s.done ? 'bg-accent-500' : 'bg-slate-200 dark:bg-slate-700')} />
            )}
            <button
              type="button"
              onClick={() => onSelect?.(s.key)}
              className={clsx(
                'group flex w-full flex-col items-center gap-1.5 rounded-xl px-2 py-2 text-center transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:hover:bg-slate-800/60',
                active && 'bg-brand-50/70 dark:bg-brand-500/10',
              )}
              aria-current={active ? 'step' : undefined}
            >
              <span
                className={clsx(
                  'relative z-[1] inline-flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold ring-4 ring-white transition dark:ring-slate-900',
                  s.done ? 'bg-accent-600 text-white' : active ? 'bg-brand-800 text-white ring-brand-100 dark:ring-brand-500/20' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                )}
              >
                {s.done ? <Check className="h-4 w-4" aria-hidden /> : i + 1}
              </span>
              <span className={clsx('text-xs font-semibold leading-tight', active ? 'text-brand-800 dark:text-white' : s.done ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400')}>
                {s.label}
              </span>
              <span className="line-clamp-2 text-[11px] leading-tight text-slate-500 dark:text-slate-400">{s.detail}</span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}
