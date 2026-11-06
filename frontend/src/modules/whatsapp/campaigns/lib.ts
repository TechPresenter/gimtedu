import type { WaTemplatePreviewData } from '../shared';
import type { CampaignAudience, CampaignDetail, CampaignTemplate, VariableMap } from './types';

/** Editor state of a campaign draft. */
export interface Draft {
  name: string;
  type: string;
  description: string;
  template_id: number | null;
  audience_source: 'filters' | 'csv' | 'mixed';
  audience: CampaignAudience;
  variable_map: VariableMap;
  rate_per_minute: string;
  respect_business_hours: boolean;
}

export const EMPTY_AUDIENCE: CampaignAudience = {
  programs: [],
  stages: [],
  admission_status: [],
  payment_status: [],
  cities: [],
  states: [],
  batches: [],
  tags: [],
  contact_types: [],
  segment_id: null,
  opt_in_only: true,
};

export function draftFromCampaign(c: CampaignDetail): Draft {
  return {
    name: c.name,
    type: c.type,
    description: c.description ?? '',
    template_id: c.template_id,
    audience_source: c.audience_source,
    audience: { ...EMPTY_AUDIENCE, ...(c.audience ?? {}) },
    variable_map: c.variable_map && !Array.isArray(c.variable_map) ? c.variable_map : {},
    rate_per_minute: c.rate_per_minute ? String(c.rate_per_minute) : '',
    respect_business_hours: !!c.respect_business_hours,
  };
}

/**
 * Link to the campaign editor. The `/:id/edit` route only needs `view` (routes.tsx); CampaignEditorPage enforces the
 * finer rules itself (edit, or create for one's own draft, or publish for the Review step). The third argument is
 * kept for existing callers and no longer changes the path; old `/:id?step=…` links still open the editor too.
 */
export function editorPath(id: number, step?: string, _hasEditPermission?: boolean): string {
  return `/whatsapp/campaigns/${id}/edit${step ? `?step=${encodeURIComponent(step)}` : ''}`;
}

/** Replace {{n}} placeholders (1-based) with values; unknown positions stay. */
export function fillPositional(text: string | null | undefined, values: string[]): string {
  return String(text ?? '').replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n: string) => {
    const v = values[Number(n) - 1];
    return v !== undefined && v !== '' ? v : m;
  });
}

/** Template drawn with its sample values (template picker, review). */
export function samplePreview(t: CampaignTemplate, values?: Record<string, string>): WaTemplatePreviewData {
  const pick = (section: 'header' | 'body') =>
    t.slots
      .filter((s) => s.section === section && s.kind === 'text')
      .sort((a, b) => a.pos - b.pos)
      .reduce<string[]>((acc, s) => {
        acc[s.pos - 1] = values?.[s.key] || s.sample;
        return acc;
      }, []);
  const media = values?.header_media || t.header_media_url;
  return {
    header_type: t.header_type,
    header_text: t.header_type === 'text' ? fillPositional(t.header_text, pick('header')) : t.header_text,
    header_media_url: media || null,
    body: fillPositional(t.body, pick('body')),
    footer: t.footer,
    buttons: t.buttons ?? [],
  };
}

export function templateTitle(t: Pick<CampaignTemplate, 'display_name' | 'name'> | null | undefined) {
  return t ? t.display_name || t.name : '';
}

/*
 * Time zone: the server stores and schedules in IST (Asia/Kolkata, UTC+05:30, no DST) and the business hours are IST.
 * These helpers work on IST wall-clock values whatever the browser's own time zone is.
 */
const IST_OFFSET_MS = 330 * 60_000;

/** IST wall-clock parts of an instant (UTC getters of a shifted Date). */
function istParts(d: Date) {
  const s = new Date(d.getTime() + IST_OFFSET_MS);
  return { y: s.getUTCFullYear(), mo: s.getUTCMonth() + 1, d: s.getUTCDate(), h: s.getUTCHours(), mi: s.getUTCMinutes(), wd: s.getUTCDay() };
}

const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const;
const DAY_LABEL: Record<string, string> = { mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat', sun: 'Sun' };

/** "Mon–Fri 09:00–18:00 · Sat 09:00–14:00 · Sun closed" from the wa_business_hours setting. */
export function businessHoursText(h: Record<string, [string, string] | null> | undefined): string {
  if (!h || !Object.keys(h).length) return 'Every day, all hours';
  const order = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  const groups: { from: string; to: string; val: string }[] = [];
  for (const d of order) {
    const v = h[d];
    const val = v && v.length === 2 ? `${v[0]}–${v[1]}` : 'closed';
    const last = groups[groups.length - 1];
    if (last && last.val === val) last.to = d;
    else groups.push({ from: d, to: d, val });
  }
  return groups.map((g) => `${DAY_LABEL[g.from]}${g.from !== g.to ? `–${DAY_LABEL[g.to]}` : ''} ${g.val}`).join(' · ');
}

/** Is an instant inside the (IST) business hours? */
export function inBusinessHours(h: Record<string, [string, string] | null> | undefined, at: Date): boolean {
  if (!h || !Object.keys(h).length) return true;
  const t = istParts(at);
  const v = h[DAYS[t.wd]];
  if (!v || v.length !== 2) return false;
  const hm = `${String(t.h).padStart(2, '0')}:${String(t.mi).padStart(2, '0')}`;
  return hm >= v[0] && hm < v[1];
}

/** 'YYYY-MM-DDTHH:mm' (IST wall clock) for a datetime-local input. */
export function toLocalInput(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0');
  const t = istParts(d);
  return `${t.y}-${p(t.mo)}-${p(t.d)}T${p(t.h)}:${p(t.mi)}`;
}

/** Instant of an IST datetime-local value ('YYYY-MM-DDTHH:mm'); null when incomplete. */
export function fromLocalInput(v: string): Date | null {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(v)) return null;
  const d = new Date(`${v}:00+05:30`);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** Server DATETIME ('Y-m-d H:i:s', IST) → instant. */
export function fromServerDate(v: string | null | undefined): Date | null {
  if (!v) return null;
  const s = String(v).trim();
  const d = /[zZ]|[+-]\d\d:?\d\d$/.test(s) ? new Date(s) : new Date(`${s.replace(' ', 'T')}+05:30`);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** "in 2 days" / "in 3 h" / "in 12 min" for future server dates; null for past ones (use timeAgo). */
export function futureText(v: string | null | undefined): string | null {
  const d = fromServerDate(v);
  if (!d) return null;
  const s = Math.round((d.getTime() - Date.now()) / 1000);
  if (s <= 0) return null;
  if (s < 3600) return `in ${Math.max(1, Math.round(s / 60))} min`;
  if (s < 86400) return `in ${Math.round(s / 3600)} h`;
  const days = Math.round(s / 86400);
  return `in ${days} day${days === 1 ? '' : 's'}`;
}

export function durationText(minutes: number): string {
  if (minutes <= 1) return 'about a minute';
  if (minutes < 60) return `about ${minutes} minutes`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `about ${h} h${m ? ` ${m} min` : ''}`;
}

export function audienceFilterCount(a: CampaignAudience): number {
  return (
    a.programs.length +
    a.stages.length +
    a.admission_status.length +
    a.payment_status.length +
    a.cities.length +
    a.states.length +
    a.batches.length +
    a.tags.length +
    a.contact_types.length +
    (a.segment_id ? 1 : 0)
  );
}
