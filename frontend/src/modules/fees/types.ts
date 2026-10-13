/** API payload types for the fees & accounts module (see api/routes/fees.php, api/routes/expenses.php). */

export type Num = number | string;

export interface Gateway {
  gateway: string | null;
  label: string | null;
  mode: string;
  configured: boolean;
}

export interface FeesMeta {
  modes: { value: string; label: string }[];
  enabled_modes: string[];
  head_types: Record<string, string>;
  concession_types: Record<string, string>;
  gateway: Gateway;
  late_fee_per_day: number;
  receipt_format: string;
  invoice_format: string;
  session: { id: number | null; name: string; start_date: string; end_date: string };
  today: string;
}

export interface InvoiceItem {
  id: number;
  fee_head_id: number;
  amount: Num;
  head_name: string;
  head_code: string;
  head_type: string;
}

export interface Installment {
  id: number;
  installment_no: number;
  amount: Num;
  due_date: string;
  paid_amount: Num;
  status: string;
}

export interface InvoiceDetail {
  id: number;
  invoice_no: string;
  student_id: number;
  title: string;
  semester_no: number | null;
  gross_amount: Num;
  discount_amount: Num;
  scholarship_amount: Num;
  fine_amount: Num;
  net_amount: Num;
  paid_amount: Num;
  balance_amount: Num;
  overdue_amount: Num;
  due_date: string | null;
  status: string;
  remarks: string | null;
  cancelled_reason: string | null;
  cancelled_at: string | null;
  cancelled_by_name: string | null;
  reminder_count: number;
  last_reminder_at: string | null;
  created_at: string;
  created_by_name: string | null;
  student_name: string;
  student_uid: string;
  admission_no: string | null;
  roll_no: string | null;
  photo: string | null;
  mobile: string | null;
  email: string | null;
  program_short: string | null;
  program_name: string | null;
  section_name: string | null;
  session_name: string | null;
  structure_name: string | null;
  items: InvoiceItem[];
  installments: Installment[];
  concessions: { id: number; type: string; amount: Num; reason: string | null; status: string; created_at: string; payment_id: number | null; scholarship_name: string | null; created_by_name: string | null }[];
  payments: { id: number; receipt_no: string; payment_date: string; mode: string; reference_no: string | null; status: string; collected_by_name: string | null; applied_amount: Num; applied_fine: Num }[];
  late_fee_due: number;
  late_fee_rate: { per_day: number; max: number | null };
}

export interface PaymentDetail {
  id: number;
  receipt_no: string;
  student_id: number | null;
  student_name: string | null;
  student_uid: string | null;
  photo: string | null;
  program_short: string | null;
  current_semester: number | null;
  amount: Num;
  fine_amount: Num;
  discount_amount: Num;
  total_amount: Num;
  payment_date: string;
  mode: string;
  mode_label: string;
  reference_no: string | null;
  bank_name: string | null;
  gateway: string | null;
  purpose: string;
  status: string;
  remarks: string | null;
  collected_by_name: string | null;
  cancelled_by_name: string | null;
  cancelled_reason: string | null;
  cancelled_at: string | null;
  created_at: string;
  printed_count: number | null;
  emailed_at: string | null;
  email: string | null;
  allocations: { student_fee_id: number; amount: Num; fine_amount: Num; invoice_no: string; title: string; balance_amount: Num; status: string }[];
  discounts: { amount: Num; reason: string | null; invoice_no: string }[];
  refunds: { id: number; refund_no: string; amount: Num; status: string; reason: string; created_at: string }[];
  refundable: number;
}

export interface StructureDetail {
  id: number;
  name: string;
  academic_session_id: number;
  program_id: number;
  program_short: string;
  program_name: string;
  total_semesters: number;
  session_name: string;
  semester_no: number | null;
  year_no: number | null;
  total_amount: Num;
  due_date: string | null;
  late_fee_per_day: Num;
  late_fee_max: Num | null;
  installments_allowed: number;
  description: string | null;
  status: string;
  created_by_name: string | null;
  created_at: string;
  invoiced_count: Num;
  invoiced_amount: Num;
  collected_amount: Num;
  items: { id: number; fee_head_id: number; amount: Num; is_optional: number; head_name: string; head_code: string; head_type: string }[];
  installments: { id: number; installment_no: number; label: string | null; percentage: Num; due_date: string }[];
}

export interface StudentProfile {
  student: {
    id: number;
    student_uid: string;
    admission_no: string | null;
    roll_no: string | null;
    name: string;
    photo: string | null;
    gender: string | null;
    mobile: string | null;
    email: string | null;
    father_name: string | null;
    guardian_phone: string | null;
    current_semester: number | null;
    status: string;
    is_hosteller: number;
    uses_transport: number;
    program_short: string | null;
    program_name: string | null;
    section_name: string | null;
    department_name: string | null;
  };
  totals: { invoices: number; net: number; paid: number; balance: number; overdue: number; concessions: number; fine: number };
  invoices: (Omit<InvoiceDetail, 'items' | 'concessions' | 'payments' | 'late_fee_rate'> & {
    installments: Installment[];
    due_now: number;
    next_due: Installment | null;
    late_fee_due: number;
    items: { head_name: string; amount: Num }[];
  })[];
  concessions: { name: string | null; type: string; amount: Num }[];
  recent_payments: { id: number; receipt_no: string; total: Num; payment_date: string; mode: string; status: string }[];
  gateway: Gateway;
  enabled_modes: string[];
}

export interface CollectResult {
  id: number;
  receipt_no: string;
  total: number;
  amount: number;
  fine_amount: number;
  discount_amount: number;
  mode: string;
  mode_label: string;
  payment_date: string;
  student: { id: number; name: string; student_uid: string; email: string | null; mobile: string | null };
  emailed: boolean;
  balance_after: number;
}

export interface ReportPayload {
  columns: [string, string, string][];
  rows: Record<string, unknown>[];
  pagination: { total: number; page: number; per_page: number; pages: number; from: number; to: number };
  summary: Record<string, unknown>;
  chart: { labels: string[]; values: number[]; [k: string]: unknown };
  filters: { from: string; to: string; session: { id: number | null; name: string } };
}
