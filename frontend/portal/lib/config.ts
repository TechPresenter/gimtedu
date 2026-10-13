import { BASE_PATH, appUrl } from '@/lib/config';

/** Student / faculty area of the portal. */
export type Area = 'student' | 'faculty';

/** React Router basename of the portal SPA, e.g. "/portal" or "/gimt/portal". */
export const PORTAL_BASE = `${BASE_PATH}/portal`;

export const AREA_LABEL: Record<Area, string> = { student: 'Student Portal', faculty: 'Faculty Portal' };

/** Area of a browser pathname ("/portal/student/..." -> 'student'). */
export function areaFromPath(pathname: string): Area | null {
  const rest = pathname.startsWith(PORTAL_BASE) ? pathname.slice(PORTAL_BASE.length) : pathname;
  if (/^\/student(\/|$)/.test(rest)) return 'student';
  if (/^\/faculty(\/|$)/.test(rest)) return 'faculty';
  return null;
}

/** In-app path inside an area: areaPath('student', 'profile') -> "/student/profile". */
export function areaPath(area: Area, path = ''): string {
  const p = path.replace(/^\/+/, '');
  return `/${area}${p ? `/${p}` : ''}`;
}

/** Bundled campus photography used by the auth screens. */
export const PHOTOS = {
  student: appUrl('assets/images/site/students-laptop.jpg'),
  faculty: appUrl('assets/images/site/campus-building.jpg'),
  landing: appUrl('assets/images/site/students-lawn.jpg'),
};

export { appUrl };
