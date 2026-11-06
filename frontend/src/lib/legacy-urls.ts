/**
 * Supports classic PHP-style admin URLs (/admin/students.php, /admin/index.php, /admin/fee-collection.php) by
 * rewriting them to the SPA routes (/admin/students, /admin/, /admin/fees/collect) before the router initialises.
 * Names that match an SPA route directly need no entry; ALIASES covers the usual classic names that differ.
 * Imported first in main.tsx.
 */
const ALIASES: Record<string, string> = {
  index: '',
  home: '',
  dashboard: '',
  student: 'students',
  'add-student': 'students/new',
  'id-cards': 'students/id-cards',
  promotion: 'students/promotion',
  admission: 'admissions',
  applications: 'admissions/list',
  'new-admission': 'admissions/new',
  enquiry: 'enquiries',
  employees: 'staff',
  leave: 'leaves',
  sessions: 'academics?tab=sessions',
  departments: 'academics?tab=departments',
  programs: 'academics?tab=programs',
  courses: 'academics?tab=courses',
  semesters: 'academics?tab=semesters',
  subjects: 'academics?tab=subjects',
  sections: 'academics?tab=sections',
  classrooms: 'academics?tab=rooms',
  'fee-collection': 'fees/collect',
  'collect-fee': 'fees/collect',
  'collect-fees': 'fees/collect',
  invoices: 'fees/invoices',
  payments: 'fees/payments',
  receipts: 'fees/payments',
  refunds: 'fees/refunds',
  scholarships: 'fees/scholarships',
  'fee-structures': 'fees/structures',
  'payment-reports': 'fees/reports',
  exam: 'examination',
  exams: 'examination',
  marks: 'marks-entry',
  marksheet: 'marksheets',
  certificate: 'certificates',
  books: 'library/books',
  circulation: 'library/circulation',
  vehicles: 'transport/vehicles',
  routes: 'transport/routes',
  companies: 'placement/companies',
  drives: 'placement/drives',
  offers: 'placement/offers',
  notice: 'notices',
  circulars: 'notices',
  contact: 'contact-messages',
  contacts: 'contact-messages',
  complaints: 'feedback',
  pages: 'cms/pages',
  menus: 'cms/menus',
  homepage: 'cms/homepage',
  banners: 'cms/banners',
  sliders: 'cms/banners',
  blog: 'cms/blog',
  news: 'cms/blog',
  gallery: 'cms/gallery',
  media: 'cms/media',
  faqs: 'cms/faqs',
  testimonials: 'cms/testimonials',
  announcements: 'cms/announcements',
  seo: 'cms/seo',
  logs: 'activity-logs',
  'activity-log': 'activity-logs',
  permissions: 'roles',
  backups: 'backup',
  analytics: 'reports',
};

const m = window.location.pathname.match(/^(.*\/admin)\/([a-z0-9-]+)\.php$/i);
if (m) {
  const name = m[2].toLowerCase();
  const target = name in ALIASES ? ALIASES[name] : m[2];
  const [route, query] = target.split('?');
  const search = [query, window.location.search.replace(/^\?/, '')].filter(Boolean).join('&');
  window.history.replaceState(window.history.state, '', `${m[1]}/${route}${search ? `?${search}` : ''}${window.location.hash}`);
}
export {};
