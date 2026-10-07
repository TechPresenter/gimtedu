import { lazy, Suspense, type ComponentType } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  BookMarked, BookOpen, Building2, CalendarDays, CalendarRange, DoorOpen, GraduationCap, Layers, LayoutGrid, UserCheck, Users, type LucideIcon,
} from 'lucide-react';
import { Button, CardSkeleton, PageHeader, Tabs } from '@/components/ui';
import { useAuth } from '@/lib/auth';

interface TabConfig {
  key: string;
  label: string;
  /** Page title when different from the tab label */
  title?: string;
  icon: LucideIcon;
  description: string;
  component: ComponentType;
}

const lazyTab = (name: string) =>
  lazy(() => import('./components/StructureTabs').then((m) => ({ default: (m as unknown as Record<string, ComponentType>)[name] })));

const TABS: TabConfig[] = [
  { key: 'overview', label: 'Overview', icon: LayoutGrid, description: 'Department → Program → Specialization → Semester → Subject → Faculty → Students at a glance.', component: lazy(() => import('./components/OverviewTab')) },
  { key: 'sessions', label: 'Sessions', title: 'Academic Sessions', icon: CalendarRange, description: 'Academic years, the current session and admission windows.', component: lazyTab('SessionsTab') },
  { key: 'departments', label: 'Departments', icon: Building2, description: 'Academic departments, their heads and size.', component: lazyTab('DepartmentsTab') },
  { key: 'programs', label: 'Programs', icon: GraduationCap, description: 'Degree, diploma and certificate programs - also shown on the website.', component: lazyTab('ProgramsTab') },
  { key: 'courses', label: 'Specializations', title: 'Courses / Specializations', icon: BookMarked, description: 'Courses and specializations offered within each program.', component: lazyTab('CoursesTab') },
  { key: 'semesters', label: 'Semesters', icon: Layers, description: 'Program semesters with dates, subjects and credit load.', component: lazyTab('SemestersTab') },
  { key: 'subjects', label: 'Subjects', icon: BookOpen, description: 'Subjects with credits, marks scheme, electives and faculty coverage.', component: lazyTab('SubjectsTab') },
  { key: 'sections', label: 'Sections', title: 'Sections & Batches', icon: Users, description: 'Class sections of the session and admission batches.', component: lazyTab('SectionsTab') },
  { key: 'rooms', label: 'Rooms', title: 'Classrooms & Time Slots', icon: DoorOpen, description: 'Classrooms, labs and halls, and the periods of the teaching day.', component: lazyTab('RoomsTab') },
  { key: 'assignments', label: 'Assignments', title: 'Faculty Assignments', icon: UserCheck, description: 'Who teaches which subject to which section, and faculty workload.', component: lazy(() => import('./components/AssignmentsTab')) },
];

export default function AcademicsPage() {
  const [params, setParams] = useSearchParams();
  const { can } = useAuth();
  const active = TABS.find((t) => t.key === params.get('tab')) ?? TABS[0];
  const Panel = active.component;

  const select = (key: string) => {
    // Drop the previous tab's search/filter params so every tab opens clean.
    setParams(key === 'overview' ? {} : { tab: key });
  };

  return (
    <>
      <PageHeader
        title={active.key === 'overview' ? 'Academics' : active.title ?? active.label}
        description={active.description}
        breadcrumbs={[{ label: 'Academics', to: '/academics' }, ...(active.key === 'overview' ? [] : [{ label: active.title ?? active.label }])]}
        actions={
          can('timetable') ? (
            <Button variant="secondary" icon={CalendarDays} to="/timetable">
              Timetable
            </Button>
          ) : undefined
        }
      >
        <Tabs tabs={TABS.map(({ key, label, icon }) => ({ key, label, icon }))} value={active.key} onChange={select} />
      </PageHeader>
      <div key={active.key} className="motion-safe:animate-fade-in">
        <Suspense
          fallback={
            <div className="space-y-4">
              <CardSkeleton lines={3} />
              <CardSkeleton lines={6} />
            </div>
          }
        >
          <Panel />
        </Suspense>
      </div>
    </>
  );
}
