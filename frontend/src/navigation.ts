import {
  Award, BadgeCheck, BedDouble, Bot, BriefcaseBusiness, Bus, CalendarCheck, CalendarClock, CalendarDays, CalendarRange, ChartColumn, ChartNoAxesCombined, ChartPie,
  ClipboardList, Contact, Database, Globe, GraduationCap, HandCoins, History, IndianRupee, Inbox, LayoutDashboard, LibraryBig, Library, Mail, Megaphone,
  MessageCircle, MessageSquare, MessagesSquare, PencilRuler, Plug, School, Send, Settings, Settings2, ShieldCheck, Target, UserCog, UserPlus, Users,
  UsersRound, Wallet, Webhook, Workflow, Zap, type LucideIcon,
} from 'lucide-react';
import type { PermissionAction } from '@/lib/auth';

/** A permission module key, or a list of keys of which the user needs the action on at least one. */
export type PermSpec = string | readonly string[];

/** True when `can` allows `action` on the module (on any of them for a list). */
export function permAllowed(can: (module: string, action?: PermissionAction) => boolean, perm: PermSpec, action: PermissionAction = 'view'): boolean {
  return typeof perm === 'string' ? can(perm, action) : perm.some((m) => can(m, action));
}

export interface NavChild {
  label: string;
  to: string;
  perm: PermSpec;
  action?: PermissionAction;
}

export interface NavItem {
  key: string;
  label: string;
  icon: LucideIcon;
  to?: string;
  perm: PermSpec;
  action?: PermissionAction;
  children?: NavChild[];
  /** extra path prefixes that should mark the item active */
  match?: string[];
}

export interface NavGroup {
  group: string;
  items: NavItem[];
}

/** Admin sidebar navigation. Permission keys must exist in app/registry.php permission_modules(). */
export const NAVIGATION: NavGroup[] = [
  { group: 'Main', items: [{ key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, to: '/', perm: 'dashboard' }] },
  {
    group: 'Academic',
    items: [
      {
        key: 'admissions', label: 'Admissions', icon: UserPlus, perm: 'admissions', match: ['/admissions'],
        children: [
          { label: 'Admission Pipeline', to: '/admissions', perm: 'admissions' },
          { label: 'All Applications', to: '/admissions/list', perm: 'admissions' },
          { label: 'New Application', to: '/admissions/new', perm: 'admissions', action: 'create' },
          { label: 'Enquiries', to: '/enquiries', perm: 'enquiries' },
        ],
      },
      {
        key: 'students', label: 'Students', icon: GraduationCap, perm: 'students', match: ['/students'],
        children: [
          { label: 'All Students', to: '/students', perm: 'students' },
          { label: 'Add Student', to: '/students/new', perm: 'students', action: 'create' },
          { label: 'ID Cards', to: '/students/id-cards', perm: 'students' },
          { label: 'Promote Students', to: '/students/promotion', perm: 'students', action: 'edit' },
        ],
      },
      {
        key: 'faculty', label: 'Faculty & Staff', icon: Users, perm: 'faculty', match: ['/faculty', '/staff', '/leaves'],
        children: [
          { label: 'Faculty', to: '/faculty', perm: 'faculty' },
          { label: 'Staff', to: '/staff', perm: 'faculty' },
          { label: 'Leave Management', to: '/leaves', perm: 'faculty' },
        ],
      },
      {
        key: 'academics', label: 'Academics', icon: School, perm: 'academics', match: ['/academics'],
        children: [
          { label: 'Overview', to: '/academics', perm: 'academics' },
          { label: 'Academic Sessions', to: '/academics?tab=sessions', perm: 'academics' },
          { label: 'Departments', to: '/academics?tab=departments', perm: 'academics' },
          { label: 'Programs', to: '/academics?tab=programs', perm: 'academics' },
          { label: 'Courses / Specializations', to: '/academics?tab=courses', perm: 'academics' },
          { label: 'Semesters', to: '/academics?tab=semesters', perm: 'academics' },
          { label: 'Subjects', to: '/academics?tab=subjects', perm: 'academics' },
          { label: 'Sections & Batches', to: '/academics?tab=sections', perm: 'academics' },
          { label: 'Classrooms & Time Slots', to: '/academics?tab=rooms', perm: 'academics' },
          { label: 'Faculty Assignments', to: '/academics?tab=assignments', perm: 'academics' },
        ],
      },
      { key: 'timetable', label: 'Timetable', icon: CalendarDays, to: '/timetable', perm: 'timetable' },
      {
        key: 'attendance', label: 'Attendance', icon: CalendarCheck, perm: 'attendance', match: ['/attendance'],
        children: [
          { label: 'Student Attendance', to: '/attendance', perm: 'attendance' },
          { label: 'Faculty & Staff Attendance', to: '/attendance/employees', perm: 'attendance' },
          { label: 'Attendance Reports', to: '/attendance/reports', perm: 'attendance' },
          { label: 'Attendance Defaulters', to: '/attendance/reports?tab=defaulters', perm: 'attendance' },
        ],
      },
      {
        key: 'examination', label: 'Examination', icon: ClipboardList, perm: 'examination', match: ['/examination', '/exam-schedule', '/marks-entry'],
        children: [
          { label: 'Exams', to: '/examination', perm: 'examination' },
          { label: 'Exam Schedule', to: '/exam-schedule', perm: 'examination' },
          { label: 'Marks Entry', to: '/marks-entry', perm: 'results' },
        ],
      },
      {
        key: 'results', label: 'Results & Marksheet', icon: Award, perm: 'results', match: ['/results', '/marksheets'],
        children: [
          { label: 'Results', to: '/results', perm: 'results' },
          { label: 'Marksheets', to: '/marksheets', perm: 'results' },
          { label: 'Grading Scale', to: '/results/grades', perm: 'results' },
        ],
      },
      {
        key: 'certificates', label: 'Certificates', icon: BadgeCheck, perm: 'certificates', match: ['/certificates'],
        children: [
          { label: 'Issued Certificates', to: '/certificates', perm: 'certificates' },
          { label: 'Certificate Templates', to: '/certificates/templates', perm: 'certificates' },
        ],
      },
    ],
  },
  {
    group: 'Finance',
    items: [
      {
        key: 'fees', label: 'Fees & Accounts', icon: IndianRupee, perm: 'fees', match: ['/fees'],
        children: [
          { label: 'Fee Dashboard', to: '/fees', perm: 'fees' },
          { label: 'Fee Structures', to: '/fees/structures', perm: 'fees' },
          { label: 'Student Fees / Invoices', to: '/fees/invoices', perm: 'fees' },
          { label: 'Payments', to: '/fees/payments', perm: 'fees' },
          { label: 'Scholarships & Discounts', to: '/fees/scholarships', perm: 'fees' },
          { label: 'Refunds', to: '/fees/refunds', perm: 'fees' },
        ],
      },
      { key: 'fee-collection', label: 'Fee Collection', icon: HandCoins, to: '/fees/collect', perm: 'fees', action: 'create' },
      { key: 'expenses', label: 'Expenses', icon: Wallet, to: '/expenses', perm: 'expenses' },
      { key: 'fee-reports', label: 'Payment Reports', icon: ChartColumn, to: '/fees/reports', perm: 'fees' },
    ],
  },
  {
    group: 'Campus',
    items: [
      {
        key: 'library', label: 'Library', icon: Library, perm: 'library', match: ['/library'],
        children: [
          { label: 'Library Dashboard', to: '/library', perm: 'library' },
          { label: 'Books & Copies', to: '/library/books', perm: 'library' },
          { label: 'Issue / Return', to: '/library/circulation', perm: 'library' },
          { label: 'Members', to: '/library/members', perm: 'library' },
          { label: 'Fines', to: '/library/fines', perm: 'library' },
        ],
      },
      {
        key: 'hostel', label: 'Hostel', icon: BedDouble, perm: 'hostel', match: ['/hostel'],
        children: [
          { label: 'Hostel Dashboard', to: '/hostel', perm: 'hostel' },
          { label: 'Hostels & Rooms', to: '/hostel/rooms', perm: 'hostel' },
          { label: 'Room Allocation', to: '/hostel/allocations', perm: 'hostel' },
          { label: 'Complaints', to: '/hostel/complaints', perm: 'hostel' },
          { label: 'Visitors', to: '/hostel/visitors', perm: 'hostel' },
        ],
      },
      {
        key: 'transport', label: 'Transport', icon: Bus, perm: 'transport', match: ['/transport'],
        children: [
          { label: 'Transport Dashboard', to: '/transport', perm: 'transport' },
          { label: 'Vehicles', to: '/transport/vehicles', perm: 'transport' },
          { label: 'Routes & Stops', to: '/transport/routes', perm: 'transport' },
          { label: 'Drivers', to: '/transport/drivers', perm: 'transport' },
          { label: 'Allocations', to: '/transport/allocations', perm: 'transport' },
          { label: 'Fuel & Maintenance', to: '/transport/maintenance', perm: 'transport' },
        ],
      },
    ],
  },
  {
    group: 'Career',
    items: [
      {
        key: 'placement', label: 'Placement', icon: BriefcaseBusiness, perm: 'placement', match: ['/placement'],
        children: [
          { label: 'Placement Dashboard', to: '/placement', perm: 'placement' },
          { label: 'Companies', to: '/placement/companies', perm: 'placement' },
          { label: 'Placement Drives', to: '/placement/drives', perm: 'placement' },
          { label: 'Applications & Interviews', to: '/placement/applications', perm: 'placement' },
          { label: 'Offers', to: '/placement/offers', perm: 'placement' },
          { label: 'Training & Mock Tests', to: '/placement/training', perm: 'placement' },
        ],
      },
      {
        key: 'alumni', label: 'Alumni', icon: UsersRound, perm: 'alumni', match: ['/alumni'],
        children: [
          { label: 'Alumni Directory', to: '/alumni', perm: 'alumni' },
          { label: 'Mentorships', to: '/alumni?tab=mentors', perm: 'alumni' },
          { label: 'Alumni Events', to: '/alumni/events', perm: 'alumni' },
          { label: 'Job Opportunities', to: '/alumni/jobs', perm: 'alumni' },
          { label: 'Success Stories', to: '/alumni/stories', perm: 'alumni' },
          { label: 'Donations', to: '/alumni/donations', perm: 'alumni' },
        ],
      },
    ],
  },
  {
    group: 'Communication',
    items: [
      { key: 'notices', label: 'Notices & Circulars', icon: Megaphone, to: '/notices', perm: 'notices' },
      {
        key: 'communication', label: 'Newsletter / Email', icon: Mail, perm: 'communication', match: ['/newsletter', '/email-templates', '/message-logs'],
        children: [
          { label: 'Campaigns', to: '/newsletter', perm: 'communication' },
          { label: 'Subscribers', to: '/newsletter/subscribers', perm: 'communication' },
          { label: 'Email Templates', to: '/email-templates', perm: 'communication' },
          { label: 'Message Logs', to: '/message-logs', perm: 'communication' },
        ],
      },
      { key: 'events', label: 'Events', icon: CalendarRange, to: '/events', perm: 'events' },
      { key: 'enquiries', label: 'Enquiries', icon: MessageCircle, to: '/enquiries', perm: 'enquiries' },
      { key: 'chatbot', label: 'AI Chatbot', icon: Bot, to: '/chatbot', perm: 'communication' },
      { key: 'contact_messages', label: 'Contact Messages', icon: Inbox, to: '/contact-messages', perm: 'contact_messages' },
      { key: 'feedback', label: 'Feedback & Complaints', icon: MessageSquare, to: '/feedback', perm: 'feedback' },
    ],
  },
  {
    // WhatsApp Automation & Student Communication (Interakt) — frontend/src/modules/whatsapp
    group: 'WhatsApp',
    items: [
      { key: 'wa-dashboard', label: 'WhatsApp Dashboard', icon: ChartPie, to: '/whatsapp', perm: ['whatsapp', 'whatsapp_reports', 'whatsapp_settings'] },
      { key: 'wa-inbox', label: 'WhatsApp Inbox', icon: MessagesSquare, to: '/whatsapp/inbox', perm: 'whatsapp' },
      { key: 'wa-contacts', label: 'Student Contacts', icon: Contact, to: '/whatsapp/contacts', perm: 'whatsapp_contacts' },
      { key: 'wa-leads', label: 'Admission Leads', icon: Target, to: '/whatsapp/leads', perm: 'whatsapp_contacts' },
      { key: 'wa-designer', label: 'Template Designer', icon: PencilRuler, to: '/whatsapp/templates/designer', perm: 'whatsapp_templates', action: 'create' },
      { key: 'wa-templates', label: 'Template Library', icon: LibraryBig, to: '/whatsapp/templates', perm: 'whatsapp_templates', match: ['/whatsapp/templates/'] },
      { key: 'wa-campaigns', label: 'Campaign Manager', icon: Send, to: '/whatsapp/campaigns', perm: 'whatsapp_campaigns' },
      { key: 'wa-automations', label: 'Automation Builder', icon: Workflow, to: '/whatsapp/automations', perm: 'whatsapp_automation' },
      { key: 'wa-events', label: 'Event Manager', icon: Zap, to: '/whatsapp/events', perm: 'whatsapp_automation' },
      { key: 'wa-bot', label: 'AI Chatbot', icon: Bot, to: '/whatsapp/chatbot', perm: 'whatsapp_automation' },
      { key: 'wa-followups', label: 'Follow-up Manager', icon: CalendarClock, to: '/whatsapp/followups', perm: 'whatsapp_contacts' },
      { key: 'wa-reports', label: 'Reports & Analytics', icon: ChartNoAxesCombined, to: '/whatsapp/reports', perm: 'whatsapp_reports' },
      { key: 'wa-integrations', label: 'Integrations', icon: Plug, to: '/whatsapp/integrations', perm: 'whatsapp_settings' },
      { key: 'wa-api', label: 'API & Webhooks', icon: Webhook, to: '/whatsapp/api', perm: 'whatsapp_settings' },
      { key: 'wa-team', label: 'Team Management', icon: UsersRound, to: '/whatsapp/team', perm: 'whatsapp_settings' },
      { key: 'wa-settings', label: 'WhatsApp Settings', icon: Settings2, to: '/whatsapp/settings', perm: 'whatsapp_settings' },
    ],
  },
  {
    group: 'Website',
    items: [
      {
        key: 'cms', label: 'Website CMS', icon: Globe, perm: 'cms', match: ['/cms'],
        children: [
          { label: 'CMS Overview', to: '/cms', perm: 'cms' },
          { label: 'Pages & Page Builder', to: '/cms/pages', perm: 'cms' },
          { label: 'Menus', to: '/cms/menus', perm: 'cms' },
          { label: 'Home Page Sections', to: '/cms/homepage', perm: 'cms' },
          { label: 'Banners / Sliders', to: '/cms/banners', perm: 'cms' },
          { label: 'Blog / News', to: '/cms/blog', perm: 'blog' },
          { label: 'Blog Categories', to: '/cms/blog?tab=categories', perm: 'blog' },
          { label: 'Media Library', to: '/cms/media', perm: 'media' },
          { label: 'Gallery', to: '/cms/gallery', perm: 'media' },
          { label: 'FAQs', to: '/cms/faqs', perm: 'cms' },
          { label: 'Testimonials', to: '/cms/testimonials', perm: 'cms' },
          { label: 'Announcements', to: '/cms/announcements', perm: 'cms' },
          { label: 'SEO Management', to: '/cms/seo', perm: 'seo' },
          { label: 'Redirects', to: '/redirects', perm: 'seo' },
        ],
      },
    ],
  },
  {
    group: 'System',
    items: [
      { key: 'reports', label: 'Reports & Analytics', icon: ChartPie, to: '/reports', perm: 'reports' },
      {
        key: 'users', label: 'Users & Roles', icon: UserCog, perm: 'users', match: ['/users', '/roles'],
        children: [
          { label: 'Users', to: '/users', perm: 'users' },
          { label: 'Roles & Permissions', to: '/roles', perm: 'roles' },
        ],
      },
      { key: 'portal_access', label: 'Portal Access', icon: BadgeCheck, to: '/portal-access', perm: 'users', action: 'manage' },
      { key: 'activity_logs', label: 'Activity Logs', icon: History, to: '/activity-logs', perm: 'activity_logs' },
      { key: 'settings', label: 'System Settings', icon: Settings, to: '/settings', perm: 'settings' },
      { key: 'backup', label: 'Backup', icon: Database, to: '/backup', perm: 'backup' },
      { key: 'security', label: 'Security', icon: ShieldCheck, to: '/security', perm: 'security' },
    ],
  },
];
