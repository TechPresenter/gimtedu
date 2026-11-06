import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowRight, LogOut, ShieldX, UserRound } from 'lucide-react';
import { Avatar, Button } from '@/components/ui';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { useDocumentTitle } from '@/lib/hooks';
import { AuthLayout } from '@/pages/auth/AuthLayout';

/** Permission modules that belong to the self-service portals, not to the admin panel. */
export const PORTAL_ONLY_MODULES = ['faculty_portal'];

/**
 * Shown by AdminLayout instead of the admin application when the signed-in account has no admin-module permission at
 * all — e.g. the Faculty role, which uses the Faculty Portal only (every admin API answers 403 for it). Offers the portal
 * and a sign-out instead of a dashboard full of "access denied" errors.
 */
export function PortalOnlyAccount() {
  const { session, can, logout } = useAuth();
  const navigate = useNavigate();
  const [leaving, setLeaving] = useState(false);
  const faculty = can('faculty_portal', 'view');
  useDocumentTitle(faculty ? 'Use the Faculty Portal' : 'No admin access');
  const user = session?.user;

  const signOut = async () => {
    setLeaving(true);
    await logout().catch(() => undefined);
    navigate('/login', { replace: true });
  };

  return (
    <AuthLayout
      title={faculty ? 'Your account uses the Faculty Portal' : 'No access to the admin panel'}
      subtitle={
        faculty
          ? 'Faculty accounts sign in to the Faculty Portal for classes, attendance, marks, leave and notices. The admin panel is for administrative staff.'
          : 'Your account is active, but no admin role has been assigned to it yet. Please contact a Super Admin to get access.'
      }
    >
      {user && (
        <div className="mb-6 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 dark:border-slate-800 dark:bg-slate-900">
          <Avatar name={user.name} src={user.avatar} size="sm" />
          <div className="min-w-0 text-sm leading-tight">
            <p className="truncate font-semibold text-slate-900 dark:text-white">{user.name}</p>
            <p className="truncate text-xs text-slate-500 dark:text-slate-400">{user.roles || user.email}</p>
          </div>
          {faculty ? (
            <UserRound className="ml-auto h-5 w-5 shrink-0 text-brand-600 dark:text-brand-300" aria-hidden />
          ) : (
            <ShieldX className="ml-auto h-5 w-5 shrink-0 text-amber-500" aria-hidden />
          )}
        </div>
      )}
      <div className="space-y-3">
        {faculty && (
          <Button href={appUrl('portal/faculty/')} className="w-full" size="lg" iconRight={ArrowRight}>
            Open the Faculty Portal
          </Button>
        )}
        <Button variant={faculty ? 'secondary' : 'primary'} className="w-full" size="lg" icon={LogOut} loading={leaving} onClick={() => void signOut()}>
          Sign out
        </Button>
      </div>
      <p className="mt-6 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
        Need admin access as well? A Super Admin can add an administrative role to your account under Users &amp; Roles.
      </p>
    </AuthLayout>
  );
}
