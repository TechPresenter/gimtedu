import { ResetForm } from '../components/RecoveryForms';
import { usePortalTitle } from '../lib/hooks';
import { useSession } from '../lib/session';
import { StudentAuthLayout } from './StudentAuthLayout';

export default function StudentResetPage() {
  usePortalTitle('Reset password', 'student');
  const { data } = useSession('student');
  return (
    <StudentAuthLayout showSwitch={false}>
      <ResetForm area="student" checkEndpoint="portal/student/reset" submitEndpoint="portal/student/reset" policy={data?.app.password_policy} />
    </StudentAuthLayout>
  );
}
