import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the campus module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('library', 'library', () => import('./LibraryDashboardPage')),
  page('library/books', 'library', () => import('./BooksPage')),
  page('library/circulation', 'library', () => import('./CirculationPage')),
  page('library/members', 'library', () => import('./LibraryMembersPage')),
  page('library/fines', 'library', () => import('./LibraryFinesPage')),
  page('hostel', 'hostel', () => import('./HostelDashboardPage')),
  page('hostel/rooms', 'hostel', () => import('./HostelRoomsPage')),
  page('hostel/allocations', 'hostel', () => import('./HostelAllocationsPage')),
  page('hostel/complaints', 'hostel', () => import('./HostelComplaintsPage')),
  page('hostel/visitors', 'hostel', () => import('./HostelVisitorsPage')),
  page('transport', 'transport', () => import('./TransportDashboardPage')),
  page('transport/vehicles', 'transport', () => import('./VehiclesPage')),
  page('transport/routes', 'transport', () => import('./RoutesPage')),
  page('transport/drivers', 'transport', () => import('./DriversPage')),
  page('transport/allocations', 'transport', () => import('./TransportAllocationsPage')),
  page('transport/maintenance', 'transport', () => import('./MaintenancePage')),
];
