import type { CSSProperties } from 'react';
import clsx from 'clsx';

interface Props {
  title: string;
  orientation: 'portrait' | 'landscape';
  borderStyle: string;
  accent: string;
  showSeal?: boolean;
  showQr?: boolean;
  className?: string;
}

const GOLD = '#B08D3C';

/**
 * Lightweight CSS miniature of a certificate design (gallery cards, border pickers). The exact rendering is the
 * server preview; this only conveys layout, border style and accent colour without loading an iframe per card.
 */
export function TemplateThumb({ title, orientation, borderStyle, accent, showSeal = true, showQr = true, className }: Props) {
  const land = orientation === 'landscape';
  const frame: CSSProperties = {};
  let ring: CSSProperties = {};
  switch (borderStyle) {
    case 'double':
      frame.border = `4px double ${accent}`;
      break;
    case 'ornate':
      ring = { padding: 4, background: `repeating-linear-gradient(45deg, ${accent} 0 1px, transparent 1px 4px), repeating-linear-gradient(-45deg, ${GOLD} 0 1px, transparent 1px 4px)`, border: `1px solid ${accent}` };
      frame.border = `1px solid ${GOLD}`;
      frame.background = '#fffdf8';
      break;
    case 'modern':
      frame.border = '1px solid #e2e8f0';
      frame.paddingLeft = '16%';
      break;
    case 'minimal':
      frame.border = '1px solid #cbd5e1';
      frame.borderTop = `3px solid ${accent}`;
      break;
    default:
      frame.border = `2px solid ${accent}`;
      frame.boxShadow = `inset 0 0 0 2px #fffdf8, inset 0 0 0 3px ${GOLD}`;
  }
  const line = (w: string, key: number) => <span key={key} className="block h-[3px] shrink-0 rounded-full bg-slate-300/80" style={{ width: w }} />;
  return (
    <div className={clsx('relative mx-auto overflow-hidden bg-[#fffdf8] shadow-[0_10px_30px_-14px_rgba(11,42,91,.45)] ring-1 ring-slate-200', land ? 'aspect-[297/210] w-full' : 'aspect-[210/297] w-[70%]', className)}>
      <div className="absolute inset-[4%]" style={ring}>
        <div className="relative flex h-full flex-col items-center px-[9%] py-[7%]" style={frame}>
          {borderStyle === 'modern' && <span className="absolute inset-y-0 left-0 w-[9%]" style={{ background: `linear-gradient(180deg, ${accent} 0%, ${accent} 62%, #22943F 100%)` }} />}
          <span className="h-[9%] w-[13%] rounded-full" style={{ background: accent, opacity: 0.85 }} />
          <span className="mt-[3%] block h-[3px] w-[62%] rounded-full" style={{ background: accent, opacity: 0.7 }} />
          <span className="mt-[2%] block h-[2px] w-[40%] rounded-full bg-slate-300" />
          <p className="mt-[7%] line-clamp-2 w-full shrink-0 text-center font-serif text-[8px] font-bold uppercase leading-tight tracking-[0.12em]" style={{ color: accent }}>{title}</p>
          <div className={clsx('mt-[6%] flex min-h-0 w-full flex-col gap-[5px] overflow-hidden', land && 'items-center')}>
            {[ '100%', '96%', '100%', '88%', '100%', '72%' ].slice(0, land ? 4 : 6).map((w, i) => line(w, i))}
          </div>
          <div className="mt-auto flex w-full shrink-0 items-end justify-between pt-[3%]">
            {showQr ? <span className="block aspect-square w-[14%] shrink-0 bg-[repeating-linear-gradient(90deg,#334155_0_2px,transparent_2px_4px)] opacity-70" /> : <span className="w-[14%]" />}
            {showSeal ? <span className="block aspect-square w-[16%] rounded-full border-2" style={{ borderColor: accent, opacity: 0.7 }} /> : <span />}
            <span className="block h-[2px] w-[24%] bg-slate-500" />
          </div>
        </div>
      </div>
    </div>
  );
}
