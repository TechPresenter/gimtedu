import { Contact, MapPin, Pencil, ShieldCheck, User } from 'lucide-react';
import { Button, Card, CardBody, CardHeader, DescriptionList, Reveal } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, labelize } from '@/lib/format';
import type { TabProps } from './shared';

export default function PersonalTab({ profile, studentId, refresh }: TabProps) {
  const { can } = useAuth();
  const s = profile.student;
  const edit = can('students', 'edit') ? <Button size="sm" variant="secondary" icon={Pencil} to={`/students/${studentId}/edit`}>Edit</Button> : undefined;
  return (
    <div className="space-y-5">
      <div className="grid gap-5 lg:grid-cols-2 [&>*]:min-w-0">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Personal information" icon={User} actions={edit} />
            <CardBody>
              <DescriptionList items={[
                { label: 'First name', value: s.first_name },
                { label: 'Middle / last name', value: [s.middle_name, s.last_name].filter(Boolean).join(' ') || null },
                { label: 'Gender', value: labelize(s.gender) },
                { label: 'Date of birth', value: s.dob ? `${formatDate(s.dob)}${s.age !== null ? ` · ${s.age} years` : ''}` : null },
                { label: 'Blood group', value: s.blood_group },
                { label: 'Category', value: s.category },
                { label: 'Religion', value: s.religion },
                { label: 'Nationality', value: s.nationality },
                { label: 'Aadhaar', value: s.aadhaar_masked ? <span className="inline-flex items-center gap-1.5 font-mono">{s.aadhaar_masked}<ShieldCheck className="h-3.5 w-3.5 text-emerald-600" aria-label="Masked for privacy" /></span> : null },
              ]} />
            </CardBody>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Contact & address" icon={Contact} />
            <CardBody>
              <DescriptionList items={[
                { label: 'Mobile', value: <a className="link !font-medium" href={`tel:${s.mobile.replace(/\s/g, '')}`}>{s.mobile}</a> },
                { label: 'WhatsApp', value: s.whatsapp },
                { label: 'Email', value: s.email ? <a className="link !font-medium break-all" href={`mailto:${s.email}`}>{s.email}</a> : null },
                { label: 'Emergency contact', value: s.emergency_contact_name ? `${s.emergency_contact_name}${s.emergency_contact_phone ? ` · ${s.emergency_contact_phone}` : ''}` : null },
                { label: 'Correspondence address', full: true, value: [s.address, s.city, s.state, s.pincode, s.country].filter(Boolean).join(', ') || null },
                { label: 'Permanent address', full: true, value: s.permanent_address },
              ]} />
              <p className="mt-5 flex items-center gap-1.5 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800">
                <MapPin className="h-3.5 w-3.5" /> Record created {formatDateTime(s.created_at)}{s.created_by_name ? ` by ${s.created_by_name}` : ''}{s.updated_at ? ` · updated ${formatDateTime(s.updated_at)}` : ''}
              </p>
            </CardBody>
          </Card>
        </Reveal>
      </div>
      <Reveal delay={100}>
        <CrudTable
          module="student_parents"
          scope={{ student_id: studentId }}
          title="Parents & guardians"
          description="Contacts used for fee reminders, attendance alerts and emergencies."
          hideColumns={['student_name']}
          addLabel="Add contact"
          emptyTitle="No parents or guardians recorded"
          emptyText="Add the father, mother or a local guardian so the institute can reach the family."
          onSaved={refresh}
        />
      </Reveal>
    </div>
  );
}
