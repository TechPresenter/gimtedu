import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the exams module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('examination', 'examination', () => import('./ExamsPage')),
  page('examination/:id', 'examination', () => import('./ExamDetailPage')),
  page('exam-schedule', 'examination', () => import('./ExamSchedulePage')),
  page('marks-entry', 'results', () => import('./MarksEntryPage')),
  page('results', 'results', () => import('./ResultsPage')),
  page('results/grades', 'results', () => import('./GradeScalePage')),
  page('marksheets', 'results', () => import('./MarksheetsPage')),
  page('certificates', 'certificates', () => import('./CertificatesPage')),
  page('certificates/templates', 'certificates', () => import('./CertificateTemplatesPage')),
];
