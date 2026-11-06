/** Template designer helpers: named variables, previews, status colours, queries and actions. */
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { BadgeColor } from '@/lib/status';
import type { Tone } from '@/components/ui';
import { api } from '@/lib/api';
import type { WaTemplateCategory, WaTemplatePreviewData, WaTemplateStatus } from '../shared';
import type { DesignerState, TplButton, TplDetail, TplList, TplMeta, TplNamed, TplOtp, TplRow, TplSummary } from './types';

export const VAR_RE = /\{\{\s*([^{}]*?)\s*\}\}/g;
export const KEY_RE = /^[a-z][a-z0-9_]{0,39}$/;

/** lowercase_underscore code name (Meta rule). */
export function slugify(s: string, max = 190): string {
  return s
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, max);
}

/** Variable keys in a named text, in order (duplicates kept). */
export function extractKeys(text: string | null | undefined): string[] {
  const out: string[] = [];
  for (const m of String(text ?? '').matchAll(VAR_RE)) out.push(m[1].trim());
  return out;
}

/** Unique keys used by a designer state (header → body → buttons). */
export function usedKeys(s: Pick<DesignerState, 'category' | 'header_type' | 'header_text' | 'body' | 'buttons'>): string[] {
  if (s.category === 'AUTHENTICATION') return ['otp_code'];
  const keys = [
    ...(s.header_type === 'text' ? extractKeys(s.header_text) : []),
    ...extractKeys(s.body),
    ...s.buttons.flatMap((b) => (b.type === 'URL' ? extractKeys(b.url) : [])),
  ];
  return [...new Set(keys)];
}

/**
 * Variables that hold a whole link (receipt_link, payment_link, website_url, link …). WhatsApp fills a dynamic URL
 * button by appending the value to the fixed address, so these make a broken https://site/x/https://… link
 * (same name rule as wa_tpl_link_valued_key on the server, which also checks catalogue samples).
 */
export function isLinkValuedKey(key: string | null | undefined): boolean {
  const k = String(key ?? '').trim().toLowerCase();
  return k === 'link' || k === 'url' || /(_link|_url)$/.test(k) || k.startsWith('website_');
}

/** The fixed part of a dynamic URL (everything before the variable). */
export function urlBase(url: string | null | undefined): string {
  const u = String(url ?? '');
  const i = u.indexOf('{{');
  return i >= 0 ? u.slice(0, i) : u;
}

/** Character count like the server (code points, positional {{n}} form). */
export function metaLength(named: string): number {
  let n = 0;
  const positional = named.replace(VAR_RE, () => `{{${++n}}}`);
  return Array.from(positional).length;
}

/** Replace {{key}} with its sample (or keep the placeholder when showVars / no sample). */
export function fillSamples(text: string | null | undefined, samples: Record<string, string>, showVars = false): string {
  return String(text ?? '').replace(VAR_RE, (_all, k: string) => {
    const v = samples[k.trim()];
    return !showVars && v ? v : `{{${k.trim()}}}`;
  });
}

export function otpBody(o: TplOtp, code: string): string {
  return `*${code || '123456'}* is your verification code.${o.add_security_recommendation ? ' For your security, do not share this code.' : ''}`;
}

/** Preview data for WaBubble from designer-like input. */
export function previewData(
  s: Pick<DesignerState, 'category' | 'header_type' | 'header_text' | 'header_media_url' | 'header_media_name' | 'body' | 'footer' | 'buttons' | 'otp'>,
  samples: Record<string, string>,
  showVars = false,
): WaTemplatePreviewData {
  if (s.category === 'AUTHENTICATION') {
    return {
      header_type: 'none',
      body: otpBody(s.otp, showVars ? '{{otp_code}}' : samples.otp_code || '482913'),
      footer: `This code expires in ${s.otp.code_expiration_minutes || 10} minutes.`,
      buttons: [{ type: 'OTP', text: s.otp.button_text || 'Copy code' }],
    };
  }
  return {
    header_type: s.header_type,
    header_text: s.header_type === 'text' ? fillSamples(s.header_text, samples, showVars) : null,
    header_media_url: s.header_media_url || null,
    file_name: s.header_media_name || null,
    body: fillSamples(s.body, samples, showVars) || '_Your message appears here as you type…_',
    footer: s.footer || null,
    buttons: s.buttons.map((b) => ({
      type: b.type,
      text: b.type === 'COPY_CODE' ? 'Copy offer code' : b.text,
      url: b.url ? fillSamples(b.url, samples, showVars) : undefined,
      phone_number: b.phone_number,
    })),
  };
}

/** Preview data straight from a stored template (positional → named → samples). */
export function previewFromNamed(t: TplRow, named: TplNamed, showVars = false): WaTemplatePreviewData {
  return previewData(
    {
      category: t.category,
      header_type: t.header_type,
      header_text: named.header_text,
      header_media_url: t.header_media_url ?? '',
      header_media_name: t.header_media_name ?? '',
      body: named.body,
      footer: t.footer ?? '',
      buttons: named.buttons,
      otp: { ...DEFAULT_OTP, ...(t.otp_settings ?? {}) },
    },
    named.samples,
    showVars,
  );
}

/** Lightweight named form of a list row (positional → {{key}} using its mapping) for cards. */
export function namedBody(t: Pick<TplRow, 'body' | 'variables'>): string {
  const byPos = new Map((t.variables?.body ?? []).map((v) => [Number(v.pos), v.key]));
  return String(t.body ?? '').replace(/\{\{\s*(\d+)\s*\}\}/g, (_all, p: string) => `{{${byPos.get(Number(p)) || `var_${p}`}}}`);
}

export const DEFAULT_OTP: TplOtp = { code_expiration_minutes: 10, add_security_recommendation: true, otp_type: 'COPY_CODE', button_text: 'Copy code' };

export function emptyDesigner(lang = 'en'): DesignerState {
  return {
    name: '',
    display_name: '',
    description: '',
    category: 'UTILITY',
    language: lang,
    header_type: 'none',
    header_text: '',
    header_media_url: '',
    header_media_name: '',
    body: '',
    footer: '',
    button_type: 'none',
    buttons: [],
    samples: {},
    otp: { ...DEFAULT_OTP },
  };
}

/** Detail (stored template) → designer state. */
export function designerFromDetail(d: TplDetail): DesignerState {
  const t = d.template;
  const bt = d.named.button_type === 'otp' ? 'none' : d.named.button_type;
  return {
    name: t.name,
    display_name: t.display_name ?? '',
    description: t.description ?? '',
    category: t.category,
    language: t.language,
    header_type: t.header_type,
    header_text: d.named.header_text ?? '',
    header_media_url: t.header_media_url ?? '',
    header_media_name: t.header_media_name ?? '',
    body: t.category === 'AUTHENTICATION' ? '' : d.named.body,
    footer: t.footer ?? '',
    button_type: t.category === 'AUTHENTICATION' ? 'none' : bt,
    buttons: t.category === 'AUTHENTICATION' ? [] : d.named.buttons,
    samples: { ...(d.named.samples ?? {}) },
    otp: { ...DEFAULT_OTP, ...(t.otp_settings ?? {}) },
  };
}

/** Payload for POST/PUT. */
export function designerPayload(s: DesignerState): Record<string, unknown> {
  const keys = usedKeys(s);
  const samples: Record<string, string> = {};
  for (const k of keys) samples[k] = s.samples[k] ?? '';
  return {
    name: s.name,
    display_name: s.display_name,
    description: s.description,
    category: s.category,
    language: s.language,
    header_type: s.category === 'AUTHENTICATION' ? 'none' : s.header_type,
    header_text: s.header_type === 'text' ? s.header_text : '',
    header_media_url: ['image', 'video', 'document'].includes(s.header_type) ? s.header_media_url : '',
    header_media_name: ['image', 'video', 'document'].includes(s.header_type) ? s.header_media_name : '',
    body: s.body,
    footer: s.footer,
    button_type: s.button_type,
    buttons: s.button_type === 'none' ? [] : s.buttons,
    samples,
    otp: s.otp,
  };
}

/* ------------------------------------------------------------------ labels / colours */

export const STATUS_COLORS: Record<WaTemplateStatus, BadgeColor> = {
  draft: 'slate',
  pending: 'amber',
  approved: 'green',
  rejected: 'red',
  paused: 'purple',
  disabled: 'red',
  archived: 'slate',
};

export const STATUS_LABELS: Record<WaTemplateStatus, string> = {
  draft: 'Draft',
  pending: 'Pending review',
  approved: 'Approved',
  rejected: 'Rejected',
  paused: 'Paused',
  disabled: 'Disabled',
  archived: 'Archived',
};

export const CATEGORY_TONE: Record<WaTemplateCategory, { badge: BadgeColor; tone: Tone; label: string; bar: string }> = {
  UTILITY: { badge: 'blue', tone: 'blue', label: 'Utility', bar: 'bg-brand-600' },
  MARKETING: { badge: 'amber', tone: 'orange', label: 'Marketing', bar: 'bg-accent-500' },
  AUTHENTICATION: { badge: 'purple', tone: 'purple', label: 'Authentication', bar: 'bg-violet-500' },
};

export function langLabel(meta: TplMeta | undefined, code: string): string {
  return meta?.languages?.[code]?.label ?? code;
}

export function rate(n: number, of: number): string {
  if (!of) return '—';
  return `${Math.round((n / of) * 100)}%`;
}

/** 'Y-m-d H:i:s' (Asia/Kolkata) for "now" — WaBubble time label. */
export function nowServer(): string {
  const d = new Date();
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`;
}

/** Button normalisation when switching group. */
export function newButton(type: TplButton['type']): TplButton {
  switch (type) {
    case 'URL':
      return { type, text: 'Visit website', url: 'https://' };
    case 'PHONE_NUMBER':
      return { type, text: 'Call us', phone_number: '' };
    case 'COPY_CODE':
      return { type, text: 'Copy offer code', example: '' };
    default:
      return { type: 'QUICK_REPLY', text: '' };
  }
}

/* ------------------------------------------------------------------ queries */

export const tplKeys = {
  meta: ['wa', 'templates', 'meta'] as const,
  summary: ['wa', 'templates', 'summary'] as const,
  list: (params: Record<string, unknown>) => ['wa', 'templates', 'list', params] as const,
  detail: (id: number) => ['wa', 'templates', 'detail', id] as const,
};

export function useTplMeta() {
  return useQuery({
    queryKey: tplKeys.meta,
    queryFn: ({ signal }) => api.get<TplMeta>('whatsapp/templates/meta', undefined, { signal }),
    staleTime: 5 * 60_000,
  });
}

export function useTplSummary() {
  return useQuery({
    queryKey: tplKeys.summary,
    queryFn: ({ signal }) => api.get<TplSummary>('whatsapp/templates/summary', undefined, { signal }),
  });
}

export function useTplList(params: Record<string, string | number>) {
  return useQuery({
    queryKey: tplKeys.list(params),
    queryFn: ({ signal }) => api.get<TplList>('whatsapp/templates', params, { signal }),
    placeholderData: keepPreviousData,
  });
}

export function useTplDetail(id: number | null) {
  return useQuery({
    queryKey: tplKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<TplDetail>(`whatsapp/templates/${id}`, undefined, { signal }),
    enabled: !!id,
  });
}
