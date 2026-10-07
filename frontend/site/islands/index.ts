import type { ComponentType } from 'react';

/**
 * Island registry: name (as used in PHP `island('Name', …)`) → lazy loader.
 * Every entry is a dynamic import, so a page only downloads the islands it actually renders (and React itself is
 * only fetched when a page has at least one island). To add an island:
 *   1. create frontend/site/islands/MyWidget.tsx with a default-exported component (props typed, reduced-motion aware)
 *   2. register it here
 *   3. render it from PHP: echo island('MyWidget', ['foo' => 1], '<p>SEO fallback</p>');
 *   4. document its props in docs/WEBSITE.md
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type IslandLoader = () => Promise<{ default: ComponentType<any> }>;

export const islandRegistry: Record<string, IslandLoader> = {
  HeroSlider: () => import('./HeroSlider'),
  Carousel: () => import('./Carousel'),
  TestimonialSlider: () => import('./TestimonialSlider'),
  LogoMarquee: () => import('./LogoMarquee'),
  ProgramExplorer: () => import('./ProgramExplorer'),
  StatCounter: () => import('./StatCounter'),
  TypingText: () => import('./TypingText'),
  Accordion: () => import('./Accordion'),
  Tabs: () => import('./Tabs'),
  VideoLightbox: () => import('./VideoLightbox'),
  GalleryLightbox: () => import('./GalleryLightbox'),
  EnquiryForm: () => import('./EnquiryForm'),
  SkeletonList: () => import('./SkeletonList'),
  AutoScrollCards: () => import('./AutoScrollCards'),
  ProgressSteps: () => import('./ProgressSteps'),
};
