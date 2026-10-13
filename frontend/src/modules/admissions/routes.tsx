import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the admissions module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('admissions', 'admissions', () => import('./AdmissionsPipelinePage')),
  page('admissions/list', 'admissions', () => import('./ApplicationsPage')),
  page('admissions/new', 'admissions', () => import('./AdmissionFormPage'), 'create'),
  page('admissions/:id', 'admissions', () => import('./AdmissionViewPage')),
  page('admissions/:id/edit', 'admissions', () => import('./AdmissionFormPage'), 'edit'),
  page('enquiries', 'enquiries', () => import('./EnquiriesPage')),
  page('contact-messages', 'contact_messages', () => import('./ContactMessagesPage')),
  page('feedback', 'feedback', () => import('./FeedbackPage')),
];
