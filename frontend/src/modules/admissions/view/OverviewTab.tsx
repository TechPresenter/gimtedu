import type { ReactNode } from 'react';
import { BookOpen, GraduationCap, Phone, User, Users } from 'lucide-react';
import { Card, CardHeader, DescriptionList } from '@/components/ui';
import { formatDate, formatDateTime, formatMoney, labelize, toNumber } from '@/lib/format';
import type { AdmissionProfile } from './types';

const QUOTAS: Record<string, string> = { general: 'General Merit', management: 'Management Quota', sports: 'Sports Quota', nri: 'NRI Quota', defence: 'Defence / Ex-servicemen', scholarship: 'Scholarship', lateral: 'Lateral Entry' };

function age(dob: unknown): string {
  if (!dob) return '';
  const d = new Date(`${String(dob)}T00:00:00`);
  const n = new Date();
  let y = n.getFullYear() - d.getFullYear();
  if (n.getMonth() < d.getMonth() || (n.getMonth() === d.getMonth() && n.getDate() < d.getDate())) y--;
  return ` (${y} yrs)`;
}

function Section({ title, icon, children }: { title: string; icon: typeof User; children: ReactNode }) {
  return (
    <Card>
      <CardHeader title={title} icon={icon} />
      <div className="card-body">{children}</div>
    </Card>
  );
}

export function OverviewTab({ data }: { data: AdmissionProfile }) {
  const a = data.admission;
  const v = (x: unknown) => (x === null || x === undefined || x === '' ? null : String(x));
  const masked = a.aadhaar_no ? `XXXX XXXX ${String(a.aadhaar_no).slice(-4)}` : null;
  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <Section title="Personal details" icon={User}>
        <DescriptionList
          items={[
            { label: 'Full name', value: v(a.full_name) },
            { label: 'Gender', value: a.gender ? labelize(String(a.gender)) : null },
            { label: 'Date of birth', value: a.dob ? `${formatDate(a.dob)}${age(a.dob)}` : null },
            { label: 'Blood group', value: v(a.blood_group) },
            { label: 'Nationality', value: v(a.nationality) },
            { label: 'Aadhaar', value: masked },
            { label: 'Category', value: v(a.category) },
            { label: 'Quota', value: a.quota ? QUOTAS[String(a.quota)] ?? labelize(String(a.quota)) : null },
          ]}
        />
      </Section>
      <Section title="Contact" icon={Phone}>
        <DescriptionList
          items={[
            { label: 'Mobile', value: a.phone ? <a className="link" href={`tel:${String(a.phone).replace(/\s/g, '')}`}>{String(a.phone)}</a> : null },
            { label: 'WhatsApp', value: v(a.whatsapp) },
            { label: 'Email', value: a.email ? <a className="link" href={`mailto:${a.email}`}>{String(a.email)}</a> : null, full: true },
            { label: 'Address', value: [a.address, a.city, a.state, a.pincode, a.country].filter(Boolean).join(', ') || null, full: true },
          ]}
        />
      </Section>
      <Section title="Parents / guardian" icon={Users}>
        <DescriptionList
          items={[
            { label: "Father's name", value: v(a.father_name) },
            { label: "Father's mobile / occupation", value: [a.father_phone, a.father_occupation].filter(Boolean).join(' · ') || null },
            { label: "Mother's name", value: v(a.mother_name) },
            { label: "Mother's mobile / occupation", value: [a.mother_phone, a.mother_occupation].filter(Boolean).join(' · ') || null },
            { label: 'Local guardian', value: a.guardian_name ? `${a.guardian_name}${a.guardian_relation ? ` (${a.guardian_relation})` : ''}` : null },
            { label: 'Guardian mobile', value: v(a.guardian_phone) },
            { label: 'Annual family income', value: a.family_income ? formatMoney(a.family_income) : null },
          ]}
        />
      </Section>
      <Section title="Previous education" icon={BookOpen}>
        <DescriptionList
          items={[
            { label: 'Class 10', value: a.tenth_board ? `${a.tenth_board}${a.tenth_year ? ` · ${a.tenth_year}` : ''}${a.tenth_percentage ? ` · ${toNumber(a.tenth_percentage).toFixed(2)}%` : ''}` : null, full: true },
            { label: 'Qualifying exam', value: v(a.previous_qualification) },
            { label: 'Board / University', value: v(a.previous_board) },
            { label: 'School / College', value: v(a.previous_institution) },
            { label: 'Passing year · Marks', value: a.passing_year ? `${a.passing_year}${a.previous_percentage ? ` · ${toNumber(a.previous_percentage).toFixed(2)}%` : ''}` : null },
            { label: 'Entrance exam', value: v(a.entrance_exam) },
          ]}
        />
      </Section>
      <div className="xl:col-span-2">
        <Section title="Program & preferences" icon={GraduationCap}>
          <DescriptionList
            columns={3}
            items={[
              { label: 'Program', value: a.program_full ? `${a.program_name} — ${a.program_full}` : v(a.program_name) },
              { label: 'Specialisation', value: v(a.course_name) },
              { label: 'Second preference', value: v(a.second_program_name) },
              { label: 'Academic session', value: v(a.session_name) },
              { label: 'Hostel / transport', value: [Number(a.hostel_required) ? 'Hostel required' : null, Number(a.transport_required) ? 'Transport required' : null].filter(Boolean).join(' · ') || 'Not required' },
              { label: 'Declaration', value: Number(a.declaration_accepted) ? `Accepted${a.declaration_at ? ` on ${formatDateTime(a.declaration_at)}` : ''}` : 'Not accepted yet' },
            ]}
          />
        </Section>
      </div>
    </div>
  );
}
