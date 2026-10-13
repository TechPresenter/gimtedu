import { useCallback, useState } from 'react';
import type { ApiError } from '@/lib/api';

/**
 * Small form state helper for the transport action dialogs (transfer, cancel, renew, complete job…):
 * values + inline field errors from the API (422) + a form-level error + saving flag.
 */
export function useActionForm<T extends Record<string, unknown>>(initial: T) {
  const [values, setValues] = useState<T>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const set = useCallback(<K extends keyof T>(key: K, value: T[K]) => {
    setValues((s) => ({ ...s, [key]: value }));
    setErrors((e) => (e[key as string] ? { ...e, [key as string]: '' } : e));
  }, []);

  const reset = useCallback((next: T) => {
    setValues(next);
    setErrors({});
    setError(null);
  }, []);

  /** Runs the request; on failure keeps field errors + message and returns false. */
  const run = useCallback(async (fn: () => Promise<unknown>): Promise<boolean> => {
    setSaving(true);
    setError(null);
    try {
      await fn();
      return true;
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setError(err.message || 'Something went wrong. Please try again.');
      return false;
    } finally {
      setSaving(false);
    }
  }, []);

  return { values, set, setValues, errors, setErrors, error, setError, saving, run, reset };
}
