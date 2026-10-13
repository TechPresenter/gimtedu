import { lazy } from 'react';
import { MessageSquareText } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const HelpdeskPage = lazy(() => import('./HelpdeskPage'));

export const routes: PortalRoute[] = [
  { path: 'helpdesk', element: <HelpdeskPage />, nav: { label: 'Grievances & Feedback', short: 'Help desk', icon: MessageSquareText, order: 620, group: 'Services' } },
];
