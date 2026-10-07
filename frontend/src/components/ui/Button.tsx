import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import type { LucideIcon } from 'lucide-react';
import { Spinner } from './Spinner';

export type ButtonVariant = 'primary' | 'secondary' | 'success' | 'danger' | 'ghost' | 'soft';
export type ButtonSize = 'xs' | 'sm' | 'md' | 'lg';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  icon?: LucideIcon;
  iconRight?: LucideIcon;
  /** Render as router link */
  to?: string;
  /** Render as plain anchor (external / print views) */
  href?: string;
  target?: string;
  children?: ReactNode;
}

const sizeClass: Record<ButtonSize, string> = { xs: 'btn-xs', sm: 'btn-sm', md: '', lg: 'btn-lg' };
const iconSize: Record<ButtonSize, string> = { xs: 'h-3.5 w-3.5', sm: 'h-4 w-4', md: 'h-4 w-4', lg: 'h-5 w-5' };

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading, icon: Icon, iconRight: IconRight, to, href, target, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  const cls = clsx('btn', `btn-${variant}`, sizeClass[size], className);
  const content = (
    <>
      {loading ? <Spinner className={iconSize[size]} /> : Icon ? <Icon className={iconSize[size]} aria-hidden /> : null}
      {children}
      {IconRight && !loading ? <IconRight className={iconSize[size]} aria-hidden /> : null}
    </>
  );
  if (to && !disabled) {
    return (
      <Link to={to} className={cls}>
        {content}
      </Link>
    );
  }
  if (href && !disabled) {
    return (
      <a href={href} target={target} rel={target === '_blank' ? 'noopener noreferrer' : undefined} className={cls}>
        {content}
      </a>
    );
  }
  return (
    <button ref={ref} type={type} className={cls} disabled={disabled || loading} aria-busy={loading || undefined} {...rest}>
      {content}
    </button>
  );
});

interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  icon: LucideIcon;
  label: string;
  tone?: 'default' | 'danger' | 'primary';
  size?: 'sm' | 'md';
}

/** Square icon-only button with accessible label + tooltip. */
export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton({ icon: Icon, label, tone = 'default', size = 'md', className, ...rest }, ref) {
  return (
    <button
      ref={ref}
      type="button"
      aria-label={label}
      title={label}
      className={clsx(
        'btn-icon',
        size === 'sm' && '!h-8 !w-8 !rounded-lg',
        tone === 'danger' && 'hover:!bg-red-50 hover:!text-red-600 dark:hover:!bg-red-500/10',
        tone === 'primary' && 'hover:!bg-brand-50 hover:!text-brand-700 dark:hover:!bg-brand-500/10',
        className,
      )}
      {...rest}
    >
      <Icon className={size === 'sm' ? 'h-4 w-4' : 'h-[18px] w-[18px]'} aria-hidden />
    </button>
  );
});
