import { CalendarClock, ClipboardList, Ticket } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Reveal, StatusBadge, type Column } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDate, formatTime } from '@/lib/format';
import { TabEmpty, TabError, TabLoading, TableCard, type TabProps } from './shared';

interface ExamRow extends Record<string, unknown> {
  id: number; name: string; type_name: string; session_name: string | null; semester_no: number | null; start_date: string | null; end_date: string | null; status: string;
  hall_ticket_no: string | null; seat_no: string | null; is_eligible: boolean; ineligibility_reason: string | null; room_name: string | null;
}
interface ScheduleRow extends Record<string, unknown> {
  id: number; exam_id: number; exam_name: string; subject_code: string; subject_name: string; exam_date: string; start_time: string; end_time: string; room_name: string | null; attendance_status: string | null;
}
interface ExamsData { exams: ExamRow[]; schedules: ScheduleRow[]; upcoming: { id: number; name: string; type_name: string; start_date: string | null; end_date: string | null; status: string }[] }

const range = (a: string | null, b: string | null) => (a ? `${formatDate(a)}${b && b !== a ? ` – ${formatDate(b)}` : ''}` : '—');

export default function ExamsTab({ studentId }: TabProps) {
  const q = useApi<ExamsData>(['students', studentId, 'exams'], `students/${studentId}/exams`);
  if (q.isLoading) return <TabLoading />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const { exams, schedules, upcoming } = q.data!;
  if (!exams.length && !upcoming.length) {
    return <TabEmpty icon={ClipboardList} title="No examinations yet" description="Exams appear here once the examination cell allocates this student to an exam." action={<Button variant="secondary" to="/examination">Open examinations</Button>} />;
  }
  const today = new Date().toISOString().slice(0, 10);
  const examCols: Column<ExamRow>[] = [
    { key: 'name', header: 'Exam', render: (r) => <div className="leading-tight"><p className="font-semibold text-slate-900 dark:text-white">{r.name}</p><p className="text-xs text-slate-500">{r.type_name}{r.semester_no ? ` · Sem ${r.semester_no}` : ''}{r.session_name ? ` · ${r.session_name}` : ''}</p></div> },
    { key: 'start_date', header: 'Dates', render: (r) => <span className="whitespace-nowrap">{range(r.start_date, r.end_date)}</span> },
    { key: 'hall_ticket_no', header: 'Hall ticket / seat', render: (r) => <span className="font-mono text-xs">{r.hall_ticket_no ?? '—'}{r.seat_no ? ` · ${r.seat_no}` : ''}{r.room_name ? <span className="block font-sans text-slate-500">{r.room_name}</span> : null}</span> },
    { key: 'is_eligible', header: 'Eligibility', render: (r) => (r.is_eligible ? <Badge color="green" dot>Eligible</Badge> : <span title={r.ineligibility_reason ?? ''}><Badge color="red" dot>Not eligible</Badge></span>) },
    { key: 'status', header: 'Exam status', render: (r) => <StatusBadge status={r.status} /> },
  ];
  const schedCols: Column<ScheduleRow>[] = [
    { key: 'exam_date', header: 'Date', render: (r) => <span className={r.exam_date >= today ? 'whitespace-nowrap font-semibold text-brand-800 dark:text-brand-200' : 'whitespace-nowrap'}>{formatDate(r.exam_date)}</span> },
    { key: 'time', header: 'Time', render: (r) => <span className="whitespace-nowrap">{formatTime(r.start_time)} – {formatTime(r.end_time)}</span> },
    { key: 'subject_name', header: 'Subject', render: (r) => <span><span className="mr-1.5 font-mono text-xs text-slate-500">{r.subject_code}</span>{r.subject_name}</span> },
    { key: 'exam_name', header: 'Exam', render: (r) => <span className="text-xs text-slate-500">{r.exam_name}</span> },
    { key: 'room_name', header: 'Room', render: (r) => r.room_name ?? '—' },
    { key: 'attendance_status', header: 'Attendance', render: (r) => (r.attendance_status ? <StatusBadge status={r.attendance_status} /> : <span className="text-xs text-slate-400">{r.exam_date >= today ? 'Upcoming' : 'Not marked'}</span>) },
  ];
  return (
    <div className="space-y-5">
      {upcoming.length > 0 && (
        <Reveal>
          <Card>
            <CardHeader title="Upcoming exams for this class" subtitle="Scheduled but not yet allocated to the student" icon={CalendarClock} />
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {upcoming.map((u) => (
                <li key={u.id} className="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                  <div className="min-w-0">
                    <p className="truncate font-medium text-slate-800 dark:text-slate-100">{u.name}</p>
                    <p className="text-xs text-slate-500">{u.type_name} · {range(u.start_date, u.end_date)}</p>
                  </div>
                  <StatusBadge status={u.status} />
                </li>
              ))}
            </ul>
          </Card>
        </Reveal>
      )}
      <TableCard<ExamRow> title="Allocated exams" subtitle="Hall tickets, seats and eligibility" icon={Ticket} columns={examCols} rows={exams} empty="Not allocated to any exam yet" emptyIcon={Ticket} />
      <TableCard<ScheduleRow> title="Exam timetable" subtitle="Papers across the allocated exams" icon={CalendarClock} columns={schedCols} rows={schedules} empty="No papers scheduled" emptyIcon={CalendarClock} delay={60} />
    </div>
  );
}
