import clsx from 'clsx';
import { WhatsAppIcon } from '../../lib/Icon';

/**
 * Official WhatsApp glyph (Simple Icons, CC0 — shared with app/icons.php 'whatsapp-brand') in a WhatsApp-green
 * (#25D366, hover #1EBE57) circle with a white glyph. Labels next to it stay dark-on-white for text contrast.
 */
export function WhatsAppBadge({ size = 22, className }: { size?: number; className?: string }) {
  return (
    <span
      className={clsx('inline-flex shrink-0 items-center justify-center rounded-full bg-[#25D366] text-white transition-colors group-hover:bg-[#1EBE57]', className)}
      style={{ width: size, height: size }}
      aria-hidden="true"
    >
      <WhatsAppIcon className="h-[62%] w-[62%]" />
    </span>
  );
}

/** Pill link: green WhatsApp badge + dark label (used for chips and the call-to-action row). */
export const WA_PILL =
  'group inline-flex items-center gap-1.5 rounded-full border border-[#25D366]/50 bg-white py-1 pl-1 pr-3 text-[13px] font-semibold text-slate-800 transition-colors hover:border-[#1EBE57] hover:bg-[#25D366]/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1EBE57] focus-visible:ring-offset-1';
