import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { QrCode as QrIcon } from 'lucide-react';
import { appUrl } from '@/lib/config';

/* The same-origin qrcode-generator build (assets/vendor/qrcode.js) is loaded on demand: no npm package, no CDN. */
type QrFactory = (type: number, level: string) => { addData: (d: string) => void; make: () => void; createSvgTag: (o: { cellSize: number; margin: number; scalable: boolean }) => string };
let loader: Promise<QrFactory | null> | null = null;

function loadQr(): Promise<QrFactory | null> {
  const w = window as unknown as { qrcode?: QrFactory };
  if (w.qrcode) return Promise.resolve(w.qrcode);
  if (!loader) {
    loader = new Promise((resolve) => {
      const s = document.createElement('script');
      s.src = appUrl('assets/vendor/qrcode.js');
      s.async = true;
      s.onload = () => resolve(w.qrcode ?? null);
      s.onerror = () => {
        loader = null; // allow a retry on the next mount
        resolve(null);
      };
      document.head.appendChild(s);
    });
  }
  return loader;
}

interface Props {
  /** Text to encode, e.g. an otpauth:// URI. */
  value: string;
  /** Accessible description of what the code is for. */
  label: string;
  className?: string;
}

/**
 * Scannable QR code. Always drawn dark-on-white with a quiet zone (phones cannot read inverted codes), also in dark mode.
 */
export function QrCode({ value, label, className }: Props) {
  const [svg, setSvg] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    setSvg(null);
    setFailed(false);
    void loadQr().then((qr) => {
      if (!alive) return;
      if (!qr) {
        setFailed(true);
        return;
      }
      try {
        const q = qr(0, 'M');
        q.addData(value);
        q.make();
        setSvg(q.createSvgTag({ cellSize: 4, margin: 2, scalable: true }));
      } catch {
        setFailed(true);
      }
    });
    return () => {
      alive = false;
    };
  }, [value]);

  return (
    <div className={clsx('relative aspect-square rounded-2xl bg-white p-2.5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-700', className)}>
      {svg ? (
        <div className="h-full w-full [&>svg]:block [&>svg]:h-full [&>svg]:w-full" role="img" aria-label={label} dangerouslySetInnerHTML={{ __html: svg }} />
      ) : failed ? (
        <div className="flex h-full w-full flex-col items-center justify-center gap-2 rounded-xl bg-slate-50 p-3 text-center text-xs text-slate-500" role="img" aria-label={`${label} (could not be drawn)`}>
          <QrIcon className="h-8 w-8 text-slate-300" aria-hidden />
          The QR code could not be drawn. Enter the setup key below instead.
        </div>
      ) : (
        <div className="skeleton h-full w-full rounded-xl !bg-slate-100" aria-hidden />
      )}
    </div>
  );
}
