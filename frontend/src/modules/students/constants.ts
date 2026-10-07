import type { Option } from '@/lib/types';
import type { BadgeColor } from '@/lib/status';

/** Mirrors app/services/students.php option lists. */
export const STUDENT_STATUSES: Option[] = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'dropped', label: 'Dropped' },
  { value: 'graduated', label: 'Passed Out' },
  { value: 'alumni', label: 'Alumni' },
];
export const INACTIVE_STATUSES = ['inactive', 'suspended', 'dropped'];
export const STATUS_COLORS: Record<string, BadgeColor> = { graduated: 'blue', alumni: 'navy' };
export const statusLabel = (s: string | null | undefined) => STUDENT_STATUSES.find((o) => o.value === s)?.label ?? (s ? s.charAt(0).toUpperCase() + s.slice(1) : '—');

export const GENDERS: Option[] = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
];
export const BLOOD_GROUPS: Option[] = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'].map((b) => ({ value: b, label: b }));
export const CATEGORIES: Option[] = ['General', 'OBC', 'SC', 'ST', 'EWS'].map((c) => ({ value: c, label: c }));
export const RELIGIONS: Option[] = ['Hindu', 'Muslim', 'Sikh', 'Christian', 'Jain', 'Buddhist', 'Other', 'Prefer not to say'].map((c) => ({ value: c, label: c }));
export const ADMISSION_TYPES: Option[] = [
  { value: 'regular', label: 'Regular' },
  { value: 'lateral', label: 'Lateral Entry' },
  { value: 'management', label: 'Management Quota' },
  { value: 'scholarship', label: 'Scholarship' },
];
export const DOCUMENT_TYPES: Option[] = [
  { value: '10th_marksheet', label: '10th Marksheet' },
  { value: '12th_marksheet', label: '12th Marksheet' },
  { value: 'graduation', label: 'Graduation Marksheet' },
  { value: 'id_proof', label: 'ID Proof (Aadhaar)' },
  { value: 'photo', label: 'Passport Photo' },
  { value: 'transfer_certificate', label: 'Transfer Certificate' },
  { value: 'migration', label: 'Migration Certificate' },
  { value: 'caste_certificate', label: 'Caste Certificate' },
  { value: 'income_certificate', label: 'Income Certificate' },
  { value: 'other', label: 'Other' },
];
export const docTypeLabel = (t: string) => DOCUMENT_TYPES.find((d) => d.value === t)?.label ?? t;
/** Documents every student is expected to submit (PG programs also need the graduation marksheet). */
export const REQUIRED_DOCS = ['10th_marksheet', '12th_marksheet', 'id_proof', 'transfer_certificate'];

export const INDIAN_STATES = [
  'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala',
  'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
  'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir',
  'Ladakh', 'Lakshadweep', 'Puducherry',
];
