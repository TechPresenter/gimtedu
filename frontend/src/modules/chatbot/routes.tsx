import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** AI chatbot administration (Settings · Conversations · Analytics) — /admin/chatbot?tab=settings|conversations|analytics */
export const routes: RouteObject[] = [page('chatbot', 'communication', () => import('./ChatbotPage'))];
