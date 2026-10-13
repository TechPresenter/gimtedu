import { EmployeeProfile } from './components/EmployeeProfile';

/** /staff/:id — staff profile with tabs (?tab=overview|professional|attendance|leaves|documents|payroll|activity). */
export default function StaffProfilePage() {
  return <EmployeeProfile type="staff" />;
}
