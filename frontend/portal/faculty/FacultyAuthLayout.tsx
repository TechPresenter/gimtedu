import type { ReactNode } from 'react';
import { CalendarClock, ClipboardCheck, NotebookPen, Plane } from 'lucide-react';
import { AuthLayout, AuthLink } from '../components/AuthLayout';
import { PHOTOS } from '../lib/config';
import { useSession } from '../lib/session';

const FEATURES = [
  { icon: CalendarClock, label: 'Timetable' },
  { icon: ClipboardCheck, label: 'Class attendance' },
  { icon: NotebookPen, label: 'Marks entry' },
  { icon: Plane, label: 'Leave' },
];

/** Split-screen frame of every faculty sign-in / recovery screen. */
export function FacultyAuthLayout({ children, showSwitch = true }: { children: ReactNode; showSwitch?: boolean }) {
  const { data } = useSession('faculty');
  return (
    <AuthLayout
      photo={PHOTOS.faculty}
      photoClassName="origin-[92%_35%] scale-[1.9] lg:scale-100"
      eyebrow="Faculty Portal"
      title={
        <>
          Teach more, <span className="text-flame-400">administer less.</span>
        </>
      }
      subtitle="Your classes, attendance registers, marks entry and leave — connected live to the GIMT ERP."
      features={FEATURES}
      app={data?.app}
      footerLink={
        showSwitch ? (
          <>
            Student? <AuthLink to="/student/login">Sign in to the Student Portal</AuthLink>
          </>
        ) : undefined
      }
    >
      {children}
    </AuthLayout>
  );
}
