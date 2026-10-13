-- =====================================================================
-- 24 Academics structure + Timetable (unit: academics)
-- Runs after 01-12 on a fresh install. Portable MySQL 8 / MariaDB 10.4+ SQL.
-- =====================================================================

-- Elective flag for subjects (type stays theory|practical|lab|project).
ALTER TABLE subjects ADD COLUMN is_elective TINYINT(1) NOT NULL DEFAULT 0 AFTER type;

-- Fast look-ups for the faculty / room timetable views and daily view.
ALTER TABLE timetables ADD KEY idx_tt_faculty_day (faculty_id, day_of_week);
ALTER TABLE timetables ADD KEY idx_tt_room_day (classroom_id, day_of_week);
ALTER TABLE timetables ADD KEY idx_tt_session_day (academic_session_id, day_of_week, time_slot_id);

-- Faculty-subject assignments are listed per session / section.
ALTER TABLE faculty_subjects ADD KEY idx_fs_section_session (section_id, academic_session_id);
