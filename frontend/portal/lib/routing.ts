import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';

/**
 * Route registration for a portal area. Each area exports an array (student/routes.tsx, faculty/routes.tsx);
 * later units append entries - the router mounts them inside the authenticated layout and the sidebar / mobile tab
 * bar are generated from the `nav` metadata.
 *
 *   { path: 'attendance', element: <AttendancePage />, nav: { label: 'Attendance', icon: CalendarCheck, order: 20, mobile: true } }
 */
export interface PortalNavMeta {
  label: string;
  icon: LucideIcon;
  /** sort order in the sidebar (Dashboard = 0, Account items = 900+) */
  order?: number;
  /** sidebar group heading ("Academics", "Campus", "Account" ...) */
  group?: string;
  /** show in the mobile bottom tab bar (max 4 are shown, the rest live under "More") */
  mobile?: boolean;
  /** short label for the tab bar */
  short?: string;
}

export interface PortalRoute {
  /** path relative to the area root ('' = dashboard / index route) */
  path: string;
  element: ReactNode;
  nav?: PortalNavMeta;
  /** reachable while a temporary password must still be changed */
  allowMustChange?: boolean;
}

export interface NavEntry extends PortalNavMeta {
  to: string;
  order: number;
}

/** Sidebar entries of an area, sorted by order. */
export function navEntries(routes: PortalRoute[]): NavEntry[] {
  return routes
    .filter((r) => r.nav)
    .map((r) => ({ ...(r.nav as PortalNavMeta), to: r.path, order: r.nav?.order ?? 500 }))
    .sort((a, b) => a.order - b.order);
}

/** True when an area registers a route for the given path (used to link KPI cards only to screens that exist). */
export function hasRoute(routes: PortalRoute[], path: string): boolean {
  return routes.some((r) => r.path === path);
}
