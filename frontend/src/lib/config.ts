/**
 * Runtime configuration injected by admin/index.php as window.__GIMT__.
 * In Vite dev mode the defaults below are used (app served at /admin/, API proxied at /api).
 */
interface GimtWindowConfig {
  basePath?: string;
  apiBase?: string;
}

declare global {
  interface Window {
    __GIMT__?: GimtWindowConfig;
  }
}

const injected = window.__GIMT__ ?? {};

/** Path of the PHP application root, e.g. "" or "/gimt". */
export const BASE_PATH = (injected.basePath ?? '').replace(/\/$/, '');
/** API root URL, e.g. "/api" or "/gimt/api". */
export const API_BASE = (injected.apiBase ?? `${BASE_PATH}/api`).replace(/\/$/, '');
/** React Router basename. */
export const ROUTER_BASE = `${BASE_PATH}/admin`;

/** URL for a file inside the PHP app (uploads, print pages, website). */
export function appUrl(path: string): string {
  if (!path) return '';
  if (/^(https?:)?\/\//i.test(path) || path.startsWith('data:') || path.startsWith('blob:')) return path;
  return `${BASE_PATH}/${path.replace(/^\/+/, '')}`;
}

/** URL for a server-rendered print view: printUrl('receipt.php', { id: 5 }) -> /admin/print/receipt.php?id=5 */
export function printUrl(page: string, params: Record<string, string | number | undefined> = {}): string {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => v !== undefined && v !== '' && qs.set(k, String(v)));
  const q = qs.toString();
  return `${BASE_PATH}/admin/print/${page}${q ? `?${q}` : ''}`;
}
