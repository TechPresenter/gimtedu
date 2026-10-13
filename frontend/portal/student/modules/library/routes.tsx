import { lazy } from 'react';
import { Library } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const LibraryPage = lazy(() => import('./LibraryPage'));

export const routes: PortalRoute[] = [
  { path: 'library', element: <LibraryPage />, nav: { label: 'Library', icon: Library, order: 60, group: 'Campus' } },
];
