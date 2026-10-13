import { ChangePasswordScreen } from '../components/Screens';
import { useNavReveal } from './shared/ui';

export default function ChangePasswordPage() {
  useNavReveal();
  return <ChangePasswordScreen area="student" />;
}
