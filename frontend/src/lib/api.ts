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
 * On 403 {code: 'password_change_required'} an `auth:password-change` event is dispatched (the admin layout then shows the
 * mandatory "Set a new password" screen until the password has been changed).
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
  /** Machine-readable error code, e.g. 'password_change_required'. */
  code?: string;
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
  if (res.status === 403 && json?.code === 'password_change_required') {
    const reason = (json.data as { reason?: string } | undefined)?.reason;
    window.dispatchEvent(new CustomEvent('auth:password-change', { detail: { reason: reason === 'expired' ? 'expired' : 'required' } }));
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

/* ------------------------------------------------------------------ downloads */

type DownloadErrorReporter = (message: string, error: ApiError) => void;
let downloadErrorReporter: DownloadErrorReporter | null = null;

/**
 * Where downloadFile() reports failures when the caller does not handle them (the admin routes register the app's
 * toast — see modules/shared.tsx). Returns a function that unregisters it.
 */
export function setDownloadErrorReporter(fn: DownloadErrorReporter): () => void {
  downloadErrorReporter = fn;
  return () => {
    if (downloadErrorReporter === fn) downloadErrorReporter = null;
  };
}

export interface DownloadOptions {
  /** File name when the response has no Content-Disposition (default: last path segment + extension of the type). */
  filename?: string;
  /**
   * true (default): a failure is shown as an error toast and the promise resolves with null — fire-and-forget callers
   * (`onClick={() => downloadFile(...)}`) need no error handling. false: the promise rejects with the ApiError and the
   * caller shows it.
   */
  notify?: boolean;
  signal?: AbortSignal;
}

/** Files above this size go to the browser's own download manager (progress bar, no copy held in memory). */
const DOWNLOAD_STREAM_LIMIT = 64 * 1024 * 1024;

const DOWNLOAD_EXT: Record<string, string> = {
  'text/csv': 'csv',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'application/pdf': 'pdf',
  'application/zip': 'zip',
  'application/gzip': 'gz',
  'application/xml': 'xml',
  'text/xml': 'xml',
  'text/plain': 'txt',
  'application/json': 'json',
};

function dispositionFilename(header: string | null): string | null {
  if (!header) return null;
  const star = /filename\*\s*=\s*([^']*)'[^']*'([^;]+)/i.exec(header);
  if (star) {
    try {
      return decodeURIComponent(star[2].trim().replace(/^"|"$/g, ''));
    } catch {
      /* fall through to the plain filename */
    }
  }
  const plain = /filename\s*=\s*(?:"((?:\\.|[^"\\])*)"|([^;]+))/i.exec(header);
  const name = plain ? (plain[1] ?? plain[2] ?? '').replace(/\\(.)/g, '$1').trim() : '';
  return name || null;
}

function saveBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  a.rel = 'noopener';
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/** Navigate a hidden link to the URL (the browser downloads it because the server sends Content-Disposition: attachment). */
function nativeDownload(url: string) {
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

async function fetchDownload(path: string, query: Query | undefined, opts: DownloadOptions): Promise<string> {
  const url = apiUrl(path, query);
  const ctrl = new AbortController();
  const onAbort = () => ctrl.abort();
  opts.signal?.addEventListener('abort', onAbort, { once: true });
  let res: Response;
  try {
    res = await fetch(url, { credentials: 'same-origin', headers: { Accept: '*/*' }, signal: ctrl.signal });
  } catch (e) {
    opts.signal?.removeEventListener('abort', onAbort);
    if ((e as Error).name === 'AbortError') throw e;
    throw new ApiError('Unable to reach the server. Check your connection and try again.', 0);
  }
  try {
    const type = (res.headers.get('Content-Type') ?? '').split(';')[0].trim().toLowerCase();
    const disposition = res.headers.get('Content-Disposition');
    const attachment = /attachment|filename/i.test(disposition ?? '');
    const isJson = type === 'application/json' || type.endsWith('+json');
    if (!res.ok || (isJson && !attachment)) {
      let json: ApiEnvelope<unknown> | null = null;
      try {
        json = (await res.json()) as ApiEnvelope<unknown>;
      } catch {
        json = null;
      }
      if (res.status === 401) window.dispatchEvent(new CustomEvent('auth:expired'));
      if (res.status === 403 && json?.code === 'password_change_required') {
        const reason = (json.data as { reason?: string } | undefined)?.reason;
        window.dispatchEvent(new CustomEvent('auth:password-change', { detail: { reason: reason === 'expired' ? 'expired' : 'required' } }));
      }
      const message =
        (json && typeof json.message === 'string' && json.message) ||
        (res.status >= 500 ? 'Something went wrong on the server. Please try again.' : res.ok ? 'The server did not send a file.' : `The file could not be downloaded (${res.status}).`);
      throw new ApiError(message, res.ok ? 422 : res.status, json?.errors ?? {}, json?.data ?? null);
    }
    const ext = DOWNLOAD_EXT[type];
    const base = path.split('?')[0].replace(/\/+$/, '').split('/').pop() || 'download';
    const name = dispositionFilename(disposition) ?? opts.filename ?? (ext && !base.toLowerCase().endsWith(`.${ext}`) ? `${base}.${ext}` : base);
    const size = Number(res.headers.get('Content-Length') ?? 0);
    if (size > DOWNLOAD_STREAM_LIMIT) {
      // access is confirmed (200 + a file): let the browser fetch the big file itself instead of buffering it here
      ctrl.abort();
      nativeDownload(url);
      return name;
    }
    saveBlob(await res.blob(), name);
    return name;
  } finally {
    opts.signal?.removeEventListener('abort', onAbort);
  }
}

/**
 * Download a file from an authenticated GET endpoint (exports, import templates, backups). The file is fetched first,
 * so a refusal (403 export not allowed, 404, 422 …) or a JSON error is reported instead of the browser leaving the
 * admin for a raw JSON page; the file is saved under the name from Content-Disposition. Resolves with the file name
 * (null when the failure was shown as a toast — see DownloadOptions.notify).
 *
 *   downloadFile('crud/students/export', { format: 'csv' });                       // fire and forget, errors → toast
 *   await downloadFile('x/export', q, { notify: false }).catch((e: ApiError) => …); // handle errors yourself
 */
export async function downloadFile(path: string, query?: Query, opts: DownloadOptions = {}): Promise<string | null> {
  try {
    return await fetchDownload(path, query, opts);
  } catch (e) {
    if ((e as Error).name === 'AbortError') return null;
    const err = e instanceof ApiError ? e : new ApiError((e as Error).message || 'The file could not be downloaded.', 0);
    if (opts.notify === false) throw err;
    if (downloadErrorReporter) downloadErrorReporter(err.message, err);
    else window.alert(err.message);
    return null;
  }
}

/** Open an authenticated GET endpoint in a new tab (print views, PDF). */
export function openInNewTab(url: string) {
  window.open(url, '_blank', 'noopener');
}
