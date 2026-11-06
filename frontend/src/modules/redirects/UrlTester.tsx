import { useEffect, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { ArrowDown, CircleCheck, CircleX, ExternalLink, FlaskConical, Globe, TriangleAlert } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, Input } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { codeColor, type TraceResult } from './types';

/** "Test a URL": shows every redirect hop (admin rules, lower-case, trailing slash) and the final answer — no request is made. */
export function UrlTester({ initial = '', autoRun = false }: { initial?: string; autoRun?: boolean }) {
  const [url, setUrl] = useState(initial);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<TraceResult | null>(null);

  const run = async (value = url) => {
    if (!value.trim()) {
      setError('Enter a path such as /old-page or a full URL of this website.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await api.post<TraceResult>('redirects/test', { url: value.trim() });
      setResult(res.data);
    } catch (e) {
      const ex = e as ApiError;
      setResult(null);
      setError(ex.errors?.url ?? ex.message);
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    setUrl(initial);
    if (autoRun && initial) void run(initial);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initial, autoRun]);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    void run();
  };
  const final = result?.final;
  const finalTone = !final ? 'slate' : final.external ? 'blue' : final.status === 200 ? 'green' : final.status === 410 ? 'amber' : 'red';

  return (
    <Card>
      <CardHeader icon={FlaskConical} title="Test a URL" subtitle="See exactly where a visitor or search engine ends up — admin rules, lower-case and trailing-slash fixes included." />
      <div className="card-body space-y-5">
        <form onSubmit={submit} className="flex flex-col gap-2 sm:flex-row">
          <label htmlFor="rd-test-url" className="sr-only">URL to test</label>
          <Input id="rd-test-url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="/old-page or https://…" className="font-mono text-sm sm:flex-1" prefix={<Globe className="h-4 w-4" />}
            invalid={!!error} autoComplete="off" spellCheck={false} />
          <Button type="submit" loading={busy} icon={FlaskConical}>Test</Button>
        </form>
        {error && <Alert variant="error">{error}</Alert>}
        {result && (
          <div className="space-y-3 motion-safe:animate-fade-in">
            {result.hops.length === 0 ? (
              <p className="text-sm text-slate-600 dark:text-slate-300">No redirect applies to this URL.</p>
            ) : (
              <ol className="space-y-2" aria-label="Redirect hops">
                {result.hops.map((h, i) => (
                  <li key={i}>
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800/40">
                      <Badge color={codeColor(h.code)}>{h.code}</Badge>
                      <code className="break-all text-xs text-slate-800 dark:text-slate-100">{h.from}</code>
                      <span className="text-xs text-slate-500">{h.reason}</span>
                    </div>
                    {i < result.hops.length - 1 || final ? <ArrowDown className="mx-auto my-1 h-4 w-4 text-slate-300 dark:text-slate-600" aria-hidden /> : null}
                  </li>
                ))}
              </ol>
            )}
            {final && (
              <div className={clsx('flex flex-wrap items-center gap-3 rounded-xl border px-3.5 py-3 text-sm',
                finalTone === 'green' ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-500/30 dark:bg-emerald-500/10'
                  : finalTone === 'red' ? 'border-red-200 bg-red-50/70 dark:border-red-500/30 dark:bg-red-500/10'
                    : finalTone === 'amber' ? 'border-amber-200 bg-amber-50/70 dark:border-amber-500/30 dark:bg-amber-500/10'
                      : 'border-blue-200 bg-blue-50/70 dark:border-blue-500/30 dark:bg-blue-500/10')}>
                {finalTone === 'green' ? <CircleCheck className="h-5 w-5 shrink-0 text-emerald-600" /> : finalTone === 'red' ? <CircleX className="h-5 w-5 shrink-0 text-red-600" />
                  : finalTone === 'amber' ? <TriangleAlert className="h-5 w-5 shrink-0 text-amber-600" /> : <Globe className="h-5 w-5 shrink-0 text-blue-600" />}
                <div className="min-w-0 flex-1">
                  <p className="font-semibold text-slate-900 dark:text-white">
                    {final.external ? 'Leaves this website' : final.status === 200 ? 'Ends on a live page (200)' : final.status === 410 ? 'Answers 410 Gone' : 'Ends on “page not found” (404)'}
                  </p>
                  {final.url && <p className="break-all font-mono text-xs text-slate-600 dark:text-slate-300">{final.url}</p>}
                </div>
                {final.url && <Button size="sm" variant="secondary" icon={ExternalLink} href={final.url} target="_blank">Open</Button>}
              </div>
            )}
            {result.warnings.map((w) => <Alert key={w} variant="warning">{w}</Alert>)}
          </div>
        )}
      </div>
    </Card>
  );
}
