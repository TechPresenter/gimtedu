import { ApiError } from '@/lib/api';

/** Human message for any thrown value. */
export function errorMessage(e: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (e instanceof ApiError) return e.message || fallback;
  if (e instanceof Error && e.message) return e.message;
  return fallback;
}

/** Field errors of a 422 response ({} for anything else). */
export function fieldErrors(e: unknown): Record<string, string> {
  return e instanceof ApiError ? e.errors ?? {} : {};
}

export function errorStatus(e: unknown): number {
  return e instanceof ApiError ? e.status : 0;
}

/** 403 sent by portal endpoints while a temporary password still has to be changed. */
export function isPasswordChangeRequired(e: unknown): boolean {
  return e instanceof ApiError && e.status === 403 && (e.data as { code?: string } | null)?.code === 'password_change_required';
}

/** Visual tone of an auth error: lockouts / throttling are warnings, everything else an error. */
export function authErrorTone(e: unknown): 'warning' | 'error' {
  const s = errorStatus(e);
  return s === 423 || s === 429 ? 'warning' : 'error';
}
