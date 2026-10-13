import type { LoginRow, NoticeItem } from '../lib/types';
import type { DayInfo } from '../student/types';

export interface FacultyClass {
  id: number;
  slot: string;
  start_time: string;
  end_time: string;
  type: string;
  subject_code: string;
  subject: string;
  program: string;
  semester_no: number;
  section: string;
  room: string | null;
  room_code: string | null;
  section_id: number;
  subject_id: number;
  marked: boolean;
  present: number | null;
  total: number | null;
  state: 'done' | 'now' | 'upcoming';
}

export interface PendingAttendance {
  date: string;
  slot: string;
  start_time: string;
  subject_code: string;
  subject: string;
  program: string;
  semester_no: number;
  section: string;
  section_id: number;
  subject_id: number;
}

export interface PendingMarks {
  exam_id: number;
  exam: string;
  subject_id: number;
  subject_code: string;
  subject: string;
  students: number;
  entered: number;
  submitted: number;
  end_date: string | null;
}

export interface FacultyDashboard {
  today: DayInfo & { classes: FacultyClass[] };
  session: string | null;
  load: { subjects: number; sections: number; periods_per_week: number };
  kpis: {
    today_classes: { count: number; done: number; next: FacultyClass | null };
    pending_attendance: { count: number; days: number };
    pending_marks: { count: number; students: number };
    leave: { available: number; pending: number; types: { code: string; name: string; available: number; quota: number; used: number }[] };
  };
  pending_attendance: PendingAttendance[];
  pending_marks: PendingMarks[];
  notices: NoticeItem[];
}

export interface FacultyProfile {
  profile: Record<string, string | number | null> & { id: number; employee_id: string; full_name: string; first_name: string; photo: string | null };
  subjects: { code: string; name: string; semester_no: number; program: string; section: string | null }[];
  account: { username: string; email: string; roles: string | null; last_login_at: string | null; last_login_ip: string | null; password_changed_at: string | null; created_at: string };
  logins: LoginRow[];
}
