import { Link } from 'react-router-dom';
import { ArrowLeft, ShieldAlert } from 'lucide-react';
import { ErrorState } from '../../components/Ui';
import { errorMessage, errorStatus } from '../../lib/errors';

/**
 * Error state for screens opened with ids from the URL: "not yours" (403) and "not found" (404) are final, so the
 * screen offers a way back instead of a retry; everything else falls back to the retryable ErrorState.
 */
export function ScopedError({ error, onRetry, backTo, backLabel, title }: { error: unknown; onRetry: () => void; backTo: string; backLabel: string; title?: string }) {
  const status = errorStatus(error);
  if (status !== 403 && status !== 404) return <ErrorState error={error} onRetry={onRetry} title={title} />;
  return (
    <div className="card flex flex-col items-center px-6 py-12 text-center" role="alert">
      <span className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300">
        <ShieldAlert className="h-6 w-6" aria-hidden />
      </span>
      <h2 className="mt-4 font-display text-base font-bold text-slate-900 dark:text-white">{status === 403 ? 'Not available to you' : 'Not found'}</h2>
      <p className="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">{errorMessage(error)}</p>
      <Link to={backTo} className="btn btn-secondary btn-sm mt-4">
        <ArrowLeft className="h-4 w-4" aria-hidden /> {backLabel}
      </Link>
    </div>
  );
}
