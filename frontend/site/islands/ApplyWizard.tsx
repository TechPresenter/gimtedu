import clsx from 'clsx';
import {
  ArrowLeft,
  ArrowRight,
  BookOpen,
  Check,
  CircleAlert,
  ClipboardCheck,
  Copy,
  Download,
  FileText,
  GraduationCap,
  Headset,
  LoaderCircle,
  Lock,
  Mail,
  MapPin,
  MessageCircle,
  Pencil,
  Phone,
  RotateCcw,
  Save,
  Sparkles,
  Trash2,
  Upload,
  User,
  Users,
  X,
  type LucideIcon,
} from 'lucide-react';
import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type DragEvent, type FormEvent, type ReactNode } from 'react';
import { csrfToken } from '../core/dom';
import { useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';

/* ------------------------------------------------------------------ props */

export interface WizardOption {
  value: string;
  label: string;
}

export interface WizardProgram {
  id: number;
  name: string;
  short: string;
  slug: string;
  /** UG | PG | Diploma | Certificate | PhD */
  level: string;
  levelLabel: string;
  category: string;
  duration: string;
  /** formatted, e.g. "₹1,20,000 / Year" */
  fee: string;
  eligibility: string;
  image: string;
  seats: number | null;
  popular: boolean;
  /** admission fee payable after approval, formatted */
  admissionFee: string;
  courses: { id: number; name: string }[];
}

export interface WizardDocument {
  /** posted as doc_<key> (photo, 10th_marksheet, 12th_marksheet, graduation, id_proof, caste_certificate) */
  key: string;
  label: string;
  hint: string;
  /** always = required, pg = required for PG programs, category = shown for reserved categories (optional) */
  when: 'always' | 'pg' | 'category' | 'optional';
  /** images only (photo) — otherwise PDF/JPG/PNG/WebP */
  image: boolean;
  /** max bytes */
  max: number;
}

export interface ApplyWizardProps extends IslandBaseProps {
  /** POST multipart endpoint (api/routes/public.php → /api/public/apply) */
  endpoint: string;
  /** academic session label, e.g. "2026-27" */
  session: string;
  programs: WizardProgram[];
  /** program id preselected via ?program=<slug|id> */
  initialProgram?: number | null;
  options: {
    genders: WizardOption[];
    categories: WizardOption[];
    quotas: WizardOption[];
    bloodGroups: string[];
    qualifications: WizardOption[];
    relations: string[];
    states: string[];
  };
  documents: WizardDocument[];
  limits: { total: number; minYear: number; maxYear: number; maxPassingYear: number };
  help: { phone: string; email: string; whatsapp?: string | null; hours?: string | null };
  /** privacy / terms are null until those CMS pages are published (the declaration then shows plain text) */
  links: { admissions: string; programs: string; privacy: string | null; terms: string | null; classic: string; contact: string };
  /** localStorage key for the draft (default gimt.apply.draft.v1) */
  storageKey?: string;
}

/* ------------------------------------------------------------------ rules (mirror app/services/public_forms.php) */

type Values = Record<string, string>;
type Errors = Record<string, string>;
type Files = Record<string, File | undefined>;

interface Rule {
  label: string;
  required?: boolean;
  min?: number;
  max?: number;
  email?: boolean;
  phone?: boolean;
  person?: boolean;
  num?: { min: number; max: number; integer?: boolean };
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const PHONE = /^\+?[0-9\s\-()]{7,20}$/;
const PERSON = /^\p{L}[\p{L}\p{M} .'-]*$/u;
const digits = (s: string) => s.replace(/\D+/g, '');
/** Keeps the required marker on the same line as the last word of a label. */
const NBSP_STAR = String.fromCharCode(160) + '*';

function buildRules(l: ApplyWizardProps['limits']): Record<string, Rule> {
  return {
    program_id: { label: 'Program', required: true },
    course_id: { label: 'Specialisation' },
    second_program_id: { label: 'Second preference' },
    category: { label: 'Category', required: true },
    quota: { label: 'Admission quota' },
    first_name: { label: 'First name', required: true, max: 80, person: true },
    middle_name: { label: 'Middle name', max: 80, person: true },
    last_name: { label: 'Last name', max: 80, person: true },
    gender: { label: 'Gender', required: true },
    dob: { label: 'Date of birth', required: true },
    blood_group: { label: 'Blood group' },
    nationality: { label: 'Nationality', max: 60 },
    aadhaar_no: { label: 'Aadhaar number', max: 14 },
    phone: { label: 'Mobile number', required: true, phone: true },
    whatsapp: { label: 'WhatsApp number', phone: true },
    email: { label: 'Email address', required: true, email: true, max: 190 },
    address: { label: 'Address', required: true, min: 5, max: 255 },
    city: { label: 'City', required: true, max: 80 },
    state: { label: 'State', required: true, max: 80 },
    pincode: { label: 'PIN code', required: true, max: 12 },
    country: { label: 'Country', max: 80 },
    father_name: { label: "Father's name", required: true, max: 150, person: true },
    father_phone: { label: "Father's mobile", phone: true },
    father_occupation: { label: "Father's occupation", max: 100 },
    mother_name: { label: "Mother's name", required: true, max: 150, person: true },
    mother_phone: { label: "Mother's mobile", phone: true },
    mother_occupation: { label: "Mother's occupation", max: 100 },
    guardian_name: { label: 'Guardian name', max: 150, person: true },
    guardian_relation: { label: 'Relation', max: 40 },
    guardian_phone: { label: 'Guardian mobile', phone: true },
    family_income: { label: 'Annual family income', num: { min: 0, max: 100000000 } },
    tenth_board: { label: 'Class 10 board', required: true, max: 100 },
    tenth_year: { label: 'Class 10 passing year', required: true, num: { min: l.minYear, max: l.maxYear, integer: true } },
    tenth_percentage: { label: 'Class 10 marks %', required: true, num: { min: 0, max: 100 } },
    previous_qualification: { label: 'Qualifying exam', required: true },
    previous_board: { label: 'Board / University', required: true, max: 100 },
    previous_institution: { label: 'School / College', max: 190 },
    passing_year: { label: 'Passing year', required: true, num: { min: l.minYear, max: l.maxPassingYear, integer: true } },
    previous_percentage: { label: 'Marks % / CGPA %', required: true, num: { min: 0, max: 100 } },
    entrance_exam: { label: 'Entrance exam', max: 60 },
  };
}

/** Same messages as validate() + pf_check_phone()/pf_check_name() on the server. */
function checkRule(r: Rule, raw: string | undefined): string | null {
  const v = (raw ?? '').trim();
  if (!v) return r.required ? `${r.label} is required.` : null;
  if (r.num) {
    const n = Number(v.replace(/,/g, ''));
    if (!Number.isFinite(n)) return `${r.label} must be a number.`;
    if (r.num.integer && !Number.isInteger(n)) return `${r.label} must be a whole number.`;
    if (n < r.num.min) return `${r.label} must be at least ${r.num.min}.`;
    if (n > r.num.max) return `${r.label} may not be greater than ${r.num.max}.`;
  }
  if (r.email && !EMAIL.test(v)) return 'Enter a valid email address.';
  if (r.min && v.length < r.min) return `${r.label} must be at least ${r.min} characters.`;
  if (r.max && v.length > r.max) return `${r.label} may not be longer than ${r.max} characters.`;
  if (r.person && !PERSON.test(v)) return `${r.label} may only contain letters, spaces, dots and hyphens.`;
  if (r.phone && (!PHONE.test(v) || digits(v).length < 10)) return 'Enter a valid 10-digit mobile number.';
  return null;
}

const STEPS: { key: StepKey; title: string; heading: string; lead: string; icon: LucideIcon }[] = [
  { key: 'program', title: 'Program', heading: 'Choose your program', lead: 'Select the program you want to join — you can add a second preference too.', icon: GraduationCap },
  { key: 'personal', title: 'Personal', heading: 'Personal details', lead: 'Enter your name exactly as it appears on your Class 10 certificate.', icon: User },
  { key: 'contact', title: 'Contact & Address', heading: 'Contact & address', lead: 'Your application number and updates will be sent to this mobile number and email.', icon: MapPin },
  { key: 'parents', title: 'Parents', heading: 'Parents & guardian', lead: 'Details of your parents and a local guardian, if you have one.', icon: Users },
  { key: 'academic', title: 'Academics', heading: 'Academic history', lead: 'Class 10 and your qualifying examination — Class 12 for UG, graduation for PG programs.', icon: BookOpen },
  { key: 'documents', title: 'Documents', heading: 'Upload documents', lead: 'Clear scans or phone photos are fine. Files are stored privately for verification only.', icon: Upload },
  { key: 'review', title: 'Review & Submit', heading: 'Review & submit', lead: 'Check your details, accept the declaration and submit your application.', icon: ClipboardCheck },
];
type StepKey = 'program' | 'personal' | 'contact' | 'parents' | 'academic' | 'documents' | 'review';

const STEP_FIELDS: Record<StepKey, string[]> = {
  program: ['course_id', 'second_program_id', 'category', 'quota'],
  personal: ['first_name', 'middle_name', 'last_name', 'gender', 'dob', 'blood_group', 'nationality', 'aadhaar_no'],
  contact: ['phone', 'whatsapp', 'email', 'address', 'city', 'state', 'pincode', 'country'],
  parents: ['father_name', 'father_phone', 'father_occupation', 'mother_name', 'mother_phone', 'mother_occupation', 'guardian_name', 'guardian_relation', 'guardian_phone', 'family_income'],
  academic: ['tenth_board', 'tenth_year', 'tenth_percentage', 'previous_qualification', 'previous_board', 'previous_institution', 'passing_year', 'previous_percentage', 'entrance_exam'],
  documents: [],
  review: [],
};

/** Step a server error field belongs to (first step with an error is opened). */
function stepOfField(field: string): number {
  if (field === 'program_id') return 0;
  if (field.startsWith('doc_')) return 5;
  if (field === 'declaration') return 6;
  const idx = STEPS.findIndex((s) => STEP_FIELDS[s.key].includes(field));
  return idx >= 0 ? idx : 6;
}

const BOARDS = ['CBSE', 'CISCE (ICSE / ISC)', 'NIOS', 'UP Board (UPMSP)', 'Bihar School Examination Board', 'Rajasthan Board (RBSE)', 'Haryana Board (HBSE)', 'Punjab School Education Board', 'Maharashtra State Board', 'IB', 'Cambridge (CAIE)'];
const OCCUPATIONS = ['Business', 'Government Service', 'Private Service', 'Self-employed', 'Farmer', 'Teacher', 'Doctor', 'Engineer', 'Defence Services', 'Homemaker', 'Retired'];

const COL: Record<number, string> = { 3: 'sm:col-span-6 lg:col-span-3', 4: 'sm:col-span-4', 6: 'sm:col-span-6', 12: 'sm:col-span-12' };

function humanSize(bytes: number): string {
  const units = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  let size = bytes;
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024;
    i++;
  }
  return `${i ? Math.round(size * 10) / 10 : Math.round(size)} ${units[i]}`;
}

function ageOn(dob: string): number {
  const d = new Date(`${dob}T00:00:00`);
  return Math.floor((Date.now() - d.getTime()) / 31557600000);
}

function timeAgo(ts: number): string {
  const s = Math.max(0, Math.round((Date.now() - ts) / 1000));
  if (s < 10) return 'just now';
  if (s < 60) return `${s}s ago`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m} min ago`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h} h ago`;
  return new Date(ts).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
}

/* ------------------------------------------------------------------ draft (localStorage, never files) */

interface Draft {
  v: Values;
  s: number;
  m: number;
  t: number;
}

const DRAFT_TTL = 30 * 24 * 3600 * 1000;

function readDraft(key: string): Draft | null {
  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) return null;
    const d = JSON.parse(raw) as Draft;
    if (!d || typeof d !== 'object' || !d.v || Date.now() - d.t > DRAFT_TTL) return null;
    return d;
  } catch {
    return null;
  }
}

function writeDraft(key: string, d: Draft | null): boolean {
  try {
    if (d) window.localStorage.setItem(key, JSON.stringify(d));
    else window.localStorage.removeItem(key);
    return true;
  } catch {
    return false;
  }
}

/* ------------------------------------------------------------------ submit (XHR for upload progress) */

interface Envelope<T> {
  ok: boolean;
  message?: string;
  data?: T;
  errors?: Errors;
}

interface SuccessData {
  application_no: string;
  name: string;
  first_name: string;
  program: string;
  session: string;
  email: string;
  email_sent: boolean;
  documents: number;
  submitted_at: string;
  ack_url: string;
}

function postMultipart<T>(url: string, body: FormData, onProgress: (p: number) => void): Promise<{ status: number; json: Envelope<T> | null }> {
  return new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.setRequestHeader('X-CSRF-Token', csrfToken());
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total);
    xhr.onload = () => {
      let json: Envelope<T> | null = null;
      try {
        json = JSON.parse(xhr.responseText) as Envelope<T>;
      } catch {
        json = null;
      }
      resolve({ status: xhr.status, json });
    };
    xhr.onerror = () => resolve({ status: 0, json: null });
    xhr.send(body);
  });
}

/** Fetch a fresh CSRF token (GET /api/public/csrf next to the apply endpoint) and put it in the page meta tag. */
async function refreshCsrf(applyEndpoint: string): Promise<boolean> {
  try {
    const res = await fetch(applyEndpoint.replace(/\/apply\/?$/, '/csrf'), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const json = (await res.json()) as Envelope<{ token?: string }>;
    const token = json?.data?.token;
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (!token || !meta) return false;
    meta.content = token;
    return true;
  } catch {
    return false;
  }
}

/* ------------------------------------------------------------------ small UI pieces */

interface FieldProps {
  id: string;
  name: string;
  rule: Rule;
  value: string;
  error?: string;
  col?: 3 | 4 | 6 | 12;
  type?: string;
  placeholder?: string;
  help?: string;
  autoComplete?: string;
  inputMode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email';
  list?: string;
  options?: WizardOption[];
  emptyLabel?: string;
  prefix?: string;
  min?: string;
  max?: string;
  step?: string;
  disabled?: boolean;
  onChange: (name: string, value: string) => void;
  onBlur: (name: string) => void;
}

function Field(p: FieldProps) {
  const describedBy = [p.error && `${p.id}-err`, p.help && `${p.id}-help`].filter(Boolean).join(' ') || undefined;
  const common = {
    id: p.id,
    name: p.name,
    value: p.value,
    disabled: p.disabled,
    'aria-invalid': p.error ? true : undefined,
    'aria-describedby': describedBy,
    'aria-required': p.rule.required || undefined,
    onBlur: () => p.onBlur(p.name),
    className: clsx('form-input', p.error && 'is-invalid', p.prefix && 'pl-8'),
  };
  return (
    <div className={clsx('col-span-12', COL[p.col ?? 6])}>
      <label htmlFor={p.id} className="form-label">
        {p.rule.label}
        {p.rule.required && <span className="form-required">{NBSP_STAR}</span>}
      </label>
      {p.options ? (
        <select {...common} onChange={(e) => p.onChange(p.name, e.target.value)}>
          <option value="">{p.emptyLabel ?? 'Select'}</option>
          {p.options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      ) : (
        <div className="relative">
          {p.prefix && <span className="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-sm font-semibold text-slate-400">{p.prefix}</span>}
          <input
            {...common}
            type={p.type ?? 'text'}
            placeholder={p.placeholder}
            autoComplete={p.autoComplete}
            inputMode={p.inputMode}
            list={p.list}
            min={p.min}
            max={p.max}
            step={p.step}
            maxLength={p.rule.max}
            onChange={(e) => p.onChange(p.name, e.target.value)}
          />
        </div>
      )}
      {p.help && !p.error && (
        <p id={`${p.id}-help`} className="form-hint">
          {p.help}
        </p>
      )}
      {p.error && (
        <p id={`${p.id}-err`} className="form-error">
          {p.error}
        </p>
      )}
    </div>
  );
}

function SubHeading({ children, icon: Icon }: { children: ReactNode; icon: LucideIcon }) {
  return (
    <h3 className="col-span-12 mt-2 flex items-center gap-2 border-b border-slate-100 pb-2 text-sm font-bold uppercase tracking-wider text-brand-800 first:mt-0">
      <Icon className="h-4 w-4 text-accent-600" aria-hidden="true" />
      {children}
    </h3>
  );
}

/** Direction-aware enter transition for a step (transform/opacity only; static when reduced motion). */
function StepPane({ dir, animate, children }: { dir: 1 | -1; animate: boolean; children: ReactNode }) {
  const [shown, setShown] = useState(!animate);
  useLayoutEffect(() => {
    if (!animate) return;
    let r2 = 0;
    const r1 = requestAnimationFrame(() => {
      r2 = requestAnimationFrame(() => setShown(true));
    });
    return () => {
      cancelAnimationFrame(r1);
      cancelAnimationFrame(r2);
    };
  }, [animate]);
  return (
    <div className={clsx(animate && 'transition duration-300 ease-out', shown ? 'translate-x-0 opacity-100' : dir > 0 ? 'translate-x-6 opacity-0' : '-translate-x-6 opacity-0')}>
      {children}
    </div>
  );
}

interface DocPickerProps {
  id: string;
  doc: WizardDocument;
  required: boolean;
  file?: File;
  preview?: string;
  error?: string;
  disabled?: boolean;
  onPick: (key: string, file: File | undefined) => void;
}

function DocPicker({ id, doc, required, file, preview, error, disabled, onPick }: DocPickerProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [over, setOver] = useState(false);
  const accept = doc.image ? 'image/jpeg,image/png,image/webp' : 'application/pdf,image/jpeg,image/png,image/webp';
  const drop = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setOver(false);
    const f = e.dataTransfer.files?.[0];
    if (f && !disabled) onPick(doc.key, f);
  };
  return (
    <div className="min-w-0">
      <div
        onDragOver={(e) => {
          e.preventDefault();
          setOver(true);
        }}
        onDragLeave={() => setOver(false)}
        onDrop={drop}
        className={clsx(
          'relative flex h-full items-center gap-4 rounded-2xl border p-4 transition',
          file ? 'border-accent-200 bg-accent-50/50' : 'border-dashed bg-slate-50/70',
          over ? 'border-brand-500 bg-brand-50/70 ring-4 ring-brand-500/10' : !file && (error ? 'border-red-300' : 'border-slate-300 hover:border-brand-300'),
        )}
      >
        <span className={clsx('relative inline-flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl', file ? 'bg-white ring-1 ring-slate-200' : 'bg-white text-brand-700 ring-1 ring-slate-200')}>
          {file && preview ? (
            <img src={preview} alt="" className="h-full w-full object-cover" />
          ) : file ? (
            <FileText className="h-7 w-7 text-brand-700" aria-hidden="true" />
          ) : (
            <Upload className="h-6 w-6" aria-hidden="true" />
          )}
          {file && (
            <span className="absolute -right-0 -top-0 inline-flex h-5 w-5 items-center justify-center rounded-bl-lg bg-accent-600 text-white">
              <Check className="h-3 w-3" aria-hidden="true" />
            </span>
          )}
        </span>
        <span className="min-w-0 flex-1">
          <label htmlFor={id} className="block text-sm font-semibold text-brand-900">
            {doc.label}
            {required ? <span className="form-required">{NBSP_STAR}</span> : <span className="ml-1 text-xs font-normal text-slate-500">(optional)</span>}
          </label>
          {file ? (
            <span className="mt-0.5 block truncate text-xs text-slate-600">
              {file.name} · {humanSize(file.size)}
            </span>
          ) : (
            <span className="mt-0.5 block text-xs leading-relaxed text-slate-500">{doc.hint}</span>
          )}
          <span className="mt-2 flex flex-wrap gap-2">
            <button type="button" className="btn btn-secondary btn-sm !px-3 !py-1.5 text-xs" disabled={disabled} onClick={() => inputRef.current?.click()}>
              <Upload className="h-3.5 w-3.5" aria-hidden="true" />
              {file ? 'Replace' : 'Choose file'}
            </button>
            {file && (
              <button type="button" className="btn btn-ghost btn-sm !px-3 !py-1.5 text-xs text-red-600" disabled={disabled} onClick={() => onPick(doc.key, undefined)}>
                <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                Remove<span className="sr-only"> {doc.label}</span>
              </button>
            )}
          </span>
        </span>
        <input
          ref={inputRef}
          id={id}
          type="file"
          accept={accept}
          className="sr-only"
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-err` : undefined}
          onChange={(e) => {
            onPick(doc.key, e.target.files?.[0]);
            e.target.value = '';
          }}
        />
      </div>
      {error && (
        <p id={`${id}-err`} className="form-error">
          {error}
        </p>
      )}
    </div>
  );
}

function ReviewBlock({ title, icon: Icon, onEdit, rows }: { title: string; icon: LucideIcon; onEdit: () => void; rows: [string, string | undefined][] }) {
  const shown = rows.filter(([, v]) => v && v.trim() !== '');
  return (
    <section className="rounded-2xl border border-slate-200 bg-white">
      <header className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
        <h3 className="flex items-center gap-2 text-sm font-bold text-brand-900">
          <Icon className="h-4 w-4 text-accent-600" aria-hidden="true" />
          {title}
        </h3>
        <button type="button" onClick={onEdit} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-brand-700 transition hover:bg-brand-50">
          <Pencil className="h-3.5 w-3.5" aria-hidden="true" />
          Edit<span className="sr-only"> {title}</span>
        </button>
      </header>
      <dl className="grid gap-x-6 gap-y-2.5 px-4 py-3.5 text-sm sm:grid-cols-2">
        {shown.map(([k, v]) => (
          <div key={k} className="min-w-0">
            <dt className="text-xs text-slate-500">{k}</dt>
            <dd className="break-words font-medium text-slate-800">{v}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

/* ------------------------------------------------------------------ main component */

/**
 * Multi-step online application (Program → Personal → Contact & Address → Parents → Academic history → Documents →
 * Review & Submit). Per-step validation mirroring the server, animated transitions + progress, draft autosave to
 * localStorage (text fields only), file pickers with previews/size checks, review with edit links, consent, multipart
 * submit with upload progress (CSRF header), success screen with the application number and acknowledgement link.
 */
export default function ApplyWizard(props: ApplyWizardProps) {
  const { endpoint, session, programs, options, documents, limits, help, links } = props;
  const storageKey = props.storageKey ?? 'gimt.apply.draft.v1';
  const uid = useId();
  const isStatic = useStaticMode();
  const rules = useMemo(() => buildRules(limits), [limits]);
  const fid = (name: string) => `${uid}-${name}`;

  const [boot] = useState(() => {
    const draft = readDraft(storageKey);
    const base: Values = { nationality: 'Indian', country: 'India', quota: 'general' };
    const v: Values = { ...base, ...(draft?.v ?? {}) };
    const initial = props.initialProgram ? programs.find((p) => p.id === props.initialProgram) : undefined;
    if (initial) {
      if (v.program_id !== String(initial.id)) {
        v.program_id = String(initial.id);
        v.course_id = '';
        if (v.second_program_id === v.program_id) v.second_program_id = '';
      }
      // same default qualifying exam as choosing the program on step 1
      if (initial.level === 'PG' && !['Graduation', 'Post Graduation'].includes(v.previous_qualification ?? '')) v.previous_qualification = 'Graduation';
      if (initial.level !== 'PG' && !v.previous_qualification) v.previous_qualification = 'Class 12';
    }
    const filled = draft ? Object.entries(draft.v).some(([k, val]) => !['nationality', 'country', 'quota', 'program_id'].includes(k) && val) : false;
    return { values: v, step: draft && filled ? Math.min(draft.s, STEPS.length - 2) : 0, max: draft && filled ? Math.min(draft.m, STEPS.length - 1) : 0, restoredAt: draft && filled ? draft.t : null };
  });

  const [values, setValues] = useState<Values>(boot.values);
  const [step, setStep] = useState(boot.step);
  const [maxStep, setMaxStep] = useState(boot.max);
  const [dir, setDir] = useState<1 | -1>(1);
  const [navigated, setNavigated] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [files, setFiles] = useState<Files>({});
  const [previews, setPreviews] = useState<Record<string, string>>({});
  const [restoredAt, setRestoredAt] = useState<number | null>(boot.restoredAt);
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const [, setTick] = useState(0);
  const [alert, setAlert] = useState('');
  const [announce, setAnnounce] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [progress, setProgress] = useState(0);
  const [result, setResult] = useState<SuccessData | null>(null);
  const [copied, setCopied] = useState(false);
  const [level, setLevel] = useState<string>('all');
  const [showGuardian, setShowGuardian] = useState(() => !!(boot.values.guardian_name || boot.values.guardian_phone));

  const rootRef = useRef<HTMLDivElement>(null);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const honeypotRef = useRef<HTMLInputElement>(null);

  const program = programs.find((p) => String(p.id) === values.program_id) ?? null;
  const isPG = program?.level === 'PG';
  const reserved = !!values.category && values.category !== 'General';
  const visibleDocs = documents.filter((d) => d.when === 'always' || (d.when === 'pg' && isPG) || (d.when === 'category' && reserved) || d.when === 'optional');
  const docRequired = (d: WizardDocument) => d.when === 'always' || (d.when === 'pg' && isPG);
  const totalBytes = Object.values(files).reduce((s, f) => s + (f?.size ?? 0), 0);
  const current = STEPS[step];

  /* ---- draft autosave (debounced) */
  useEffect(() => {
    if (result) return;
    const t = window.setTimeout(() => {
      if (writeDraft(storageKey, { v: values, s: step, m: maxStep, t: Date.now() })) setSavedAt(Date.now());
    }, 600);
    return () => window.clearTimeout(t);
  }, [values, step, maxStep, storageKey, result]);

  /* ---- clear the step alert once every highlighted field is fixed */
  const errorCount = Object.keys(errors).length;
  const hadErrors = useRef(false);
  useEffect(() => {
    if (errorCount > 0) hadErrors.current = true;
    else if (hadErrors.current) {
      hadErrors.current = false;
      setAlert('');
    }
  }, [errorCount]);

  /* ---- refresh "saved x ago" labels */
  useEffect(() => {
    const t = window.setInterval(() => setTick((n) => n + 1), 30000);
    return () => window.clearInterval(t);
  }, []);

  /* ---- revoke image previews on unmount (replaced ones are revoked in pickFile) */
  const previewsRef = useRef(previews);
  previewsRef.current = previews;
  useEffect(() => () => Object.values(previewsRef.current).forEach((u) => URL.revokeObjectURL(u)), []);

  /* ---- focus + scroll on step change */
  useEffect(() => {
    if (!navigated) return;
    const root = rootRef.current;
    if (root) {
      const top = root.getBoundingClientRect().top;
      if (top < 80 || top > window.innerHeight * 0.6) window.scrollTo({ top: window.scrollY + top - 96, behavior: isStatic ? 'auto' : 'smooth' });
    }
    headingRef.current?.focus({ preventScroll: true });
    setAnnounce(`Step ${step + 1} of ${STEPS.length}: ${STEPS[step].heading}`);
  }, [step, navigated, isStatic]);

  /* ---- value helpers */
  const set = (name: string, value: string) => {
    setValues((prev) => {
      const next = { ...prev, [name]: value };
      if (name === 'program_id') {
        const p = programs.find((x) => String(x.id) === value);
        if (p && !p.courses.some((c) => String(c.id) === prev.course_id)) next.course_id = '';
        if (next.second_program_id === value) next.second_program_id = '';
        if (p?.level === 'PG' && !['Graduation', 'Post Graduation'].includes(next.previous_qualification ?? '')) next.previous_qualification = 'Graduation';
        if (p && p.level !== 'PG' && !next.previous_qualification) next.previous_qualification = 'Class 12';
      }
      if (name === 'phone' && prev._wa_same === '1') next.whatsapp = value;
      if (name === '_wa_same') next.whatsapp = value === '1' ? prev.phone ?? '' : '';
      return next;
    });
    if (errors[name]) {
      const r = rules[name];
      const err = r ? checkRule(r, value) : null;
      setErrors((prev) => {
        const n = { ...prev };
        if (err) n[name] = err;
        else delete n[name];
        return n;
      });
    }
  };

  const blur = (name: string) => {
    const r = rules[name];
    if (!r) return;
    const err = checkRule(r, values[name]);
    setErrors((prev) => {
      const n = { ...prev };
      if (err) n[name] = err;
      else delete n[name];
      return n;
    });
  };

  const pickFile = (key: string, file: File | undefined) => {
    const doc = documents.find((d) => d.key === key);
    if (!doc) return;
    setPreviews((prev) => {
      const n = { ...prev };
      if (n[key]) URL.revokeObjectURL(n[key]);
      delete n[key];
      if (file && file.type.startsWith('image/')) n[key] = URL.createObjectURL(file);
      return n;
    });
    setFiles((prev) => ({ ...prev, [key]: file }));
    const err = file ? checkFile(doc, file) : null;
    setErrors((prev) => {
      const n = { ...prev };
      if (err) n[`doc_${key}`] = err;
      else delete n[`doc_${key}`];
      return n;
    });
  };

  const checkFile = (doc: WizardDocument, f: File): string | null => {
    const ext = (f.name.split('.').pop() ?? '').toLowerCase();
    const allowed = doc.image ? ['jpg', 'jpeg', 'png', 'webp'] : ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!allowed.includes(ext)) return `Upload a ${doc.image ? 'JPG, PNG, WEBP' : 'PDF, JPG, PNG, WEBP'} file.`;
    if (f.size > doc.max) return `This file is ${humanSize(f.size)}. Maximum ${humanSize(doc.max)}.`;
    if (f.size === 0) return 'This file is empty. Please choose it again.';
    return null;
  };

  /* ---- step validation (mirrors pf_validate_application) */
  const validateStep = (key: StepKey, v: Values = values): Errors => {
    const e: Errors = {};
    STEP_FIELDS[key].forEach((name) => {
      const err = checkRule(rules[name], v[name]);
      if (err) e[name] = err;
    });
    if (key === 'program') {
      if (!program) e.program_id = 'Select the program you want to apply for.';
      if (v.second_program_id && v.second_program_id === v.program_id) e.second_program_id = 'Second preference must be a different program.';
    }
    if (key === 'personal') {
      if (v.dob && !e.dob) {
        if (v.dob >= new Date().toISOString().slice(0, 10)) e.dob = 'Date of birth must be in the past.';
        else if (ageOn(v.dob) < 14 || ageOn(v.dob) > 65) e.dob = 'Applicant age must be between 14 and 65 years.';
      }
      if (v.aadhaar_no && !e.aadhaar_no && !/^\d{12}$/.test(v.aadhaar_no.replace(/\s+/g, ''))) e.aadhaar_no = 'Enter a valid 12-digit Aadhaar number.';
    }
    if (key === 'contact') {
      const india = !v.country || v.country.trim().toLowerCase() === 'india';
      if (v.pincode && !e.pincode && india && !/^\d{6}$/.test(v.pincode.trim())) e.pincode = 'Enter a valid 6-digit PIN code.';
    }
    if (key === 'parents') {
      if (v.guardian_name?.trim() && !v.guardian_phone?.trim() && !e.guardian_phone) e.guardian_phone = "Enter the guardian's mobile number.";
    }
    if (key === 'academic') {
      const ty = Number(v.tenth_year);
      const py = Number(v.passing_year);
      if (!e.passing_year && !e.tenth_year && ty && py && py < ty + 2) e.passing_year = 'The qualifying exam must be at least 2 years after Class 10.';
      if (!e.tenth_year && ty && v.dob && ty - Number(v.dob.slice(0, 4)) < 13) e.tenth_year = 'Class 10 passing year does not match your date of birth.';
      if (isPG && v.previous_qualification && !e.previous_qualification && !['Graduation', 'Post Graduation'].includes(v.previous_qualification)) {
        e.previous_qualification = "Postgraduate programs require a bachelor's degree — select Graduation.";
      }
    }
    if (key === 'documents') {
      visibleDocs.forEach((d) => {
        const f = files[d.key];
        if (!f) {
          if (docRequired(d)) e[`doc_${d.key}`] = `Upload your ${d.label}.`;
          return;
        }
        const err = checkFile(d, f);
        if (err) e[`doc_${d.key}`] = err;
      });
      if (totalBytes > limits.total) e.doc_other = `Your documents add up to ${humanSize(totalBytes)}. Please keep the total under ${humanSize(limits.total)}.`;
    }
    if (key === 'review' && v.declaration !== '1') e.declaration = 'Please accept the declaration to submit your application.';
    return e;
  };

  const focusFirst = (errs: Errors) => {
    const first = Object.keys(errs)[0];
    if (!first) return;
    window.setTimeout(() => {
      document.getElementById(fid(first))?.focus({ preventScroll: false });
    }, 60);
  };

  const goTo = (i: number) => {
    if (i === step || i < 0 || i >= STEPS.length) return;
    setDir(i > step ? 1 : -1);
    setNavigated(true);
    setStep(i);
    setAlert('');
    setMaxStep((m) => Math.max(m, i));
  };

  const next = (e?: FormEvent) => {
    e?.preventDefault();
    if (submitting) return;
    const errs = validateStep(current.key);
    setErrors(errs);
    if (Object.keys(errs).length) {
      setAlert(Object.keys(errs).length === 1 ? Object.values(errs)[0] : 'Please fix the highlighted fields to continue.');
      setAnnounce(`${Object.keys(errs).length} field${Object.keys(errs).length === 1 ? ' needs' : 's need'} attention.`);
      focusFirst(errs);
      return;
    }
    if (current.key === 'review') {
      void submit();
      return;
    }
    goTo(step + 1);
  };

  const submit = async () => {
    // validate everything once more (draft restores can skip steps)
    for (let i = 0; i < STEPS.length; i++) {
      const errs = validateStep(STEPS[i].key);
      if (Object.keys(errs).length) {
        setErrors(errs);
        if (i !== step) goTo(i);
        setAlert('Please complete this step before submitting.');
        focusFirst(errs);
        return;
      }
    }
    const fd = new FormData();
    Object.entries(values).forEach(([k, v]) => {
      if (!k.startsWith('_') && v !== undefined && v !== null) fd.append(k, v);
    });
    if (values.aadhaar_no) fd.set('aadhaar_no', values.aadhaar_no.replace(/\s+/g, ''));
    fd.set('website', honeypotRef.current?.value ?? '');
    visibleDocs.forEach((d) => {
      const f = files[d.key];
      if (f) fd.append(`doc_${d.key}`, f, f.name);
    });
    setSubmitting(true);
    setProgress(0);
    setAlert('');
    let { status, json } = await postMultipart<SuccessData>(endpoint, fd, setProgress);
    // session token expired while the form was open: get a fresh one and retry once (files are still attached)
    if (status === 419 && (await refreshCsrf(endpoint))) {
      setProgress(0);
      ({ status, json } = await postMultipart<SuccessData>(endpoint, fd, setProgress));
    }
    setSubmitting(false);
    if (json?.ok && json.data) {
      writeDraft(storageKey, null);
      setResult(json.data);
      setAnnounce(`Application ${json.data.application_no} submitted successfully.`);
      window.setTimeout(() => rootRef.current?.scrollIntoView({ behavior: isStatic ? 'auto' : 'smooth', block: 'start' }), 30);
      return;
    }
    const serverErrors = json?.errors && typeof json.errors === 'object' ? json.errors : {};
    const keys = Object.keys(serverErrors);
    if (keys.length) {
      const target = Math.min(...keys.map(stepOfField));
      setErrors(serverErrors);
      if (target !== step) goTo(target);
      setAlert(json?.message || 'Please fix the highlighted fields and try again.');
      focusFirst(serverErrors);
      return;
    }
    setAlert(
      json?.message ||
        (status === 413 ? 'Your documents are too large to upload together. Please use smaller files.' : status === 0 ? 'Unable to submit right now. Please check your connection and try again.' : 'Something went wrong. Please try again.'),
    );
  };

  const startOver = () => {
    writeDraft(storageKey, null);
    setValues({ nationality: 'Indian', country: 'India', quota: 'general', ...(props.initialProgram ? { program_id: String(props.initialProgram) } : {}) });
    setFiles({});
    Object.values(previews).forEach((u) => URL.revokeObjectURL(u));
    setPreviews({});
    setErrors({});
    setRestoredAt(null);
    setShowGuardian(false);
    setMaxStep(0);
    setDir(-1);
    setNavigated(true);
    setStep(0);
  };

  /* ---- completion meter (required fields + documents) */
  const completion = useMemo(() => {
    const req = Object.entries(rules).filter(([, r]) => r.required).map(([k]) => k);
    const docs = documents.filter((d) => d.when === 'always' || (d.when === 'pg' && isPG));
    const done = req.filter((k) => (values[k] ?? '').trim() !== '').length + docs.filter((d) => files[d.key]).length;
    return Math.round((done / (req.length + docs.length)) * 100);
  }, [rules, documents, isPG, values, files]);

  const opt = (list: string[]) => list.map((s) => ({ value: s, label: s }));
  const years = useMemo(() => {
    const out: WizardOption[] = [];
    for (let y = limits.maxPassingYear; y >= limits.minYear; y--) out.push({ value: String(y), label: String(y) });
    return out;
  }, [limits.maxPassingYear, limits.minYear]);
  const tenthYears = years.filter((y) => Number(y.value) <= limits.maxYear);
  const fieldProps = (name: string) => ({ id: fid(name), name, rule: rules[name], value: values[name] ?? '', error: errors[name], onChange: set, onBlur: blur, disabled: submitting });
  const levels = useMemo(() => {
    const present = new Set<string>(programs.map((p) => (p.level === 'UG' ? 'ug' : p.level === 'PG' || p.level === 'PhD' ? 'pg' : 'other')));
    return [
      { key: 'all', label: 'All Programs' },
      { key: 'ug', label: 'Undergraduate' },
      { key: 'pg', label: 'Postgraduate' },
      { key: 'other', label: 'Diploma & Certificate' },
    ].filter((l) => l.key === 'all' || present.has(l.key));
  }, [programs]);
  const levelOf = (p: WizardProgram) => (p.level === 'UG' ? 'ug' : p.level === 'PG' || p.level === 'PhD' ? 'pg' : 'other');
  const shownPrograms = programs.filter((p) => level === 'all' || levelOf(p) === level);
  const label = (list: WizardOption[], v?: string) => list.find((o) => o.value === v)?.label ?? v ?? '';

  /* ------------------------------------------------------------------ success screen */
  if (result) {
    const nextSteps: [LucideIcon, string, string][] = [
      [ClipboardCheck, 'Document verification', 'Our admission team verifies your documents within 3 working days.'],
      [Users, 'Entrance test / interview', 'The schedule is sent to your email and mobile number.'],
      [Sparkles, 'Offer letter', 'Selected applicants receive an offer letter with the admission fee details.'],
      [GraduationCap, 'Fee payment & enrollment', 'Pay the admission fee to confirm your seat and receive your student ID.'],
    ];
    return (
      <div ref={rootRef} className="mx-auto max-w-3xl scroll-mt-28">
        <div className="overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-soft">
          <div className="relative isolate overflow-hidden bg-brand-900 px-6 py-10 text-center text-white sm:px-10">
            <div className="pattern-dots-light pattern-fade absolute inset-0 -z-10 opacity-60" aria-hidden="true" />
            <div className="enquiry-success !py-0">
              <svg className="success-check" viewBox="0 0 52 52" aria-hidden="true">
                <circle cx="26" cy="26" r="24" />
                <path d="M15 27 l7 7 l15-15" />
              </svg>
              <h2 className="mt-5 font-display text-2xl font-extrabold text-white sm:text-3xl">
                Application submitted, {result.first_name}!
              </h2>
              <p className="mt-2 max-w-xl text-sm text-white/75">
                Thank you for applying to GIMT. Keep your application number handy — you will need it for every update.
              </p>
              <div className="mt-6 inline-flex flex-wrap items-center justify-center gap-2 rounded-2xl bg-white/10 px-4 py-3 ring-1 ring-white/15">
                <span className="text-xs font-semibold uppercase tracking-widest text-accent-300">Application No.</span>
                <span className="font-mono text-lg font-bold tracking-wide sm:text-xl">{result.application_no}</span>
                <button
                  type="button"
                  className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-white/80 transition hover:bg-white/10 hover:text-white"
                  onClick={() => {
                    void navigator.clipboard?.writeText(result.application_no).then(() => {
                      setCopied(true);
                      window.setTimeout(() => setCopied(false), 2000);
                    });
                  }}
                >
                  {copied ? <Check className="h-3.5 w-3.5" aria-hidden="true" /> : <Copy className="h-3.5 w-3.5" aria-hidden="true" />}
                  {copied ? 'Copied' : 'Copy'}
                </button>
              </div>
            </div>
          </div>
          <div className="grid gap-8 px-6 py-8 sm:px-10 md:grid-cols-2">
            <dl className="space-y-3 text-sm">
              {(
                [
                  ['Applicant', result.name],
                  ['Program', result.program],
                  ['Session', result.session],
                  ['Submitted on', result.submitted_at],
                  ['Documents uploaded', String(result.documents)],
                ] as [string, string][]
              ).map(([k, v]) => (
                <div key={k} className="flex justify-between gap-4 border-b border-slate-100 pb-2.5">
                  <dt className="text-slate-500">{k}</dt>
                  <dd className="text-right font-semibold text-slate-900">{v}</dd>
                </div>
              ))}
              {result.email_sent && (
                <p className="flex items-start gap-2 rounded-xl bg-emerald-50 px-3 py-2.5 text-[13px] text-emerald-800">
                  <Mail className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                  A confirmation has been emailed to {result.email}.
                </p>
              )}
            </dl>
            <div>
              <h3 className="font-display text-base font-bold text-brand-900">What happens next</h3>
              <ol className="mt-4 space-y-4">
                {nextSteps.map(([Icon, t, d], i) => (
                  <li key={t} className="flex gap-3">
                    <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-800">
                      <Icon className="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span>
                      <span className="block text-sm font-semibold text-brand-900">
                        {i + 1}. {t}
                      </span>
                      <span className="block text-[13px] leading-relaxed text-slate-600">{d}</span>
                    </span>
                  </li>
                ))}
              </ol>
            </div>
          </div>
          <div className="flex flex-col gap-3 border-t border-slate-100 bg-slate-50/70 px-6 py-5 sm:flex-row sm:items-center sm:justify-center sm:px-10">
            <a href={result.ack_url} target="_blank" rel="noopener" className="btn btn-accent btn-shine">
              <Download className="h-4 w-4" aria-hidden="true" />
              Download Acknowledgement
            </a>
            <a href={links.admissions} className="btn btn-outline">
              Back to Admissions
            </a>
            <a href={links.programs} className="btn btn-ghost">
              Explore Programs
              <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </a>
          </div>
        </div>
        <p className="sr-only" role="status" aria-live="polite">
          {announce}
        </p>
      </div>
    );
  }

  /* ------------------------------------------------------------------ wizard */
  const StepIcon = current.icon;
  const pct = Math.round((step / (STEPS.length - 1)) * 100);

  return (
    <div ref={rootRef} className="grid scroll-mt-28 gap-6 lg:grid-cols-12">
      <div className="min-w-0 rounded-3xl border border-slate-100 bg-white shadow-soft lg:col-span-8">
        {/* stepper */}
        <div className="border-b border-slate-100 px-5 py-5 sm:px-8">
          <nav aria-label="Application steps" className="hidden md:block">
            <ol className="relative flex justify-between">
              <span className="absolute left-[18px] right-[18px] top-[18px] h-0.5 rounded bg-slate-200" aria-hidden="true" />
              <span
                className={clsx('absolute left-[18px] right-[18px] top-[18px] h-0.5 origin-left rounded bg-gradient-to-r from-brand-600 to-accent-500', !isStatic && 'transition-transform duration-500 ease-out')}
                style={{ transform: `scaleX(${step / (STEPS.length - 1)})` }}
                aria-hidden="true"
              />
              {STEPS.map((s, i) => {
                const done = i < maxStep && i !== step;
                const active = i === step;
                const reachable = i <= maxStep && !submitting;
                const Icon = s.icon;
                return (
                  <li key={s.key} className="relative z-10 flex w-9 flex-col items-center lg:w-20">
                    <button
                      type="button"
                      onClick={() => reachable && goTo(i)}
                      disabled={!reachable}
                      aria-current={active ? 'step' : undefined}
                      aria-label={`Step ${i + 1}: ${s.title}${done ? ' (completed)' : active ? ' (current)' : ''}`}
                      className={clsx(
                        'inline-flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold ring-4 ring-white transition',
                        active ? 'scale-110 bg-brand-900 text-white shadow-soft' : done ? 'bg-accent-600 text-white hover:bg-accent-700' : reachable ? 'bg-brand-50 text-brand-800 hover:bg-brand-100' : 'bg-slate-100 text-slate-400',
                      )}
                    >
                      {done ? <Check className="h-4 w-4" aria-hidden="true" /> : <Icon className="h-4 w-4" aria-hidden="true" />}
                    </button>
                    <span className={clsx('mt-2 hidden text-center text-[11px] font-semibold leading-tight lg:block', active ? 'text-brand-900' : done ? 'text-accent-700' : 'text-slate-400')}>{s.title}</span>
                  </li>
                );
              })}
            </ol>
          </nav>
          <div className="md:hidden">
            <div className="flex items-center justify-between gap-3 text-sm">
              <span className="font-semibold text-brand-900">
                Step {step + 1} of {STEPS.length} · <span className="text-slate-600">{current.title}</span>
              </span>
              <span className="text-xs font-semibold text-accent-700">{pct}%</span>
            </div>
            <span className="progress-track mt-3">
              <span className="progress-fill" style={{ ['--progress' as string]: Math.max(0.04, step / (STEPS.length - 1)), transitionDuration: isStatic ? '0ms' : '500ms' }} />
            </span>
          </div>
        </div>

        <form onSubmit={next} noValidate aria-busy={submitting}>
          <input ref={honeypotRef} type="text" name="website" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />
          <div className="px-5 py-6 sm:px-8 sm:py-8">
            {restoredAt && (
              <div className="mb-6 flex flex-col gap-3 rounded-2xl bg-brand-50/80 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-100 sm:flex-row sm:items-center sm:justify-between">
                <p className="flex items-start gap-2">
                  <RotateCcw className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-hidden="true" />
                  <span>
                    Welcome back! We restored the application you started {timeAgo(restoredAt)}. Documents need to be attached again.
                  </span>
                </p>
                <span className="flex shrink-0 gap-2">
                  <button type="button" className="btn btn-sm btn-ghost !px-3" onClick={() => setRestoredAt(null)}>
                    <X className="h-3.5 w-3.5" aria-hidden="true" />
                    Dismiss
                  </button>
                  <button type="button" className="btn btn-sm btn-secondary !px-3" onClick={startOver}>
                    Start over
                  </button>
                </span>
              </div>
            )}

            <StepPane key={current.key} dir={dir} animate={navigated && !isStatic}>
              <div className="mb-6 flex items-start gap-4">
                <span className="hidden h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-800 sm:inline-flex">
                  <StepIcon className="h-6 w-6" aria-hidden="true" />
                </span>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-widest text-accent-700">
                    Step {step + 1} of {STEPS.length}
                  </p>
                  <h2 ref={headingRef} tabIndex={-1} className="mt-1 font-display text-2xl font-extrabold text-brand-900 outline-none">
                    {current.heading}
                  </h2>
                  <p className="mt-1 text-sm text-slate-500">{current.lead}</p>
                </div>
              </div>

              {/* ---------------- step 1: program */}
              {current.key === 'program' && (
                <div className="space-y-6">
                  <div className="pill-row scrollbar-none" role="toolbar" aria-label="Filter programs by level">
                    {levels.map((l) => (
                      <button key={l.key} type="button" className={clsx('chip', level === l.key && 'chip-active')} aria-pressed={level === l.key} onClick={() => setLevel(l.key)}>
                        {l.label}
                      </button>
                    ))}
                  </div>
                  <fieldset>
                    <legend className="sr-only">Program<span> (required)</span></legend>
                    <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-invalid={errors.program_id ? true : undefined} aria-describedby={errors.program_id ? fid('program_id-err') : undefined}>
                      {shownPrograms.map((p) => {
                        const selected = String(p.id) === values.program_id;
                        return (
                          <label
                            key={p.id}
                            className={clsx(
                              'group relative flex cursor-pointer items-center gap-3 rounded-2xl border p-3 transition duration-200 hover:-translate-y-0.5 hover:shadow-soft has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-500/25',
                              selected ? 'border-accent-500 bg-accent-50/60 ring-2 ring-accent-500/20' : 'border-slate-200 bg-white hover:border-brand-200',
                            )}
                          >
                            <input
                              type="radio"
                              name="program_id"
                              id={selected || (!values.program_id && p === shownPrograms[0]) ? fid('program_id') : undefined}
                              value={p.id}
                              checked={selected}
                              disabled={submitting}
                              onChange={() => {
                                set('program_id', String(p.id));
                                setErrors((prev) => {
                                  const n = { ...prev };
                                  delete n.program_id;
                                  return n;
                                });
                              }}
                              className="sr-only"
                            />
                            <img src={p.image} alt="" loading="lazy" className="h-14 w-14 shrink-0 rounded-xl object-cover" />
                            <span className="min-w-0 flex-1">
                              <span className="block text-sm font-semibold leading-snug text-brand-900">{p.name}</span>
                              <span className="mt-0.5 block text-xs text-slate-500">
                                {p.levelLabel} · {p.duration}
                              </span>
                              <span className="mt-0.5 block text-xs font-semibold text-brand-700">{p.fee}</span>
                            </span>
                            <span
                              className={clsx(
                                'inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 transition',
                                selected ? 'scale-100 border-accent-600 bg-accent-600 text-white' : 'border-slate-300 bg-white text-transparent',
                              )}
                              aria-hidden="true"
                            >
                              <Check className="h-3.5 w-3.5" />
                            </span>
                            {p.popular && (
                              <span className="absolute -top-2 right-10 rounded-full bg-accent-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm">Popular</span>
                            )}
                          </label>
                        );
                      })}
                    </div>
                    {errors.program_id && (
                      <p id={fid('program_id-err')} className="form-error">
                        {errors.program_id}
                      </p>
                    )}
                  </fieldset>

                  {program && (
                    <div className="rounded-2xl bg-slate-50 p-4 text-sm ring-1 ring-slate-100">
                      <p className="font-semibold text-brand-900">Eligibility for {program.short}</p>
                      <p className="mt-1 leading-relaxed text-slate-600">{program.eligibility || 'As per university norms.'}</p>
                      <p className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                        {program.seats && <span>{program.seats} seats</span>}
                        <span>Admission fee {program.admissionFee} — payable only after approval</span>
                      </p>
                    </div>
                  )}

                  <div className="grid grid-cols-12 gap-5">
                    <Field {...fieldProps('category')} col={6} options={options.categories} emptyLabel="Select category" />
                    <Field {...fieldProps('quota')} col={6} options={options.quotas} emptyLabel="Select quota" />
                    {program && program.courses.length > 0 && (
                      <Field {...fieldProps('course_id')} options={program.courses.map((c) => ({ value: String(c.id), label: c.name }))} emptyLabel="Decide later / no specialisation" />
                    )}
                    <Field
                      {...fieldProps('second_program_id')}
                      col={program && program.courses.length > 0 ? 6 : 12}
                      options={programs.filter((p) => String(p.id) !== values.program_id).map((p) => ({ value: String(p.id), label: p.name }))}
                      emptyLabel="None"
                      help="Optional — considered if seats in your first choice are full."
                    />
                    <div className="col-span-12 grid gap-3 sm:grid-cols-2">
                      {(
                        [
                          ['hostel_required', 'Hostel accommodation', 'Separate secure hostels for boys and girls'],
                          ['transport_required', 'College transport', 'Buses across Noida, Greater Noida & Ghaziabad'],
                        ] as [string, string, string][]
                      ).map(([name, title, text]) => (
                        <label
                          key={name}
                          className={clsx(
                            'flex cursor-pointer items-start gap-3 rounded-2xl border p-3.5 transition has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-500/25',
                            values[name] === '1' ? 'border-brand-300 bg-brand-50/60' : 'border-slate-200 hover:border-brand-200',
                          )}
                        >
                          <input type="checkbox" className="form-check mt-0.5" checked={values[name] === '1'} disabled={submitting} onChange={(e) => set(name, e.target.checked ? '1' : '')} />
                          <span>
                            <span className="block text-sm font-semibold text-brand-900">I need {title.toLowerCase()}</span>
                            <span className="block text-xs text-slate-500">{text}</span>
                          </span>
                        </label>
                      ))}
                    </div>
                  </div>
                </div>
              )}

              {/* ---------------- step 2: personal */}
              {current.key === 'personal' && (
                <div className="grid grid-cols-12 gap-5">
                  <Field {...fieldProps('first_name')} col={4} autoComplete="given-name" placeholder="e.g. Ananya" />
                  <Field {...fieldProps('middle_name')} col={4} autoComplete="additional-name" />
                  <Field {...fieldProps('last_name')} col={4} autoComplete="family-name" placeholder="e.g. Sharma" />
                  <div className="col-span-12">
                    <span className="form-label" id={fid('gender-label')}>
                      Gender<span className="form-required">{NBSP_STAR}</span>
                    </span>
                    <div className="flex flex-wrap gap-2" role="radiogroup" aria-labelledby={fid('gender-label')} aria-describedby={errors.gender ? fid('gender-err') : undefined}>
                      {options.genders.map((g, i) => (
                        <label
                          key={g.value}
                          className={clsx(
                            'inline-flex cursor-pointer items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-500/25',
                            values.gender === g.value ? 'border-brand-900 bg-brand-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-300',
                            errors.gender && values.gender !== g.value && 'border-red-300',
                          )}
                        >
                          <input
                            type="radio"
                            name="gender"
                            id={i === 0 ? fid('gender') : undefined}
                            value={g.value}
                            checked={values.gender === g.value}
                            disabled={submitting}
                            onChange={() => {
                              set('gender', g.value);
                              setErrors((prev) => {
                                const n = { ...prev };
                                delete n.gender;
                                return n;
                              });
                            }}
                            className="sr-only"
                          />
                          {g.label}
                        </label>
                      ))}
                    </div>
                    {errors.gender && (
                      <p id={fid('gender-err')} className="form-error">
                        {errors.gender}
                      </p>
                    )}
                  </div>
                  <Field {...fieldProps('dob')} col={4} type="date" autoComplete="bday" max={new Date(Date.now() - 14 * 31557600000).toISOString().slice(0, 10)} />
                  <Field {...fieldProps('blood_group')} col={4} options={opt(options.bloodGroups)} emptyLabel="Select (optional)" />
                  <Field {...fieldProps('nationality')} col={4} />
                  <Field {...fieldProps('aadhaar_no')} col={6} inputMode="numeric" placeholder="1234 5678 9012" help="Optional — speeds up document verification." />
                </div>
              )}

              {/* ---------------- step 3: contact */}
              {current.key === 'contact' && (
                <div className="grid grid-cols-12 gap-5">
                  <Field {...fieldProps('phone')} col={6} type="tel" inputMode="tel" autoComplete="tel" placeholder="+91 98765 43210" />
                  <div className="col-span-12 sm:col-span-6">
                    <Field {...fieldProps('whatsapp')} col={12} type="tel" inputMode="tel" disabled={submitting || values._wa_same === '1'} />
                    <label className="mt-2 flex items-center gap-2 text-xs text-slate-600">
                      <input type="checkbox" className="form-check" checked={values._wa_same === '1'} onChange={(e) => set('_wa_same', e.target.checked ? '1' : '')} />
                      Same as mobile number
                    </label>
                  </div>
                  <Field {...fieldProps('email')} col={12} type="email" inputMode="email" autoComplete="email" placeholder="name@example.com" help="Your application number and admission updates will be sent here." />
                  <Field {...fieldProps('address')} col={12} autoComplete="street-address" placeholder="House no., street, locality" />
                  <Field {...fieldProps('city')} col={3} autoComplete="address-level2" />
                  <Field {...fieldProps('state')} col={3} options={opt(options.states)} emptyLabel="Select state" />
                  <Field {...fieldProps('pincode')} col={3} inputMode="numeric" autoComplete="postal-code" placeholder="201310" />
                  <Field {...fieldProps('country')} col={3} autoComplete="country-name" />
                </div>
              )}

              {/* ---------------- step 4: parents */}
              {current.key === 'parents' && (
                <div className="grid grid-cols-12 gap-5">
                  <SubHeading icon={User}>Father</SubHeading>
                  <Field {...fieldProps('father_name')} col={6} />
                  <Field {...fieldProps('father_phone')} col={3} type="tel" inputMode="tel" />
                  <Field {...fieldProps('father_occupation')} col={3} list={fid('occupations')} />
                  <SubHeading icon={User}>Mother</SubHeading>
                  <Field {...fieldProps('mother_name')} col={6} />
                  <Field {...fieldProps('mother_phone')} col={3} type="tel" inputMode="tel" />
                  <Field {...fieldProps('mother_occupation')} col={3} list={fid('occupations')} />
                  <datalist id={fid('occupations')}>
                    {OCCUPATIONS.map((o) => (
                      <option key={o} value={o} />
                    ))}
                  </datalist>
                  <SubHeading icon={Users}>Local guardian &amp; income</SubHeading>
                  {showGuardian ? (
                    <>
                      <Field {...fieldProps('guardian_name')} col={4} />
                      <Field {...fieldProps('guardian_relation')} col={4} options={opt(options.relations)} emptyLabel="Select relation" />
                      <Field {...fieldProps('guardian_phone')} col={4} type="tel" inputMode="tel" />
                    </>
                  ) : (
                    <div className="col-span-12">
                      <button type="button" className="btn btn-secondary btn-sm" onClick={() => setShowGuardian(true)}>
                        <Users className="h-4 w-4" aria-hidden="true" />
                        Add a local guardian (optional)
                      </button>
                    </div>
                  )}
                  <Field {...fieldProps('family_income')} col={6} type="number" inputMode="numeric" prefix="₹" min="0" step="1000" placeholder="e.g. 600000" help="Optional — used for need-based scholarships." />
                </div>
              )}

              {/* ---------------- step 5: academic */}
              {current.key === 'academic' && (
                <div className="grid grid-cols-12 gap-5">
                  <SubHeading icon={BookOpen}>Class 10</SubHeading>
                  <Field {...fieldProps('tenth_board')} col={6} list={fid('boards')} placeholder="e.g. CBSE" />
                  <Field {...fieldProps('tenth_year')} col={3} options={tenthYears} emptyLabel="Year" />
                  <Field {...fieldProps('tenth_percentage')} col={3} type="number" inputMode="decimal" step="0.01" min="0" max="100" placeholder="e.g. 86.4" />
                  <datalist id={fid('boards')}>
                    {BOARDS.map((b) => (
                      <option key={b} value={b} />
                    ))}
                  </datalist>
                  <SubHeading icon={GraduationCap}>{isPG ? 'Graduation (qualifying exam)' : 'Class 12 / Diploma (qualifying exam)'}</SubHeading>
                  <Field {...fieldProps('previous_qualification')} col={4} options={options.qualifications} emptyLabel="Select" />
                  <Field {...fieldProps('previous_board')} col={4} list={isPG ? undefined : fid('boards')} placeholder={isPG ? 'e.g. CCS University' : 'e.g. CBSE'} />
                  <Field {...fieldProps('previous_institution')} col={4} />
                  <Field {...fieldProps('passing_year')} col={4} options={years} emptyLabel="Year" help="Appearing this year? Choose the expected year." />
                  <Field {...fieldProps('previous_percentage')} col={4} type="number" inputMode="decimal" step="0.01" min="0" max="100" placeholder="e.g. 78.2" />
                  <Field {...fieldProps('entrance_exam')} col={4} placeholder={isPG ? 'e.g. CAT 2025 — 82 %ile' : 'e.g. CUET 2026'} help="Optional" />
                </div>
              )}

              {/* ---------------- step 6: documents */}
              {current.key === 'documents' && (
                <div className="space-y-5">
                  <div className="grid gap-4 sm:grid-cols-2">
                    {visibleDocs.map((d) => (
                      <DocPicker
                        key={d.key}
                        id={fid(`doc_${d.key}`)}
                        doc={d}
                        required={docRequired(d)}
                        file={files[d.key]}
                        preview={previews[d.key]}
                        error={errors[`doc_${d.key}`]}
                        disabled={submitting}
                        onPick={pickFile}
                      />
                    ))}
                  </div>
                  <div className="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-100">
                    <div className="flex items-center justify-between text-xs text-slate-600">
                      <span>
                        Total size <strong className="text-slate-800">{humanSize(totalBytes)}</strong> of {humanSize(limits.total)}
                      </span>
                      <span className="flex items-center gap-1.5">
                        <Lock className="h-3.5 w-3.5 text-accent-600" aria-hidden="true" />
                        Stored privately
                      </span>
                    </div>
                    <span className="progress-track mt-2">
                      <span className="progress-fill" style={{ ['--progress' as string]: Math.min(1, totalBytes / limits.total), transitionDuration: isStatic ? '0ms' : '400ms' }} />
                    </span>
                    {errors.doc_other && <p className="form-error">{errors.doc_other}</p>}
                  </div>
                </div>
              )}

              {/* ---------------- step 7: review */}
              {current.key === 'review' && (
                <div className="space-y-4">
                  <ReviewBlock
                    title="Program"
                    icon={GraduationCap}
                    onEdit={() => goTo(0)}
                    rows={[
                      ['Program', program ? `${program.name} (${program.short})` : ''],
                      ['Specialisation', program?.courses.find((c) => String(c.id) === values.course_id)?.name],
                      ['Second preference', programs.find((p) => String(p.id) === values.second_program_id)?.name],
                      ['Category', label(options.categories, values.category)],
                      ['Admission quota', label(options.quotas, values.quota)],
                      ['Facilities', [values.hostel_required === '1' && 'Hostel', values.transport_required === '1' && 'Transport'].filter(Boolean).join(', ') || 'None'],
                    ]}
                  />
                  <ReviewBlock
                    title="Personal details"
                    icon={User}
                    onEdit={() => goTo(1)}
                    rows={[
                      ['Full name', [values.first_name, values.middle_name, values.last_name].filter(Boolean).join(' ')],
                      ['Gender', label(options.genders, values.gender)],
                      ['Date of birth', values.dob ? new Date(`${values.dob}T00:00:00`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : ''],
                      ['Blood group', values.blood_group],
                      ['Nationality', values.nationality],
                      ['Aadhaar', values.aadhaar_no ? `•••• •••• ${values.aadhaar_no.replace(/\s+/g, '').slice(-4)}` : ''],
                    ]}
                  />
                  <ReviewBlock
                    title="Contact & address"
                    icon={MapPin}
                    onEdit={() => goTo(2)}
                    rows={[
                      ['Mobile', values.phone],
                      ['WhatsApp', values.whatsapp],
                      ['Email', values.email],
                      ['Address', [values.address, values.city, values.state, values.pincode, values.country].filter(Boolean).join(', ')],
                    ]}
                  />
                  <ReviewBlock
                    title="Parents & guardian"
                    icon={Users}
                    onEdit={() => goTo(3)}
                    rows={[
                      ['Father', [values.father_name, values.father_phone, values.father_occupation].filter(Boolean).join(' · ')],
                      ['Mother', [values.mother_name, values.mother_phone, values.mother_occupation].filter(Boolean).join(' · ')],
                      ['Local guardian', [values.guardian_name, values.guardian_relation, values.guardian_phone].filter(Boolean).join(' · ')],
                      ['Annual family income', values.family_income ? `₹${Number(values.family_income).toLocaleString('en-IN')}` : ''],
                    ]}
                  />
                  <ReviewBlock
                    title="Academic history"
                    icon={BookOpen}
                    onEdit={() => goTo(4)}
                    rows={[
                      ['Class 10', [values.tenth_board, values.tenth_year, values.tenth_percentage && `${values.tenth_percentage}%`].filter(Boolean).join(' · ')],
                      ['Qualifying exam', [label(options.qualifications, values.previous_qualification), values.previous_board, values.passing_year, values.previous_percentage && `${values.previous_percentage}%`].filter(Boolean).join(' · ')],
                      ['School / College', values.previous_institution],
                      ['Entrance exam', values.entrance_exam],
                    ]}
                  />
                  <ReviewBlock
                    title="Documents"
                    icon={FileText}
                    onEdit={() => goTo(5)}
                    rows={visibleDocs.map((d) => [d.label, files[d.key] ? `${files[d.key]!.name} (${humanSize(files[d.key]!.size)})` : docRequired(d) ? 'Missing' : 'Not provided'] as [string, string])}
                  />
                  {program && (
                    <p className="flex items-start gap-2 rounded-2xl bg-accent-50 px-4 py-3 text-[13px] text-accent-800 ring-1 ring-accent-100">
                      <Sparkles className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                      <span>
                        There is no fee to apply online. The admission fee of <strong>{program.admissionFee}</strong> is payable only after your application is approved.
                      </span>
                    </p>
                  )}
                  <div>
                    <label className={clsx('flex cursor-pointer items-start gap-3 rounded-2xl border p-4 text-sm leading-relaxed text-slate-700', errors.declaration ? 'border-red-300 bg-red-50/50' : 'border-slate-200 bg-slate-50')}>
                      <input
                        id={fid('declaration')}
                        type="checkbox"
                        className={clsx('form-check mt-1', errors.declaration && 'is-invalid')}
                        checked={values.declaration === '1'}
                        disabled={submitting}
                        aria-invalid={errors.declaration ? true : undefined}
                        aria-describedby={errors.declaration ? fid('declaration-err') : undefined}
                        onChange={(e) => {
                          set('declaration', e.target.checked ? '1' : '');
                          if (e.target.checked)
                            setErrors((prev) => {
                              const n = { ...prev };
                              delete n.declaration;
                              return n;
                            });
                        }}
                      />
                      <span>
                        I declare that the information provided is true and correct to the best of my knowledge. I understand that my admission is subject to verification of
                        original documents and eligibility, and I agree to the{' '}
                        {links.terms ? (
                          <a className="link" href={links.terms} target="_blank" rel="noopener">
                            Terms &amp; Conditions
                          </a>
                        ) : (
                          'Terms & Conditions'
                        )}{' '}
                        and{' '}
                        {links.privacy ? (
                          <a className="link" href={links.privacy} target="_blank" rel="noopener">
                            Privacy Policy
                          </a>
                        ) : (
                          'Privacy Policy'
                        )}
                        .
                      </span>
                    </label>
                    {errors.declaration && (
                      <p id={fid('declaration-err')} className="form-error">
                        {errors.declaration}
                      </p>
                    )}
                  </div>
                </div>
              )}
            </StepPane>

            {alert && (
              <p className="form-alert mt-6" role="alert">
                <CircleAlert className="h-4 w-4 shrink-0" aria-hidden="true" />
                {alert}
              </p>
            )}
            {submitting && (
              <div className="mt-6" role="status">
                <div className="mb-1.5 flex justify-between text-xs font-semibold text-brand-900">
                  <span>{progress < 1 ? 'Uploading your application…' : 'Saving your application…'}</span>
                  <span>{Math.round(progress * 100)}%</span>
                </div>
                <span className="progress-track">
                  <span className="progress-fill" style={{ ['--progress' as string]: Math.max(0.03, progress), transitionDuration: '200ms' }} />
                </span>
              </div>
            )}
          </div>

          {/* footer nav */}
          <div className="flex flex-col-reverse gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <button type="button" className="btn btn-secondary" onClick={() => goTo(step - 1)} disabled={step === 0 || submitting}>
              <ArrowLeft className="h-4 w-4" aria-hidden="true" />
              Back
            </button>
            <span className="hidden items-center gap-1.5 text-xs text-slate-500 md:inline-flex" aria-live="off">
              <Save className="h-3.5 w-3.5" aria-hidden="true" />
              {savedAt ? `Draft saved on this device · ${timeAgo(savedAt)}` : 'Your progress is saved on this device'}
            </span>
            <button type="submit" className="btn btn-accent btn-shine" disabled={submitting}>
              {submitting ? (
                <>
                  <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />
                  Submitting…
                </>
              ) : current.key === 'review' ? (
                <>
                  Submit Application
                  <Check className="h-4 w-4" aria-hidden="true" />
                </>
              ) : (
                <>
                  <span>
                    Continue<span className="hidden sm:inline"> to {STEPS[step + 1].title}</span>
                  </span>
                  <ArrowRight className="h-4 w-4" aria-hidden="true" />
                </>
              )}
            </button>
          </div>
        </form>
        <p className="sr-only" role="status" aria-live="polite">
          {announce}
        </p>
      </div>

      {/* sidebar */}
      <aside className="space-y-5 lg:col-span-4" aria-label="Application summary and help">
        <div className="hidden overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-soft lg:block">
          {program ? (
            <>
              <div className="relative aspect-[16/9] overflow-hidden">
                <img src={program.image} alt="" className="h-full w-full object-cover" loading="lazy" />
                <span className="absolute inset-0 bg-gradient-to-t from-brand-950/85 via-brand-950/20 to-transparent" aria-hidden="true" />
                <span className="absolute left-4 top-4 rounded-full bg-white/90 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-900">{program.levelLabel}</span>
                <p className="absolute inset-x-4 bottom-3 font-display text-lg font-bold leading-snug text-white">{program.name}</p>
              </div>
              <dl className="grid grid-cols-2 gap-3 px-5 py-4 text-sm">
                <div>
                  <dt className="text-xs text-slate-500">Duration</dt>
                  <dd className="font-semibold text-slate-800">{program.duration}</dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">Tuition fee</dt>
                  <dd className="font-semibold text-slate-800">{program.fee}</dd>
                </div>
              </dl>
            </>
          ) : (
            <div className="flex flex-col items-center px-6 py-10 text-center">
              <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-800">
                <GraduationCap className="h-7 w-7" aria-hidden="true" />
              </span>
              <p className="mt-3 font-display font-bold text-brand-900">No program selected yet</p>
              <p className="mt-1 text-sm text-slate-500">Choose a program to see its fee and eligibility here.</p>
            </div>
          )}
          <div className="border-t border-slate-100 px-5 py-4">
            <div className="flex items-center justify-between text-xs font-semibold">
              <span className="text-slate-600">Application {completion}% complete</span>
              <span className="rounded-full bg-brand-50 px-2 py-0.5 text-brand-800">Session {session}</span>
            </div>
            <span className="progress-track mt-2">
              <span className="progress-fill" style={{ ['--progress' as string]: Math.max(0.02, completion / 100), transitionDuration: isStatic ? '0ms' : '500ms' }} />
            </span>
          </div>
        </div>

        <div className="hidden rounded-3xl border border-slate-100 bg-white p-5 shadow-soft lg:block">
          <h3 className="font-display text-base font-bold text-brand-900">Keep these ready</h3>
          <ul className="mt-3 space-y-2.5">
            {documents
              .filter((d) => d.when === 'always' || (d.when === 'pg' && isPG))
              .map((d) => (
                <li key={d.key} className="flex items-center gap-2.5 text-sm text-slate-700">
                  <span
                    className={clsx('inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full transition', files[d.key] ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-400')}
                    aria-hidden="true"
                  >
                    <Check className="h-3 w-3" />
                  </span>
                  <span className={clsx(files[d.key] && 'text-slate-500 line-through decoration-slate-300')}>{d.label}</span>
                  <span className="sr-only">{files[d.key] ? '(attached)' : '(not attached yet)'}</span>
                </li>
              ))}
          </ul>
        </div>

        <div className="relative isolate overflow-hidden rounded-3xl bg-brand-900 p-5 text-white shadow-soft">
          <div className="pattern-dots-light pattern-fade absolute inset-0 -z-10 opacity-50" aria-hidden="true" />
          <span className="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15">
            <Headset className="h-5 w-5 text-accent-300" aria-hidden="true" />
          </span>
          <h3 className="mt-3 font-display text-lg font-bold text-white">Need help with your application?</h3>
          <p className="mt-1 text-sm text-white/70">Our admission counsellors are available {help.hours || 'Monday to Saturday'}.</p>
          <ul className="mt-4 space-y-2 text-sm">
            <li>
              <a href={`tel:${help.phone.replace(/[^\d+]/g, '')}`} className="flex items-center gap-2.5 rounded-xl px-2 py-1.5 transition hover:bg-white/10">
                <Phone className="h-4 w-4 text-accent-300" aria-hidden="true" />
                {help.phone}
              </a>
            </li>
            {help.whatsapp && (
              <li>
                <a href={help.whatsapp} target="_blank" rel="noopener" className="flex items-center gap-2.5 rounded-xl px-2 py-1.5 transition hover:bg-white/10">
                  <MessageCircle className="h-4 w-4 text-accent-300" aria-hidden="true" />
                  Chat on WhatsApp
                </a>
              </li>
            )}
            <li>
              <a href={`mailto:${help.email}`} className="flex items-center gap-2.5 break-all rounded-xl px-2 py-1.5 transition hover:bg-white/10">
                <Mail className="h-4 w-4 shrink-0 text-accent-300" aria-hidden="true" />
                {help.email}
              </a>
            </li>
          </ul>
        </div>

        <p className="flex items-start gap-2.5 rounded-2xl bg-white/70 px-4 py-3 text-xs leading-relaxed text-slate-500 ring-1 ring-slate-100">
          <Lock className="mt-0.5 h-4 w-4 shrink-0 text-accent-600" aria-hidden="true" />
          <span>
            Your documents are stored privately and used only for admission verification. Prefer a single page?{' '}
            <a href={links.classic} className="font-semibold text-brand-700 underline-offset-2 hover:underline">
              Use the simple form
            </a>
            .
          </span>
        </p>
      </aside>
    </div>
  );
}
