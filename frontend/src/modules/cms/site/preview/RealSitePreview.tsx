import { useLayoutEffect, useRef, useState } from 'react';
import { Spinner } from '@/components/ui';
import { BASE_PATH } from '@/lib/config';

/**
 * The real website home page in a frame (saved version, exactly as visitors see it), at a desktop (1366 px) or phone
 * (390 px) width scaled down to fit the pane; it scrolls inside. `?preview=admin` keeps popups, the cookie banner and
 * analytics tags out of the frame (includes/config.php site_admin_preview()). `version` reloads it after a save.
 * Same origin as the admin panel (the website allows framing only by itself).
 */
export function RealSitePreview({ device, version, height }: { device: 'desktop' | 'mobile'; version: number; height: number }) {
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
  const src = `${BASE_PATH}/?preview=admin&v=${version}`;
  const loading = loadedSrc !== src;

  return (
    <div ref={outer} className="relative w-full overflow-hidden bg-[#E9E4DE]" style={{ height }}>
      {avail > 0 && (
        <iframe
          key={src}
          src={src}
          title="Website home page — real preview"
          onLoad={() => setLoadedSrc(src)}
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
