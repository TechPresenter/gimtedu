import { ResetForm } from '../components/RecoveryForms';
import { usePortalTitle } from '../lib/hooks';
import { useSession } from '../lib/session';
import { FacultyAuthLayout } from './FacultyAuthLayout';

/** Reset link from the faculty "forgot password" email - validated by the shared /api/auth/reset endpoints. */
export default function FacultyResetPage() {
  usePortalTitle('Reset password', 'faculty');
  const { data } = useSession('faculty');
  return (
    <FacultyAuthLayout showSwitch={false}>
      <ResetForm area="faculty" checkEndpoint="auth/reset" submitEndpoint="auth/reset" policy={data?.app.password_policy} />
    </FacultyAuthLayout>
  );
}
