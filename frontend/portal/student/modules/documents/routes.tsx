import { lazy } from 'react';
import { FolderOpen } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const DocumentsPage = lazy(() => import('./DocumentsPage'));

export const routes: PortalRoute[] = [
  { path: 'documents', element: <DocumentsPage />, nav: { label: 'My Documents', short: 'Documents', icon: FolderOpen, order: 905, group: 'Account' } },
];
