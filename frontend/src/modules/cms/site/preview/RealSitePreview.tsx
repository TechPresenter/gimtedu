import { useLayoutEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { Globe, Zap } from 'lucide-react';
import { Spinner } from '@/components/ui';
import { BASE_PATH } from '@/lib/config';

export type PreviewView = 'real' | 'quick';

/** Remembered choice between the real website frame and the quick drawing (per screen, `storageKey`). Default: real. */
export function usePreviewView(storageKey: string): [PreviewView, (v: PreviewView) => void] {
  const [view, setView] = useState<PreviewView>(() => {
    try {
      return localStorage.getItem(storageKey) === 'quick' ? 'quick' : 'real';
    } catch {
      return 'real';
    }
  });
  const choose = (v: PreviewView) => {
    setView(v);
    try {
      localStorage.setItem(storageKey, v);
    } catch {
      /* private window: not remembered */
    }
  };
  return [view, choose];
}

/** "Real website | Quick" pill switch. */
export function PreviewViewToggle({ value, onChange }: { value: PreviewView; onChange: (v: PreviewView) => void }) {
  return (
    <div className="inline-flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800" role="tablist" aria-label="Preview type">
      {([['real', Globe, 'Real website'], ['quick', Zap, 'Quick']] as const).map(([v, Icon, label]) => (
        <button key={v} type="button" role="tab" aria-selected={value === v} onClick={() => onChange(v)}
          className={clsx('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold', value === v ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500')}>
          <Icon className="h-3.5 w-3.5" />{label}
        </button>
      ))}
    </div>
  );
}

/**
 * A real website page in a frame (saved version, exactly as visitors see it), at a desktop (1366 px) or phone (390 px)
 * width scaled down to fit the pane; it scrolls inside. `?preview=admin` keeps self-opening popups, the cookie banner and
 * analytics tags out of the frame (includes/config.php site_admin_preview()); `params` adds query values (e.g.
 * banner=<id> = Banners › Preview, site_banner_preview()). `scrollTo` = CSS selector scrolled into view once loaded.
 * `version` reloads it after a save. Same origin as the admin panel (the website allows framing only by itself).
 */
export function RealSitePreview({ device, version, height, path = '/', params, scrollTo, title = 'Website page — real preview' }: {
  device: 'desktop' | 'mobile';
  version: number;
  height: number;
  path?: string;
  params?: Record<string, string | number>;
  scrollTo?: string;
  title?: string;
}) {
  const outer = useRef<HTMLDivElement>(null);
  const [avail, setAvail] = useState(0);
  const [loadedSrc, setLoadedSrc] = useState('');
  const width = device === 'mobile' ? 390 : 1366;

  useLayoutEffect(() => {
    const o = outer.current;
    if (!o) return;
    const measure = () => setAvail(o.clientWidth);
    measure();
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(measure);
    ro.observe(o);
    return () => ro.disconnect();
  }, []);

  const scale = avail ? Math.min(1, avail / width) : 1;
  const qs = new URLSearchParams({ preview: 'admin', ...Object.fromEntries(Object.entries(params ?? {}).map(([k, v]) => [k, String(v)])), v: String(version) });
  const src = `${BASE_PATH}/${path.replace(/^\/+/, '')}?${qs.toString()}`;
  const loading = loadedSrc !== src;

  const onLoad = (e: React.SyntheticEvent<HTMLIFrameElement>) => {
    setLoadedSrc(src);
    if (!scrollTo) return;
    try {
      const doc = e.currentTarget.contentDocument;
      const el = doc?.querySelector(scrollTo);
      const win = e.currentTarget.contentWindow;
      if (el && win) win.scrollTo({ top: Math.max(0, el.getBoundingClientRect().top + win.scrollY - 120) });
    } catch {
      /* another origin: leave it at the top */
    }
  };

  return (
    <div ref={outer} className="relative w-full overflow-hidden bg-[#E9E4DE]" style={{ height }}>
      {avail > 0 && (
        <iframe
          key={src}
          src={src}
          title={title}
          onLoad={onLoad}
          className="block border-0 bg-white"
          style={{
            width,
            height: Math.round(height / scale),
            transform: scale < 1 ? `scale(${scale})` : undefined,
            transformOrigin: 'top left',
            marginLeft: scale < 1 ? 0 : Math.max(0, (avail - width) / 2),
          }}
        />
      )}
      {loading && (
        <div className="absolute inset-0 flex items-center justify-center gap-2 bg-white/80 text-sm text-slate-500 dark:bg-slate-900/80 dark:text-slate-400" role="status">
          <Spinner className="h-5 w-5" /> Loading the website…
        </div>
      )}
    </div>
  );
}
