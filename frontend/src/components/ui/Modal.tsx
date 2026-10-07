import type { ReactNode } from 'react';
import { Dialog, DialogPanel, DialogTitle, Description } from '@headlessui/react';
import clsx from 'clsx';
import { X } from 'lucide-react';

export type ModalSize = 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'full';
const sizes: Record<ModalSize, string> = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl', '2xl': 'max-w-6xl', full: 'max-w-[96vw]' };

interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  size?: ModalSize;
  children: ReactNode;
  footer?: ReactNode;
  /** Prevent closing by clicking outside (e.g. while saving) */
  static?: boolean;
  icon?: ReactNode;
}

export function Modal({ open, onClose, title, description, size = 'md', children, footer, static: isStatic, icon }: ModalProps) {
  return (
    <Dialog open={open} onClose={isStatic ? () => {} : onClose} className="relative z-50">
      <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-[2px] animate-fade-in" aria-hidden="true" />
      <div className="fixed inset-0 overflow-y-auto">
        <div className="flex min-h-full items-end justify-center p-0 sm:items-center sm:p-4">
          <DialogPanel className={clsx('w-full animate-slide-up rounded-t-2xl bg-white shadow-pop sm:rounded-2xl dark:bg-slate-900 dark:ring-1 dark:ring-slate-800', sizes[size])}>
            <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
              <div className="flex min-w-0 items-start gap-3">
                {icon}
                <div className="min-w-0">
                  <DialogTitle className="font-display text-base font-semibold text-slate-900 dark:text-white">{title}</DialogTitle>
                  {description && <Description className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{description}</Description>}
                </div>
              </div>
              <button type="button" onClick={onClose} className="btn-icon -mr-2 -mt-1" aria-label="Close dialog">
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="max-h-[calc(100vh-11rem)] overflow-y-auto px-5 py-5">{children}</div>
            {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 px-5 py-3.5 dark:border-slate-800">{footer}</div>}
          </DialogPanel>
        </div>
      </div>
    </Dialog>
  );
}

interface DrawerProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  width?: string;
}

/** Right-side slide-over panel (record details, filters on mobile). */
export function Drawer({ open, onClose, title, description, children, footer, width = 'max-w-xl' }: DrawerProps) {
  return (
    <Dialog open={open} onClose={onClose} className="relative z-50">
      <div className="fixed inset-0 bg-slate-900/40 animate-fade-in" aria-hidden="true" />
      <div className="fixed inset-y-0 right-0 flex w-full justify-end">
        <DialogPanel className={clsx('flex h-full w-full flex-col bg-white shadow-pop dark:bg-slate-900', width)}>
          <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <div className="min-w-0">
              <DialogTitle className="font-display text-base font-semibold text-slate-900 dark:text-white">{title}</DialogTitle>
              {description && <Description className="mt-0.5 text-sm text-slate-500">{description}</Description>}
            </div>
            <button type="button" onClick={onClose} className="btn-icon -mr-2" aria-label="Close panel">
              <X className="h-5 w-5" />
            </button>
          </div>
          <div className="flex-1 overflow-y-auto px-5 py-5">{children}</div>
          {footer && <div className="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3.5 dark:border-slate-800">{footer}</div>}
        </DialogPanel>
      </div>
    </Dialog>
  );
}
