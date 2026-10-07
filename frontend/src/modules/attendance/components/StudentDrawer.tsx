import { useNavigate } from 'react-router-dom';
import { BellRing, ExternalLink, Mail, Phone } from 'lucide-react';
import { Alert, Avatar, Button, Drawer, Skeleton } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import type { ApiError, Query } from '@/lib/api';
import type { StudentDetailPayload } from '../types';
import { MiniStat, PercentBar, PercentRing } from './shared';
import { PercentTrend } from './PercentTrend';

export function StudentDrawer({ studentId, query, onClose, onAlert }: { studentId: number | null; query: Query; onClose: () => void; onAlert?: (s: { id: number; name: string; percent: number | null }) => void }) {
  const { can } = useAuth();
  const navigate = useNavigate();
  const q = useApi<StudentDetailPayload>(['attendance', 'student', studentId, query], `attendance/student/${studentId}`, query, { enabled: !!studentId });
  const d = q.data;
  return (
    <Drawer open={!!studentId} onClose={onClose} width="max-w-2xl" title="Student attendance" description={d ? `${formatDate(d.filters.from)} – ${formatDate(d.filters.to)}` : 'Loading…'}>
      {q.error ? (
        <Alert variant="error">{(q.error as ApiError).message}</Alert>
      ) : !d ? (
        <div className="space-y-4">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-40 w-full" />
          <Skeleton className="h-40 w-full" />
        </div>
      ) : (
        <div className="space-y-6">
          <div className="flex items-start gap-4">
            <Avatar name={d.student.name} src={d.student.photo} size="lg" />
            <div className="min-w-0 flex-1">
              <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{d.student.name}</p>
              <p className="text-sm text-slate-500 dark:text-slate-400">{d.student.student_uid} · Roll {d.student.roll_no ?? '—'} · {d.student.class_label ?? '—'}</p>
              <div className="mt-2 flex flex-wrap gap-3 text-xs text-slate-500">
                {d.student.mobile && <a href={`tel:${d.student.mobile.replace(/\s/g, '')}`} className="inline-flex items-center gap-1 hover:text-brand-700"><Phone className="h-3.5 w-3.5" />{d.student.mobile}</a>}
                {d.student.email && <a href={`mailto:${d.student.email}`} className="inline-flex items-center gap-1 hover:text-brand-700"><Mail className="h-3.5 w-3.5" />{d.student.email}</a>}
              </div>
            </div>
            <PercentRing value={d.overall.percent} min={d.min_percent} size={72} />
          </div>

          {d.overall.percent !== null && d.overall.percent < d.min_percent && (
            <Alert variant="warning" title={`Below the required ${d.min_percent}%`} action={can('attendance', 'manage') && onAlert ? <Button size="xs" variant="secondary" icon={BellRing} onClick={() => onAlert({ id: d.student.id, name: d.student.name, percent: d.overall.percent })}>Send alert</Button> : undefined}>
              Needs to attend the next <strong>{d.overall.needed}</strong> classes without absence to reach {d.min_percent}%.
              {d.last_alert ? ` Last alerted ${formatDate(d.last_alert)}.` : ' No alert sent yet.'}
            </Alert>
          )}

          <div className="grid grid-cols-3 gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-5 dark:bg-slate-800/40">
            <MiniStat label="Classes" value={d.overall.held} />
            <MiniStat label="Attended" value={d.overall.attended} tone="green" />
            <MiniStat label="Absent" value={d.overall.absent} tone="red" />
            <MiniStat label="Late" value={d.overall.late} tone="amber" />
            <MiniStat label="Leave" value={d.overall.leave} tone="blue" />
          </div>

          <section>
            <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Subject-wise attendance</h3>
            {d.subjects.length === 0 ? (
              <p className="rounded-xl bg-slate-50 px-4 py-6 text-center text-sm text-slate-500 dark:bg-slate-800/40">No classes recorded in this period.</p>
            ) : (
              <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                {d.subjects.map((s) => (
                  <li key={s.id} className="flex flex-col gap-1.5 px-3 py-2.5 sm:flex-row sm:items-center sm:gap-4">
                    <div className="min-w-0 flex-1 leading-tight">
                      <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{s.name}</p>
                      <p className="text-xs text-slate-500">{s.code} · {s.present + s.late}/{s.held} attended · {s.absent} absent</p>
                    </div>
                    <PercentBar value={s.percent} min={d.min_percent} className="sm:w-48" />
                  </li>
                ))}
              </ul>
            )}
          </section>

          {d.monthly.length > 1 && (
            <section>
              <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Monthly trend</h3>
              <PercentTrend labels={d.monthly.map((m) => m.label)} values={d.monthly.map((m) => m.percent)} min={d.min_percent} height={190} color="#1D4ED8" />
            </section>
          )}

          <section>
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Attendance log</h3>
              <Button size="xs" variant="ghost" iconRight={ExternalLink} to={`/students/${d.student.id}`}>Student profile</Button>
            </div>
            <CrudTable module="attendance_records" bare title={false} scope={{ person_type: 'student', person_id: d.student.id }} hideColumns={['person_name', 'class_label', 'in_time', 'out_time', 'person_type', 'method', 'updated_at']} hideFilters={['person_type', 'section_id']} addLabel="Mark attendance" onCreate={() => navigate('/attendance?tab=mark')} emptyTitle="No records" emptyText="No attendance has been recorded for this student." />
          </section>
        </div>
      )}
    </Drawer>
  );
}
