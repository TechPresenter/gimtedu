import clsx from 'clsx';
import { ArrowLeft, BadgeCheck, Camera, EllipsisVertical, Mic, Paperclip, Phone, Smile, Video } from 'lucide-react';
import type { ReactNode } from 'react';
import { appUrl } from '@/lib/config';
import { useWaStatus } from './api';

/**
 * Mobile WhatsApp-style preview frame: GIMT business header (logo, name, verified tick), chat wallpaper, a date chip
 * and the bubbles passed as children, with a decorative (non-interactive) composer bar. Used by the template designer,
 * campaign preview and bot simulator. `footer` replaces the decorative composer (e.g. a real input for the bot tester).
 */
export function WaPhoneFrame({
  children,
  name,
  subtitle = 'Official business account',
  dateLabel = 'Today',
  footer,
  className,
  bodyClassName,
  height = 560,
}: {
  children?: ReactNode;
  name?: string;
  subtitle?: string;
  dateLabel?: string | null;
  footer?: ReactNode;
  className?: string;
  bodyClassName?: string;
  /** Height of the screen in px (the frame keeps a phone aspect at 320 px wide). */
  height?: number;
}) {
  const status = useWaStatus({ enabled: !name });
  const title = name ?? status.data?.business_name ?? 'GIMT';

  return (
    <div
      className={clsx(
        'mx-auto w-full max-w-[340px] rounded-[2.6rem] border border-slate-300 bg-slate-900 p-2.5 shadow-xl shadow-slate-900/10 dark:border-slate-700 dark:bg-black dark:shadow-black/40',
        className,
      )}
    >
      <div className="relative flex flex-col overflow-hidden rounded-[2.1rem] bg-[#EFEAE2] dark:bg-[#0B141A]" style={{ height }}>
        {/* status bar */}
        <div className="flex h-6 shrink-0 items-center justify-between bg-[#075E54] px-5 text-[10px] font-semibold text-white/90 dark:bg-[#1F2C34]" aria-hidden>
          <span>9:41</span>
          <span className="h-3.5 w-16 rounded-full bg-black/80" />
          <span>5G ▮▮▮</span>
        </div>
        {/* chat header */}
        <div className="flex shrink-0 items-center gap-2 bg-[#075E54] px-2.5 py-2 text-white dark:bg-[#1F2C34]">
          <ArrowLeft size={18} aria-hidden className="shrink-0 opacity-90" />
          <span className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white">
            <img src={appUrl('assets/images/logo-icon.svg')} alt="" className="h-7 w-7 object-contain" />
          </span>
          <div className="min-w-0 flex-1 leading-tight">
            <p className="flex items-center gap-1 truncate text-sm font-semibold">
              <span className="truncate">{title}</span>
              <BadgeCheck size={14} className="shrink-0 fill-[#25D366] text-white" aria-label="Verified business" />
            </p>
            {subtitle && <p className="truncate text-[11px] text-white/75">{subtitle}</p>}
          </div>
          <Video size={18} aria-hidden className="shrink-0 opacity-90" />
          <Phone size={16} aria-hidden className="shrink-0 opacity-90" />
          <EllipsisVertical size={18} aria-hidden className="shrink-0 opacity-90" />
        </div>
        {/* wallpaper + messages */}
        <div
          className={clsx('wa-wallpaper flex-1 space-y-1.5 overflow-y-auto px-2.5 py-3', bodyClassName)}
          style={{
            backgroundImage:
              'radial-gradient(circle at 12px 12px, rgba(120,110,90,.10) 1.6px, transparent 1.7px), radial-gradient(circle at 36px 30px, rgba(120,110,90,.07) 1.2px, transparent 1.3px)',
            backgroundSize: '48px 48px',
          }}
        >
          {dateLabel && (
            <div className="mb-1 flex justify-center">
              <span className="rounded-md bg-white/90 px-2 py-0.5 text-[11px] font-medium text-slate-500 shadow-sm dark:bg-[#182229] dark:text-slate-400">{dateLabel}</span>
            </div>
          )}
          {children}
        </div>
        {/* composer */}
        {footer ?? (
          <div className="flex shrink-0 items-center gap-1.5 px-2 py-2" aria-hidden>
            <div className="flex h-10 flex-1 items-center gap-2 rounded-full bg-white px-3 text-slate-400 shadow-sm dark:bg-[#202C33] dark:text-slate-500">
              <Smile size={18} />
              <span className="flex-1 text-sm">Message</span>
              <Paperclip size={17} />
              <Camera size={17} />
            </div>
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-[#00A884] text-white">
              <Mic size={18} />
            </span>
          </div>
        )}
      </div>
    </div>
  );
}

export default WaPhoneFrame;
