import clsx from 'clsx';
import { appUrl } from '@/lib/config';

const COLOR = appUrl('assets/images/logo.svg');
const WHITE = appUrl('assets/images/logo-white.svg');
const ICON = appUrl('assets/images/logo-icon.svg');

interface LogoProps {
  /** 'auto' = colour logo in light mode, white logo in dark mode */
  variant?: 'auto' | 'color' | 'white';
  className?: string;
  /** Logo URLs from the session payload (admin-uploaded logos win over the bundled ones). */
  src?: string | null;
  srcWhite?: string | null;
  alt?: string;
}

/** GIMT logo (landscape lock-up, ~2:1). */
export function Logo({ variant = 'auto', className = 'h-10 w-auto', src, srcWhite, alt = 'Global Institute of Management & Technology' }: LogoProps) {
  const color = src || COLOR; // server URLs already include the base path
  const white = srcWhite || WHITE;
  if (variant === 'color') return <img src={color} alt={alt} className={className} width={206} height={100} />;
  if (variant === 'white') return <img src={white} alt={alt} className={className} width={206} height={100} />;
  return (
    <>
      <img src={color} alt={alt} className={clsx(className, 'dark:hidden')} width={206} height={100} />
      <img src={white} alt={alt} className={clsx(className, 'hidden dark:block')} width={206} height={100} />
    </>
  );
}

/** Square GIMT mark for compact places (mobile top bar). */
export function LogoMark({ className = 'h-9 w-9' }: { className?: string }) {
  return <img src={ICON} alt="GIMT" className={className} width={36} height={36} />;
}
