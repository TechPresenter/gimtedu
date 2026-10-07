import { EmployeeProfile } from './components/EmployeeProfile';

/** /faculty/:id — faculty profile with tabs (?tab=overview|professional|subjects|timetable|attendance|leaves|documents|payroll|activity). */
export default function FacultyProfilePage() {
  return <EmployeeProfile type="faculty" />;
}
