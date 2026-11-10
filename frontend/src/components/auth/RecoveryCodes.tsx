import { useEffect, useRef, useState } from 'react';
import { Check, Copy, Download, LifeBuoy, Printer } from 'lucide-react';
import { Alert, Button, Checkbox, useToast } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import { formatDateTime } from '@/lib/format';
import { copyText } from './clipboard';

interface Props {
  codes: string[];
  /** Account the codes belong to (shown in the download / print-out). */
  account?: string;
  /** Show the "I have saved these codes" checkbox. */
  acknowledged?: boolean;
  onAcknowledge?: (v: boolean) => void;
}

const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);

/**
 * One-time recovery codes, shown right after they were generated (the server never shows them again): copy, download
 * as .txt or print, and an optional "I have saved these codes" acknowledgement.
 */
export function RecoveryCodes({ codes, account, acknowledged, onAcknowledge }: Props) {
  const { session } = useAuth();
  const toast = useToast();
  const [copied, setCopied] = useState(false);
  const timer = useRef<number>();
  useEffect(() => () => window.clearTimeout(timer.current), []);

  const issuer = session?.app.short_name || 'GIMT';
  const product = `${issuer} SmartCampus`;
  const [generated] = useState(() => formatDateTime(new Date()));
  const intro = [`${product} - two-factor recovery codes`, account ? `Account: ${account}` : '', `Generated: ${generated}`].filter(Boolean);
  const notes = [
    'Each code signs you in once when you cannot use your authenticator app.',
    'Keep them private, e.g. in a password manager or printed in a safe place.',
    'Generating new codes makes all of these codes stop working.',
  ];

  const copy = async () => {
    if (await copyText(codes.join('\n'))) {
      setCopied(true);
      window.clearTimeout(timer.current);
      timer.current = window.setTimeout(() => setCopied(false), 2000);
      toast.success('Recovery codes copied.');
    } else {
      toast.error('Copying is blocked in this browser. Download or print the codes instead.');
    }
  };

  const download = () => {
    const text = [...intro, '', ...codes.map((c, i) => `${String(i + 1).padStart(2, ' ')}. ${c}`), '', ...notes, ''].join('\r\n');
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `${issuer.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-recovery-codes.txt`;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
  };

  /** Print from a hidden frame: no pop-up window and the admin page stays where it is. */
  const print = () => {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    document.body.appendChild(frame);
    const doc = frame.contentDocument;
    const win = frame.contentWindow;
    if (!doc || !win) {
      frame.remove();
      return;
    }
    doc.open();
    doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(product)} recovery codes</title><style>
      body{font-family:Inter,Segoe UI,Arial,sans-serif;color:#0f172a;margin:32px}
      h1{font-size:18px;margin:0 0 4px}p{margin:2px 0;font-size:12px;color:#475569}
      ol{display:grid;grid-template-columns:1fr 1fr;gap:10px 32px;margin:24px 0;padding:16px 16px 16px 40px;border:1px dashed #94a3b8;border-radius:12px}
      li{font:600 16px/1.4 ui-monospace,Consolas,monospace;letter-spacing:.08em}
      ul{padding-left:18px;font-size:12px;color:#475569}
    </style></head><body>
      <h1>${esc(intro[0])}</h1>${intro.slice(1).map((l) => `<p>${esc(l)}</p>`).join('')}
      <ol>${codes.map((c) => `<li>${esc(c)}</li>`).join('')}</ol>
      <ul>${notes.map((n) => `<li>${esc(n)}</li>`).join('')}</ul>
    </body></html>`);
    doc.close();
    win.focus();
    win.print();
    window.setTimeout(() => frame.remove(), 1000);
  };

  return (
    <div className="space-y-4">
      <Alert variant="warning" title="Save these codes now">
        You will not see them again. Each code works once to sign in if you lose your phone or cannot open your authenticator app.
      </Alert>
      <div className="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/60">
        <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
          <LifeBuoy className="h-4 w-4" aria-hidden /> {codes.length} recovery codes
        </div>
        <ol className="grid grid-cols-2 gap-x-4 gap-y-2" aria-label="Recovery codes">
          {codes.map((c, i) => (
            <li key={c} className="flex items-baseline gap-2">
              <span className="w-5 shrink-0 text-right text-[11px] tabular-nums text-slate-400" aria-hidden>
                {i + 1}.
              </span>
              <code className="select-all font-mono text-[15px] font-semibold tracking-wider text-slate-900 dark:text-white">{c}</code>
            </li>
          ))}
        </ol>
      </div>
      <div className="grid grid-cols-3 gap-2">
        <Button variant="secondary" size="sm" icon={copied ? Check : Copy} onClick={copy}>
          {copied ? 'Copied' : 'Copy'}
        </Button>
        <Button variant="secondary" size="sm" icon={Download} onClick={download}>
          Download
        </Button>
        <Button variant="secondary" size="sm" icon={Printer} onClick={print}>
          Print
        </Button>
      </div>
      {onAcknowledge && (
        <div className="rounded-xl border border-slate-200 px-3.5 py-3 dark:border-slate-700">
          <Checkbox
            label="I have saved these codes"
            description="Stored in a password manager, downloaded or printed."
            checked={!!acknowledged}
            onChange={(e) => onAcknowledge(e.target.checked)}
          />
        </div>
      )}
    </div>
  );
}
