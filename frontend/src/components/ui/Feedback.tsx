import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react';
import clsx from 'clsx';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import { Modal } from './Modal';
import { Button } from './Button';

/* ---------------------------------------------------------------- Toasts */
type ToastType = 'success' | 'error' | 'warning' | 'info';
interface ToastItem {
  id: number;
  type: ToastType;
  message: string;
  title?: string;
}
interface ToastApi {
  success: (message: string, title?: string) => void;
  error: (message: string, title?: string) => void;
  warning: (message: string, title?: string) => void;
  info: (message: string, title?: string) => void;
}

const ToastCtx = createContext<ToastApi | null>(null);
const toastStyle: Record<ToastType, { icon: typeof Info; cls: string }> = {
  success: { icon: CheckCircle2, cls: 'text-emerald-600 dark:text-emerald-400' },
  error: { icon: XCircle, cls: 'text-red-600 dark:text-red-400' },
  warning: { icon: AlertTriangle, cls: 'text-amber-600 dark:text-amber-400' },
  info: { icon: Info, cls: 'text-brand-700 dark:text-brand-300' },
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const seq = useRef(0);
  const remove = useCallback((id: number) => setItems((l) => l.filter((t) => t.id !== id)), []);
  const push = useCallback(
    (type: ToastType, message: string, title?: string) => {
      const id = ++seq.current;
      setItems((l) => [...l.slice(-4), { id, type, message, title }]);
      setTimeout(() => remove(id), type === 'error' ? 7000 : 4500);
    },
    [remove],
  );
  const api: ToastApi = {
    success: (m, t) => push('success', m, t),
    error: (m, t) => push('error', m, t),
    warning: (m, t) => push('warning', m, t),
    info: (m, t) => push('info', m, t),
  };
  return (
    <ToastCtx.Provider value={api}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-center gap-2 p-4 sm:bottom-auto sm:right-0 sm:top-16 sm:items-end">
        {items.map((t) => {
          const { icon: Icon, cls } = toastStyle[t.type];
          return (
            <div key={t.id} role={t.type === 'error' ? 'alert' : 'status'} className="pointer-events-auto flex w-full max-w-sm animate-slide-up items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5 shadow-pop dark:border-slate-700 dark:bg-slate-900">
              <Icon className={clsx('mt-0.5 h-5 w-5 shrink-0', cls)} aria-hidden />
              <div className="min-w-0 flex-1 text-sm">
                {t.title && <p className="font-semibold text-slate-900 dark:text-white">{t.title}</p>}
                <p className="text-slate-600 dark:text-slate-300">{t.message}</p>
              </div>
              <button type="button" onClick={() => remove(t.id)} className="rounded p-0.5 text-slate-400 hover:text-slate-700" aria-label="Dismiss notification">
                <X className="h-4 w-4" />
              </button>
            </div>
          );
        })}
      </div>
    </ToastCtx.Provider>
  );
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastCtx);
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>');
  return ctx;
}

/* ---------------------------------------------------------------- Confirm dialog */
interface ConfirmOptions {
  title?: string;
  message: ReactNode;
  confirmText?: string;
  cancelText?: string;
  danger?: boolean;
}
type ConfirmFn = (o: ConfirmOptions | string) => Promise<boolean>;
const ConfirmCtx = createContext<ConfirmFn | null>(null);

export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<(ConfirmOptions & { resolve: (v: boolean) => void }) | null>(null);
  const confirm: ConfirmFn = useCallback(
    (o) =>
      new Promise<boolean>((resolve) => {
        const opts = typeof o === 'string' ? { message: o } : o;
        setState({ ...opts, resolve });
      }),
    [],
  );
  const close = (v: boolean) => {
    state?.resolve(v);
    setState(null);
  };
  return (
    <ConfirmCtx.Provider value={confirm}>
      {children}
      <Modal
        open={!!state}
        onClose={() => close(false)}
        size="sm"
        title={state?.title ?? 'Are you sure?'}
        icon={
          <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full', state?.danger ? 'bg-red-100 text-red-600 dark:bg-red-500/15' : 'bg-amber-100 text-amber-600 dark:bg-amber-500/15')}>
            <AlertTriangle className="h-5 w-5" />
          </span>
        }
        footer={
          <>
            <Button variant="secondary" onClick={() => close(false)}>
              {state?.cancelText ?? 'Cancel'}
            </Button>
            <Button variant={state?.danger ? 'danger' : 'primary'} onClick={() => close(true)} autoFocus>
              {state?.confirmText ?? 'Confirm'}
            </Button>
          </>
        }
      >
        <div className="text-sm text-slate-600 dark:text-slate-300">{state?.message}</div>
      </Modal>
    </ConfirmCtx.Provider>
  );
}

export function useConfirm(): ConfirmFn {
  const ctx = useContext(ConfirmCtx);
  if (!ctx) throw new Error('useConfirm must be used inside <ConfirmProvider>');
  return ctx;
}

/* ---------------------------------------------------------------- Alert */
export function Alert({ variant = 'info', title, children, className, action }: { variant?: ToastType; title?: ReactNode; children?: ReactNode; className?: string; action?: ReactNode }) {
  const { icon: Icon } = toastStyle[variant];
  const colors: Record<ToastType, string> = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
    error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
    warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
    info: 'border-brand-200 bg-brand-50 text-brand-900 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-100',
  };
  return (
    <div className={clsx('flex items-start gap-3 rounded-xl border px-4 py-3 text-sm', colors[variant], className)} role={variant === 'error' ? 'alert' : undefined}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
      <div className="min-w-0 flex-1">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={clsx(title && 'mt-0.5', 'opacity-90')}>{children}</div>}
      </div>
      {action}
    </div>
  );
}
