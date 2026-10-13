import { ForgotForm } from '../components/RecoveryForms';
import { usePortalTitle } from '../lib/hooks';
import { StudentAuthLayout } from './StudentAuthLayout';

export default function StudentForgotPage() {
  usePortalTitle('Forgot password', 'student');
  return (
    <StudentAuthLayout>
      <ForgotForm
        area="student"
        endpoint="portal/student/forgot"
        field="identifier"
        label="Student ID or registered email"
        placeholder="e.g. GIMT26BBA0001"
        subtitle="Enter your Student ID or registered email. We'll email a secure link to set a new password."
      />
    </StudentAuthLayout>
  );
}
