import type { HTMLAttributes, ReactNode } from 'react';
import clsx from 'clsx';
import type { LucideIcon } from 'lucide-react';

export function Card({ className, children, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={clsx('card', className)} {...rest}>
      {children}
    </div>
  );
}

interface CardHeaderProps {
  title: ReactNode;
  subtitle?: ReactNode;
  icon?: LucideIcon;
  actions?: ReactNode;
  className?: string;
}

export function CardHeader({ title, subtitle, icon: Icon, actions, className }: CardHeaderProps) {
  return (
    <div className={clsx('card-header', className)}>
      <div className="flex min-w-0 items-start gap-3">
        {Icon && (
          <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
            <Icon className="h-4 w-4" />
          </span>
        )}
        <div className="min-w-0">
          <h2 className="card-title">{title}</h2>
          {subtitle && <p className="card-subtitle">{subtitle}</p>}
        </div>
      </div>
      {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </div>
  );
}

export function CardBody({ className, children, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={clsx('card-body', className)} {...rest}>
      {children}
    </div>
  );
}

export function CardFooter({ className, children }: { className?: string; children: ReactNode }) {
  return <div className={clsx('flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3.5 dark:border-slate-800', className)}>{children}</div>;
}
