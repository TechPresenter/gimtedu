/**
 * Colour map for islands — mirrors site_hues() in includes/config.php and the .hue-* classes / --hue-* custom
 * properties in assets/css/src/style.css. Use the class names (they set --hue / --tint / --ink) rather than raw hex:
 *   <span className={`level-chip ${levelHue('PG')}`}>Postgraduate</span>
 *   <article className={`program-card ${deptHue(p.departmentCode, p.category)}`}>…
 * --hue = strong accent (≥ 4.5:1 on white and with white text) · --tint = pastel surface · --ink = text on the tint.
 */
export type HueKey = 'ug' | 'pg' | 'diploma' | 'cert' | 'mgt' | 'tech' | 'ca' | 'com' | 'sci' | 'skill' | 'other' | 'all';

export const HUES: Record<HueKey, { hue: string; tint: string; ink: string; label: string }> = {
  ug: { hue: '#0369A1', tint: '#E0F2FE', ink: '#0C4A6E', label: 'Undergraduate' },
  pg: { hue: '#6D28D9', tint: '#EDE9FE', ink: '#4C1D95', label: 'Postgraduate' },
  diploma: { hue: '#0F766E', tint: '#CCFBF1', ink: '#134E4A', label: 'Diploma' },
  cert: { hue: '#B45309', tint: '#FEF3C7', ink: '#78350F', label: 'Certificate' },
  mgt: { hue: '#D03E16', tint: '#FDEDE7', ink: '#7A2410', label: 'Management' },
  tech: { hue: '#1D4ED8', tint: '#DBEAFE', ink: '#1E3A8A', label: 'Technology' },
  ca: { hue: '#7E22CE', tint: '#F3E8FF', ink: '#581C87', label: 'Computer Applications' },
  com: { hue: '#047857', tint: '#D1FAE5', ink: '#064E3B', label: 'Commerce' },
  sci: { hue: '#0E7490', tint: '#CFFAFE', ink: '#164E63', label: 'Science & Data' },
  skill: { hue: '#BE123C', tint: '#FFE4E6', ink: '#881337', label: 'Professional & Skill' },
  other: { hue: '#4338CA', tint: '#E0E7FF', ink: '#312E81', label: 'Other' },
  all: { hue: '#0B2A5B', tint: '#EFF4FB', ink: '#0B2A5B', label: 'All' },
};

export const LEVEL_HUES: Record<string, HueKey> = { UG: 'ug', PG: 'pg', PhD: 'pg', Diploma: 'diploma', Certificate: 'cert' };
export const DEPARTMENT_HUES: Record<string, HueKey> = { MGT: 'mgt', CSE: 'tech', DCA: 'ca', COM: 'com', SCI: 'sci', PSD: 'skill' };
export const CATEGORY_HUES: Record<string, HueKey> = {
  management: 'mgt', technology: 'tech', 'computer-applications': 'ca', commerce: 'com', science: 'sci', diploma: 'skill', certificate: 'skill',
};

const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/** CSS class for a program level code ('UG' → 'hue-ug'). */
export const levelHue = (level?: string | null): string => `hue-${LEVEL_HUES[level ?? ''] ?? 'other'}`;

/** CSS class for a department code (falls back to the program category, then 'other'). */
export const deptHue = (departmentCode?: string | null, category?: string | null): string =>
  `hue-${DEPARTMENT_HUES[(departmentCode ?? '').toUpperCase()] ?? CATEGORY_HUES[slug(category ?? '')] ?? 'other'}`;
