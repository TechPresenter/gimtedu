import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/**
 * WhatsApp Automation & Student Communication (Interakt). Paths are relative to /admin; permission keys are the
 * whatsapp* modules in app/registry.php. Each feature folder is owned by one part of the build (see docs/WHATSAPP.md).
 */
export const routes: RouteObject[] = [
  // the dashboard is also for report and settings viewers (GET whatsapp/dashboard allows any of the three; it hides
  // chat content from users without inbox access)
  page('whatsapp', ['whatsapp', 'whatsapp_reports', 'whatsapp_settings'], () => import('./dashboard/DashboardPage')),
  page('whatsapp/inbox', 'whatsapp', () => import('./inbox/InboxPage')),
  page('whatsapp/contacts', 'whatsapp_contacts', () => import('./contacts/ContactsPage')),
  page('whatsapp/contacts/:id', 'whatsapp_contacts', () => import('./contacts/ContactDetailPage')),
  page('whatsapp/leads', 'whatsapp_contacts', () => import('./leads/LeadsPage')),
  page('whatsapp/followups', 'whatsapp_contacts', () => import('./followups/FollowupManagerPage')),
  page('whatsapp/templates/designer', 'whatsapp_templates', () => import('./templates/TemplateDesignerPage'), 'create'),
  page('whatsapp/templates/designer/:id', 'whatsapp_templates', () => import('./templates/TemplateDesignerPage'), 'edit'),
  page('whatsapp/templates', 'whatsapp_templates', () => import('./templates/TemplateLibraryPage')),
  page('whatsapp/campaigns', 'whatsapp_campaigns', () => import('./campaigns/CampaignsPage')),
  page('whatsapp/campaigns/new', 'whatsapp_campaigns', () => import('./campaigns/CampaignEditorPage'), 'create'),
  // 'view': creators may edit their own drafts and publishers send from the Review step — CampaignEditorPage enforces that
  page('whatsapp/campaigns/:id/edit', 'whatsapp_campaigns', () => import('./campaigns/CampaignEditorPage'), 'view'),
  page('whatsapp/campaigns/:id', 'whatsapp_campaigns', () => import('./campaigns/CampaignDetailPage')),
  page('whatsapp/automations', 'whatsapp_automation', () => import('./automation/WorkflowsPage')),
  page('whatsapp/automations/:id', 'whatsapp_automation', () => import('./automation/WorkflowBuilderPage')),
  page('whatsapp/events', 'whatsapp_automation', () => import('./events/EventManagerPage')),
  page('whatsapp/chatbot', 'whatsapp_automation', () => import('./bot/ChatbotPage')),
  page('whatsapp/reports', 'whatsapp_reports', () => import('./reports/ReportsPage')),
  page('whatsapp/integrations', 'whatsapp_settings', () => import('./admin/IntegrationsPage')),
  page('whatsapp/api', 'whatsapp_settings', () => import('./admin/ApiWebhooksPage')),
  page('whatsapp/team', 'whatsapp_settings', () => import('./admin/TeamPage')),
  page('whatsapp/settings', 'whatsapp_settings', () => import('./admin/SettingsPage')),
];
