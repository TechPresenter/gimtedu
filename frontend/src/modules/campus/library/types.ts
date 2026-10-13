/** Payload types of the library API (api/routes/library.php). */

export type MemberType = 'student' | 'faculty' | 'staff';
export type LoanState = 'issued' | 'due_today' | 'overdue' | 'returned' | 'returned_late' | 'lost';

export interface LibMember {
  id: number;
  membership_no: string;
  member_type: MemberType;
  name: string;
  photo: string | null;
  code: string | null;
  info: string | null;
  email: string | null;
  phone: string | null;
  user_id: number | null;
  student_id: number | null;
  faculty_id: number | null;
  staff_id: number | null;
  department: string | null;
  max_books: number;
  loan_days: number;
  valid_until: string | null;
  status: 'active' | 'suspended' | 'expired';
  is_expired: boolean;
  person_status: string | null;
  remarks: string | null;
  loans: number;
  overdue: number;
  unpaid_fines: number;
  total_loans: number;
  created_at: string;
}

export interface LoanMember {
  id: number;
  name: string;
  photo: string | null;
  membership_no: string;
  type: MemberType;
  info: string | null;
  email: string | null;
  phone: string | null;
}

export interface Loan {
  id: number;
  book_id: number;
  book_copy_id: number;
  member_id: number;
  title: string;
  authors: string | null;
  isbn: string | null;
  cover_image: string | null;
  category: string | null;
  accession_no: string;
  barcode: string | null;
  price: number | null;
  issue_date: string;
  due_date: string;
  return_date: string | null;
  return_condition: string | null;
  renew_count: number;
  status: 'issued' | 'returned' | 'lost';
  state: LoanState;
  days_overdue: number;
  accrued_fine: number;
  fine_amount: number;
  can_renew: boolean;
  reminder_count: number;
  last_reminded_at: string | null;
  remarks: string | null;
  member: LoanMember;
  issued_by: string | null;
  returned_to: string | null;
}

export interface LibFine {
  id: number;
  transaction_id: number | null;
  member_id: number;
  member_name: string;
  membership_no: string;
  member_type: MemberType;
  member_photo: string | null;
  book_title: string | null;
  accession_no: string | null;
  amount: number;
  reason: 'late_return' | 'lost' | 'damage' | 'other';
  days_overdue: number | null;
  status: 'unpaid' | 'paid' | 'waived';
  paid_at: string | null;
  payment_mode: string | null;
  receipt_no: string | null;
  collected_by: string | null;
  waived_at: string | null;
  waived_by: string | null;
  waive_reason: string | null;
  remarks: string | null;
  created_at: string;
}

export interface MemberCardPayload {
  member: LibMember;
  blocks: string[];
  loans: Loan[];
  fines: LibFine[];
  history: Loan[];
  stats: { borrowed: number; returned_late: number; lost: number; fines_paid: number; accrued: number };
  rules: { fine_per_day: number; max_renewals: number; grace_days: number };
}

export interface CopyLookup {
  copy: {
    id: number;
    accession_no: string;
    barcode: string | null;
    condition: string;
    status: string;
    rack_no: string | null;
    shelf_no: string | null;
    price: number | null;
    acquired_date: string | null;
    remarks: string | null;
  };
  book: {
    id: number;
    title: string;
    subtitle: string | null;
    authors: string | null;
    isbn: string | null;
    edition: string | null;
    publish_year: number | null;
    cover_image: string | null;
    type: string;
    status: string;
    category: string | null;
    publisher: string | null;
    total_copies: number;
    available_copies: number;
  };
  loan: Loan | null;
}

export interface FineBreakup {
  days_late: number;
  days: number;
  per_day: number;
  amount: number;
}

export interface LoanPreview {
  loan: Loan;
  return: { date: string; late: FineBreakup; damage: number; total: number };
  lost: { price: number; processing_fee: number; default_amount: number; late: FineBreakup };
  rules: { fine_per_day: number; grace_days: number; max_renewals: number };
  payment_modes: { value: string; label: string }[];
  can_waive: boolean;
}

export interface DashboardPayload {
  kpis: {
    titles: number;
    copies: number;
    issued: number;
    available: number;
    damaged_lost: number;
    overdue: number;
    due_today: number;
    issued_today: number;
    returned_today: number;
    members: number;
    active_borrowers: number;
    fine_collected: number;
    fine_collected_session: number;
    fine_collected_month: number;
    fine_pending: number;
    overdue_days: number;
    accrued_fine: number;
    utilisation: number;
  };
  monthly: { month: string; label: string; issues: number; returns: number; fines: number }[];
  categories: { id: number | null; name: string; titles: number; copies: number }[];
  overdue: Loan[];
  popular: { id: number; title: string; authors: string | null; category: string | null; cover_image: string | null; issues: number; total_copies: number; available_copies: number }[];
  recent: { id: number; status: string; title: string; accession_no: string; member_name: string; membership_no: string; member_type: MemberType; issue_date: string; return_date: string | null; due_date: string; at: string }[];
  member_mix: { type: MemberType; loans: number }[];
  rules: { fine_per_day: number; grace_days: number; max_renewals: number };
  session: string | null;
}

export interface BookDetail {
  book: {
    id: number;
    title: string;
    subtitle: string | null;
    isbn: string | null;
    edition: string | null;
    publish_year: number | null;
    language: string | null;
    pages: number | null;
    price: number | null;
    rack_no: string | null;
    shelf_no: string | null;
    cover_image: string | null;
    description: string | null;
    type: string;
    status: string;
    total_copies: number;
    available_copies: number;
    authors: string | null;
    category_id: number | null;
    category_name: string | null;
    publisher_name: string | null;
    publisher_city: string | null;
    department_name: string | null;
    created_at: string;
  };
  copies: { total: number; available: number; issued: number; damaged: number; lost: number; withdrawn: number; reserved: number };
  stats: { loans: number; readers: number; on_loan: number; overdue: number; last_issued: string | null; loans_90: number };
  current: Loan[];
  trend: { month: string; label: string; issues: number }[];
  can: { edit: boolean; create: boolean; delete: boolean };
}

export interface FinesSummary {
  totals: {
    unpaid: number;
    unpaid_count: number;
    unpaid_members: number;
    paid: number;
    paid_count: number;
    paid_session: number;
    paid_month: number;
    paid_today: number;
    waived: number;
    waived_count: number;
  };
  by_reason: { reason: LibFine['reason']; count: number; amount: number; unpaid: number }[];
  accrued: number;
  payment_modes: { value: string; label: string }[];
  can_waive: boolean;
  can_collect: boolean;
  session: string | null;
}

export interface RuleDef {
  key: string;
  label: string;
  type: 'money' | 'int' | 'bool';
  default: number;
  min: number;
  max: number;
  value: number;
}

export interface RulesPayload {
  rules: {
    fine_per_day: number;
    grace_days: number;
    max_renewals: number;
    max_unpaid_fine: number;
    block_on_overdue: boolean;
    lost_processing_fee: number;
    types: Record<MemberType, { max_books: number; loan_days: number }>;
    payment_modes: Record<string, string>;
  };
  definitions: RuleDef[];
  can_manage: boolean;
}
