import { useMemo } from 'react';
import { GraduationCap, LineChart as LineIcon } from 'lucide-react';
import { Card, CardBody, CardHeader, DescriptionList, Reveal } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { LineChart } from '@/components/charts';
import { formatDate, labelize } from '@/lib/format';
import { ADMISSION_TYPES } from '../constants';
import type { TabProps } from './shared';

export default function AcademicTab({ profile, studentId, refresh }: TabProps) {
  const s = profile.student;
  const trend = profile.academic_history.filter((h) => h.sgpa !== null);
  const defaults = useMemo(() => ({ program_id: s.program_id, academic_session_id: s.academic_session_id ?? '', roll_no: s.roll_no ?? '' }), [s.program_id, s.academic_session_id, s.roll_no]);
  return (
    <div className="space-y-5">
      <div className="grid gap-5 lg:grid-cols-5 [&>*]:min-w-0">
        <Reveal className="lg:col-span-3">
          <Card className="h-full">
            <CardHeader title="Current enrolment" icon={GraduationCap} subtitle={s.session_name ? `Admitted in ${s.session_name}` : undefined} />
            <CardBody>
              <DescriptionList columns={2} items={[
                { label: 'Program', value: `${s.program_full_name} (${s.program_name})` },
                { label: 'Level', value: s.program_level },
                { label: 'Department', value: s.department_name },
                { label: 'Specialization', value: s.course_name },
                { label: 'Batch', value: s.batch_name },
                { label: 'Semester', value: `${s.current_semester} of ${s.total_semesters}` },
                { label: 'Section', value: s.section_name ? `Section ${s.section_name}` : 'Not assigned' },
                { label: 'Roll number', value: s.roll_no },
                { label: 'University enrollment', value: s.enrollment_no },
                { label: 'Admission', value: `${s.admission_date ? formatDate(s.admission_date) : '—'} · ${ADMISSION_TYPES.find((a) => a.value === s.admission_type)?.label ?? labelize(s.admission_type)}` },
                { label: 'Hosteller', value: s.is_hosteller ? 'Yes' : 'No' },
                { label: 'College transport', value: s.uses_transport ? 'Yes' : 'No' },
              ]} />
            </CardBody>
          </Card>
        </Reveal>
        <Reveal className="lg:col-span-2" delay={60}>
          <Card className="h-full">
            <CardHeader title="Performance trend" icon={LineIcon} subtitle="SGPA and CGPA by semester" />
            <CardBody>
              {trend.length ? (
                <LineChart
                  height={230}
                  labels={trend.map((t) => `Sem ${t.semester_no}`)}
                  series={[
                    { label: 'SGPA', data: trend.map((t) => Number(t.sgpa)), color: '#1D4ED8' },
                    { label: 'CGPA', data: trend.map((t) => Number(t.cgpa)), color: '#22943F', dashed: true },
                  ]}
                />
              ) : (
                <div className="flex h-[230px] flex-col items-center justify-center text-center text-sm text-slate-500">
                  <LineIcon className="mb-2 h-8 w-8 text-slate-300" />
                  No completed semesters yet — the trend appears after the first promotion.
                </div>
              )}
            </CardBody>
          </Card>
        </Reveal>
      </div>
      <Reveal delay={100}>
        <CrudTable
          module="student_academic"
          scope={{ student_id: studentId }}
          title="Academic history"
          description="One record per semester: enrolment, promotion, detention or completion."
          hideColumns={['student_name']}
          hideFilters={['program_id']}
          addLabel="Add record"
          formDefaults={defaults}
          emptyTitle="No academic records"
          emptyText="Semester records are created automatically on admission and promotion."
          onSaved={refresh}
        />
      </Reveal>
    </div>
  );
}
