import { NoticesScreen } from '../components/Screens';
import { useNavReveal } from './shared/ui';

export default function NoticesPage() {
  useNavReveal();
  return <NoticesScreen area="student" />;
}
