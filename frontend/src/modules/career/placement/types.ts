/** API payloads of the placement unit (api/routes/placement.php). */

export interface SessionRef {
  id: number | null;
  name: string;
  start_date: string;
  end_date: string;
}

export interface PlacementDashboard {
  session: SessionRef;
  kpis: {
    companies: number;
    companies_visited: number;
    mou: number;
    drives: number;
    drives_upcoming: number;
    drives_completed: number;
    eligible: number;
    placed: number;
    confirmed: number;
    placement_percent: number;
    offers: number;
    highest: number | null;
    highest_company: string | null;
    highest_student: string | null;
    average: number | null;
    median: number | null;
  };
  departments: { id: number; label: string; name: string; eligible: number; placed: number; percent: number; average: number | null; highest: number | null }[];
  distribution: { label: string; count: number }[];
  companies: { id: number; label: string; logo: string | null; offers: number; students: number; highest: number; average: number }[];
  months: { label: string; offers: number; future: boolean }[];
  upcoming: UpcomingDrive[];
  recruiters: { id: number; name: string; logo: string | null; industry: string | null; tie_up_status: string; session_hires: number; total_hires: number; highest: number }[];
  offer_status: Record<string, number>;
  application_status: Record<string, number>;
  training: { session_total: number; upcoming: number; attended: number; next: { id: number; title: string; type: string; start_date: string; trainer: string | null } | null };
}

export interface UpcomingDrive {
  id: number;
  title: string;
  job_role: string;
  job_type: string;
  drive_date: string | null;
  registration_deadline: string | null;
  package_min: string | null;
  package_max: string | null;
  status: string;
  mode: string;
  location: string | null;
  company_name: string;
  company_logo: string | null;
  registered: number;
  eligible: number;
}

export interface DriveRow {
  id: number;
  company_id: number;
  title: string;
  job_role: string;
  job_type: string;
  openings: number | null;
  description: string | null;
  package_min: string | null;
  package_max: string | null;
  stipend: string | null;
  location: string | null;
  eligible_programs: number[];
  batch_year: number | null;
  min_cgpa: string | null;
  min_percentage: string | null;
  max_backlogs: number | null;
  eligibility_notes: string | null;
  rounds: string | null;
  drive_date: string | null;
  registration_deadline: string | null;
  venue: string | null;
  mode: string;
  status: string;
  company_name: string;
  company_logo: string | null;
  industry: string | null;
  company_website: string | null;
  tie_up_status: string;
  session: string | null;
  created_at: string;
}

export interface DriveDetail {
  drive: DriveRow;
  programs: { id: number; short_name: string; name: string }[];
  rounds: string[];
  stats: {
    eligible: number;
    applied: number;
    by_status: Record<string, number>;
    in_process: number;
    selected: number;
    rejected: number;
    offers: number;
    offers_total: number;
    confirmed: number;
    highest: number | null;
    average: number | null;
    registration_rate: number;
  };
  round_stats: { round: string; stage: string; appeared: number; cleared: number; dropped: number; pending: number }[];
  can_register: boolean;
  can: { edit: boolean; create: boolean; delete: boolean };
}

export interface EligibleStudent {
  id: number;
  name: string;
  student_uid: string;
  roll_no: string | null;
  photo: string | null;
  gender: string;
  current_semester: number;
  previous_percentage: string | null;
  program_short: string;
  cgpa: number | null;
  backlogs: number;
  application_id: number | null;
  application_status: string | null;
  current_round: string | null;
  best_offer_lpa: number | null;
}

export interface Interview {
  id: number;
  round_name: string;
  scheduled_at: string | null;
  mode: string | null;
  panel: string | null;
  result: string;
  score: number | null;
  feedback: string | null;
  created_at: string;
}

export interface ApplicationDetail {
  application: {
    id: number;
    drive_id: number;
    student_id: number;
    status: string;
    current_round: string | null;
    resume_path: string | null;
    remarks: string | null;
    applied_at: string;
    drive_title: string;
    job_role: string;
    rounds: string | null;
    drive_date: string | null;
    drive_status: string;
    company_id: number;
    package_min: string | null;
    package_max: string | null;
    drive_location: string | null;
    company_name: string;
    company_logo: string | null;
    student_name: string;
    student_uid: string;
    student_photo: string | null;
  };
  student: {
    id: number;
    name: string;
    student_uid: string;
    roll_no: string | null;
    photo: string | null;
    email: string | null;
    mobile: string | null;
    program_short: string;
    program_name: string;
    department_name: string | null;
    batch_name: string | null;
    current_semester: number;
    previous_percentage: string | null;
    cgpa: string | null;
    backlogs: string | null;
  } | null;
  rounds: string[];
  interviews: Interview[];
  offer: { id: number; job_role: string; package_lpa: string; offer_date: string; joining_date: string | null; location: string | null; status: string; offer_letter: string | null } | null;
  other_offers: { id: number; company_name: string; package_lpa: string; status: string }[];
  next_round: string | null;
}

export interface CompanyHistory {
  company: Record<string, string | number | null>;
  stats: { drives: number; offers: number; students: number; highest: number | null; average: number | null; last_offer: string | null; last_drive: string | null };
  drives: { id: number; title: string; job_role: string; job_type: string; drive_date: string | null; status: string; package_min: string | null; package_max: string | null; mode: string; applicants: number; selected: number; offers: number }[];
  by_session: { session: string; offers: number; highest: number }[];
  offers: { id: number; job_role: string; package_lpa: string; offer_date: string; status: string; student_name: string; student_uid: string; photo: string | null; program_short: string }[];
}

export interface Participant {
  id: number;
  student_id: number;
  attended: boolean;
  score: number | null;
  remarks: string | null;
  name: string;
  student_uid: string;
  photo: string | null;
  program_short: string;
  current_semester: number;
}

export interface ParticipantsPayload {
  rows: Participant[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
  summary: { total: number; attended: number; scored: number; avg_score: number | null; top_score: number | null };
  training: Record<string, string | number | null>;
}

export interface PageStats {
  session: string;
  [key: string]: unknown;
}
