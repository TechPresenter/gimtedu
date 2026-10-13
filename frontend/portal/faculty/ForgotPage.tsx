import { ForgotForm } from '../components/RecoveryForms';
import { usePortalTitle } from '../lib/hooks';
import { FacultyAuthLayout } from './FacultyAuthLayout';

export default function FacultyForgotPage() {
  usePortalTitle('Forgot password', 'faculty');
  return (
    <FacultyAuthLayout>
      <ForgotForm
        area="faculty"
        endpoint="portal/faculty/forgot"
        field="email"
        label="Institute email"
        placeholder="name@gimt.ac.in"
        subtitle="Enter the email address of your faculty account. We'll send a secure link to set a new password."
      />
    </FacultyAuthLayout>
  );
}
