import { lazy } from 'react';
import { ScrollText } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const CertificatesPage = lazy(() => import('./CertificatesPage'));

export const routes: PortalRoute[] = [
  { path: 'certificates', element: <CertificatesPage />, nav: { label: 'Certificates', icon: ScrollText, order: 600, group: 'Services' } },
];
