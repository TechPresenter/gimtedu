import type { ReactNode } from 'react';
import { BookOpenCheck, CalendarCheck, IndianRupee, Megaphone } from 'lucide-react';
import { AuthLayout, AuthLink } from '../components/AuthLayout';
import { PHOTOS } from '../lib/config';
import { useSession } from '../lib/session';

const FEATURES = [
  { icon: CalendarCheck, label: 'Attendance' },
  { icon: BookOpenCheck, label: 'Results & CGPA' },
  { icon: IndianRupee, label: 'Fees & receipts' },
  { icon: Megaphone, label: 'Notices' },
];

/** Split-screen frame of every student sign-in / activation / recovery screen. */
export function StudentAuthLayout({ children, showSwitch = true }: { children: ReactNode; showSwitch?: boolean }) {
  const { data } = useSession('student');
  return (
    <AuthLayout
      photo={PHOTOS.student}
      eyebrow="Student Portal"
      title={
        <>
          Your semester, <span className="text-flame-400">all in one place.</span>
        </>
      }
      subtitle="Attendance, results, fees, timetable and notices from the GIMT ERP — live, private and available on any device."
      features={FEATURES}
      app={data?.app}
      footerLink={
        showSwitch ? (
          <>
            Faculty member? <AuthLink to="/faculty/login">Sign in to the Faculty Portal</AuthLink>
          </>
        ) : undefined
      }
    >
      {children}
    </AuthLayout>
  );
}
