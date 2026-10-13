import type { LoginRow, NoticeItem } from '../lib/types';

export interface DayInfo {
  date: string;
  dow: number;
  day: string;
  holiday: string | null;
  weekly_off: boolean;
  working: boolean;
}

export interface TodayClass {
  id: number;
  type: string;
  slot: string;
  start_time: string;
  end_time: string;
  subject_code: string;
  subject: string;
  faculty: string | null;
  room_code: string | null;
  room: string | null;
  attendance: string | null;
  state: 'done' | 'now' | 'upcoming';
}

export interface UpcomingExam {
  id: number;
  exam_id: number;
  exam_date: string;
  start_time: string | null;
  end_time: string | null;
  exam_name: string;
  subject_code: string;
  subject: string;
  room: string | null;
  days_left: number;
}

export interface StudentDashboard {
  today: DayInfo & { classes: TodayClass[] };
  session: string | null;
  kpis: {
    attendance: { percent: number | null; threshold: number; held: number; attended: number; present: number; absent: number; late: number; leave: number; classes_needed: number };
    cgpa: { sgpa: number | null; cgpa: number | null; semester: number | null; result_status: string | null; backlogs: number; source: string | null };
    fees: { invoices: number; net: number; paid: number; balance: number; overdue: number; discount: number; scholarship: number; fine: number; next_due: string | null };
    exams: { upcoming: number; next: UpcomingExam | null };
  };
  exams: UpcomingExam[];
  notices: NoticeItem[];
}

export interface StudentProfile {
  profile: Record<string, string | number | null> & {
    id: number;
    student_uid: string;
    full_name: string;
    first_name: string;
    photo: string | null;
  };
  parents: { id: number; relation: string; name: string; phone: string | null; email: string | null; occupation: string | null; is_emergency_contact: boolean }[];
  account: {
    username: string;
    status: string;
    activated_at: string | null;
    activation_method: string | null;
    last_login_at: string | null;
    last_login_ip: string | null;
    login_count: number;
    password_changed_at: string | null;
    created_at: string;
  };
  logins: LoginRow[];
}
