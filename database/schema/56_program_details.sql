-- Long-form course details (calendar, timetable, assessment, materials …) shown on the course page as
-- "Complete Program Details" — Academics › Programs › "Complete program details" (rich text). 2026-11-12.
ALTER TABLE programs ADD COLUMN details_html MEDIUMTEXT NULL AFTER career_prospects;
