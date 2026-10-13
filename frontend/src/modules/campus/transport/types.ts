/** API payload types of the transport unit (api/routes/transport.php). */

export type ExpiryState = 'expired' | 'due' | 'ok' | null;

export interface TransportKpis {
  vehicles_total: number;
  vehicles_active: number;
  vehicles_maintenance: number;
  vehicles_inactive: number;
  seats: number;
  riders: number;
  occupancy: number;
  routes_total: number;
  routes_active: number;
  stops: number;
  students: number;
  staff_riders: number;
  fee_total: number;
  invoiced: number;
  drivers: number;
  crew: number;
  maintenance_due: number;
  in_workshop: number;
  fuel_month: number;
  fuel_month_liters: number;
  fuel_prev_month: number;
  fuel_prev_to_date: number;
  documents_expired: number;
  documents_due: number;
  open_complaints: number;
  pending_requests: number;
}

export interface MonthCost {
  month: string;
  label: string;
  fuel: number;
  liters: number;
  maintenance: number;
}

export interface RouteOverview {
  id: number;
  code: string;
  name: string;
  start_point: string | null;
  end_point: string | null;
  distance_km: number | null;
  morning_departure: string | null;
  status: string;
  capacity: number;
  allocated: number;
  students: number;
  stops: number;
  vehicles: string | null;
  driver_name: string | null;
  driver_phone: string | null;
  open_complaints: number;
  occupancy: number;
}

export interface DocumentAlert {
  kind: 'vehicle' | 'driver';
  id: number;
  label: string;
  sub: string | null;
  document: string;
  field: string;
  renew: string | null;
  date: string;
  days: number;
}

export interface UpcomingService {
  source: 'record' | 'due';
  id: number;
  vehicle_id: number;
  vehicle_no: string;
  model: string | null;
  route_code: string | null;
  type: string;
  status: 'in_progress' | 'scheduled' | 'overdue' | 'due';
  due_date: string | null;
  due_km: number | null;
  odometer: number | null;
  vendor: string | null;
  description: string | null;
  cost: number | null;
  days: number | null;
}

export interface ComplaintBrief {
  id: number;
  complaint_no: string;
  subject: string;
  category: string;
  priority: string;
  status: string;
  complainant_name: string;
  created_at: string;
  route_code: string | null;
}

export interface TransportSettings {
  alert_days: number;
  fee_due_days: number;
  staff_fee_percent: number;
  gps_enabled: boolean;
  gps_provider: string;
  gps_api_url: string;
  gps_refresh_seconds: number;
  parent_alerts: boolean;
  devices_mapped: number;
  vehicles: number;
  providers: { value: string; label: string }[];
  can_manage?: boolean;
}

export interface TransportDashboard {
  kpis: TransportKpis;
  months: MonthCost[];
  routes: RouteOverview[];
  alerts: DocumentAlert[];
  alert_days: number;
  upcoming: UpcomingService[];
  complaints: ComplaintBrief[];
  settings: TransportSettings;
}

export interface StopOption {
  id: number;
  name: string;
  landmark: string | null;
  sequence: number;
  pickup_time: string | null;
  drop_time: string | null;
  distance_km: number | null;
  annual_fare: number | null;
  riders: number;
}

export interface RouteOption {
  id: number;
  code: string;
  name: string;
  start_point: string | null;
  end_point: string | null;
  annual_fare: number | null;
  morning_departure: string | null;
  capacity: number;
  allocated: number;
  available: number;
  stops: StopOption[];
}

export interface Rider {
  id: number;
  person_type: 'student' | 'faculty' | 'staff';
  student_id: number | null;
  name: string;
  sub: string | null;
  phone: string | null;
  photo: string | null;
  fee_amount: string | number | null;
  start_date: string;
  vehicle_no: string | null;
  invoice_no: string | null;
  invoice_status: string | null;
}

export interface RouteStop {
  id: number;
  route_id: number;
  name: string;
  landmark: string | null;
  sequence: number;
  pickup_time: string | null;
  drop_time: string | null;
  distance_km: string | null;
  annual_fare: string | null;
  latitude: string | null;
  longitude: string | null;
  riders: Rider[];
  rider_count: number;
}

export interface RouteVehicle {
  id: number;
  vehicle_no: string;
  type: string;
  make: string | null;
  model: string | null;
  capacity: number;
  fuel_type: string | null;
  status: string;
  current_odometer: number | null;
  gps_device_id: string | null;
  driver_id: number | null;
  driver_name: string | null;
  driver_phone: string | null;
  driver_photo: string | null;
  driver_license_expiry: string | null;
  conductor_id: number | null;
  conductor_name: string | null;
  conductor_phone: string | null;
  conductor_photo: string | null;
  allocated: number;
  next_expiry: string | null;
  next_expiry_state: ExpiryState;
}

export interface RouteDetail {
  route: {
    id: number;
    code: string;
    name: string;
    start_point: string | null;
    end_point: string | null;
    description: string | null;
    distance_km: string | null;
    annual_fare: string | null;
    morning_departure: string | null;
    evening_departure: string | null;
    status: string;
    load: { capacity: number; allocated: number; available: number };
    occupancy: number;
  };
  vehicles: RouteVehicle[];
  stops: RouteStop[];
  unassigned: Rider[];
  by_type: Record<string, number>;
  fee_total: number;
  complaints: { id: number; complaint_no: string; subject: string; status: string; priority: string; created_at: string }[];
}

export interface VehicleDocument {
  field: string;
  label: string;
  renew: string;
  date: string | null;
  days: number | null;
  state: ExpiryState;
}

export interface VehicleDetail {
  vehicle: Record<string, any> & { id: number; vehicle_no: string; allocated: number }; // eslint-disable-line @typescript-eslint/no-explicit-any
  documents: VehicleDocument[];
  fuel: { fills: number; liters: number; cost: number; km: number; mileage: number | null; cost_per_km: number | null };
  recent_fuel: { id: number; fill_date: string; liters: string; cost: string; odometer: number | null; station: string | null; km: number | null; mileage: number | null }[];
  maintenance: { id: number; service_date: string; type: string; description: string | null; cost: string; vendor: string | null; odometer: number | null; next_service_date: string | null; status: string }[];
  maintenance_cost_session: number;
}

export interface FuelStats {
  from: string;
  to: string;
  vehicles: { id: number; vehicle_no: string; type: string; fuel_type: string | null; fills: number; liters: number; cost: number; km: number; mileage: number | null; cost_per_km: number | null }[];
  totals: { liters: number; cost: number; km: number; fills: number; avg_rate: number | null; cost_per_km: number | null };
}

export interface PendingStudent {
  id: number;
  student_uid: string;
  name: string;
  photo: string | null;
  mobile: string | null;
  city: string | null;
  address: string | null;
  program: string | null;
  current_semester: number | null;
  admission_date: string | null;
}

export interface ComplaintDetail {
  complaint: Record<string, any> & { id: number; complaint_no: string; subject: string; status: string }; // eslint-disable-line @typescript-eslint/no-explicit-any
  history: { action: string; title: string; note: string | null; user: string | null; at: string }[];
  transitions: string[];
}
