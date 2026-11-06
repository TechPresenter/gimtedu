import clsx from 'clsx';

/**
 * Format a WhatsApp number for display: '919876543210' / '+919876543210' / '9876543210' → '+91 98765 43210'.
 * Other country codes are shown as '+<digits>' grouped loosely.
 */
export function formatWaPhone(v: string | null | undefined): string {
  if (!v) return '';
  const digits = String(v).replace(/\D+/g, '');
  if (!digits) return String(v);
  if (digits.length === 10 && /^[6-9]/.test(digits)) return `+91 ${digits.slice(0, 5)} ${digits.slice(5)}`;
  if (digits.length === 12 && digits.startsWith('91')) return `+91 ${digits.slice(2, 7)} ${digits.slice(7)}`;
  if (digits.length > 10) {
    const cc = digits.slice(0, digits.length - 10);
    const n = digits.slice(-10);
    return `+${cc} ${n.slice(0, 5)} ${n.slice(5)}`;
  }
  return `+${digits}`;
}

/** wa.me click-to-chat link for a stored number. */
export function waMeHref(v: string | null | undefined): string {
  return `https://wa.me/${String(v ?? '').replace(/\D+/g, '')}`;
}

/** Inline phone number (+91 98765 43210). `link="tel"` makes it a tel: link, `link="wa"` a wa.me link. */
export function PhoneText({ phone, className, link }: { phone: string | null | undefined; className?: string; link?: 'tel' | 'wa' }) {
  if (!phone) return <span className={clsx('text-slate-400', className)}>—</span>;
  const text = formatWaPhone(phone);
  const cls = clsx('whitespace-nowrap tabular-nums', className);
  if (link === 'tel') {
    return (
      <a href={`tel:+${String(phone).replace(/\D+/g, '')}`} className={clsx(cls, 'hover:underline')}>
        {text}
      </a>
    );
  }
  if (link === 'wa') {
    return (
      <a href={waMeHref(phone)} target="_blank" rel="noopener noreferrer" className={clsx(cls, 'hover:underline')}>
        {text}
      </a>
    );
  }
  return <span className={cls}>{text}</span>;
}

export default PhoneText;
