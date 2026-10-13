import { Link } from 'react-router-dom';
import { ArrowLeft, ArrowRight, BookOpenCheck, CalendarCheck, ClipboardCheck, GraduationCap, IndianRupee, NotebookPen, Presentation, ShieldCheck } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { Logo } from '../components/Brand';
import { PHOTOS } from '../lib/config';
import { usePortalTitle } from '../lib/hooks';
import { useSession } from '../lib/session';

const CARDS = [
  {
    to: '/student/login',
    eyebrow: 'For students',
    title: 'Student Portal',
    text: 'Attendance, results, fees, timetable, exams and notices for your program.',
    icon: GraduationCap,
    photo: PHOTOS.student,
    points: [
      { icon: CalendarCheck, label: 'Attendance' },
      { icon: BookOpenCheck, label: 'Results' },
      { icon: IndianRupee, label: 'Fees' },
    ],
  },
  {
    to: '/faculty/login',
    eyebrow: 'For faculty',
    title: 'Faculty Portal',
    text: 'Your classes, attendance registers, marks entry, students and leave.',
    icon: Presentation,
    photo: PHOTOS.faculty,
    points: [
      { icon: ClipboardCheck, label: 'Attendance' },
      { icon: NotebookPen, label: 'Marks' },
      { icon: CalendarCheck, label: 'Leave' },
    ],
  },
];

/** /portal/ - choose the student or faculty portal. */
export default function Landing() {
  usePortalTitle('Sign in', null);
  const { data } = useSession('student');
  const site = data?.app.site_url || appUrl('/');
  return (
    <div className="relative min-h-[100dvh] overflow-hidden bg-slate-50 dark:bg-slate-950">
      <div className="hero-navy absolute inset-x-0 top-0 h-[300px] sm:h-[340px]" aria-hidden>
        <div className="pattern-grid absolute inset-0 opacity-50" />
      </div>
      <div className="relative mx-auto max-w-5xl px-4 pb-14 pt-5 sm:px-6">
        <header className="flex items-center justify-between">
          <a href={site} className="rounded-xl bg-white/95 px-3 py-1.5 shadow-soft">
            <Logo variant="color" src={data?.app.logo} className="h-9 w-auto" />
          </a>
          <a href={site} className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-white/80 hover:text-white">
            <ArrowLeft className="h-4 w-4" aria-hidden /> Website
          </a>
        </header>
        <div className="mt-10 text-center text-white sm:mt-12">
          <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-flame-300">{data?.app.short_name ?? 'GIMT'} SmartCampus</p>
          <h1 className="mt-2 font-display text-3xl font-extrabold tracking-tight text-white sm:text-4xl">Welcome to the GIMT Portal</h1>
          <p className="mx-auto mt-2 max-w-xl text-[15px] text-white/75">Choose your portal to sign in. Your data comes live from the institute ERP.</p>
        </div>
        <div className="mt-8 grid gap-5 sm:mt-10 md:grid-cols-2">
          {CARDS.map((c) => (
            <Link key={c.to} to={c.to} className="card lift group overflow-hidden">
              <div className="relative h-36 overflow-hidden sm:h-40">
                <img src={c.photo} alt="" className="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]" />
                <div className="absolute inset-0 bg-gradient-to-t from-brand-950/80 via-brand-950/20 to-transparent" />
                <span className="absolute bottom-3 left-4 inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-flame-600 shadow-soft">
                  <c.icon className="h-5 w-5" aria-hidden />
                </span>
              </div>
              <div className="p-5">
                <p className="eyebrow">{c.eyebrow}</p>
                <h2 className="mt-1 font-display text-xl font-extrabold text-slate-900 dark:text-white">{c.title}</h2>
                <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{c.text}</p>
                <div className="mt-4 flex flex-wrap gap-2">
                  {c.points.map((p) => (
                    <span key={p.label} className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                      <p.icon className="h-3.5 w-3.5 text-flame-600 dark:text-flame-300" aria-hidden /> {p.label}
                    </span>
                  ))}
                </div>
                <span className="btn btn-primary mt-5 w-full">
                  Sign in <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" aria-hidden />
                </span>
              </div>
            </Link>
          ))}
        </div>
        <p className="mt-8 flex items-center justify-center gap-2 text-center text-xs text-slate-500 dark:text-slate-400">
          <ShieldCheck className="h-4 w-4 text-emerald-600" aria-hidden /> Secure, encrypted sign-in · Staff and administrators use the{' '}
          <a href={data?.app.admin_url || appUrl('admin/')} className="font-semibold text-slate-700 underline-offset-4 hover:underline dark:text-slate-200">
            admin panel
          </a>
        </p>
      </div>
    </div>
  );
}
