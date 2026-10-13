/** Types for the Website CMS (unit cms_site). Shapes mirror app/services/cms.php and docs/CMS.md. */
import type { Option } from '@/lib/types';

export type ShapeFieldType =
  | 'text' | 'textarea' | 'richtext' | 'url' | 'link' | 'image' | 'select' | 'multiselect' | 'number' | 'toggle' | 'icon' | 'list'
  | 'repeater' | 'programs' | 'department' | 'gallery';

export interface ShapeField {
  type: ShapeFieldType;
  name: string;
  label: string;
  required?: boolean;
  max?: number;
  min?: number;
  step?: number;
  col?: number;
  rows?: number;
  placeholder?: string;
  help?: string;
  options?: Option[];
  default?: unknown;
  when?: Record<string, unknown>;
  fields?: ShapeField[];
  min_items?: number;
  max_items?: number;
  item_label?: string;
  item_max?: number;
  pattern?: string;
}

export interface SectionDef {
  key: string;
  label: string;
  icon: string;
  description: string;
  title_label: string;
  subtitle_label: string;
  title_required?: boolean;
  fields: ShapeField[];
}

export interface BlockDef {
  type: string;
  label: string;
  icon: string;
  group: string;
  description: string;
  fields: ShapeField[];
  default: Record<string, unknown>;
}

export interface MenuLocation {
  key: string;
  label: string;
  short: string;
  description: string;
  depth: number;
}

export interface StatSource {
  value: string;
  label: string;
  live: number | null;
}

export interface CmsSchema {
  sections: SectionDef[];
  blocks: BlockDef[];
  icons: string[];
  tints: Option[];
  stat_sources: StatSource[];
  menu_locations: MenuLocation[];
  banner_placements: Option[];
  announcement_types: Option[];
  page_templates: Option[];
  program_categories: Option[];
  site_url: string;
}

export type Data = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export interface BlockSettings {
  anchor: string;
  background: 'white' | 'light' | 'cream' | 'sand' | 'dark' | string;
  padding: 'normal' | 'tight' | 'none' | string;
}

export interface Block {
  id: string;
  type: string;
  hidden: boolean;
  settings: BlockSettings;
  data: Data;
}

export interface LinkValue {
  label: string;
  url: string;
}

export interface PreviewData {
  programs: { id: number; name: string; short_name: string; slug: string; level: string; category: string | null; duration_label: string | null; fee_per_year: number | null; fee_label: string | null; image: string | null; is_featured: number; is_popular: number; department_id: number; eligibility: string | null }[];
  departments: { id: number; name: string; code: string }[];
  faculty: { id: number; name: string; designation: string | null; qualification: string | null; photo: string | null; department_id: number; department: string | null; bio: string | null }[];
  testimonials: { id: number; name: string; designation: string | null; company: string | null; photo: string | null; content: string; rating: number; type: string; program: string | null }[];
  faqs: { id: number; category: string; question: string; answer: string; show_on_home: number }[];
  galleries: { id: number; title: string; cover_image: string | null; items: number; photos: string[] }[];
  recruiters: { id: number; name: string; logo: string | null; industry: string | null }[];
  offers: { id: number; student: string; photo: string | null; program: string | null; company: string; job_role: string | null; package_lpa: string }[];
  events: { id: number; title: string; slug: string; category: string | null; venue: string | null; start_datetime: string; image: string | null }[];
  news: { id: number; title: string; slug: string; excerpt: string | null; thumbnail: string | null; published_at: string | null }[];
  notices: { id: number; title: string; slug: string; category: string | null; publish_date: string | null }[];
  stats: Record<string, number | null>;
  banners: { id: number; title: string; highlight: string | null; eyebrow: string | null; subheading: string | null; desktop_image: string | null; mobile_image: string | null; image_alt: string | null; cta_text: string | null; cta_url: string | null; cta2_text: string | null; cta2_url: string | null }[];
  settings: { institute_name: string; short_name: string; phone: string; email: string; tagline: string; logo: string; header_cta_text: string; admissions_open_text: string };
  topbar: string[];
}

export interface CmsPage {
  id: number;
  title: string;
  slug: string;
  subtitle: string | null;
  excerpt: string | null;
  banner_image: string | null;
  template: string;
  status: string;
  published_at: string | null;
  is_system: boolean;
  url: string;
  absolute_url: string;
  updated_at: string | null;
  updated_by_name: string | null;
  has_content: boolean;
  content: string | null;
  seo_ref: SeoRef;
}

export interface SeoRef {
  type: string;
  id: number | null;
  route: string | null;
}

export interface MenuItemNode {
  /** server id (null for new items) */
  id: number | null;
  /** stable client key */
  key: string;
  title: string;
  url: string;
  page_id: number | null;
  target: '_self' | '_blank';
  icon: string | null;
  description: string | null;
  is_mega: boolean;
  status: 'active' | 'inactive';
  children: MenuItemNode[];
}

export interface HomeSection {
  key: string;
  id: number | null;
  exists: boolean;
  label: string;
  icon: string;
  description: string;
  title: string | null;
  subtitle: string | null;
  content: Data;
  is_enabled: boolean;
  sort_order: number;
  updated_at: string | null;
}

export interface MediaItem {
  id: number | null;
  path: string;
  title: string | null;
  alt_text?: string | null;
  width: number | null;
  height: number | null;
  size: number | null;
  source: 'library' | 'stock';
}
