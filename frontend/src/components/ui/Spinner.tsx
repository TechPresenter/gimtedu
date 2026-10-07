import clsx from 'clsx';

export function Spinner({ className }: { className?: string }) {
  return (
    <svg className={clsx('animate-spin', className ?? 'h-4 w-4')} viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" className="opacity-20" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}

export function PageLoader({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex min-h-[40vh] flex-col items-center justify-center gap-3 text-slate-500" role="status">
      <Spinner className="h-7 w-7 text-brand-700 dark:text-brand-300" />
      <span className="text-sm">{label}</span>
    </div>
  );
}
