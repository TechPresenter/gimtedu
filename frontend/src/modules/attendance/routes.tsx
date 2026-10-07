import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the attendance module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('attendance', 'attendance', () => import('./StudentAttendancePage')),
  page('attendance/employees', 'attendance', () => import('./EmployeeAttendancePage')),
  page('attendance/reports', 'attendance', () => import('./AttendanceReportsPage')),
];
