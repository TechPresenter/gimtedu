import clsx from 'clsx';
import { Braces, Smartphone } from 'lucide-react';
import { WaBubble, WaPhoneFrame, type WaTemplatePreviewData } from '../shared';
import { nowServer } from './lib';

/**
 * Live WhatsApp preview of a template inside the shared phone frame. `showVars` toggles between sample values and
 * the {{variable}} placeholders (highlighted). Pass `onToggleVars` to show the toggle.
 */
export function TemplatePreview({
  data,
  showVars,
  onToggleVars,
  businessName,
  height = 560,
  className,
  caption,
}: {
  data: WaTemplatePreviewData;
  showVars?: boolean;
  onToggleVars?: (v: boolean) => void;
  businessName?: string;
  height?: number;
  className?: string;
  caption?: string;
}) {
  return (
    <div className={className}>
      {onToggleVars && (
        <div className="mb-3 flex items-center justify-center">
          <div className="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium dark:bg-slate-800" role="group" aria-label="Preview mode">
            {[
              { v: false, label: 'Sample values', icon: Smartphone },
              { v: true, label: 'Variables', icon: Braces },
            ].map((o) => (
              <button
                key={String(o.v)}
                type="button"
                aria-pressed={!!showVars === o.v}
                onClick={() => onToggleVars(o.v)}
                className={clsx(
                  'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                  !!showVars === o.v ? 'bg-white text-brand-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200',
                )}
              >
                <o.icon size={13} aria-hidden />
                {o.label}
              </button>
            ))}
          </div>
        </div>
      )}
      <WaPhoneFrame name={businessName} height={height}>
        {/* placeholders are always highlighted: in sample mode only variables without a sample remain */}
        <WaBubble direction="out" template={data} time={nowServer()} status="read" highlightVars />
      </WaPhoneFrame>
      {caption && <p className="mt-2 text-center text-[11px] text-slate-500 dark:text-slate-400">{caption}</p>}
    </div>
  );
}

export default TemplatePreview;
