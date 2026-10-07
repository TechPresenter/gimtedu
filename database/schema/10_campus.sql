-- =====================================================================
-- 10 Campus: Library, Hostel, Transport
-- =====================================================================

-- ---------------- Library ----------------
CREATE TABLE book_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(20) NULL COMMENT 'DDC class e.g. 650',
  description VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_book_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE authors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  bio TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_authors_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publishers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  city VARCHAR(80) NULL,
  website VARCHAR(190) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_publishers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE books (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  isbn VARCHAR(20) NULL,
  title VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) NULL,
  author_id INT UNSIGNED NULL,
  co_authors VARCHAR(255) NULL,
  category_id INT UNSIGNED NULL,
  publisher_id INT UNSIGNED NULL,
  edition VARCHAR(40) NULL,
  publish_year SMALLINT UNSIGNED NULL,
  language VARCHAR(40) NULL DEFAULT 'English',
  pages SMALLINT UNSIGNED NULL,
  price DECIMAL(10,2) NULL,
  rack_no VARCHAR(30) NULL,
  department_id INT UNSIGNED NULL,
  cover_image VARCHAR(255) NULL,
  description TEXT NULL,
  total_copies SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_copies SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(20) NOT NULL DEFAULT 'book' COMMENT 'book|journal|magazine|thesis|ebook|reference',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_books_isbn (isbn),
  KEY idx_books_title (title),
  CONSTRAINT fk_books_author FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE SET NULL,
  CONSTRAINT fk_books_category FOREIGN KEY (category_id) REFERENCES book_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_books_publisher FOREIGN KEY (publisher_id) REFERENCES publishers(id) ON DELETE SET NULL,
  CONSTRAINT fk_books_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE book_copies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id INT UNSIGNED NOT NULL,
  accession_no VARCHAR(30) NOT NULL,
  barcode VARCHAR(60) NULL,
  rack_no VARCHAR(30) NULL,
  condition_note VARCHAR(20) NOT NULL DEFAULT 'good' COMMENT 'new|good|fair|poor',
  acquired_date DATE NULL,
  price DECIMAL(10,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'available' COMMENT 'available|issued|reserved|lost|damaged|withdrawn',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_copies_accession (accession_no),
  KEY idx_copies_book_status (book_id, status),
  CONSTRAINT fk_copies_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE library_members (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_no VARCHAR(30) NOT NULL,
  member_type VARCHAR(10) NOT NULL COMMENT 'student|faculty|staff',
  student_id INT UNSIGNED NULL,
  faculty_id INT UNSIGNED NULL,
  staff_id INT UNSIGNED NULL,
  max_books TINYINT UNSIGNED NOT NULL DEFAULT 3,
  loan_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  valid_until DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|suspended|expired',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_no (membership_no),
  UNIQUE KEY uq_members_student (student_id),
  UNIQUE KEY uq_members_faculty (faculty_id),
  UNIQUE KEY uq_members_staff (staff_id),
  CONSTRAINT fk_members_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_members_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
  CONSTRAINT fk_members_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE library_transactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_copy_id INT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  return_date DATE NULL,
  renew_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'issued' COMMENT 'issued|returned|lost',
  fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  issued_by INT UNSIGNED NULL,
  returned_to INT UNSIGNED NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lt_member (member_id, status),
  KEY idx_lt_status_due (status, due_date),
  CONSTRAINT fk_lt_copy FOREIGN KEY (book_copy_id) REFERENCES book_copies(id),
  CONSTRAINT fk_lt_book FOREIGN KEY (book_id) REFERENCES books(id),
  CONSTRAINT fk_lt_member FOREIGN KEY (member_id) REFERENCES library_members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE library_fines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id INT UNSIGNED NULL,
  member_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  reason VARCHAR(20) NOT NULL DEFAULT 'late_return' COMMENT 'late_return|lost|damage|other',
  status VARCHAR(20) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid|paid|waived',
  paid_at DATETIME NULL,
  payment_mode VARCHAR(20) NULL,
  collected_by INT UNSIGNED NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fines_member (member_id, status),
  CONSTRAINT fk_fines_txn FOREIGN KEY (transaction_id) REFERENCES library_transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_fines_member FOREIGN KEY (member_id) REFERENCES library_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- Hostel ----------------
CREATE TABLE hostels (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(20) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'boys' COMMENT 'boys|girls|staff|international',
  warden_staff_id INT UNSIGNED NULL,
  warden_name VARCHAR(150) NULL,
  warden_phone VARCHAR(20) NULL,
  address VARCHAR(255) NULL,
  total_floors TINYINT UNSIGNED NULL,
  amenities VARCHAR(500) NULL,
  annual_fee DECIMAL(12,2) NULL,
  mess_fee DECIMAL(12,2) NULL,
  image VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hostels_code (code),
  CONSTRAINT fk_hostels_warden FOREIGN KEY (warden_staff_id) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hostel_blocks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hostel_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL COMMENT 'building / block',
  floors TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_blocks (hostel_id, name),
  CONSTRAINT fk_blocks_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hostel_rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hostel_id INT UNSIGNED NOT NULL,
  block_id INT UNSIGNED NULL,
  floor_no TINYINT NOT NULL DEFAULT 0,
  room_no VARCHAR(20) NOT NULL,
  room_type VARCHAR(20) NOT NULL DEFAULT 'double' COMMENT 'single|double|triple|quad|dormitory',
  capacity TINYINT UNSIGNED NOT NULL DEFAULT 2,
  occupied TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_ac TINYINT(1) NOT NULL DEFAULT 0,
  annual_fee DECIMAL(12,2) NULL,
  amenities VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'available' COMMENT 'available|full|maintenance|inactive',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms (hostel_id, room_no),
  CONSTRAINT fk_rooms_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  CONSTRAINT fk_rooms_block FOREIGN KEY (block_id) REFERENCES hostel_blocks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hostel_beds (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id INT UNSIGNED NOT NULL,
  bed_no VARCHAR(10) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'available' COMMENT 'available|occupied|maintenance',
  PRIMARY KEY (id),
  UNIQUE KEY uq_beds (room_id, bed_no),
  CONSTRAINT fk_beds_room FOREIGN KEY (room_id) REFERENCES hostel_rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hostel_allocations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  hostel_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NOT NULL,
  bed_id INT UNSIGNED NULL,
  academic_session_id INT UNSIGNED NULL,
  allocated_on DATE NOT NULL,
  vacated_on DATE NULL,
  fee_amount DECIMAL(12,2) NULL,
  student_fee_id INT UNSIGNED NULL COMMENT 'invoice raised for hostel fee',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|vacated|cancelled',
  remarks VARCHAR(255) NULL,
  allocated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ha_student (student_id, status),
  KEY idx_ha_room (room_id, status),
  CONSTRAINT fk_ha_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_ha_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id),
  CONSTRAINT fk_ha_room FOREIGN KEY (room_id) REFERENCES hostel_rooms(id),
  CONSTRAINT fk_ha_bed FOREIGN KEY (bed_id) REFERENCES hostel_beds(id) ON DELETE SET NULL,
  CONSTRAINT fk_ha_fee FOREIGN KEY (student_fee_id) REFERENCES student_fees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hostel_complaints (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hostel_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NULL,
  student_id INT UNSIGNED NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'maintenance' COMMENT 'maintenance|electrical|plumbing|cleanliness|mess|security|internet|other',
  subject VARCHAR(190) NOT NULL,
  description TEXT NULL,
  priority VARCHAR(10) NOT NULL DEFAULT 'medium',
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|in_progress|resolved|closed',
  assigned_to VARCHAR(150) NULL,
  resolution VARCHAR(500) NULL,
  resolved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hc_status (status),
  CONSTRAINT fk_hc_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  CONSTRAINT fk_hc_room FOREIGN KEY (room_id) REFERENCES hostel_rooms(id) ON DELETE SET NULL,
  CONSTRAINT fk_hc_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hostel_visitors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hostel_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  visitor_name VARCHAR(150) NOT NULL,
  relation VARCHAR(40) NULL,
  phone VARCHAR(20) NULL,
  id_proof VARCHAR(60) NULL,
  purpose VARCHAR(190) NULL,
  check_in DATETIME NOT NULL,
  check_out DATETIME NULL,
  approved_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hv_checkin (check_in),
  CONSTRAINT fk_hv_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE,
  CONSTRAINT fk_hv_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------- Transport ----------------
CREATE TABLE drivers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  license_no VARCHAR(40) NOT NULL,
  license_expiry DATE NULL,
  address VARCHAR(255) NULL,
  photo VARCHAR(255) NULL,
  experience_years TINYINT UNSIGNED NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'driver' COMMENT 'driver|conductor|helper',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_drivers_license (license_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transport_routes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(20) NOT NULL,
  start_point VARCHAR(150) NULL,
  end_point VARCHAR(150) NULL,
  distance_km DECIMAL(6,1) NULL,
  annual_fare DECIMAL(10,2) NULL,
  morning_departure TIME NULL,
  evening_departure TIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_routes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_no VARCHAR(20) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'bus' COMMENT 'bus|mini_bus|van|car|other',
  make VARCHAR(60) NULL,
  model VARCHAR(60) NULL,
  manufacture_year SMALLINT UNSIGNED NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  fuel_type VARCHAR(20) NULL DEFAULT 'diesel',
  driver_id INT UNSIGNED NULL,
  conductor_id INT UNSIGNED NULL,
  route_id INT UNSIGNED NULL,
  registration_date DATE NULL,
  insurance_expiry DATE NULL,
  fitness_expiry DATE NULL,
  permit_expiry DATE NULL,
  pollution_expiry DATE NULL,
  gps_device_id VARCHAR(60) NULL COMMENT 'future GPS / live tracking integration',
  current_odometer INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|maintenance|inactive',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehicles_no (vehicle_no),
  CONSTRAINT fk_vehicles_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_vehicles_conductor FOREIGN KEY (conductor_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_vehicles_route FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transport_stops (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  sequence SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pickup_time TIME NULL,
  drop_time TIME NULL,
  annual_fare DECIMAL(10,2) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  PRIMARY KEY (id),
  KEY idx_stops_route (route_id, sequence),
  CONSTRAINT fk_stops_route FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transport_allocations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_type VARCHAR(10) NOT NULL DEFAULT 'student' COMMENT 'student|faculty|staff',
  student_id INT UNSIGNED NULL,
  faculty_id INT UNSIGNED NULL,
  staff_id INT UNSIGNED NULL,
  route_id INT UNSIGNED NOT NULL,
  stop_id INT UNSIGNED NULL,
  vehicle_id INT UNSIGNED NULL,
  academic_session_id INT UNSIGNED NULL,
  fee_amount DECIMAL(10,2) NULL,
  student_fee_id INT UNSIGNED NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|cancelled|completed',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ta_route (route_id, status),
  KEY idx_ta_student (student_id),
  CONSTRAINT fk_ta_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_route FOREIGN KEY (route_id) REFERENCES transport_routes(id),
  CONSTRAINT fk_ta_stop FOREIGN KEY (stop_id) REFERENCES transport_stops(id) ON DELETE SET NULL,
  CONSTRAINT fk_ta_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
  CONSTRAINT fk_ta_fee FOREIGN KEY (student_fee_id) REFERENCES student_fees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transport_fuel (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id INT UNSIGNED NOT NULL,
  fill_date DATE NOT NULL,
  liters DECIMAL(8,2) NOT NULL,
  cost DECIMAL(10,2) NOT NULL,
  odometer INT UNSIGNED NULL,
  station VARCHAR(150) NULL,
  bill_no VARCHAR(60) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fuel_vehicle_date (vehicle_id, fill_date),
  CONSTRAINT fk_fuel_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transport_maintenance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id INT UNSIGNED NOT NULL,
  service_date DATE NOT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'service' COMMENT 'service|repair|tyre|insurance|fitness|other',
  description VARCHAR(500) NULL,
  cost DECIMAL(10,2) NOT NULL DEFAULT 0,
  vendor VARCHAR(150) NULL,
  odometer INT UNSIGNED NULL,
  next_service_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'completed' COMMENT 'scheduled|in_progress|completed',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_maint_vehicle (vehicle_id, service_date),
  CONSTRAINT fk_maint_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transport_complaints (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_id INT UNSIGNED NULL,
  vehicle_id INT UNSIGNED NULL,
  student_id INT UNSIGNED NULL,
  complainant_name VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NULL,
  subject VARCHAR(190) NOT NULL,
  description TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|in_progress|resolved|closed',
  resolution VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_tc_route FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE SET NULL,
  CONSTRAINT fk_tc_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
  CONSTRAINT fk_tc_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
