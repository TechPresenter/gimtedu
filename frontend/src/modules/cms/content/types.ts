/** Shared types of the CMS content screens (blog, media library, gallery, FAQs, testimonials). */

export type MediaType = 'image' | 'video' | 'pdf' | 'document' | 'other';

export interface MediaUsage {
  label: string;
  title: string;
  id: number | null;
  link: string | null;
}

export interface MediaItem {
  id: number;
  filename: string;
  original_name: string | null;
  path: string;
  url: string;
  absolute_url: string;
  mime: string | null;
  extension: string | null;
  size_bytes: number | null;
  size_label: string;
  type: MediaType;
  width: number | null;
  height: number | null;
  dimensions?: string | null;
  alt_text: string | null;
  title: string | null;
  caption: string | null;
  folder: string | null;
  folder_label?: string;
  uploaded_by: number | null;
  uploaded_by_name?: string | null;
  created_at: string;
  updated_at?: string | null;
  is_bundled: boolean;
  usage?: MediaUsage[];
}

export interface UploadRule {
  extensions: string[];
  max_bytes: number;
}

export interface MediaStats {
  total: number;
  by_type: Record<MediaType, number>;
  total_bytes: number;
  this_month: number;
  missing_alt: number;
  folders: { value: string; label: string; count: number }[];
  rules: { image: UploadRule; video: UploadRule; document: UploadRule };
}

export interface SeoValues {
  meta_title: string;
  meta_description: string;
  meta_keywords: string;
  canonical_url: string;
  og_title: string;
  og_description: string;
  og_image: string;
  robots: string;
  schema_json: string;
  include_in_sitemap: boolean;
  sitemap_priority: string;
  sitemap_changefreq: string;
  exists?: boolean;
  updated_at?: string | null;
}

export interface BlogSummary {
  total: number;
  published: number;
  draft: number;
  scheduled: number;
  featured: number;
  views: number;
  this_month: number;
  last_month: number;
  seo_missing: number;
  categories_count: number;
  monthly: { label: string; posts: number; views: number }[];
  by_category: { name: string; posts: number; published: number }[];
  top_posts: { id: number; title: string; slug: string; thumbnail: string | null; views: number; published_at: string; category_name: string | null }[];
  upcoming: { id: number; title: string; published_at: string; category_name: string | null }[];
}

export interface GalleryItem {
  id: number;
  media_id: number | null;
  caption: string | null;
  sort_order: number;
  path: string | null;
  url: string | null;
  video_url: string | null;
  youtube_id: string | null;
  thumbnail: string | null;
  kind: 'photo' | 'video';
  title: string | null;
  alt_text: string | null;
  width: number | null;
  height: number | null;
  size_label: string | null;
}

export interface GalleryAlbum {
  id: number;
  title: string;
  slug: string;
  category: string | null;
  description: string | null;
  cover_image: string | null;
  cover_url: string | null;
  event_date: string | null;
  sort_order: number;
  status: string;
  created_at: string;
  updated_at: string | null;
  public_url: string;
}

export interface GalleryStats {
  albums: number;
  published: number;
  drafts: number;
  items: number;
  videos: number;
  empty: number;
  by_category: Record<string, number>;
}
