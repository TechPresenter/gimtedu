import { API_BASE } from './config';

/**
 * Typed fetch wrapper for the PHP JSON API.
 *
 *   const data = await api.get<Student[]>('students', { page: 2 });
 *   await api.post('crud/departments', { name: 'Law', code: 'LAW' });
 *   await api.post('crud/students/5', formData);        // multipart (files)
 *   await api.del('crud/departments/7');
 *
 * Every non-GET request carries the X-CSRF-Token header. On 419 the token is refreshed once and the request retried.
 * On 401 an `auth:expired` window event is dispatched (the AuthProvider redirects to the login page).
 */

export type QueryValue = string | number | boolean | null | undefined | Array<string | number> | Record<string, unknown>;
export type Query = Record<string, QueryValue>;

export class ApiError extends Error {
  status: number;
  errors: Record<string, string>;
  data: unknown;
  constructor(message: string, status: number, errors: Record<string, string> = {}, data: unknown = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.data = data;
  }
}

interface ApiEnvelope<T> {
  ok: boolean;
  message?: string;
  data?: T;
  errors?: Record<string, string>;
}

let csrfToken = '';
export function setCsrfToken(token: string | undefined | null) {
  if (token) csrfToken = token;
}
export function getCsrfToken() {
  return csrfToken;
}

/** Serialize nested query objects PHP-style: f[status]=active, f[date][from]=... */
export function buildQuery(query?: Query): string {
  if (!query) return '';
  const parts: string[] = [];
  const add = (key: string, value: unknown) => {
    if (value === undefined || value === null || value === '') return;
    if (Array.isArray(value)) {
      value.forEach((v) => add(`${key}[]`, v));
    } else if (typeof value === 'object') {
      Object.entries(value as Record<string, unknown>).forEach(([k, v]) => add(`${key}[${k}]`, v));
    } else if (typeof value === 'boolean') {
      parts.push(`${encodeURIComponent(key)}=${value ? 1 : 0}`);
    } else {
      parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
    }
  };
  Object.entries(query).forEach(([k, v]) => add(k, v));
  return parts.length ? `?${parts.join('&')}` : '';
}

export function apiUrl(path: string, query?: Query): string {
  return `${API_BASE}/${path.replace(/^\/+/, '')}${buildQuery(query)}`;
}

interface RequestOptions {
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
  /** Polling requests must not keep an idle session alive. */
  background?: boolean;
  headers?: Record<string, string>;
}

async function refreshCsrf(): Promise<void> {
  const res = await fetch(apiUrl('auth/session'), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  const json = (await res.json()) as ApiEnvelope<{ csrf_token: string }>;
  setCsrfToken(json.data?.csrf_token);
}

export interface ApiResult<T> {
  data: T;
  message: string;
}

async function request<T>(method: string, path: string, opts: RequestOptions = {}, retried = false): Promise<ApiResult<T>> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(opts.headers ?? {}) };
  let body: BodyInit | undefined;
  if (opts.body instanceof FormData) {
    body = opts.body;
  } else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(opts.body);
  }
  if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken;
  if (opts.background) headers['X-Background'] = '1';
  // Multipart bodies cannot be sent with PUT/PATCH/DELETE by PHP - tunnel through POST.
  let httpMethod = method;
  if (opts.body instanceof FormData && method !== 'POST' && method !== 'GET') {
    headers['X-HTTP-Method-Override'] = method;
    httpMethod = 'POST';
  }
  let res: Response;
  try {
    res = await fetch(apiUrl(path, opts.query), { method: httpMethod, headers, body, credentials: 'same-origin', signal: opts.signal });
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e;
    throw new ApiError('Unable to reach the server. Check your connection and try again.', 0);
  }
  let json: ApiEnvelope<T> | null = null;
  const text = await res.text();
  try {
    json = text ? (JSON.parse(text) as ApiEnvelope<T>) : null;
  } catch {
    json = null;
  }
  if (res.status === 419 && !retried) {
    await refreshCsrf();
    return request<T>(method, path, opts, true);
  }
  if (res.status === 401 && !path.startsWith('auth/')) {
    window.dispatchEvent(new CustomEvent('auth:expired'));
  }
  if (!res.ok || !json || json.ok === false) {
    const message = json?.message || (res.status >= 500 ? 'Something went wrong on the server. Please try again.' : `Request failed (${res.status}).`);
    throw new ApiError(message, res.status, (json?.errors as Record<string, string>) ?? {}, json?.data ?? null);
  }
  return { data: json.data as T, message: json.message ?? '' };
}

export const api = {
  get: <T = unknown>(path: string, query?: Query, opts: Omit<RequestOptions, 'query' | 'body'> = {}) =>
    request<T>('GET', path, { ...opts, query }).then((r) => r.data),
  /** Same as get() but also returns the server message. */
  getFull: <T = unknown>(path: string, query?: Query) => request<T>('GET', path, { query }),
  post: <T = unknown>(path: string, body?: unknown, opts: Omit<RequestOptions, 'body'> = {}) => request<T>('POST', path, { ...opts, body }),
  put: <T = unknown>(path: string, body?: unknown) => request<T>('PUT', path, { body }),
  patch: <T = unknown>(path: string, body?: unknown) => request<T>('PATCH', path, { body }),
  del: <T = unknown>(path: string, body?: unknown) => request<T>('DELETE', path, { body }),
};

/** Convert a plain object (with File values) to FormData. Arrays/objects are JSON-encoded into "__json". */
export function toFormData(values: Record<string, unknown>): FormData {
  const fd = new FormData();
  const json: Record<string, unknown> = {};
  Object.entries(values).forEach(([k, v]) => {
    if (v instanceof File || v instanceof Blob) fd.append(k, v);
    else if (v === undefined) return;
    else if (v === null) fd.append(k, '');
    else if (typeof v === 'boolean') fd.append(k, v ? '1' : '0');
    else if (Array.isArray(v) || typeof v === 'object') json[k] = v;
    else fd.append(k, String(v));
  });
  if (Object.keys(json).length) fd.append('__json', JSON.stringify(json));
  return fd;
}

/** Trigger a file download from an authenticated GET endpoint (exports, templates). */
export function downloadFile(path: string, query?: Query) {
  const a = document.createElement('a');
  a.href = apiUrl(path, query);
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

/** Open an authenticated GET endpoint in a new tab (print views, PDF). */
export function openInNewTab(url: string) {
  window.open(url, '_blank', 'noopener');
}
