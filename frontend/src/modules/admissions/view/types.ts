import type { Row, Option } from '@/lib/types';

export interface AdmissionDoc {
  id: number;
  admission_id: number;
  doc_type: string;
  label: string;
  file_path: string;
  original_name: string | null;
  mime: string | null;
  size_bytes: number | null;
  status: 'pending' | 'verified' | 'rejected';
  remarks: string | null;
  verified_by_name: string | null;
  verified_at: string | null;
  created_at: string;
  exists: boolean;
}

export interface AdmissionFollowup {
  id: number;
  admission_id: number | null;
  enquiry_id: number | null;
  type: string;
  notes: string;
  outcome: string | null;
  next_followup_date: string | null;
  completed_at: string | null;
  created_by: number | null;
  created_by_name: string | null;
  completed_by_name: string | null;
  created_at: string;
  is_open: boolean;
  from_enquiry: boolean;
}

export interface TimelineEvent {
  type: string;
  stage?: string;
  title: string;
  description: string | null;
  user: string | null;
  at: string;
}

export interface Transition {
  stage: string;
  label: string;
  kind: 'next' | 'back' | 'approve' | 'reopen' | 'rejected' | 'withdrawn' | 'waitlisted';
  blockers: string[];
}

export interface AdmissionProfile {
  admission: Row;
  documents: AdmissionDoc[];
  required_docs: { doc_type: string; label: string; verified: boolean; uploaded: boolean }[];
  followups: AdmissionFollowup[];
  history: { from_stage: string | null; to_stage: string; remarks: string | null; changed_by_name: string | null; created_at: string }[];
  timeline: TimelineEvent[];
  transitions: Transition[];
  student: { id: number; student_uid: string; admission_no: string; roll_no: string | null; status: string } | null;
  enquiry: { id: number; name: string; source: string; status: string; created_at: string } | null;
  payment: { id: number; receipt_no: string; amount: string; payment_date: string; invoice_no: string | null } | null;
  default_fee: number;
  doc_types: Option[];
  can: { edit: boolean; approve: boolean; delete: boolean; fee: boolean; convert: boolean; view_student: boolean };
}
