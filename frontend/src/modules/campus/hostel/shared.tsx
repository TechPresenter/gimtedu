import { useRef, type ReactNode } from 'react';
import { useLocation, useNavigationType } from 'react-router-dom';
import clsx from 'clsx';
import { BedDouble, BedSingle, Building, Building2, Users, Wrench } from 'lucide-react';
import { Badge } from '@/components/ui';
import { apiUrl } from '@/lib/api';
import type { BadgeColor } from '@/lib/status';
import { toNumber } from '@/lib/format';

/* ------------------------------------------------------------------ types */

export interface HostelStat {
  id: number;
  name: string;
  code: string;
  type: string;
  status: string;
  image: string | null;
  warden_name: string | null;
  warden_phone: string | null;
  total_floors: number;
  annual_fee: number | null;
  mess_fee: number | null;
  amenities: string[];
  address: string | null;
  visiting_hours: string | null;
  blocks: number;
  rooms: number;
  rooms_occupied: number;
  rooms_maintenance: number;
  beds: number;
  occupied: number;
  maintenance: number;
  available: number;
  occupancy_pct: number;
  open_complaints: number;
  visitors_inside: number;
}

export interface GridOccupant {
  allocation_id: number;
  allocation_no: string;
  allocated_on: string;
  student_id: number;
  name: string;
  uid: string;
  photo: string | null;
  gender: string | null;
  program: string | null;
  semester: number | null;
}

export interface GridBed {
  id: number;
  bed_no: string;
  status: 'available' | 'occupied' | 'maintenance';
  occupant: GridOccupant | null;
}

export interface GridRoom {
  id: number;
  block_id: number | null;
  floor_no: number;
  room_no: string;
  room_type: string;
  capacity: number;
  occupied: number;
  is_ac: boolean;
  annual_fee: number | null;
  amenities: string[];
  status: string;
  free: number;
  beds: GridBed[];
}

export interface HostelGrid {
  hostel: { id: number; name: string; code: string; type: string; status: string; warden_name: string | null; warden_phone: string | null; total_floors: number; annual_fee: number | null; mess_fee: number | null };
  blocks: { id: number; name: string; floors: number; status: string; rooms: number }[];
  rooms: GridRoom[];
}

/* ------------------------------------------------------------------ labels */

export const HOSTEL_TYPES: Record<string, string> = { boys: 'Boys', girls: 'Girls', staff: 'Staff', international: 'International' };
export const HOSTEL_TYPE_COLORS: Record<string, BadgeColor> = { boys: 'blue', girls: 'purple', staff: 'slate', international: 'cyan' };
export const ROOM_TYPES: Record<string, string> = { single: 'Single', double: 'Double', triple: 'Triple', quad: 'Four seater', dormitory: 'Dormitory' };
export const ROOM_AMENITIES: Record<string, string> = {
  attached_bath: 'Attached washroom', balcony: 'Balcony', study_table: 'Study table & chair', wardrobe: 'Wardrobe', ceiling_fan: 'Ceiling fan', ac: 'Air conditioner',
  geyser: 'Geyser', cooler: 'Air cooler', lan: 'LAN point', bookshelf: 'Bookshelf',
};
export const HOSTEL_AMENITIES: Record<string, string> = {
  wifi: 'Wi-Fi', mess: 'Mess / dining hall', laundry: 'Laundry', gym: 'Gymnasium', reading_room: 'Reading room', common_room: 'Common room & TV', ro_water: 'RO drinking water',
  hot_water: 'Hot water', power_backup: '24x7 power backup', cctv: 'CCTV & security', indoor_games: 'Indoor games', medical: 'Sick room / first aid', housekeeping: 'Daily housekeeping',
  parking: 'Two-wheeler parking',
};
export const ALLOCATION_STATUS: Record<string, string> = { active: 'Active', vacated: 'Vacated', transferred: 'Transferred', cancelled: 'Cancelled' };
export const ALLOCATION_COLORS: Record<string, BadgeColor> = { active: 'green', vacated: 'slate', transferred: 'purple', cancelled: 'red' };
export const INVOICE_COLORS: Record<string, BadgeColor> = { paid: 'green', partial: 'amber', pending: 'amber', overdue: 'red', cancelled: 'slate', waived: 'purple', not_invoiced: 'slate' };
export const INVOICE_LABELS: Record<string, string> = { paid: 'Paid', partial: 'Part paid', pending: 'Due', overdue: 'Overdue', cancelled: 'Cancelled', waived: 'Waived', not_invoiced: 'Not invoiced' };

export const COMPLAINT_CATEGORIES: Record<string, string> = {
  maintenance: 'Maintenance', electrical: 'Electrical', plumbing: 'Plumbing', cleanliness: 'Cleanliness', mess: 'Mess & food', security: 'Security', internet: 'Internet / Wi-Fi',
  furniture: 'Furniture', other: 'Other',
};
export const COMPLAINT_STATUS: Record<string, string> = { open: 'Open', in_progress: 'In progress', resolved: 'Resolved', closed: 'Closed' };
export const COMPLAINT_STATUS_COLORS: Record<string, BadgeColor> = { open: 'red', in_progress: 'amber', resolved: 'green', closed: 'slate' };
export const PRIORITY_COLORS: Record<string, BadgeColor> = { low: 'slate', medium: 'amber', high: 'red', urgent: 'red' };
export const VISITOR_RELATIONS: Record<string, string> = {
  Father: 'Father', Mother: 'Mother', Brother: 'Brother', Sister: 'Sister', Guardian: 'Local guardian', Uncle: 'Uncle', Aunt: 'Aunt', Cousin: 'Cousin', Grandparent: 'Grandparent',
  Friend: 'Friend', Other: 'Other',
};

export function floorLabel(n: number | string | null | undefined): string {
  const f = Number(n ?? 0);
  if (!f) return 'Ground floor';
  const s = [11, 12, 13].includes(f % 100) ? 'th' : ['th', 'st', 'nd', 'rd'][f % 10] ?? 'th';
  return `${f}${s} floor`;
}

/** Short floor chip label: G, 1, 2 … */
export function floorShort(n: number): string {
  return n === 0 ? 'G' : String(n);
}

/**
 * Key for a `urlState` CrudTable that changes on every PUSH / POP navigation (links such as KPI cards, "show not invoiced",
 * status tabs, browser back) but not on the REPLACE updates the table itself makes while typing / filtering.
 * Keying the table with it re-reads `f.*` filters from the URL, so in-page links really filter the list.
 */
export function useUrlNavKey(): string {
  const location = useLocation();
  const navType = useNavigationType();
  const key = useRef(location.key);
  if (navType !== 'REPLACE') key.current = location.key;
  return key.current;
}

/** Opens a server-rendered hostel print view (allotment letter, register, visitor pass, job card) in a new tab. */
export function openHostelPrint(path: string, query?: Record<string, string | number | undefined>) {
  window.open(apiUrl(`hostel/print/${path}`, query), '_blank', 'noopener');
}

export function minutesLabel(min: number | null | undefined): string {
  const m = Math.max(0, Math.round(Number(min ?? 0)));
  if (m < 60) return `${m} min`;
  const h = Math.floor(m / 60);
  const r = m % 60;
  return r ? `${h} h ${r} min` : `${h} h`;
}

export function hoursLabel(h: number | null | undefined): string {
  if (h === null || h === undefined) return '—';
  if (h < 1) return `${Math.round(h * 60)} min`;
  if (h < 48) return `${toNumber(h).toFixed(h < 10 ? 1 : 0)} h`;
  return `${(h / 24).toFixed(1)} days`;
}

/* ------------------------------------------------------------------ small components */

export function HostelTypeBadge({ type }: { type: string }) {
  return (
    <Badge color={HOSTEL_TYPE_COLORS[type] ?? 'slate'} dot>
      {HOSTEL_TYPES[type] ?? type}
    </Badge>
  );
}

export function RoomTypeIcon({ type, className }: { type: string; className?: string }) {
  const Icon = type === 'single' ? BedSingle : type === 'dormitory' ? Users : BedDouble;
  return <Icon className={className ?? 'h-4 w-4'} aria-hidden />;
}

/** Stacked occupied / free / maintenance bar for a hostel or block. */
export function OccupancyBar({ occupied, available, maintenance, className, showLegend }: { occupied: number; available: number; maintenance: number; className?: string; showLegend?: boolean }) {
  const total = Math.max(1, occupied + available + maintenance);
  const seg = (v: number) => `${(v / total) * 100}%`;
  return (
    <div className={className}>
      <div
        className="flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
        role="img"
        aria-label={`${occupied} occupied, ${available} free, ${maintenance} under maintenance`}
      >
        <div className="h-full bg-brand-700 transition-all duration-500 dark:bg-brand-400" style={{ width: seg(occupied) }} />
        <div className="h-full bg-accent-500 transition-all duration-500" style={{ width: seg(available) }} />
        <div className="h-full bg-amber-400 transition-all duration-500" style={{ width: seg(maintenance) }} />
      </div>
      {showLegend && (
        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
          <LegendDot className="bg-brand-700 dark:bg-brand-400">{occupied} occupied</LegendDot>
          <LegendDot className="bg-accent-500">{available} free</LegendDot>
          {maintenance > 0 && <LegendDot className="bg-amber-400">{maintenance} maintenance</LegendDot>}
        </div>
      )}
    </div>
  );
}

export function LegendDot({ className, children }: { className: string; children: ReactNode }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className={clsx('h-2 w-2 rounded-full', className)} aria-hidden />
      {children}
    </span>
  );
}

/** Colour of a room tile by occupancy. */
export function roomTone(r: { status: string; capacity: number; occupied: number }): { tile: string; text: string; label: string } {
  if (r.status === 'maintenance' || r.status === 'inactive') {
    return {
      tile: 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10',
      text: 'text-amber-800 dark:text-amber-200',
      label: r.status === 'maintenance' ? 'Under maintenance' : 'Inactive',
    };
  }
  if (r.occupied <= 0) {
    return { tile: 'border-emerald-300 bg-emerald-50 dark:border-emerald-500/40 dark:bg-emerald-500/10', text: 'text-emerald-800 dark:text-emerald-200', label: 'Vacant' };
  }
  if (r.occupied >= r.capacity) {
    return { tile: 'border-brand-400 bg-brand-100 dark:border-brand-400/50 dark:bg-brand-500/25', text: 'text-brand-900 dark:text-brand-100', label: 'Full' };
  }
  return { tile: 'border-cyan-400 bg-cyan-50 dark:border-cyan-500/40 dark:bg-cyan-500/10', text: 'text-cyan-900 dark:text-cyan-100', label: 'Partly occupied' };
}

/** Bed dots inside a room tile. */
export function BedDots({ beds, className }: { beds: GridBed[]; className?: string }) {
  return (
    <div className={clsx('flex flex-wrap gap-1', className)} aria-hidden>
      {beds.map((b) => (
        <span
          key={b.id}
          className={clsx(
            'h-2 w-2 rounded-full',
            b.status === 'occupied' ? 'bg-brand-700 dark:bg-brand-300' : b.status === 'maintenance' ? 'bg-amber-400' : 'bg-accent-500',
          )}
        />
      ))}
    </div>
  );
}

export const HOSTEL_ICON = Building2;
export const BLOCK_ICON = Building;
export const MAINTENANCE_ICON = Wrench;
