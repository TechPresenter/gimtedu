import { AuthNote, LoginForm } from '../components/LoginForm';
import { usePortalTitle } from '../lib/hooks';
import { useSession } from '../lib/session';
import { FacultyAuthLayout } from './FacultyAuthLayout';

export default function FacultyLoginPage() {
  usePortalTitle('Sign in', 'faculty');
  const { data } = useSession('faculty');
  return (
    <FacultyAuthLayout>
      <LoginForm
        area="faculty"
        title="Faculty sign in"
        subtitle="Sign in with your institute email or username."
        identifierLabel="Email or username"
        identifierPlaceholder="name@gimt.ac.in"
        disabled={data ? !data.app.features.faculty_portal : false}
        below={<AuthNote>No login yet? The administration office creates faculty accounts from the Portal Access screen.</AuthNote>}
      />
    </FacultyAuthLayout>
  );
}
