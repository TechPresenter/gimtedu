/**
 * WhatsApp contact CRM — shared types, query keys and hooks for the Contacts, Contact detail, Admission Leads and
 * Follow-up Manager pages (backend: api/routes/whatsapp/contacts.php).
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Query } from '@/lib/api';
import { formatPhone } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';
import type { Option } from '@/lib/types';

export interface WaTag {
  id: number;
  name: string;
  color: BadgeColor;
}

export interface WaContact {
  id: number;
  phone: string;
  country_code: string;
  national_number: string;
  name: string | null;
  email: string | null;
  alt_phone: string | null;
  contact_type: string;
  student_id: number | null;
  enquiry_id: number | null;
  admission_id: number | null;
  application_no: string | null;
  program_id: number | null;
  program_name: string | null;
  program_code: string | null;
  batch: string | null;
  city: string | null;
  state: string | null;
  stage: string;
  stage_name: string;
  stage_changed_at: string | null;
  admission_status: string | null;
  payment_status: string | null;
  counsellor_id: number | null;
  counsellor_name: string | null;
  language: string;
  opt_in: number;
  opt_in_at: string | null;
  opt_in_source: string | null;
  opted_out_at: string | null;
  source: string | null;
  lead_source: string | null;
  status: string;
  last_inbound_at: string | null;
  last_outbound_at: string | null;
  last_message_at: string | null;
  created_at: string;
  updated_at: string | null;
  synced_at: string | null;
  conversation_id: number | null;
  unread_count: number | null;
  next_followup_at: string | null;
  tags: WaTag[];
  window_open: boolean;
  window_expires_at: string | null;
  notes?: string | null;
}

/** POST whatsapp/contacts/{id}/enquiry — the ERP admission enquiry created for (or linked to) a contact. */
export interface EnquiryResult {
  ok: boolean;
  /** false when the contact was already linked, or linked to the open enquiry of the same number */
  created: boolean;
  enquiry_id: number | null;
  /** "#123" — ERP enquiries have no separate number; Admissions shows them as "Enquiry #123" */
  enquiry_no: string | null;
  error: string | null;
  message: string;
}

/** Contact types that can become an admission enquiry (students, alumni and staff are already in the ERP). */
export const ENQUIRY_CONTACT_TYPES = ['lead', 'applicant', 'guardian', 'other'];

export interface Paged<T> {
  data: T[];
  meta: { total: number; page: number; per_page: number; pages: number };
}

export interface StageOption extends Option {
  color: BadgeColor;
}
export interface CounsellorOption extends Option {
  contacts: number;
}
export interface TagOption extends Option {
  id: number;
  name: string;
  color: BadgeColor;
  contacts: number;
}

export interface Facets {
  types: Option[];
  stages: StageOption[];
  programs: Option[];
  cities: Option[];
  states: Option[];
  batches: Option[];
  admission_statuses: Option[];
  payment_statuses: Option[];
  sources: Option[];
  lead_sources: Option[];
  counsellors: CounsellorOption[];
  tags: TagOption[];
  tag_colors: BadgeColor[];
  /** the server can read / write .xlsx (PHP zip extension) */
  xlsx: boolean;
  /** export permission + Settings › Security "who can export" */
  can_export?: boolean;
  /** Settings › Security masks phone numbers for this user */
  phones_masked?: boolean;
}

export interface SyncStats {
  scanned: number;
  phones: number;
  created: number;
  updated: number;
  unchanged: number;
  stage_changes: number;
  opted_in: number;
  errors: number;
  duration_ms: number;
  scope: string;
  at: string;
}

export interface ContactsSummary {
  total: number;
  opted_in: number;
  opted_out: number;
  window_open: number;
  leads: number;
  students: number;
  guardians: number;
  unassigned_leads: number;
  new_7d: number;
  last_sync_at: string | null;
  last_sync: SyncStats | null;
}

export interface Segment {
  id: number;
  name: string;
  description: string | null;
  filters: Record<string, string | string[]>;
  contacts_count: number;
  opted_in_count: number;
  counted_at: string | null;
  created_by_name: string | null;
  created_at: string;
}

export const STAGE_KEYS = [
  'new_enquiry',
  'contacted',
  'interested',
  'counselling_scheduled',
  'application_started',
  'application_submitted',
  'payment_pending',
  'admission_confirmed',
  'not_interested',
] as const;

/** Fallback stage meta (the server sends configured names / colours in the facets). */
export const STAGE_META: Record<string, { label: string; color: BadgeColor; hex: string }> = {
  new_enquiry: { label: 'New Enquiry', color: 'blue', hex: '#2563EB' },
  contacted: { label: 'Contacted', color: 'cyan', hex: '#06B6D4' },
  interested: { label: 'Interested', color: 'purple', hex: '#8B5CF6' },
  counselling_scheduled: { label: 'Counselling Scheduled', color: 'navy', hex: '#183C7A' },
  application_started: { label: 'Application Started', color: 'amber', hex: '#F59E0B' },
  application_submitted: { label: 'Application Submitted', color: 'amber', hex: '#D97706' },
  payment_pending: { label: 'Payment Pending', color: 'red', hex: '#EF4444' },
  admission_confirmed: { label: 'Admission Confirmed', color: 'green', hex: '#10B981' },
  not_interested: { label: 'Not Interested', color: 'slate', hex: '#64748B' },
};

export const TYPE_LABELS: Record<string, string> = {
  lead: 'Lead',
  applicant: 'Applicant',
  student: 'Student',
  guardian: 'Parent / Guardian',
  alumni: 'Alumni',
  staff: 'Staff',
  other: 'Other',
};
export const TYPE_COLORS: Record<string, BadgeColor> = {
  lead: 'blue',
  applicant: 'amber',
  student: 'green',
  guardian: 'purple',
  alumni: 'cyan',
  staff: 'navy',
  other: 'slate',
};
export const PAYMENT_COLORS: Record<string, BadgeColor> = { paid: 'green', partial: 'amber', pending: 'amber', overdue: 'red' };

/** A number masked by Settings › Security ('+91 98•••••210'): shown as is, never dialled or edited. */
export const isMasked = (phone: string | null | undefined) => !!phone && String(phone).includes('•');

/** E.164 display helper for stored digits ('919876543210' → '+919876543210'); masked numbers pass through. */
export const plus = (phone: string | null | undefined) => (phone ? (isMasked(phone) ? String(phone) : `+${String(phone).replace(/^\+/, '')}`) : '');

/** Formatted number for display ('+91 98765 43210'), or the masked form. */
export const showPhone = (phone: string | null | undefined) => (phone ? (isMasked(phone) ? String(phone) : formatPhone(plus(phone))) : '');

export const crmKeys = {
  all: ['wa', 'crm'] as const,
  facets: ['wa', 'crm', 'facets'] as const,
  summary: ['wa', 'crm', 'summary'] as const,
  list: (q: Query) => ['wa', 'crm', 'list', q] as const,
  contact: (id: number | string) => ['wa', 'crm', 'contact', String(id)] as const,
  segments: ['wa', 'crm', 'segments'] as const,
  duplicates: ['wa', 'crm', 'duplicates'] as const,
  board: (q: Query) => ['wa', 'crm', 'board', q] as const,
  leadStats: (q: Query) => ['wa', 'crm', 'lead-stats', q] as const,
  followups: (q: Query) => ['wa', 'crm', 'followups', q] as const,
  followupStages: ['wa', 'crm', 'followup-stages'] as const,
};

export function useFacets() {
  return useQuery({ queryKey: crmKeys.facets, queryFn: ({ signal }) => api.get<Facets>('whatsapp/contacts/facets', undefined, { signal }), staleTime: 60_000 });
}

/** Refresh every CRM query (lists, board, detail, facets) after a change. */
export function useCrmRefresh() {
  const qc = useQueryClient();
  return () => qc.invalidateQueries({ queryKey: crmKeys.all });
}

/** Stage label / colour from the facets when loaded, the defaults otherwise. */
export function stageInfo(key: string | null | undefined, facets?: Facets | null): { label: string; color: BadgeColor; hex: string } {
  const k = key ?? '';
  const f = facets?.stages.find((s) => s.value === k);
  const base = STAGE_META[k] ?? { label: k || '—', color: 'slate' as BadgeColor, hex: '#64748B' };
  return f ? { label: String(f.label), color: f.color, hex: base.hex } : base;
}

/** Comma list param helpers for URL state. */
export const splitList = (v: string | null | undefined) => (v ? v.split(',').filter(Boolean) : []);
export const joinList = (v: (string | number)[]) => v.map(String).join(',');
