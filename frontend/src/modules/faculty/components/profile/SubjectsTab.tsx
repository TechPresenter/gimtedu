import { useState } from 'react';
import { BookOpen, ExternalLink, Layers } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, Reveal, Select, StatTile, Stagger, type Column } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useAcademicSession } from '@/lib/auth';
import { formatNumber, labelize } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { CalendarClock, GraduationCap, Hash } from 'lucide-react';

interface SubjectRow {
  id: number;
  is_primary: boolean;
  subject_id: number;
  code: string;
  name: string;
  type: string;
  credits: number;
  semester_no: number;
  hours_per_week: number | null;
  program: string;
  section: string | null;
  session_name: string | null;
  weekly_periods: number;
}

interface Payload {
  rows: SubjectRow[];
  totals: { subjects: number; sections: number; credits: number; weekly_periods: number };
  can_manage: boolean;
}

const typeColor: Record<string, 'navy' | 'cyan' | 'purple' | 'amber' | 'green'> = { theory: 'navy', practical: 'cyan', lab: 'cyan', project: 'purple', elective: 'amber' };

/** Subjects assigned to a faculty member (academics' faculty_subjects), read-only here. */
export default function SubjectsTab({ id }: { id: number }) {
  const { all } = useAcademicSession();
  const [session, setSession] = useState('');
  const { data, isLoading, error } = useApi<Payload>(['hr-subjects', id, session], `faculty/${id}/subjects`, session ? { session_id: session } : undefined);
  const columns: Column<SubjectRow>[] = [
    {
      key: 'name', header: 'Subject',
      render: (r) => (
        <div className="min-w-0">
          <p className="font-semibold text-slate-900 dark:text-white">{r.name}</p>
          <p className="text-xs text-slate-500"><code className="font-semibold">{r.code}</code> · Semester {r.semester_no}</p>
        </div>
      ),
    },
    { key: 'type', header: 'Type', render: (r) => <Badge color={typeColor[r.type] ?? 'slate'}>{labelize(r.type)}</Badge> },
    { key: 'class', header: 'Class', render: (r) => <span className="whitespace-nowrap">{r.program} · Sem {r.semester_no}{r.section ? ` · Sec ${r.section}` : ''}</span> },
    { key: 'credits', header: 'Credits', align: 'center', render: (r) => formatNumber(r.credits, r.credits % 1 ? 1 : 0) },
    {
      key: 'periods', header: 'Periods / week', align: 'center',
      render: (r) => (
        <span className={r.hours_per_week && r.weekly_periods < r.hours_per_week ? 'font-semibold text-amber-600 dark:text-amber-400' : ''} title={r.hours_per_week ? `${r.hours_per_week} hours/week planned` : undefined}>
          {r.weekly_periods}{r.hours_per_week ? ` / ${r.hours_per_week}` : ''}
        </span>
      ),
    },
    { key: 'role', header: 'Role', render: (r) => (r.is_primary ? <Badge color="green" dot>Primary</Badge> : <Badge color="slate">Co-faculty</Badge>) },
    { key: 'session', header: 'Session', render: (r) => r.session_name ?? '—' },
  ];
  return (
    <div className="space-y-4">
      <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatTile label="Subjects" value={data?.totals.subjects ?? 0} icon={BookOpen} tone="navy" loading={isLoading} />
        <StatTile label="Class sections" value={data?.totals.sections ?? 0} icon={Layers} tone="cyan" loading={isLoading} />
        <StatTile label="Credits handled" value={data?.totals.credits ?? 0} icon={GraduationCap} tone="green" loading={isLoading} />
        <StatTile label="Scheduled periods / week" value={data?.totals.weekly_periods ?? 0} icon={CalendarClock} tone="purple" loading={isLoading} />
      </Stagger>
      <Reveal>
        <Card className="overflow-hidden">
          <CardHeader
            title="Assigned subjects"
            subtitle="Assignments are managed by Academics › Faculty Assignments"
            icon={Hash}
            actions={
              <div className="flex flex-wrap items-center gap-2">
                <Select
                  inputSize="sm"
                  aria-label="Academic session"
                  value={session}
                  onChange={(e) => setSession(e.target.value)}
                  options={[...all.map((s) => ({ value: String(s.id), label: `Session ${s.name}` })), { value: 'all', label: 'All sessions' }]}
                  placeholder="Current session"
                  className="!w-40"
                />
                <Button size="sm" variant="secondary" icon={ExternalLink} to="/academics?tab=assignments">
                  Manage
                </Button>
              </div>
            }
          />
          {error ? (
            <div className="p-5"><Alert variant="error">{(error as ApiError).message}</Alert></div>
          ) : (
            <DataTable<SubjectRow>
              columns={columns}
              rows={data?.rows ?? []}
              loading={isLoading}
              skeletonRows={5}
              caption="Assigned subjects"
              empty={
                <EmptyState
                  icon={BookOpen}
                  title="No subjects assigned"
                  description="Assign subjects and sections to this faculty member from Academics › Faculty Assignments."
                  action={<Button to="/academics?tab=assignments" icon={ExternalLink}>Open faculty assignments</Button>}
                />
              }
            />
          )}
        </Card>
      </Reveal>
    </div>
  );
}
