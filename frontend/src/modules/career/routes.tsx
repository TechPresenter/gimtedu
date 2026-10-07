import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';
import { routes as placementExtra } from './placement/routes';
import { routes as alumniExtra } from './alumni/routes';

/** Routes owned by the career module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('placement', 'placement', () => import('./PlacementDashboardPage')),
  page('placement/companies', 'placement', () => import('./CompaniesPage')),
  page('placement/drives', 'placement', () => import('./DrivesPage')),
  page('placement/applications', 'placement', () => import('./PlacementApplicationsPage')),
  page('placement/offers', 'placement', () => import('./OffersPage')),
  page('placement/training', 'placement', () => import('./TrainingPage')),
  page('alumni', 'alumni', () => import('./AlumniPage')),
  page('alumni/events', 'alumni', () => import('./AlumniEventsPage')),
  page('alumni/jobs', 'alumni', () => import('./AlumniJobsPage')),
  page('alumni/stories', 'alumni', () => import('./AlumniStoriesPage')),
  page('alumni/donations', 'alumni', () => import('./AlumniDonationsPage')),
  ...placementExtra, ...alumniExtra,
];
