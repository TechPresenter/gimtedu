import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the academics module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('academics', 'academics', () => import('./AcademicsPage')),
  page('timetable', 'timetable', () => import('./TimetablePage')),
];
