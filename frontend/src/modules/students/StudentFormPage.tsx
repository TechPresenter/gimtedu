import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import { AlertCircle, ArrowLeft, Check, GraduationCap, Home, MapPin, Phone, Save, ShieldCheck, Sparkles, User, Users, type LucideIcon } from 'lucide-react';
import {
  Alert, Button, Card, Checkbox, EmptyState, Field, Input, PageHeader, RadioGroup, Select, Skeleton, Textarea, Toggle, useConfirm, useToast,
} from '@/components/ui';
import { api, ApiError, toFormData } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAcademicSession } from '@/lib/auth';
import { isoDate } from '@/lib/format';
import { appUrl } from '@/lib/config';
import type { CrudRecordPayload } from '@/components/crud';
import { ADMISSION_TYPES, BLOOD_GROUPS, CATEGORIES, GENDERS, INACTIVE_STATUSES, INDIAN_STATES, RELIGIONS, STUDENT_STATUSES } from './constants';
import { useAcademicTree, useTreeOptions } from './hooks';
import { DuplicateWarning, FormSection, PhotoPicker } from './components/FormBits';
import type { Duplicates, ParentRow } from './types';

type Rel = 'father' | 'mother' | 'guardian';
interface ParentForm { name: string; phone: string; email: string; occupation: string; annual_income: string }
const emptyParent = (): ParentForm => ({ name: '', phone: '', email: '', occupation: '', annual_income: '' });

const TEXT_FIELDS = [
  'first_name', 'middle_name', 'last_name', 'gender', 'dob', 'blood_group', 'category', 'religion', 'nationality', 'aadhaar_no',
  'mobile', 'email', 'whatsapp', 'emergency_contact_name', 'emergency_contact_phone',
  'address', 'city', 'state', 'country', 'pincode', 'permanent_address', 'guardian_relation',
  'department_id', 'program_id', 'course_id', 'batch_id', 'current_semester', 'section_id', 'academic_session_id', 'admission_date', 'admission_type',
  'enrollment_no', 'student_uid', 'admission_no', 'roll_no', 'previous_qualification', 'previous_percentage', 'status', 'status_reason', 'remarks',
] as const;
type FieldName = (typeof TEXT_FIELDS)[number];
type Values = Record<FieldName, string>;

const SECTIONS: { id: string; label: string; icon: LucideIcon; fields: string[] }[] = [
  { id: 'personal', label: 'Personal details', icon: User, fields: ['photo', 'first_name', 'middle_name', 'last_name', 'gender', 'dob', 'blood_group', 'category', 'religion', 'nationality', 'aadhaar_no'] },
  { id: 'contact', label: 'Contact', icon: Phone, fields: ['mobile', 'email', 'whatsapp', 'emergency_contact_name', 'emergency_contact_phone'] },
  { id: 'address', label: 'Address', icon: MapPin, fields: ['address', 'city', 'state', 'country', 'pincode', 'permanent_address'] },
  { id: 'parents', label: 'Parents & guardian', icon: Users, fields: ['parents', 'guardian_relation', 'father_name', 'mother_name', 'guardian_name', 'guardian_phone'] },
  { id: 'academic', label: 'Academic', icon: GraduationCap, fields: ['department_id', 'program_id', 'course_id', 'batch_id', 'current_semester', 'section_id', 'academic_session_id', 'admission_date', 'admission_type', 'enrollment_no', 'student_uid', 'admission_no', 'roll_no', 'previous_qualification', 'previous_percentage'] },
  { id: 'status', label: 'Status & notes', icon: ShieldCheck, fields: ['status', 'status_reason', 'remarks'] },
];
const REQUIRED: FieldName[] = ['first_name', 'gender', 'mobile', 'program_id', 'current_semester', 'status'];
const LABELS: Partial<Record<string, string>> = {
  first_name: 'First name', gender: 'Gender', mobile: 'Mobile', program_id: 'Program', current_semester: 'Semester', status: 'Status',
};

const sectionOf = (field: string) => SECTIONS.find((s) => s.fields.some((f) => field === f || field.startsWith(`${f}.`)))?.id ?? 'personal';
const digits = (v: string) => v.replace(/\D/g, '');

function blankValues(sessionId: number | null): Values {
  const v = Object.fromEntries(TEXT_FIELDS.map((k) => [k, ''])) as Values;
  return { ...v, nationality: 'Indian', country: 'India', status: 'active', admission_type: 'regular', current_semester: '1', academic_session_id: sessionId ? String(sessionId) : '', admission_date: isoDate() };
}

/** Client-side checks (the server validates everything again). */
function validateClient(v: Values, parents: Record<Rel, ParentForm>, isEdit: boolean): Record<string, string> {
  const e: Record<string, string> = {};
  REQUIRED.forEach((k) => {
    if (!String(v[k] ?? '').trim()) e[k] = `${LABELS[k] ?? k} is required.`;
  });
  if (v.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.email)) e.email = 'Enter a valid email address.';
  if (v.mobile && digits(v.mobile).slice(-10).length < 10) e.mobile = 'Enter a valid 10-digit mobile number.';
  if (v.whatsapp && digits(v.whatsapp).slice(-10).length < 10) e.whatsapp = 'Enter a valid 10-digit WhatsApp number.';
  if (v.emergency_contact_phone && digits(v.emergency_contact_phone).length < 10) e.emergency_contact_phone = 'Enter a valid phone number.';
  if (v.aadhaar_no && !/^[2-9]\d{11}$/.test(digits(v.aadhaar_no))) e.aadhaar_no = 'Enter a valid 12-digit Aadhaar number.';
  if (v.pincode && !/^[A-Za-z0-9 -]{3,12}$/.test(v.pincode)) e.pincode = 'Enter a valid pincode.';
  if (v.dob && v.dob >= isoDate()) e.dob = 'Date of birth must be in the past.';
  if (v.previous_percentage && (Number(v.previous_percentage) < 0 || Number(v.previous_percentage) > 100)) e.previous_percentage = 'Enter a percentage between 0 and 100.';
  if (INACTIVE_STATUSES.includes(v.status) && !v.status_reason.trim()) e.status_reason = 'Give a reason for this status.';
  (['father', 'mother', 'guardian'] as Rel[]).forEach((r) => {
    const p = parents[r];
    if (!p.name.trim() && (p.phone || p.email || p.occupation || p.annual_income)) e[`parents.${r}.name`] = 'Enter the name or clear the other details.';
    if (p.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(p.email)) e[`parents.${r}.email`] = 'Enter a valid email address.';
    if (p.phone && digits(p.phone).length < 10) e[`parents.${r}.phone`] = 'Enter a valid phone number.';
  });
  void isEdit;
  return e;
}

export default function StudentFormPage() {
  const { id: idParam } = useParams();
  const id = idParam ? Number(idParam) : null;
  const isEdit = !!id;
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { id: currentSessionId } = useAcademicSession();
  const { data: meta } = useCrudMeta('students');
  const { data: tree, isLoading: treeLoading } = useAcademicTree();
  const record = useApi<CrudRecordPayload>(['crud-record', 'students', id], `crud/students/${id}`, undefined, { enabled: isEdit, staleTime: 0 });
  const parentsQ = useApi<ParentRow[]>(['students', id, 'parents'], `students/${id}/parents`, undefined, { enabled: isEdit, staleTime: 0 });

  const [values, setValues] = useState<Values>(() => blankValues(currentSessionId));
  const [toggles, setToggles] = useState({ is_hosteller: false, uses_transport: false });
  const [parents, setParents] = useState<Record<Rel, ParentForm>>({ father: emptyParent(), mother: emptyParent(), guardian: emptyParent() });
  const [emergencyRel, setEmergencyRel] = useState<Rel | ''>('father');
  const [showGuardian, setShowGuardian] = useState(false);
  const [photoFile, setPhotoFile] = useState<File | null>(null);
  const [photoRemoved, setPhotoRemoved] = useState(false);
  const [currentPhoto, setCurrentPhoto] = useState<string | null>(null);
  const [aadhaarMasked, setAadhaarMasked] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [dups, setDups] = useState<Duplicates>({});
  const [allowDupMobile, setAllowDupMobile] = useState(false);
  const [sameWhatsapp, setSameWhatsapp] = useState(!isEdit);
  const [samePermanent, setSamePermanent] = useState(false);
  const [saving, setSaving] = useState<false | 'save' | 'another'>(false);
  const [dirty, setDirty] = useState(false);
  const [activeSection, setActiveSection] = useState('personal');
  const loadedRef = useRef(false);

  // Load the record for editing
  useEffect(() => {
    if (!isEdit || loadedRef.current || !record.data || !parentsQ.data) return;
    loadedRef.current = true;
    const v = record.data.values;
    const next = blankValues(null);
    TEXT_FIELDS.forEach((k) => {
      const x = v[k];
      next[k] = x === null || x === undefined ? '' : String(x);
    });
    next.aadhaar_no = '';
    setValues(next);
    setToggles({ is_hosteller: !!v.is_hosteller, uses_transport: !!v.uses_transport });
    setCurrentPhoto((v.photo as string) || null);
    setAadhaarMasked((record.data.row.aadhaar_masked as string) || null);
    const ps: Record<Rel, ParentForm> = { father: emptyParent(), mother: emptyParent(), guardian: emptyParent() };
    let em: Rel | '' = '';
    parentsQ.data.forEach((p) => {
      if (p.relation === 'father' || p.relation === 'mother' || p.relation === 'guardian') {
        ps[p.relation] = { name: p.name ?? '', phone: p.phone ?? '', email: p.email ?? '', occupation: p.occupation ?? '', annual_income: p.annual_income ? String(Number(p.annual_income)) : '' };
        if (p.is_emergency_contact) em = p.relation;
      }
    });
    setParents(ps);
    setEmergencyRel(em);
    setShowGuardian(!!ps.guardian.name);
    setSameWhatsapp(!!next.mobile && next.mobile === next.whatsapp);
    setSamePermanent(false);
  }, [isEdit, record.data, parentsQ.data]);

  // Default session once the auth session is known (create)
  useEffect(() => {
    if (!isEdit && currentSessionId && !values.academic_session_id) setValues((v) => ({ ...v, academic_session_id: String(currentSessionId) }));
  }, [currentSessionId, isEdit, values.academic_session_id]);

  // Warn before leaving with unsaved changes
  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  // Scroll-spy for the section nav
  useEffect(() => {
    const els = SECTIONS.map((s) => document.getElementById(s.id)).filter(Boolean) as HTMLElement[];
    if (!els.length || typeof IntersectionObserver === 'undefined') return;
    const io = new IntersectionObserver(
      (entries) => {
        const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (visible[0]) setActiveSection(visible[0].target.id);
      },
      { rootMargin: '-80px 0px -55% 0px' },
    );
    els.forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, [record.data, treeLoading]);

  const opts = useTreeOptions(tree, { department_id: values.department_id, program_id: values.program_id, semester: values.current_semester });
  const sectionOptions = useMemo(() => {
    // Prefer sections of the current session; keep the saved section visible when editing
    const rows = opts.sectionRows.filter((s) => !currentSessionId || s.academic_session_id === currentSessionId || String(s.id) === values.section_id);
    return rows.map((s) => ({ value: s.id, label: `Section ${s.name} · ${s.strength}/${s.capacity} students${s.academic_session_id !== currentSessionId ? ` (${tree?.sessions.find((x) => x.id === s.academic_session_id)?.name ?? 'other session'})` : ''}` }));
  }, [opts.sectionRows, currentSessionId, values.section_id, tree]);

  const nextIds = useApi<{ student_uid?: string; admission_no?: string; roll_no?: string }>(
    ['students', 'next-ids', values.program_id, values.admission_date, values.academic_session_id],
    'students/next-ids',
    { program_id: values.program_id, admission_date: values.admission_date || undefined, academic_session_id: values.academic_session_id || undefined },
    { enabled: !!values.program_id, staleTime: 30_000 },
  );

  const set = (k: FieldName, v: string) => {
    setDirty(true);
    setValues((prev) => {
      const next = { ...prev, [k]: v };
      if (k === 'department_id' && prev.department_id !== v) {
        const p = tree?.programs.find((x) => String(x.id) === prev.program_id);
        if (p && String(p.department_id) !== v) Object.assign(next, { program_id: '', course_id: '', batch_id: '', section_id: '' });
      }
      if (k === 'program_id' && prev.program_id !== v) {
        const p = tree?.programs.find((x) => String(x.id) === v);
        Object.assign(next, { course_id: '', batch_id: '', section_id: '' });
        if (p) {
          next.department_id = String(p.department_id);
          if (Number(next.current_semester) > p.total_semesters) next.current_semester = '1';
          // pick the batch that started in the admission year
          const year = Number((next.admission_date || isoDate()).slice(0, 4));
          const b = tree?.batches.find((x) => x.program_id === p.id && x.start_year === year);
          if (b) next.batch_id = String(b.id);
        }
      }
      if (k === 'current_semester' && prev.current_semester !== v) next.section_id = '';
      if (k === 'mobile' && sameWhatsapp) next.whatsapp = v;
      if (samePermanent && ['address', 'city', 'state', 'pincode'].includes(k)) next.permanent_address = [next.address, next.city, next.state, next.pincode].filter(Boolean).join(', ');
      return next;
    });
    if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
  };
  const setParent = (rel: Rel, k: keyof ParentForm, v: string) => {
    setDirty(true);
    setParents((p) => ({ ...p, [rel]: { ...p[rel], [k]: v } }));
    if (errors[`parents.${rel}.${k}`]) setErrors((e) => ({ ...e, [`parents.${rel}.${k}`]: '' }));
  };

  const checkDuplicate = async (field: keyof Duplicates, value: string) => {
    const v = field === 'aadhaar_no' ? digits(value) : value.trim();
    if (!v || (field === 'aadhaar_no' && v.length !== 12) || (field === 'mobile' && digits(v).length < 10)) {
      setDups((d) => ({ ...d, [field]: undefined }));
      return;
    }
    try {
      const res = await api.get<Duplicates>('students/check-duplicates', { [field]: v, exclude_id: id ?? undefined });
      setDups((d) => ({ ...d, [field]: res[field] }));
      if (field === 'mobile' && !res.mobile) setAllowDupMobile(false);
    } catch {
      /* non-blocking helper */
    }
  };

  const fillEmergencyFrom = (rel: Rel) => {
    const p = parents[rel];
    if (!p.name) return toast.warning(`Enter the ${rel}'s details first.`);
    setEmergencyRel(rel);
    set('emergency_contact_name', p.name);
    set('emergency_contact_phone', p.phone);
  };

  const sectionErrors = useMemo(() => {
    const out: Record<string, number> = {};
    Object.entries(errors).forEach(([k, msg]) => {
      if (msg) out[sectionOf(k)] = (out[sectionOf(k)] ?? 0) + 1;
    });
    return out;
  }, [errors]);
  const sectionDone = (sid: string) => {
    const s = SECTIONS.find((x) => x.id === sid)!;
    const req = REQUIRED.filter((r) => s.fields.includes(r));
    if (sid === 'parents') return !!(parents.father.name || parents.mother.name || parents.guardian.name);
    if (sid === 'address') return !!(values.address && values.city);
    if (sid === 'contact') return !!values.mobile;
    return req.length ? req.every((r) => String(values[r]).trim()) : !!values.status;
  };

  const scrollTo = (sid: string) => {
    document.getElementById(sid)?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    setActiveSection(sid);
  };
  const focusFirstError = (errs: Record<string, string>) => {
    const first = Object.keys(errs).find((k) => errs[k]);
    if (!first) return;
    scrollTo(sectionOf(first));
    setTimeout(() => (document.getElementById(`sf-${first.replace(/\./g, '-')}`) as HTMLElement | null)?.focus({ preventScroll: true }), 350);
  };

  const submit = async (mode: 'save' | 'another', e?: FormEvent) => {
    e?.preventDefault();
    const clientErrors = validateClient(values, parents, isEdit);
    if (dups.mobile?.length && !allowDupMobile) clientErrors.mobile = 'This mobile number belongs to another student. Tick “shared number” below to continue.';
    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors);
      setFormError(`Please fix ${Object.keys(clientErrors).length} highlighted field${Object.keys(clientErrors).length === 1 ? '' : 's'}.`);
      focusFirstError(clientErrors);
      return;
    }
    setSaving(mode);
    setFormError(null);
    const payload: Record<string, unknown> = { ...values, ...toggles };
    payload.aadhaar_no = digits(values.aadhaar_no);
    if (isEdit && !payload.aadhaar_no) delete payload.aadhaar_no;
    payload.parents = (['father', 'mother', 'guardian'] as Rel[]).map((rel) => ({ relation: rel, ...parents[rel], is_emergency_contact: emergencyRel === rel }));
    if (allowDupMobile) payload.allow_duplicate_mobile = 1;
    if (photoFile) payload.photo = photoFile;
    else if (photoRemoved) payload.photo__remove = 1;
    try {
      const res = await api.post<{ id: number; row: Record<string, unknown> | null }>(isEdit ? `crud/students/${id}` : 'crud/students', photoFile ? toFormData(payload) : payload);
      const name = [values.first_name, values.last_name].filter(Boolean).join(' ');
      toast.success(isEdit ? `${name}'s profile has been updated.` : `${name} added successfully${res.data.row?.student_uid ? ` (${res.data.row.student_uid})` : ''}.`);
      setDirty(false);
      await Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'students'] }), qc.invalidateQueries({ queryKey: ['students'] }), qc.invalidateQueries({ queryKey: ['crud-record', 'students'] })]);
      if (mode === 'another' && !isEdit) {
        const keep = { program_id: values.program_id, department_id: values.department_id, batch_id: values.batch_id, current_semester: values.current_semester, section_id: values.section_id, academic_session_id: values.academic_session_id, admission_date: values.admission_date };
        setValues({ ...blankValues(currentSessionId), ...keep });
        setParents({ father: emptyParent(), mother: emptyParent(), guardian: emptyParent() });
        setPhotoFile(null);
        setDups({});
        setErrors({});
        setAllowDupMobile(false);
        window.scrollTo({ top: 0 });
      } else {
        navigate(`/students/${res.data.id}`);
      }
    } catch (err) {
      const ex = err as ApiError;
      const fieldErrors = ex.errors ?? {};
      setErrors(fieldErrors);
      if (fieldErrors.mobile && /already used/i.test(fieldErrors.mobile)) void checkDuplicate('mobile', values.mobile);
      setFormError(ex.message || 'Unable to save student. Please try again.');
      if (Object.keys(fieldErrors).length) focusFirstError(fieldErrors);
      else toast.error(ex.message || 'Unable to save student. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const cancel = async () => {
    if (dirty && !(await confirm({ title: 'Discard changes?', message: 'You have unsaved changes on this form. Leave without saving?', confirmText: 'Discard', danger: true }))) return;
    setDirty(false);
    navigate(isEdit ? `/students/${id}` : '/students');
  };

  const err = (k: string) => errors[k] || undefined;
  const fid = (k: string) => `sf-${k.replace(/\./g, '-')}`;
  const studentName = [values.first_name, values.middle_name, values.last_name].filter(Boolean).join(' ');
  const title = isEdit ? `Edit ${record.data ? String(record.data.row.full_name ?? 'student') : 'student'}` : 'Add Student';

  if (isEdit && (record.error || parentsQ.error)) {
    return (
      <>
        <PageHeader title="Edit student" breadcrumbs={[{ label: 'Students', to: '/students' }, { label: 'Edit' }]} />
        <Card>
          <EmptyState icon={AlertCircle} title="Student not found" description={(record.error as ApiError)?.message ?? 'This student may have been deleted.'} action={<Button to="/students" icon={ArrowLeft}>Back to students</Button>} />
        </Card>
      </>
    );
  }
  const loading = (isEdit && (!record.data || !parentsQ.data)) || treeLoading;

  return (
    <>
      <PageHeader
        title={title}
        description={isEdit ? 'Update personal, contact, parent and academic details. Changes are logged.' : 'Enrol a new student. Student ID, admission and roll numbers are generated automatically when left blank.'}
        breadcrumbs={[{ label: 'Students', to: '/students' }, ...(isEdit ? [{ label: String(record.data?.row.full_name ?? 'Student'), to: `/students/${id}` }, { label: 'Edit' }] : [{ label: 'Add Student' }])]}
        actions={
          <>
            <Button variant="secondary" icon={ArrowLeft} onClick={cancel}>
              Cancel
            </Button>
            <Button icon={Save} loading={saving === 'save'} disabled={loading || !!saving} onClick={() => submit('save')}>
              {isEdit ? 'Save changes' : 'Save student'}
            </Button>
          </>
        }
      />

      {/* Mobile section chips */}
      <nav aria-label="Form sections" className="sticky top-16 z-10 -mx-4 mb-4 flex gap-1.5 overflow-x-auto border-b border-slate-200/70 bg-slate-50/95 px-4 py-2 backdrop-blur scrollbar-none lg:hidden dark:border-slate-800 dark:bg-slate-950/90">
        {SECTIONS.map((s) => (
          <button key={s.id} type="button" onClick={() => scrollTo(s.id)} className={clsx('inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition', activeSection === s.id ? 'bg-brand-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700')}>
            {s.label}
            {sectionErrors[s.id] ? <span className="h-1.5 w-1.5 rounded-full bg-red-500" aria-label="has errors" /> : null}
          </button>
        ))}
      </nav>

      <div className="grid gap-6 lg:grid-cols-[230px_minmax(0,1fr)]">
        {/* Desktop sticky section nav */}
        <aside className="hidden lg:block">
          <div className="sticky top-24 space-y-4">
            <Card className="p-2">
              <nav aria-label="Form sections">
                <ol className="space-y-0.5">
                  {SECTIONS.map((s, i) => {
                    const active = activeSection === s.id;
                    const errCount = sectionErrors[s.id];
                    const done = !errCount && sectionDone(s.id);
                    return (
                      <li key={s.id}>
                        <button type="button" onClick={() => scrollTo(s.id)} aria-current={active ? 'step' : undefined}
                          className={clsx('group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition', active ? 'bg-brand-50 font-semibold text-brand-900 dark:bg-brand-500/15 dark:text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800/60')}>
                          <span className={clsx('inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold transition',
                            errCount ? 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' : done ? 'bg-accent-600 text-white' : active ? 'bg-brand-800 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
                            {errCount ? '!' : done ? <Check className="h-3.5 w-3.5" /> : i + 1}
                          </span>
                          <span className="truncate">{s.label}</span>
                        </button>
                      </li>
                    );
                  })}
                </ol>
              </nav>
            </Card>
            <Card className="p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Preview</p>
              <div className="mt-3 flex items-center gap-3">
                <div className="h-11 w-11 shrink-0 overflow-hidden rounded-xl bg-brand-50 dark:bg-brand-500/15">
                  {photoFile || (currentPhoto && !photoRemoved) ? <PreviewImg file={photoFile} path={photoRemoved ? null : currentPhoto} /> : <span className="flex h-full w-full items-center justify-center text-sm font-bold text-brand-700 dark:text-brand-200">{(values.first_name[0] ?? '?').toUpperCase()}{(values.last_name[0] ?? '').toUpperCase()}</span>}
                </div>
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{studentName || 'New student'}</p>
                  <p className="truncate text-xs text-slate-500">{values.student_uid || nextIds.data?.student_uid || 'Student ID on save'}</p>
                </div>
              </div>
              <p className="mt-3 truncate text-xs text-slate-500">{opts.program ? `${opts.program.short_name} · Semester ${values.current_semester || '—'}` : 'Choose a program'}</p>
            </Card>
          </div>
        </aside>

        <form onSubmit={(e) => submit('save', e)} noValidate className="min-w-0 space-y-5" aria-busy={loading}>
          {formError && (
            <Alert variant="error" title="The student was not saved">
              {formError}
            </Alert>
          )}
          {loading ? (
            <FormSkeleton />
          ) : (
            <>
              <FormSection id="personal" title="Personal details" description="Name as per 12th marksheet / ID proof." icon={User}>
                <div className="mb-5">
                  <PhotoPicker name={studentName} current={currentPhoto} file={photoFile} removed={photoRemoved}
                    onFile={(f) => { setPhotoFile(f); setDirty(true); if (f) setPhotoRemoved(false); }}
                    onRemove={() => { setPhotoRemoved(true); setDirty(true); }}
                    accept={meta?.fields.find((f) => f.name === 'photo')?.accept} maxSize={meta?.fields.find((f) => f.name === 'photo')?.max_size}
                    error={err('photo')} onError={(m) => toast.error(m)} />
                </div>
                <Grid>
                  <F span={4} label="First name" required error={err('first_name')} id={fid('first_name')}>
                    <Input id={fid('first_name')} value={values.first_name} onChange={(e) => set('first_name', e.target.value)} maxLength={80} autoComplete="off" invalid={!!err('first_name')} />
                  </F>
                  <F span={4} label="Middle name" error={err('middle_name')} id={fid('middle_name')}>
                    <Input id={fid('middle_name')} value={values.middle_name} onChange={(e) => set('middle_name', e.target.value)} maxLength={80} autoComplete="off" />
                  </F>
                  <F span={4} label="Last name" error={err('last_name')} id={fid('last_name')}>
                    <Input id={fid('last_name')} value={values.last_name} onChange={(e) => set('last_name', e.target.value)} maxLength={80} autoComplete="off" />
                  </F>
                  <F span={4} label="Gender" required error={err('gender')} id={fid('gender')}>
                    <div id={fid('gender')} tabIndex={-1} className="flex h-[42px] items-center">
                      <RadioGroup name="gender" value={values.gender} onChange={(v) => set('gender', v)} options={GENDERS} />
                    </div>
                  </F>
                  <F span={4} label="Date of birth" error={err('dob')} id={fid('dob')}>
                    <Input id={fid('dob')} type="date" value={values.dob} max={isoDate()} onChange={(e) => set('dob', e.target.value)} invalid={!!err('dob')} />
                  </F>
                  <F span={4} label="Blood group" error={err('blood_group')} id={fid('blood_group')}>
                    <Select id={fid('blood_group')} value={values.blood_group} onChange={(e) => set('blood_group', e.target.value)} options={BLOOD_GROUPS} placeholder="Select" />
                  </F>
                  <F span={4} label="Category" error={err('category')} id={fid('category')}>
                    <Select id={fid('category')} value={values.category} onChange={(e) => set('category', e.target.value)} options={CATEGORIES} placeholder="Select" />
                  </F>
                  <F span={4} label="Religion" error={err('religion')} id={fid('religion')}>
                    <Select id={fid('religion')} value={values.religion} onChange={(e) => set('religion', e.target.value)} options={RELIGIONS} placeholder="Select" />
                  </F>
                  <F span={4} label="Nationality" error={err('nationality')} id={fid('nationality')}>
                    <Input id={fid('nationality')} value={values.nationality} onChange={(e) => set('nationality', e.target.value)} maxLength={60} />
                  </F>
                  <F span={6} label="Aadhaar number" error={err('aadhaar_no')} id={fid('aadhaar_no')}
                    hint={aadhaarMasked ? `On file: ${aadhaarMasked}. Leave blank to keep it.` : 'Stored securely; shown masked everywhere.'}>
                    <Input id={fid('aadhaar_no')} inputMode="numeric" autoComplete="off" value={formatAadhaar(values.aadhaar_no)} placeholder={aadhaarMasked ?? 'XXXX XXXX XXXX'} maxLength={14}
                      onChange={(e) => set('aadhaar_no', digits(e.target.value).slice(0, 12))} onBlur={() => checkDuplicate('aadhaar_no', values.aadhaar_no)} invalid={!!err('aadhaar_no')} />
                    <DuplicateWarning matches={dups.aadhaar_no} what="Aadhaar number" />
                  </F>
                </Grid>
              </FormSection>

              <FormSection id="contact" title="Contact" description="Used for SMS / WhatsApp alerts and the student portal." icon={Phone}>
                <Grid>
                  <F span={4} label="Mobile" required error={err('mobile')} id={fid('mobile')}>
                    <Input id={fid('mobile')} type="tel" inputMode="tel" value={values.mobile} placeholder="+91 98xxxxxxxx" maxLength={20} onChange={(e) => set('mobile', e.target.value)} onBlur={() => checkDuplicate('mobile', values.mobile)} invalid={!!err('mobile')} />
                    <DuplicateWarning matches={dups.mobile} what="mobile number">
                      <Checkbox className="mt-1.5" checked={allowDupMobile} onChange={(e) => { setAllowDupMobile(e.target.checked); if (e.target.checked) setErrors((x) => ({ ...x, mobile: '' })); }} label="This is a shared number (e.g. siblings) — allow it" />
                    </DuplicateWarning>
                  </F>
                  <F span={4} label="Email" error={err('email')} id={fid('email')}>
                    <Input id={fid('email')} type="email" value={values.email} placeholder="name@example.com" maxLength={190} onChange={(e) => set('email', e.target.value)} onBlur={() => checkDuplicate('email', values.email)} invalid={!!err('email')} />
                    <DuplicateWarning matches={dups.email} what="email" />
                  </F>
                  <F span={4} label="WhatsApp" error={err('whatsapp')} id={fid('whatsapp')}>
                    <Input id={fid('whatsapp')} type="tel" value={values.whatsapp} disabled={sameWhatsapp} maxLength={20} onChange={(e) => set('whatsapp', e.target.value)} invalid={!!err('whatsapp')} />
                    <Checkbox className="mt-1.5" checked={sameWhatsapp} onChange={(e) => { setSameWhatsapp(e.target.checked); if (e.target.checked) set('whatsapp', values.mobile); }} label="Same as mobile" />
                  </F>
                  <F span={6} label="Emergency contact name" error={err('emergency_contact_name')} id={fid('emergency_contact_name')}>
                    <Input id={fid('emergency_contact_name')} value={values.emergency_contact_name} maxLength={150} onChange={(e) => set('emergency_contact_name', e.target.value)} />
                  </F>
                  <F span={6} label="Emergency contact phone" error={err('emergency_contact_phone')} id={fid('emergency_contact_phone')}>
                    <Input id={fid('emergency_contact_phone')} type="tel" value={values.emergency_contact_phone} maxLength={20} onChange={(e) => set('emergency_contact_phone', e.target.value)} invalid={!!err('emergency_contact_phone')} />
                  </F>
                  <div className="sm:col-span-12 -mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <span>Quick fill:</span>
                    {(['father', 'mother', 'guardian'] as Rel[]).map((r) => (
                      <button key={r} type="button" onClick={() => fillEmergencyFrom(r)} disabled={!parents[r].name}
                        className="rounded-full border border-slate-200 px-2.5 py-1 font-medium capitalize text-slate-600 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-800 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-brand-500/10">
                        Use {r === 'guardian' ? 'local guardian' : r}
                      </button>
                    ))}
                  </div>
                </Grid>
              </FormSection>

              <FormSection id="address" title="Address" description="Correspondence address; add the permanent address if different." icon={Home}>
                <Grid>
                  <F span={12} label="Address" error={err('address')} id={fid('address')}>
                    <Input id={fid('address')} value={values.address} maxLength={255} placeholder="House no., street, locality" onChange={(e) => set('address', e.target.value)} />
                  </F>
                  <F span={3} label="City" error={err('city')} id={fid('city')}>
                    <Input id={fid('city')} value={values.city} maxLength={80} onChange={(e) => set('city', e.target.value)} />
                  </F>
                  <F span={3} label="State" error={err('state')} id={fid('state')}>
                    <Input id={fid('state')} value={values.state} maxLength={80} list="students-states" onChange={(e) => set('state', e.target.value)} />
                    <datalist id="students-states">{INDIAN_STATES.map((s) => <option key={s} value={s} />)}</datalist>
                  </F>
                  <F span={3} label="Country" error={err('country')} id={fid('country')}>
                    <Input id={fid('country')} value={values.country} maxLength={80} onChange={(e) => set('country', e.target.value)} />
                  </F>
                  <F span={3} label="Pincode" error={err('pincode')} id={fid('pincode')}>
                    <Input id={fid('pincode')} inputMode="numeric" value={values.pincode} maxLength={12} onChange={(e) => set('pincode', e.target.value)} invalid={!!err('pincode')} />
                  </F>
                  <F span={12} label="Permanent address" error={err('permanent_address')} id={fid('permanent_address')}>
                    <Input id={fid('permanent_address')} value={values.permanent_address} maxLength={255} disabled={samePermanent} onChange={(e) => set('permanent_address', e.target.value)} />
                    <Checkbox className="mt-1.5" checked={samePermanent}
                      onChange={(e) => { setSamePermanent(e.target.checked); if (e.target.checked) set('permanent_address', [values.address, values.city, values.state, values.pincode].filter(Boolean).join(', ')); }}
                      label="Same as correspondence address" />
                  </F>
                </Grid>
              </FormSection>

              <FormSection id="parents" title="Parents & guardian" description="Father, mother and an optional local guardian. Mark who to call in an emergency." icon={Users}>
                <div className="grid gap-4 xl:grid-cols-2">
                  {(['father', 'mother'] as Rel[]).map((rel) => (
                    <ParentCard key={rel} rel={rel} title={rel === 'father' ? 'Father' : 'Mother'} p={parents[rel]} errors={errors} onChange={setParent} emergency={emergencyRel === rel} onEmergency={() => { setEmergencyRel(rel); setDirty(true); }} />
                  ))}
                </div>
                <div className="mt-4">
                  {showGuardian ? (
                    <ParentCard rel="guardian" title="Local guardian" p={parents.guardian} errors={errors} onChange={setParent} emergency={emergencyRel === 'guardian'} onEmergency={() => { setEmergencyRel('guardian'); setDirty(true); }}
                      extra={
                        <F span={6} label="Relation to student" error={err('guardian_relation')} id={fid('guardian_relation')}>
                          <Input id={fid('guardian_relation')} value={values.guardian_relation} placeholder="e.g. Uncle" maxLength={40} onChange={(e) => set('guardian_relation', e.target.value)} />
                        </F>
                      }
                      onRemove={() => { setParents((p) => ({ ...p, guardian: emptyParent() })); setShowGuardian(false); if (emergencyRel === 'guardian') setEmergencyRel('father'); setDirty(true); }} />
                  ) : (
                    <button type="button" onClick={() => setShowGuardian(true)} className="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 transition hover:border-brand-300 hover:bg-brand-50/50 hover:text-brand-800 dark:border-slate-700 dark:hover:bg-brand-500/10 dark:hover:text-white">
                      <Users className="h-4 w-4" /> Add a local guardian (hostellers / outstation students)
                    </button>
                  )}
                </div>
              </FormSection>

              <FormSection id="academic" title="Academic" description="Department → program → specialization → batch → semester → section." icon={GraduationCap}
                aside={nextIds.data?.student_uid && !isEdit ? <span className="inline-flex items-center gap-1.5 rounded-full bg-accent-50 px-2.5 py-1 text-xs font-medium text-accent-700 dark:bg-accent-500/10 dark:text-accent-300"><Sparkles className="h-3.5 w-3.5" />IDs auto-generate on save</span> : undefined}>
                <Grid>
                  <F span={6} label="Department" error={err('department_id')} id={fid('department_id')}>
                    <Select id={fid('department_id')} value={values.department_id} onChange={(e) => set('department_id', e.target.value)} options={opts.departments} placeholder="All departments" />
                  </F>
                  <F span={6} label="Program" required error={err('program_id')} id={fid('program_id')}>
                    <Select id={fid('program_id')} value={values.program_id} onChange={(e) => set('program_id', e.target.value)} options={opts.programs} placeholder="Select program" invalid={!!err('program_id')} />
                  </F>
                  <F span={6} label="Specialization / course" error={err('course_id')} id={fid('course_id')} hint={values.program_id && !opts.courses.length ? 'No specializations for this program.' : undefined}>
                    <Select id={fid('course_id')} value={values.course_id} onChange={(e) => set('course_id', e.target.value)} options={opts.courses} placeholder={values.program_id ? '— None —' : 'Select program first'} disabled={!values.program_id || !opts.courses.length} />
                  </F>
                  <F span={6} label="Batch" error={err('batch_id')} id={fid('batch_id')}>
                    <Select id={fid('batch_id')} value={values.batch_id} onChange={(e) => set('batch_id', e.target.value)} options={opts.batches} placeholder={values.program_id ? '— None —' : 'Select program first'} disabled={!values.program_id} invalid={!!err('batch_id')} />
                  </F>
                  <F span={4} label="Semester" required error={err('current_semester')} id={fid('current_semester')}>
                    <Select id={fid('current_semester')} value={values.current_semester} onChange={(e) => set('current_semester', e.target.value)} options={opts.semesters} placeholder="Select" disabled={!values.program_id} invalid={!!err('current_semester')} />
                  </F>
                  <F span={4} label="Section" error={err('section_id')} id={fid('section_id')} hint={values.program_id && values.current_semester && !sectionOptions.length ? 'No sections set up for this semester yet.' : undefined}>
                    <Select id={fid('section_id')} value={values.section_id} onChange={(e) => set('section_id', e.target.value)} options={sectionOptions} placeholder={values.program_id ? '— Not assigned —' : 'Select program first'} disabled={!values.program_id} invalid={!!err('section_id')} />
                  </F>
                  <F span={4} label="Admission session" error={err('academic_session_id')} id={fid('academic_session_id')}>
                    <Select id={fid('academic_session_id')} value={values.academic_session_id} onChange={(e) => set('academic_session_id', e.target.value)} options={opts.sessions} placeholder="Select" />
                  </F>
                  <F span={4} label="Admission date" error={err('admission_date')} id={fid('admission_date')}>
                    <Input id={fid('admission_date')} type="date" value={values.admission_date} onChange={(e) => set('admission_date', e.target.value)} invalid={!!err('admission_date')} />
                  </F>
                  <F span={4} label="Admission type" error={err('admission_type')} id={fid('admission_type')}>
                    <Select id={fid('admission_type')} value={values.admission_type} onChange={(e) => set('admission_type', e.target.value)} options={ADMISSION_TYPES} />
                  </F>
                  <F span={4} label="University enrollment no." error={err('enrollment_no')} id={fid('enrollment_no')}>
                    <Input id={fid('enrollment_no')} value={values.enrollment_no} maxLength={40} onChange={(e) => set('enrollment_no', e.target.value)} />
                  </F>
                  <F span={4} label="Student ID" error={err('student_uid')} id={fid('student_uid')} hint={!values.student_uid && nextIds.data?.student_uid ? `Auto: ${nextIds.data.student_uid}` : undefined}>
                    <Input id={fid('student_uid')} value={values.student_uid} maxLength={30} placeholder={isEdit ? '' : nextIds.data?.student_uid ?? 'Auto-generated'} onChange={(e) => set('student_uid', e.target.value.toUpperCase())} onBlur={() => checkDuplicate('student_uid', values.student_uid)} invalid={!!err('student_uid')} className="font-mono" />
                    <DuplicateWarning matches={dups.student_uid} what="student ID" />
                  </F>
                  <F span={4} label="Admission number" error={err('admission_no')} id={fid('admission_no')} hint={!values.admission_no && nextIds.data?.admission_no ? `Auto: ${nextIds.data.admission_no}` : undefined}>
                    <Input id={fid('admission_no')} value={values.admission_no} maxLength={30} placeholder={isEdit ? '' : nextIds.data?.admission_no ?? 'Auto-generated'} onChange={(e) => set('admission_no', e.target.value.toUpperCase())} onBlur={() => checkDuplicate('admission_no', values.admission_no)} invalid={!!err('admission_no')} className="font-mono" />
                    <DuplicateWarning matches={dups.admission_no} what="admission number" />
                  </F>
                  <F span={4} label="Roll number" error={err('roll_no')} id={fid('roll_no')} hint={!values.roll_no && nextIds.data?.roll_no ? `Auto: ${nextIds.data.roll_no}` : undefined}>
                    <Input id={fid('roll_no')} value={values.roll_no} maxLength={30} placeholder={isEdit ? '' : nextIds.data?.roll_no ?? 'Auto-generated'} onChange={(e) => set('roll_no', e.target.value.toUpperCase())} onBlur={() => checkDuplicate('roll_no', values.roll_no)} invalid={!!err('roll_no')} className="font-mono" />
                    <DuplicateWarning matches={dups.roll_no} what="roll number" />
                  </F>
                  <F span={8} label="Previous qualification" error={err('previous_qualification')} id={fid('previous_qualification')}>
                    <Input id={fid('previous_qualification')} value={values.previous_qualification} placeholder="e.g. 12th (CBSE) / B.Com" maxLength={150} onChange={(e) => set('previous_qualification', e.target.value)} />
                  </F>
                  <F span={4} label="Previous percentage" error={err('previous_percentage')} id={fid('previous_percentage')}>
                    <Input id={fid('previous_percentage')} type="number" inputMode="decimal" min={0} max={100} step="0.01" suffix="%" value={values.previous_percentage} onChange={(e) => set('previous_percentage', e.target.value)} invalid={!!err('previous_percentage')} />
                  </F>
                  <div className="sm:col-span-12 grid gap-3 rounded-xl bg-slate-50 p-3.5 sm:grid-cols-2 dark:bg-slate-800/40">
                    <Toggle checked={toggles.is_hosteller} onChange={(v) => { setToggles((t) => ({ ...t, is_hosteller: v })); setDirty(true); }} label="Hosteller" description="Needs hostel accommodation" />
                    <Toggle checked={toggles.uses_transport} onChange={(v) => { setToggles((t) => ({ ...t, uses_transport: v })); setDirty(true); }} label="College transport" description="Uses the college bus service" />
                  </div>
                </Grid>
              </FormSection>

              <FormSection id="status" title="Status & notes" description="Inactive, suspended and dropped students are hidden from class lists." icon={ShieldCheck}>
                <Grid>
                  <F span={4} label="Status" required error={err('status')} id={fid('status')}>
                    <Select id={fid('status')} value={values.status} onChange={(e) => set('status', e.target.value)} options={STUDENT_STATUSES} invalid={!!err('status')} />
                  </F>
                  <F span={8} label="Status reason" required={INACTIVE_STATUSES.includes(values.status)} error={err('status_reason')} id={fid('status_reason')}>
                    <Input id={fid('status_reason')} value={values.status_reason} maxLength={255} placeholder={values.status === 'active' ? 'Not needed for active students' : 'Why is the status changing?'} onChange={(e) => set('status_reason', e.target.value)} invalid={!!err('status_reason')} />
                  </F>
                  <F span={12} label="Remarks" error={err('remarks')} id={fid('remarks')}>
                    <Textarea id={fid('remarks')} rows={3} maxLength={2000} value={values.remarks} onChange={(e) => set('remarks', e.target.value)} placeholder="Internal notes (scholarship details, special needs…)" />
                  </F>
                </Grid>
              </FormSection>

              {/* Sticky action bar */}
              <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border sm:shadow-card dark:border-slate-800 dark:bg-slate-900/95">
                <p className="hidden text-xs text-slate-500 sm:block">{dirty ? 'You have unsaved changes.' : 'Fields marked * are required.'}</p>
                <div className="flex w-full gap-2 sm:w-auto">
                  <Button variant="secondary" onClick={cancel} className="flex-1 sm:flex-none">Cancel</Button>
                  {!isEdit && (
                    <Button variant="secondary" loading={saving === 'another'} disabled={!!saving} onClick={() => submit('another')} className="hidden sm:inline-flex">
                      Save & add another
                    </Button>
                  )}
                  <Button type="submit" icon={Save} loading={saving === 'save'} disabled={!!saving} className="flex-1 sm:flex-none">
                    {isEdit ? 'Save changes' : 'Save student'}
                  </Button>
                </div>
              </div>
            </>
          )}
        </form>
      </div>
    </>
  );
}

/* ------------------------------------------------------------------ helpers */

function formatAadhaar(v: string) {
  return v.replace(/\D/g, '').replace(/(\d{4})(?=\d)/g, '$1 ').trim();
}

function Grid({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-12">{children}</div>;
}

const spanClass: Record<number, string> = { 3: 'sm:col-span-6 lg:col-span-3', 4: 'sm:col-span-6 lg:col-span-4', 6: 'sm:col-span-6', 8: 'sm:col-span-12 lg:col-span-8', 12: 'sm:col-span-12' };
function F({ span, label, required, error, hint, id, children }: { span: number; label: string; required?: boolean; error?: string; hint?: string; id: string; children: ReactNode }) {
  return (
    <Field className={clsx('col-span-1', spanClass[span])} label={label} required={required} error={error} hint={hint} htmlFor={id}>
      {children}
    </Field>
  );
}

function PreviewImg({ file, path }: { file: File | null; path: string | null }) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (!file) return setUrl(null);
    const u = URL.createObjectURL(file);
    setUrl(u);
    return () => URL.revokeObjectURL(u);
  }, [file]);
  const src = url ?? (path ? appUrl(path) : null);
  return src ? <img src={src} alt="" className="h-full w-full object-cover" /> : null;
}

function ParentCard({ rel, title, p, errors, onChange, emergency, onEmergency, extra, onRemove }: {
  rel: Rel; title: string; p: ParentForm; errors: Record<string, string>; onChange: (rel: Rel, k: keyof ParentForm, v: string) => void; emergency: boolean; onEmergency: () => void; extra?: ReactNode; onRemove?: () => void;
}) {
  const e = (k: string) => errors[`parents.${rel}.${k}`] || undefined;
  const id = (k: string) => `sf-parents-${rel}-${k}`;
  return (
    <div className={clsx('rounded-2xl border p-4 transition', emergency ? 'border-accent-300 bg-accent-50/40 dark:border-accent-500/40 dark:bg-accent-500/5' : 'border-slate-200 dark:border-slate-700')}>
      <div className="mb-3 flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-slate-900 dark:text-white">{title}</h3>
        <div className="flex items-center gap-2">
          <label className="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300">
            <input type="radio" name="emergency-contact" checked={emergency} onChange={onEmergency} className="h-3.5 w-3.5 border-slate-300 text-accent-600 focus:ring-accent-500/30" />
            Emergency contact
          </label>
          {onRemove && (
            <button type="button" onClick={onRemove} className="rounded-md px-1.5 py-0.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
              Remove
            </button>
          )}
        </div>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-12">
        <Field className="sm:col-span-12" label="Full name" error={e('name')} htmlFor={id('name')}>
          <Input id={id('name')} value={p.name} maxLength={150} onChange={(ev) => onChange(rel, 'name', ev.target.value)} invalid={!!e('name')} />
        </Field>
        <Field className="sm:col-span-6" label="Phone" error={e('phone')} htmlFor={id('phone')}>
          <Input id={id('phone')} type="tel" value={p.phone} maxLength={20} onChange={(ev) => onChange(rel, 'phone', ev.target.value)} invalid={!!e('phone')} />
        </Field>
        <Field className="sm:col-span-6" label="Email" error={e('email')} htmlFor={id('email')}>
          <Input id={id('email')} type="email" value={p.email} maxLength={190} onChange={(ev) => onChange(rel, 'email', ev.target.value)} invalid={!!e('email')} />
        </Field>
        <Field className="sm:col-span-6" label="Occupation" error={e('occupation')} htmlFor={id('occupation')}>
          <Input id={id('occupation')} value={p.occupation} maxLength={100} onChange={(ev) => onChange(rel, 'occupation', ev.target.value)} />
        </Field>
        {rel !== 'guardian' ? (
          <Field className="sm:col-span-6" label="Annual income" error={e('annual_income')} htmlFor={id('annual_income')}>
            <Input id={id('annual_income')} type="number" min={0} prefix="₹" value={p.annual_income} onChange={(ev) => onChange(rel, 'annual_income', ev.target.value)} invalid={!!e('annual_income')} />
          </Field>
        ) : (
          extra
        )}
      </div>
    </div>
  );
}

function FormSkeleton() {
  return (
    <div className="space-y-5">
      {[0, 1, 2].map((i) => (
        <div key={i} className="card p-5">
          <div className="mb-5 flex items-center gap-3">
            <Skeleton className="h-9 w-9 rounded-xl" />
            <div className="space-y-2">
              <Skeleton className="h-4 w-40" />
              <Skeleton className="h-3 w-64" />
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            {Array.from({ length: 6 }).map((_, j) => (
              <div key={j} className="space-y-2">
                <Skeleton className="h-3 w-24" />
                <Skeleton className="h-10 w-full rounded-xl" />
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
