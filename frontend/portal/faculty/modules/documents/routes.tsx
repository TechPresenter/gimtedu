import { lazy } from 'react';
import { FolderLock } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const DocumentsPage = lazy(() => import('./DocumentsPage'));

export const routes: PortalRoute[] = [
  { path: 'documents', element: <DocumentsPage />, nav: { label: 'My Documents', short: 'Documents', icon: FolderLock, order: 60, group: 'Office' } },
];
