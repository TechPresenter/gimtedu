import type { WaMessage, WaTemplateButton } from '../shared';

/** Inbox list filters (counts come back with the first page). */
export type InboxFilter = 'all' | 'unread' | 'read' | 'open' | 'pending' | 'resolved' | 'mine' | 'unassigned' | 'bot';
export type ConvStatus = 'open' | 'pending' | 'resolved';

export interface InboxTag {
  id: number;
  name: string;
  color: string;
  contacts?: number;
}

export interface InboxRow {
  id: number;
  contact_id: number;
  name: string;
  has_name: boolean;
  phone: string;
  phone_display: string;
  /** Settings › Security masks phone numbers for this user (phone then holds the masked display) */
  phone_masked?: boolean;
  contact_type: string;
  program_name: string | null;
  opt_in: boolean;
  blocked: boolean;
  status: ConvStatus;
  assigned_to: number | null;
  assigned_name: string | null;
  assigned_avatar: string | null;
  unread_count: number;
  last_message_at: string | null;
  last_message_preview: string | null;
  last_message_direction: 'in' | 'out' | null;
  last_status: string | null;
  last_type: string | null;
  bot_active: boolean;
  window_open: boolean;
  window_expires_at: string | null;
  tags: InboxTag[];
}

export interface InboxSummary extends InboxRow {
  bot_handover_at: string | null;
  resolved_at: string | null;
  resolved_by_name: string | null;
  window_last_inbound_at: string | null;
}

export type InboxCounts = Record<InboxFilter, number> & { unread_messages: number };

export interface InboxPage {
  rows: InboxRow[];
  next_cursor: string | null;
  counts?: InboxCounts;
}

export interface LinkedRecord {
  type: 'student' | 'admission' | 'enquiry';
  id: number;
  label: string;
  title: string;
  ref: string | null;
  status: string | null;
  detail: string | null;
  to: string;
}

export interface InboxProfile {
  id: number;
  name: string | null;
  phone: string;
  phone_display: string;
  phone_masked?: boolean;
  email: string | null;
  alt_phone: string | null;
  contact_type: string;
  program_id: number | null;
  program_name: string | null;
  batch: string | null;
  application_no: string | null;
  stage: string | null;
  stage_name: string | null;
  admission_status: string | null;
  payment_status: string | null;
  counsellor_id: number | null;
  counsellor_name: string | null;
  city: string | null;
  state: string | null;
  language: string | null;
  opt_in: boolean;
  opt_in_at: string | null;
  opt_in_source: string | null;
  opted_out_at: string | null;
  source: string | null;
  status: string;
  student_id: number | null;
  enquiry_id: number | null;
  admission_id: number | null;
  last_inbound_at: string | null;
  created_at: string;
  links: LinkedRecord[];
  fees: { balance: number; overdue: number; next_due: string | null } | null;
}

export interface InboxPermissions {
  send: boolean;
  edit: boolean;
  delete: boolean;
  manage: boolean;
  claim: boolean;
  templates: boolean;
  /** "Create admission enquiry" (whatsapp_contacts edit + enquiries create) */
  create_enquiry?: boolean;
}

export interface InboxDetail {
  conversation: InboxSummary;
  profile: InboxProfile;
  notes_count: number;
  permissions: InboxPermissions;
}

/** A wa_messages row as the inbox returns it (+ client-only optimistic fields). */
export type InboxMessage = Pick<
  WaMessage,
  | 'id'
  | 'conversation_id'
  | 'contact_id'
  | 'direction'
  | 'type'
  | 'body'
  | 'media_url'
  | 'media_local'
  | 'media_mime'
  | 'file_name'
  | 'interactive'
  | 'template_id'
  | 'template_name'
  | 'template_vars'
  | 'status'
  | 'error_code'
  | 'error'
  | 'sender_type'
  | 'sent_by'
  | 'sent_by_name'
  | 'reply_to_id'
  | 'campaign_id'
  | 'created_at'
  | 'sent_at'
  | 'delivered_at'
  | 'read_at'
  | 'failed_at'
> & {
  can_retry?: boolean;
  /** optimistic (not yet confirmed by the server) */
  pending?: 'sending' | 'error';
  client_ref?: string;
  pending_error?: string;
  /** payload to resend an optimistic message that failed */
  payload?: SendPayload;
};

export interface MessagesPage {
  messages: InboxMessage[];
  has_more: boolean;
  conversation?: InboxSummary;
}

export interface SendPayload {
  type: 'text' | 'image' | 'video' | 'document' | 'audio' | 'interactive_button' | 'interactive_list' | 'template' | 'location' | 'contact';
  body?: string;
  media_url?: string;
  media_local?: string;
  media_mime?: string;
  file_name?: string;
  buttons?: { title: string }[];
  footer?: string;
  list_button?: string;
  sections?: { title: string; rows: { title: string; description?: string }[] }[];
  template_id?: number;
  vars?: { header?: string[]; body?: string[]; buttons?: Record<string, string[]>; file_name?: string };
  latitude?: number | string;
  longitude?: number | string;
  label?: string;
  address?: string;
  contact_name?: string;
  contact_phone?: string;
  contact_email?: string;
  contact_org?: string;
  client_ref?: string;
}

export interface InboxNote {
  id: number;
  body: string;
  created_at: string;
  user_id: number | null;
  user_name: string;
  user_avatar: string | null;
  can_delete: boolean;
  can_edit: boolean;
}

export interface InboxAgent {
  id: number;
  name: string;
  email: string;
  designation: string | null;
  avatar: string | null;
  is_me: boolean;
  open_assigned: number;
  pending: number;
  unread: number;
  replied_today: number;
  avg_first_response_secs: number | null;
  response_samples: number;
  last_active_at: string | null;
}

export interface ActivityItem {
  key: string;
  kind: 'agent' | 'event' | 'system';
  action: string;
  title: string;
  detail: string | null;
  user_name: string | null;
  at: string;
}

export interface FeedRow {
  id: number;
  action: string;
  title: string;
  detail: string | null;
  created_at: string;
  conversation_id: number | null;
  contact_id: number | null;
  contact_name: string | null;
  user_id: number | null;
  user_name: string;
  user_avatar: string | null;
}

export interface QuickReply {
  id: number;
  shortcut: string;
  title: string;
  body: string;
  category: string | null;
  usage_count: number;
  created_by_name: string | null;
  updated_at: string;
  filled?: string;
}

export interface QuickReplyList {
  rows: QuickReply[];
  categories: string[];
  can_manage: boolean;
}

export interface TemplateField {
  key: string;
  part: 'header' | 'body' | 'button';
  pos: number;
  label: string;
  sample: string;
  value: string;
}

export interface PickerTemplate {
  id: number;
  name: string;
  display_name: string;
  category: string;
  language: string;
  header_type: 'none' | 'text' | 'image' | 'video' | 'document';
  header_text: string | null;
  header_media_url: string | null;
  body: string;
  footer: string | null;
  buttons: WaTemplateButton[];
  fields: TemplateField[];
  usage_count: number;
}
