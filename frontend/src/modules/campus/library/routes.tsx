import type { RouteObject } from 'react-router-dom';
import { page } from '../../shared';

/** Extra routes owned by the library unit (paths are relative to /admin). */
export const routes: RouteObject[] = [page('library/books/:id', 'library', () => import('./BookDetailPage'))];
