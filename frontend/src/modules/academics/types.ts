/** API payload types for the academics + timetable screens (see api/routes/academics.php, timetable.php). */

export interface AcademicKpis {
  departments: number;
  programs: number;
  courses: number;
  subjects: number;
  electives: number;
  sections: number;
  batches: number;
  faculty: number;
  students: number;
  classrooms: number;
  assignments: number;
  unassigned: number;
  timetabled_sections: number;
}

export interface TreeCourse {
  id: number;
  name: string;
  code: string;
  status: string;
  students: number;
}

export interface TreeProgram {
  id: number;
  name: string;
  short_name: string;
  code: string;
  level: string;
  category: string | null;
  duration: string;
  total_semesters: number;
  intake: number;
  status: string;
  featured: boolean;
  students: number;
  subjects: number;
  sections: number;
  courses: TreeCourse[];
}

export interface TreeDepartment {
  id: number;
  name: string;
  code: string;
  status: string;
  hod_name: string | null;
  faculty: number;
  students: number;
  subjects: number;
  sections: number;
  programs: TreeProgram[];
}

export interface AcademicOverview {
  session: { id: number; name: string; start_date: string; end_date: string; is_current: number; admissions_open: number; status: string } | null;
  kpis: AcademicKpis;
  tree: TreeDepartment[];
  levels: Record<string, number>;
  subject_types: Record<string, number>;
}

export interface TreeFaculty {
  faculty_id: number;
  name: string;
  photo: string | null;
  designation: string | null;
  section_id: number | null;
  section: string | null;
  students: number | null;
  primary: boolean;
}

export interface TreeSubject {
  id: number;
  code: string;
  name: string;
  type: string;
  elective: boolean;
  credits: number;
  hours: number;
  course: string | null;
  status: string;
  faculty: TreeFaculty[];
  missing_sections: number;
}

export interface TreeSemester {
  number: number;
  name: string;
  id: number | null;
  status: string;
  credits: number;
  students: number;
  sections: { id: number; name: string; students: number; capacity: number; class_teacher: string | null; room: string | null }[];
  subjects: TreeSubject[];
}

export interface ProgramTree {
  program: { id: number; name: string; short_name: string; department: string; total_semesters: number };
  semesters: TreeSemester[];
}

export interface WorkloadRow {
  id: number;
  name: string;
  employee_id: string;
  designation: string | null;
  photo: string | null;
  status: string;
  department_code: string | null;
  department_name: string | null;
  assignments: number;
  subjects: number;
  sections: number;
  planned_hours: number;
  scheduled_periods: number;
  max_hours: number;
  load: 'overloaded' | 'normal' | 'light' | 'free';
}

export interface Workload {
  rows: WorkloadRow[];
  stats: { faculty: number; assigned: number; free: number; overloaded: number; avg_hours: number; max_hours: number };
}

export interface UnassignedRow {
  section_id: number;
  section_label: string;
  subject_id: number;
  subject_code: string;
  subject_name: string;
  subject_type: string;
  department_id: number;
}

/* ---------------------------------------------------------------- Timetable */

export interface TtDay {
  no: number;
  name: string;
  short: string;
}

export interface TtSlot {
  id: number;
  name: string;
  start_time: string;
  end_time: string;
  is_break: boolean;
  sort_order: number;
  status: string;
}

export interface TtEntry {
  id: number;
  academic_session_id: number;
  program_id: number;
  semester_no: number;
  section_id: number;
  day_of_week: number;
  time_slot_id: number;
  subject_id: number;
  faculty_id: number | null;
  classroom_id: number | null;
  type: 'lecture' | 'lab' | 'tutorial' | 'seminar';
  notes: string | null;
  status: 'draft' | 'published';
  subject_code: string;
  subject_name: string;
  subject_type: string;
  is_elective: boolean;
  faculty_name: string | null;
  faculty_photo: string | null;
  room_code: string | null;
  room_name: string | null;
  room_type: string | null;
  room_capacity: number | null;
  section_name: string;
  program_short: string;
  section_label: string;
  slot_name: string;
  start_time: string;
  end_time: string;
  day_name: string;
}

export interface TtSectionSubject {
  id: number;
  code: string;
  name: string;
  type: string;
  elective: boolean;
  credits: number;
  required: number;
  scheduled: number;
  faculty: { id: number; name: string; designation: string | null; photo: string | null; primary: boolean }[];
}

export interface TtStats {
  periods: number;
  published: number;
  draft: number;
  capacity: number;
  utilisation: number;
  subjects: number;
  faculty: number;
  without_faculty: number;
  without_room: number;
  status: 'empty' | 'published' | 'draft' | 'partial';
}

export interface TtSectionContext {
  id: number;
  label: string;
  name: string;
  program_id: number;
  program: string;
  program_short: string;
  semester_no: number;
  department: string | null;
  session: string | null;
  academic_session_id: number | null;
  batch: string | null;
  strength: number;
  capacity: number;
  classroom_id: number | null;
  room: string | null;
  class_teacher: string | null;
}

export interface TtGrid {
  view: 'class' | 'faculty' | 'room' | 'student';
  context: {
    section?: TtSectionContext | null;
    faculty?: { id: number; name: string; employee_id: string; designation: string | null; photo: string | null; email: string | null; department: string | null; status: string; assignments: number };
    room?: { id: number; code: string; name: string; building: string | null; floor: string | null; capacity: number; type: string; facilities: string | null; status: string };
    student?: { id: number; name: string; uid: string; roll_no: string | null; photo: string | null; program: string | null; semester: number; section_id: number | null };
  };
  entries: TtEntry[];
  subjects: TtSectionSubject[] | null;
  stats: TtStats;
  days: TtDay[];
  slots: TtSlot[];
}

export interface TtMeta {
  session_id: number;
  days: TtDay[];
  slots: TtSlot[];
  types: { value: string; label: string }[];
  summary: {
    sections: number;
    scheduled_sections: number;
    published_sections: number;
    draft_sections: number;
    unscheduled_sections: number;
    periods: number;
    faculty: number;
    room_utilisation: number;
    without_faculty: number;
  };
}

export interface TtCellOptions {
  section: { id: number; label: string; strength: number; classroom_id: number | null };
  subjects: TtSectionSubject[];
  rooms: { id: number; code: string; name: string; type: string; capacity: number; building: string | null }[];
  busy: { faculty: Record<string, string>; rooms: Record<string, string> };
}

export interface TtDaily {
  day: { no: number; name: string };
  sections: { id: number; label: string; program_short: string; semester_no: number; name: string; strength: number }[];
  entries: TtEntry[];
  stats: { periods: number; faculty: number; rooms: number; sections: number };
  slots: TtSlot[];
  days: TtDay[];
}

export interface FreeRoom {
  id: number;
  code: string;
  name: string;
  building: string | null;
  floor: string | null;
  capacity: number;
  type: string;
  facilities: string | null;
  periods_today: number;
}
