/** Shapes returned by api/routes/redirects.php (unit seo_platform). */

export interface RedirectSummary {
  rules: { total: number; active: number; permanent: number; temporary: number; gone: number; hits: number; used_30d: number; last_hit: string | null };
  not_found: { paths: number; hits: number; recent: number; last_seen: string | null };
  warnings: {
    missing_targets: { id: number; from: string; to: string }[];
    missing_count: number;
    chains: { id: number; from: string; to: string; final: string | null }[];
  };
  can: { edit: boolean };
  site_url: string;
}

export interface RedirectCheck {
  errors: Record<string, string>;
  notes: string[];
  normalized: { from_path: string | null; to_url: string | null; status_code: number; from_url: string | null; to_full: string | null };
}

export interface TraceHop {
  from: string;
  to: string | null;
  code: number;
  reason: string;
  rule_id: number | null;
}

export interface TraceResult {
  ok: boolean;
  hops: TraceHop[];
  final: { url: string | null; path: string | null; status: number | null; exists: boolean | null; external: boolean };
  warnings: string[];
}

export interface NotFoundRow {
  id: number;
  path: string;
  hits: number;
  bot_hits: number;
  last_referrer: string | null;
  first_seen_at: string;
  last_seen_at: string;
  internal_referrer: boolean;
  suggestion: string | null;
  url: string;
}

export interface RedirectDraft {
  id?: number | null;
  from_path: string;
  to_url: string;
  status_code: string;
  preserve_query: boolean;
  is_active: boolean;
  notes: string;
  source?: string;
}

export const CODE_OPTIONS = [
  { value: '301', label: '301 · Permanent' },
  { value: '302', label: '302 · Temporary' },
  { value: '307', label: '307 · Temporary, keep method' },
  { value: '308', label: '308 · Permanent, keep method' },
  { value: '410', label: '410 · Gone (removed)' },
];

export function codeColor(code: string | number): 'blue' | 'amber' | 'red' | 'slate' {
  const c = Number(code);
  if (c === 410) return 'red';
  if (c === 302 || c === 307) return 'amber';
  if (c === 301 || c === 308) return 'blue';
  return 'slate';
}
