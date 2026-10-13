import clsx from 'clsx';
import { X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { lockScroll, staticMode, trapFocus } from '../core/dom';

interface ModalProps {
  open: boolean;
  onClose: () => void;
  /** Accessible name (or use labelledBy). */
  label?: string;
  labelledBy?: string;
  /** 'media' = dark full-screen stage for video/images, 'dialog' = white card. */
  variant?: 'media' | 'dialog';
  className?: string;
  children: ReactNode;
}

/**
 * Accessible modal rendered in a portal: focus trap, Esc to close, backdrop click, scroll lock, restores focus,
 * fade/scale enter + exit transition (CSS `.modal-shell` in style.css).
 */
export function Modal({ open, onClose, label, labelledBy, variant = 'dialog', className, children }: ModalProps) {
  const [mounted, setMounted] = useState(open);
  const [visible, setVisible] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const close = useRef(onClose);
  close.current = onClose;

  useEffect(() => {
    if (open) {
      setMounted(true);
      const r = requestAnimationFrame(() => requestAnimationFrame(() => setVisible(true)));
      return () => cancelAnimationFrame(r);
    }
    setVisible(false);
    const t = window.setTimeout(() => setMounted(false), staticMode() ? 0 : 280);
    return () => window.clearTimeout(t);
  }, [open]);

  useEffect(() => {
    if (!open || !mounted || !ref.current) return;
    const unlock = lockScroll();
    const untrap = trapFocus(ref.current, ref.current.querySelector<HTMLElement>('[data-autofocus]'));
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        close.current();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      untrap();
      unlock();
    };
  }, [open, mounted]);

  if (!mounted) return null;
  return createPortal(
    <div
      ref={ref}
      className={clsx('modal-shell', `modal-${variant}`, visible && 'is-open', className)}
      role="dialog"
      aria-modal="true"
      aria-label={labelledBy ? undefined : label}
      aria-labelledby={labelledBy}
    >
      <div className="modal-backdrop" onClick={onClose} aria-hidden="true" />
      <div className="modal-panel">
        <button type="button" className="modal-close" onClick={onClose} aria-label="Close" data-autofocus>
          <X className="h-5 w-5" aria-hidden="true" />
        </button>
        {children}
      </div>
    </div>,
    document.body,
  );
}
