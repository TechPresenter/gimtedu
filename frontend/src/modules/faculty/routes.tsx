import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the faculty module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('faculty', 'faculty', () => import('./FacultyPage')),
  page('faculty/:id', 'faculty', () => import('./FacultyProfilePage')),
  page('staff', 'faculty', () => import('./StaffPage')),
  page('staff/:id', 'faculty', () => import('./StaffProfilePage')),
  page('leaves', 'faculty', () => import('./LeavesPage')),
];
