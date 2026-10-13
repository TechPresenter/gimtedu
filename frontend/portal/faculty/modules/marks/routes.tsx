import { lazy } from 'react';
import { NotebookPen } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const MarksPage = lazy(() => import('./MarksPage'));
const MarksEntryPage = lazy(() => import('./MarksEntryPage'));

export const routes: PortalRoute[] = [
  { path: 'marks', element: <MarksPage />, nav: { label: 'Marks Entry', short: 'Marks', icon: NotebookPen, order: 30, group: 'Teaching', mobile: true } },
  { path: 'marks/:examId/:subjectId', element: <MarksEntryPage /> },
];
