import type { ReactNode } from 'react';
import { Award, Building2, GraduationCap, ShieldCheck } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { useAuth } from '@/lib/auth';

/** Split-screen layout for login / forgot / reset pages. */
export function AuthLayout({ children, title, subtitle }: { children: ReactNode; title: string; subtitle?: string }) {
  const { session } = useAuth();
  return (
    <div className="flex min-h-screen bg-white dark:bg-slate-950">
      <div className="relative hidden w-1/2 overflow-hidden bg-brand-900 lg:flex lg:flex-col xl:w-[55%]">
        <img src={appUrl('assets/images/site/hero-campus-students.jpg')} alt="" className="absolute inset-0 h-full w-full object-cover opacity-30" />
        <div className="absolute inset-0 bg-gradient-to-br from-[#0B2A5B]/95 via-[#0B2A5B]/80 to-[#0E5A3A]/70" />
        <div className="relative z-10 flex flex-1 flex-col justify-between p-12 text-white">
          <img src={session?.app.logo_white ?? appUrl('assets/images/logo-white.svg')} alt="Global IMT" className="h-16 w-auto self-start" />
          <div className="max-w-lg">
            <p className="text-sm font-semibold uppercase tracking-[0.2em] text-accent-300">GIMT SmartCampus</p>
            <h1 className="mt-3 font-display text-4xl font-extrabold leading-tight xl:text-5xl">
              One platform for your entire <span className="text-accent-300">campus</span>.
            </h1>
            <p className="mt-4 text-base text-white/75">Admissions, academics, attendance, fees, examinations, placements and the website — securely managed in one place.</p>
            <div className="mt-8 grid grid-cols-2 gap-3">
              {[
                { icon: GraduationCap, label: 'Student lifecycle', text: 'Enquiry to alumni' },
                { icon: Award, label: 'Exams & results', text: 'Marksheets & certificates' },
                { icon: Building2, label: 'Campus operations', text: 'Library, hostel, transport' },
                { icon: ShieldCheck, label: 'Enterprise security', text: 'Roles, audit logs, backups' },
              ].map((f) => (
                <div key={f.label} className="flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[.06] p-3.5 backdrop-blur">
                  <f.icon className="mt-0.5 h-5 w-5 shrink-0 text-accent-300" />
                  <div>
                    <p className="text-sm font-semibold">{f.label}</p>
                    <p className="text-xs text-white/60">{f.text}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
          <p className="text-xs text-white/50">© {new Date().getFullYear()} {session?.app.institute_name ?? 'Global Institute of Management & Technology'}</p>
        </div>
      </div>
      <div className="flex flex-1 flex-col justify-center px-6 py-12 sm:px-12">
        <div className="mx-auto w-full max-w-sm">
          <img src={session?.app.logo ?? appUrl('assets/images/logo.svg')} alt="Global IMT" className="mb-8 h-14 w-auto lg:hidden" />
          <h2 className="font-display text-2xl font-bold text-slate-900 dark:text-white">{title}</h2>
          {subtitle && <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>}
          <div className="mt-8">{children}</div>
        </div>
      </div>
    </div>
  );
}
