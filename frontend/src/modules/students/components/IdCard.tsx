import { memo, useEffect, useState } from 'react';
import clsx from 'clsx';
import { appUrl } from '@/lib/config';
import { formatDate, initials } from '@/lib/format';
import type { IdCardRow, InstituteInfo } from '../types';

/* ------------------------------------------------------------------ QR (uses the bundled assets/vendor/qrcode.js) */

type QrFactory = (type: number, level: string) => { addData: (d: string) => void; make: () => void; createSvgTag: (o: { cellSize: number; margin: number; scalable: boolean }) => string };
let qrLoader: Promise<QrFactory | null> | null = null;

function loadQr(): Promise<QrFactory | null> {
  const w = window as unknown as { qrcode?: QrFactory };
  if (w.qrcode) return Promise.resolve(w.qrcode);
  if (!qrLoader) {
    qrLoader = new Promise((resolve) => {
      const s = document.createElement('script');
      s.src = appUrl('assets/vendor/qrcode.js');
      s.async = true;
      s.onload = () => resolve(w.qrcode ?? null);
      s.onerror = () => resolve(null);
      document.head.appendChild(s);
    });
  }
  return qrLoader;
}

export function QrCode({ text, className }: { text: string; className?: string }) {
  const [svg, setSvg] = useState<string | null>(null);
  useEffect(() => {
    let alive = true;
    void loadQr().then((qr) => {
      if (!qr || !alive) return;
      try {
        const q = qr(0, 'M');
        q.addData(text);
        q.make();
        setSvg(q.createSvgTag({ cellSize: 3, margin: 0, scalable: true }));
      } catch {
        setSvg(null);
      }
    });
    return () => {
      alive = false;
    };
  }, [text]);
  return svg ? (
    <div className={clsx('[&>svg]:h-full [&>svg]:w-full', className)} role="img" aria-label={`QR code ${text}`} dangerouslySetInnerHTML={{ __html: svg }} />
  ) : (
    <div className={clsx('skeleton', className)} aria-hidden />
  );
}

/* ------------------------------------------------------------------ Card faces (CR80 ratio 85.6 × 54 mm) */

const face = 'relative aspect-[85.6/54] w-full overflow-hidden rounded-xl bg-white text-slate-800 shadow-card ring-1 ring-slate-200 select-none';

export const IdCardFront = memo(function IdCardFront({ s, institute }: { s: IdCardRow; institute?: InstituteInfo }) {
  return (
    <div className={face} style={{ containerType: 'inline-size' }}>
      <div className="absolute inset-x-0 top-0 flex h-[26%] items-center gap-[3cqw] bg-gradient-to-r from-brand-950 via-brand-900 to-brand-700 px-[4cqw]">
        <img src={institute?.logo_white ?? appUrl('assets/images/logo-white.svg')} alt="" className="h-[70%] w-auto" />
        <div className="min-w-0 leading-tight text-white">
          <p className="line-clamp-2 text-[3.1cqw] font-extrabold uppercase leading-tight tracking-wide">{institute?.name ?? 'Global Institute of Management & Technology'}</p>
          <p className="text-[2.6cqw] text-white/75">Student Identity Card</p>
        </div>
      </div>
      <div className="absolute inset-x-0 top-[26%] h-[1.4%] bg-accent-500" />
      <div className="absolute inset-x-[4cqw] bottom-[15%] top-[33%] flex gap-[4cqw]">
        <div className="h-full aspect-[3/3.7] shrink-0 overflow-hidden rounded-[1.6cqw] bg-brand-50 ring-1 ring-brand-100">
          {s.photo ? (
            <img src={appUrl(s.photo)} alt="" className="h-full w-full object-cover" />
          ) : (
            <span className="flex h-full w-full items-center justify-center text-[7cqw] font-bold text-brand-700">{initials(s.full_name)}</span>
          )}
        </div>
        <div className="min-w-0 flex-1 leading-tight">
          <p className="truncate text-[4.6cqw] font-bold text-brand-900">{s.full_name}</p>
          <p className="mt-[0.8cqw] font-mono text-[3.4cqw] font-semibold text-accent-700">{s.student_uid}</p>
          <dl className="mt-[1.6cqw] grid grid-cols-[auto_1fr] gap-x-[2cqw] gap-y-[0.6cqw] text-[2.7cqw]">
            <dt className="text-slate-500">Program</dt>
            <dd className="truncate font-semibold">{s.program_name}{s.course_name ? ` · ${s.course_name.replace(/^.* - /, '')}` : ''}</dd>
            <dt className="text-slate-500">Batch</dt>
            <dd className="truncate font-semibold">{s.batch_name ?? '—'}</dd>
            <dt className="text-slate-500">Roll No</dt>
            <dd className="truncate font-semibold">{s.roll_no ?? '—'}</dd>
            <dt className="text-slate-500">Blood</dt>
            <dd className="font-semibold text-red-600">{s.blood_group ?? '—'}</dd>
          </dl>
        </div>
      </div>
      <div className="absolute inset-x-0 bottom-0 flex h-[13%] items-center justify-between bg-slate-50 px-[4cqw] text-[2.6cqw]">
        <span>Emergency: <strong>{s.emergency_contact_phone ?? s.guardian_phone ?? '—'}</strong></span>
        <span>Valid till <strong>{formatDate(s.valid_until)}</strong></span>
      </div>
    </div>
  );
});

export const IdCardBack = memo(function IdCardBack({ s, institute }: { s: IdCardRow; institute?: InstituteInfo }) {
  const address = [s.address, s.city, s.state, s.pincode].filter(Boolean).join(', ');
  return (
    <div className={face} style={{ containerType: 'inline-size' }}>
      <div className="absolute inset-x-0 top-0 h-[2.5%] bg-brand-900" />
      <div className="absolute inset-[4cqw] top-[7%] flex gap-[4cqw]">
        <div className="min-w-0 flex-1 text-[2.7cqw] leading-snug">
          <p className="text-[2.4cqw] font-semibold uppercase tracking-wider text-slate-500">Student address</p>
          <p className="mt-[0.6cqw] line-clamp-3 font-medium">{address || '—'}</p>
          <p className="mt-[2cqw] text-[2.4cqw] font-semibold uppercase tracking-wider text-slate-500">Mobile</p>
          <p className="font-medium">{s.mobile}</p>
          <p className="mt-[2cqw] text-[2.4cqw] font-semibold uppercase tracking-wider text-slate-500">If found, please return to</p>
          <p className="line-clamp-2 font-medium">{institute?.name ?? 'Global Institute of Management & Technology'}{institute?.address ? `, ${institute.address}` : ''}</p>
        </div>
        <div className="flex w-[27%] shrink-0 flex-col items-center">
          <QrCode text={s.student_uid} className="aspect-square w-full" />
          <p className="mt-[1cqw] font-mono text-[2.3cqw] font-semibold">{s.student_uid}</p>
        </div>
      </div>
      <div className="absolute inset-x-0 bottom-0 flex h-[15%] items-center justify-between bg-brand-900 px-[4cqw] text-[2.5cqw] text-white">
        <span>{institute?.phone ?? ''}{institute?.email ? ` · ${institute.email}` : ''}</span>
        <span className="border-t border-white/50 pt-[0.4cqw] italic">Authorised signatory</span>
      </div>
    </div>
  );
});
