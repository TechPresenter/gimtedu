import { useEffect, useRef, useState, type ReactNode } from 'react';
import clsx from 'clsx';
import {
  ArrowRightLeft, Award, BriefcaseBusiness, FileBadge, GraduationCap, PlaneTakeoff, ScrollText, ShieldCheck, type LucideIcon,
} from 'lucide-react';
import { Skeleton, StatusBadge, toneClasses, type Tone } from '@/components/ui';
import { useApi } from '@/lib/queries';
import type { BadgeColor } from '@/lib/status';
import type { CertConfig, CertStatus } from './types';

/** Lucide icon per certificate type (keys match cert_types() in app/services/certificates.php). */
export const TYPE_ICONS: Record<string, LucideIcon> = {
  bonafide: FileBadge,
  transfer: ArrowRightLeft,
  character: ShieldCheck,
  course_completion: GraduationCap,
  internship: BriefcaseBusiness,
  migration: PlaneTakeoff,
  provisional: ScrollText,
  custom: Award,
};

export const TYPE_TONES: Record<string, Tone> = {
  bonafide: 'blue', transfer: 'purple', character: 'green', course_completion: 'navy', internship: 'cyan', migration: 'orange', provisional: 'amber', custom: 'pink',
};

export const STATUS_COLORS: Record<string, BadgeColor> = { issued: 'green', pending: 'amber', revoked: 'red', rejected: 'slate', expired: 'purple' };
export const STATUS_LABELS: Record<CertStatus, string> = { issued: 'Valid', pending: 'Pending approval', revoked: 'Revoked', rejected: 'Rejected', expired: 'Expired' };

/** Status badge; `short` (dense tables) shows "Pending" instead of "Pending approval". */
export function CertStatusBadge({ status, short }: { status: string | null | undefined; short?: boolean }) {
  const s = (status ?? '') as CertStatus;
  return <StatusBadge status={s} label={short && s === 'pending' ? 'Pending' : STATUS_LABELS[s] ?? undefined} colors={STATUS_COLORS} />;
}

/** Small tinted icon chip for a certificate type. */
export function TypeIcon({ type, size = 'md', className }: { type: string; size?: 'sm' | 'md' | 'lg'; className?: string }) {
  const Icon = TYPE_ICONS[type] ?? Award;
  const tone = toneClasses[TYPE_TONES[type] ?? 'slate'];
  const box = size === 'sm' ? 'h-7 w-7 rounded-lg' : size === 'lg' ? 'h-11 w-11 rounded-xl' : 'h-9 w-9 rounded-xl';
  const ic = size === 'sm' ? 'h-3.5 w-3.5' : size === 'lg' ? 'h-5 w-5' : 'h-4 w-4';
  return (
    <span className={clsx('inline-flex shrink-0 items-center justify-center', box, tone.icon, className)} aria-hidden>
      <Icon className={ic} />
    </span>
  );
}

export function useCertConfig() {
  return useApi<CertConfig>(['cert-config'], 'certificates/config', undefined, { staleTime: 60_000 });
}

/** A4 sizes in CSS px at 96 dpi. */
const SHEET = { portrait: { w: 794, h: 1123 }, landscape: { w: 1123, h: 794 } };

interface CertificateFrameProps {
  /** Full HTML document (srcDoc) … */
  html?: string | null;
  /** … or a same-origin URL (print view with embed=1) */
  src?: string | null;
  orientation?: 'portrait' | 'landscape';
  loading?: boolean;
  title: string;
  className?: string;
  /** Overlay shown on top (e.g. "Updating…") */
  overlay?: ReactNode;
  /** Max height of the scaled sheet in px (keeps landscape/portrait previews compact) */
  maxHeight?: number;
}

/**
 * Renders an A4 certificate document scaled to the available width. The document is self-contained (embed mode of
 * cert_document()), so the preview looks exactly like the printed sheet.
 */
export function CertificateFrame({ html, src, orientation = 'portrait', loading, title, className, overlay, maxHeight }: CertificateFrameProps) {
  const wrap = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(0);
  useEffect(() => {
    const el = wrap.current;
    if (!el) return;
    const update = () => setWidth(el.clientWidth);
    update();
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(update);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  const size = SHEET[orientation];
  let scale = width ? width / size.w : 0;
  if (maxHeight && scale * size.h > maxHeight) scale = maxHeight / size.h;
  const boxW = Math.round(size.w * scale);
  const boxH = Math.round(size.h * scale);
  const hasDoc = !!(html || src);
  return (
    <div ref={wrap} className={clsx('w-full', className)}>
      {width > 0 && (
        <div
          className="relative mx-auto overflow-hidden rounded-lg bg-white shadow-[0_16px_40px_-18px_rgba(11,42,91,.45)] ring-1 ring-slate-200 transition-[height] duration-300 dark:ring-slate-700"
          style={{ width: boxW, height: boxH }}
        >
          {hasDoc && (
            <iframe
              title={title}
              {...(html ? { srcDoc: html } : { src: src ?? undefined })}
              className="pointer-events-none absolute left-0 top-0 origin-top-left border-0 bg-white"
              style={{ width: size.w, height: size.h, transform: `scale(${scale})` }}
              tabIndex={-1}
            />
          )}
          {(!hasDoc || loading) && (
            <div className={clsx('absolute inset-0 flex flex-col gap-3 p-[8%]', hasDoc ? 'bg-white/70 backdrop-blur-[1px] dark:bg-slate-900/40' : 'bg-white')}>
              {!hasDoc && (
                <>
                  <Skeleton className="mx-auto h-[8%] w-[22%]" />
                  <Skeleton className="mx-auto h-[3%] w-[70%]" />
                  <Skeleton className="mx-auto mt-[6%] h-[5%] w-[55%]" />
                  {Array.from({ length: 6 }).map((_, i) => (
                    <Skeleton key={i} className={clsx('h-[2.2%]', i % 3 === 2 ? 'w-2/3' : 'w-full')} />
                  ))}
                </>
              )}
            </div>
          )}
          {overlay}
        </div>
      )}
    </div>
  );
}

/** "Expires in 23 days" style helper. */
export function daysUntil(date: string | null | undefined): number | null {
  if (!date) return null;
  const d = new Date(`${date}T00:00:00`);
  const t = new Date();
  t.setHours(0, 0, 0, 0);
  return Math.round((d.getTime() - t.getTime()) / 86400000);
}

export function copyText(text: string): Promise<boolean> {
  if (navigator.clipboard?.writeText) return navigator.clipboard.writeText(text).then(() => true, () => false);
  return Promise.resolve(false);
}
