import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Bell, CalendarRange, ChevronDown, CircleHelp, ExternalLink, LayoutGrid, LogOut, Mail, Menu, Moon, PanelLeftClose, PanelLeftOpen, Plus, Search, Settings, Sun, UserRound, UserPlus, GraduationCap, Megaphone, HandCoins, ClipboardList, CalendarDays, BookOpen, CalendarPlus, Users } from 'lucide-react';
import { Avatar, Dropdown, useToast } from '@/components/ui';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { useTheme } from '@/lib/theme';
import { api } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { NotificationMenu } from './NotificationMenu';

interface TopbarProps {
  collapsed: boolean;
  onToggleCollapse: () => void;
  onOpenMobile: () => void;
  onOpenSearch: () => void;
}

export function Topbar({ collapsed, onToggleCollapse, onOpenMobile, onOpenSearch }: TopbarProps) {
  const { session, logout, setAcademicSession, can } = useAuth();
  const academic = useAcademicSession();
  const { resolved, setTheme } = useTheme();
  const navigate = useNavigate();
  const toast = useToast();
  const user = session?.user;
  const { data: counts } = useQuery({
    queryKey: ['notification-counts'],
    queryFn: () => api.get<{ notifications: number; messages: number }>('notifications/count', undefined, { background: true }),
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
  });

  const quickAdd = [
    can('students', 'create') && { label: 'Add Student', icon: GraduationCap, to: '/students/new' },
    can('faculty', 'create') && { label: 'Add Faculty', icon: Users, to: '/faculty?add=1' },
    can('admissions', 'create') && { label: 'New Admission', icon: UserPlus, to: '/admissions/new' },
    can('notices', 'create') && { label: 'Create Notice', icon: Megaphone, to: '/notices?add=1' },
    can('examination', 'create') && { label: 'Create Exam', icon: ClipboardList, to: '/examination?add=1' },
    can('fees', 'create') && { label: 'Collect Fee', icon: HandCoins, to: '/fees/collect' },
    can('academics', 'create') && { label: 'Add Program', icon: BookOpen, to: '/academics?tab=programs&add=1' },
    can('timetable', 'create') && { label: 'Create Timetable', icon: CalendarDays, to: '/timetable' },
    can('events', 'create') && { label: 'Add Event', icon: CalendarPlus, to: '/events?add=1' },
  ];

  return (
    <header className="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-slate-200/80 bg-white/90 px-3 backdrop-blur sm:gap-3 sm:px-5 dark:border-slate-800 dark:bg-slate-900/90">
      <button type="button" className="btn-icon lg:hidden" onClick={onOpenMobile} aria-label="Open menu">
        <Menu className="h-5 w-5" />
      </button>
      <button type="button" className="btn-icon hidden lg:inline-flex" onClick={onToggleCollapse} aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'} title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}>
        {collapsed ? <PanelLeftOpen className="h-5 w-5" /> : <PanelLeftClose className="h-5 w-5" />}
      </button>

      <button
        type="button"
        onClick={onOpenSearch}
        className="group flex h-10 min-w-0 flex-1 items-center gap-2.5 rounded-xl border border-slate-200 bg-slate-50/80 px-3 text-left text-sm text-slate-400 transition hover:border-slate-300 hover:bg-white sm:max-w-md dark:border-slate-700 dark:bg-slate-800/60 dark:hover:bg-slate-800"
        aria-label="Search students, applications, fees and more"
      >
        <Search className="h-4 w-4 shrink-0" />
        <span className="truncate">Search students, applications, fees, etc…</span>
        <span className="ml-auto hidden items-center gap-1 sm:flex">
          <kbd className="kbd">Ctrl</kbd>
          <kbd className="kbd">K</kbd>
        </span>
      </button>

      <div className="ml-auto flex items-center gap-1 sm:gap-1.5">
        {academic.all.length > 0 && (
          <Dropdown
            label="Academic session"
            align="right"
            triggerClassName="hidden md:flex items-center gap-2 rounded-xl px-2.5 py-1.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800"
            trigger={
              <>
                <CalendarRange className="h-4 w-4 text-brand-700 dark:text-brand-300" />
                <span className="leading-tight">
                  <span className="block text-[10px] font-medium text-slate-500">Academic Session</span>
                  <span className="block text-sm font-bold text-slate-900 dark:text-white">{academic.session?.name ?? '—'}</span>
                </span>
                <ChevronDown className="h-3.5 w-3.5 text-slate-400" />
              </>
            }
            items={academic.all.map((s) => ({
              label: `${s.name}${s.is_current ? ' (current)' : ''}${s.id === academic.id ? '  ✓' : ''}`,
              onClick: async () => {
                await setAcademicSession(s.id);
                toast.success(`Switched to academic session ${s.name}.`);
              },
            }))}
          />
        )}

        <Dropdown
          label="Quick add"
          triggerClassName="btn-icon hidden sm:inline-flex"
          trigger={<Plus className="h-5 w-5" />}
          header={<span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Quick add</span>}
          items={quickAdd}
        />
        <button type="button" className="btn-icon" onClick={() => setTheme(resolved === 'dark' ? 'light' : 'dark')} aria-label="Toggle dark mode" title="Toggle dark mode">
          {resolved === 'dark' ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
        </button>
        <NotificationMenu count={counts?.notifications ?? 0} />
        <Link to="/messages" className="btn-icon relative" aria-label={`Messages${counts?.messages ? ` (${counts.messages} unread)` : ''}`} title="Messages">
          <Mail className="h-5 w-5" />
          {!!counts?.messages && <span className="absolute -right-0.5 -top-0.5 inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-white dark:ring-slate-900">{counts.messages > 99 ? '99+' : counts.messages}</span>}
        </Link>
        <Dropdown
          label="Apps"
          triggerClassName="btn-icon hidden md:inline-flex"
          trigger={<LayoutGrid className="h-5 w-5" />}
          items={[
            { label: 'Visit website', icon: ExternalLink, href: appUrl(''), target: '_blank' },
            can('reports') && { label: 'Reports & Analytics', icon: LayoutGrid, to: '/reports' },
            can('settings') && { label: 'System settings', icon: Settings, to: '/settings' },
            { label: 'Help & support', icon: CircleHelp, href: appUrl('contact'), target: '_blank' },
          ]}
        />

        <Dropdown
          label="Account menu"
          triggerClassName="ml-1 flex items-center gap-2.5 rounded-xl py-1 pl-1 pr-2 hover:bg-slate-100 dark:hover:bg-slate-800"
          width="w-64"
          trigger={
            <>
              <Avatar name={user?.name} src={user?.avatar} size="md" />
              <span className="hidden text-left leading-tight lg:block">
                <span className="block max-w-[10rem] truncate text-sm font-semibold text-slate-900 dark:text-white">{user?.name}</span>
                <span className="block max-w-[10rem] truncate text-xs text-slate-500">{user?.roles ?? user?.designation}</span>
              </span>
              <ChevronDown className="hidden h-4 w-4 text-slate-400 lg:block" />
            </>
          }
          header={
            <div className="py-1">
              <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{user?.name}</p>
              <p className="truncate text-xs text-slate-500">{user?.email}</p>
            </div>
          }
          items={[
            { label: 'My profile', icon: UserRound, to: '/profile' },
            { label: 'Notifications', icon: Bell, to: '/notifications' },
            { label: 'Messages', icon: Mail, to: '/messages' },
            can('settings') && { label: 'Settings', icon: Settings, to: '/settings' },
            { divider: true, label: '' },
            {
              label: 'Sign out',
              icon: LogOut,
              danger: true,
              onClick: async () => {
                await logout();
                navigate('/login', { replace: true });
                toast.success('You have been signed out.');
              },
            },
          ]}
        />
      </div>
    </header>
  );
}
