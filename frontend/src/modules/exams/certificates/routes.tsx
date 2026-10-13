import type { RouteObject } from 'react-router-dom';
import { page } from '../../shared';

/**
 * Extra routes owned by the certificates unit (paths relative to /admin). The list (/certificates) and the template
 * gallery (/certificates/templates) are registered in ../routes.tsx.
 */
export const routes: RouteObject[] = [
  page('certificates/templates/new', 'certificates', () => import('./TemplateEditorPage'), 'create'),
  page('certificates/templates/:id', 'certificates', () => import('./TemplateEditorPage')),
];
