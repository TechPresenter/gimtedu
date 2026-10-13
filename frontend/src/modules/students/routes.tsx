import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the students module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('students', 'students', () => import('./StudentsPage')),
  page('students/new', 'students', () => import('./StudentFormPage'), 'create'),
  page('students/:id', 'students', () => import('./StudentProfilePage')),
  page('students/:id/edit', 'students', () => import('./StudentFormPage'), 'edit'),
  page('students/id-cards', 'students', () => import('./IdCardsPage')),
  page('students/promotion', 'students', () => import('./PromotionPage'), 'edit'),
];
