<?php
/**
 * Campus map data for the homepage Campus Explorer (island CampusExplorer, docs/HOMEPAGE.md).
 *
 * The illustration is an original SVG drawn for GIMT (assets/images/site/campus-map/campus-map.svg, viewBox
 * 1200 × 760). Each zone is a hotspot at (x, y) in that viewBox; the island converts it to percentages so the pins
 * stay on their building at every width. Facts shown in the info panel come from the database where it holds them
 * (classrooms / labs / auditorium from `classrooms`, library holdings from `books`, hostels + amenities from
 * `hostels`, bus routes from `transport_routes`); everything else is short qualitative copy without numbers.
 * Zone copy lives here until the CMS gains an editable "campus map" contract (see the request in docs/HOMEPAGE.md).
 */

/** Safe DB call for the map facts (missing tables → fallback). */
function hm_cm_try(callable $fn, $fallback = null)
{
    try {
        $v = $fn();
        return $v === null || $v === false ? $fallback : $v;
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * Campus map: ['image' => URL, 'width' => 1200, 'height' => 760, 'alt' => text, 'zones' => [...]].
 * Zone: id, n (pin number), name, category (academic|facility|residential|sports|services|green), icon (lucide),
 *       x, y (viewBox units), summary (one line), description, facts (string[] — live where possible),
 *       image (URL|null), image_alt, link {label, url}|null.
 */
function hm_campus_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    // ---- live facts (each query guarded; empty → qualitative copy only)
    $rooms = hm_cm_try(fn () => db_all("SELECT building, type, COUNT(*) AS n, COALESCE(SUM(capacity), 0) AS seats, GROUP_CONCAT(name ORDER BY name SEPARATOR '|') AS names
        FROM classrooms WHERE status = 'active' GROUP BY building, type"), []);
    $by = [];
    foreach ($rooms as $r) {
        $by[(string) $r['building']][(string) $r['type']] = ['n' => (int) $r['n'], 'seats' => (int) $r['seats'], 'names' => array_values(array_filter(explode('|', (string) $r['names'])))];
    }
    $find = function (string $needle, string $type) use ($by): ?array {
        foreach ($by as $building => $types) {
            if (stripos($building, $needle) !== false && isset($types[$type])) {
                return $types[$type] + ['building' => $building];
            }
        }
        return null;
    };
    $plural = fn (int $n, string $one, string $many) => number_format($n) . ' ' . ($n === 1 ? $one : $many);

    $blockA = ['classroom' => $find('Block A', 'classroom'), 'seminar' => $find('Block A', 'seminar_hall')];
    $blockB = ['classroom' => $find('Block B', 'classroom'), 'exam' => $find('Block B', 'exam_hall')];
    $tech = ['classroom' => $find('Technology', 'classroom'), 'lab' => $find('Technology', 'lab')];
    $auditorium = null;
    foreach ($by as $building => $types) {
        if (isset($types['auditorium'])) {
            $auditorium = $types['auditorium'] + ['building' => $building];
            break;
        }
    }
    $library = hm_cm_try(fn () => db_row("SELECT COUNT(*) AS titles, COALESCE(SUM(total_copies), 0) AS copies FROM books WHERE status = 'active'"), null);
    $hostels = hm_cm_try(fn () => db_all("SELECT name, type, amenities FROM hostels WHERE status = 'active' ORDER BY type, name"), []);
    $routes = (int) hm_cm_try(fn () => db_value("SELECT COUNT(*) FROM transport_routes WHERE status = 'active'"), 0);

    $amenityLabels = ['wifi' => 'Wi-Fi', 'mess' => 'Mess', 'laundry' => 'Laundry', 'ro_water' => 'RO drinking water', 'hot_water' => 'Hot water',
        'power_backup' => 'Power backup', 'cctv' => 'CCTV security', 'housekeeping' => 'Housekeeping', 'medical' => 'Medical support', 'gym' => 'Gym',
        'reading_room' => 'Reading room', 'common_room' => 'Common room', 'indoor_games' => 'Indoor games', 'ac' => 'Air-conditioned rooms'];
    $amenities = [];
    foreach ($hostels as $h) {
        $list = json_decode((string) $h['amenities'], true);
        foreach (is_array($list) ? $list : preg_split('/[,;\n]/', (string) $h['amenities']) as $a) {
            $k = strtolower(str_replace([' ', '-'], '_', trim((string) $a)));
            if ($k !== '') {
                $amenities[$k] = $amenityLabels[$k] ?? ucfirst(str_replace('_', ' ', $k));
            }
        }
    }
    $boys = count(array_filter($hostels, fn ($h) => $h['type'] === 'boys'));
    $girls = count(array_filter($hostels, fn ($h) => $h['type'] === 'girls'));

    $img = fn (string $f) => asset('assets/images/site/' . $f);
    $zones = [
        [
            'id' => 'main-building', 'name' => 'Main Building', 'category' => 'services', 'icon' => 'landmark', 'x' => 600, 'y' => 592,
            'summary' => 'Admissions, administration and the main auditorium — right by the main gate.',
            'description' => 'The first stop for every visitor: the admission cell, administrative offices and the main auditorium where orientations, convocations and guest lectures take place.',
            'facts' => array_values(array_filter([
                $auditorium ? 'Main auditorium' . ($auditorium['seats'] ? ' · ' . number_format($auditorium['seats']) . ' seats' : '') : 'Main auditorium',
                'Admission cell', 'Conference room', 'Administrative offices',
            ])),
            'image' => $img('campus-building.jpg'), 'image_alt' => 'Front view of the GIMT main building with its landscaped entrance',
            'link' => ['label' => 'Admissions', 'url' => site_url('admissions')],
        ],
        [
            'id' => 'academic-block-a', 'name' => 'Academic Block A', 'category' => 'academic', 'icon' => 'school', 'x' => 300, 'y' => 345,
            'summary' => 'Classrooms and the seminar hall for discussion-led teaching.',
            'description' => 'Air-conditioned classrooms with projectors and interactive panels, plus the seminar hall used for case discussions, workshops and industry talks.',
            'facts' => array_values(array_filter([
                $blockA['classroom'] ? $plural($blockA['classroom']['n'], 'classroom', 'classrooms') . ($blockA['classroom']['seats'] ? ' · ' . number_format($blockA['classroom']['seats']) . ' seats' : '') : 'Smart classrooms',
                $blockA['seminar'] ? 'Seminar hall' . ($blockA['seminar']['seats'] ? ' · ' . number_format($blockA['seminar']['seats']) . ' seats' : '') : null,
                'Projectors & interactive panels',
            ])),
            'image' => $img('campus-academic-block.jpg'), 'image_alt' => 'Academic block exterior with classrooms and faculty offices',
            'link' => ['label' => 'Academics', 'url' => site_url('academics') . '#facilities'],
        ],
        [
            'id' => 'academic-block-b', 'name' => 'Academic Block B', 'category' => 'academic', 'icon' => 'presentation', 'x' => 300, 'y' => 466,
            'summary' => 'More classrooms and the examination halls.',
            'description' => 'A second teaching block with tiered classrooms and the examination halls used for mid-semester and end-semester examinations.',
            'facts' => array_values(array_filter([
                $blockB['classroom'] ? $plural($blockB['classroom']['n'], 'classroom', 'classrooms') . ($blockB['classroom']['seats'] ? ' · ' . number_format($blockB['classroom']['seats']) . ' seats' : '') : 'Classrooms',
                $blockB['exam'] ? $plural($blockB['exam']['n'], 'examination hall', 'examination halls') . ($blockB['exam']['seats'] ? ' · ' . number_format($blockB['exam']['seats']) . ' seats' : '') : 'Examination halls',
            ])),
            'image' => $img('facility-classroom.jpg'), 'image_alt' => 'Smart classroom with interactive display and tiered seating',
            'link' => ['label' => 'Academics', 'url' => site_url('academics') . '#facilities'],
        ],
        [
            'id' => 'technology-block', 'name' => 'Technology Block & Labs', 'category' => 'academic', 'icon' => 'cpu', 'x' => 902, 'y' => 398,
            'summary' => 'Engineering and computing classrooms with specialised laboratories.',
            'description' => 'Home to the computing and engineering programs: classrooms plus specialised labs with modern workstations and licensed software for hands-on learning.',
            'facts' => array_values(array_filter(array_merge(
                [$tech['classroom'] ? $plural($tech['classroom']['n'], 'classroom', 'classrooms') : null,
                 $tech['lab'] ? $plural($tech['lab']['n'], 'specialised lab', 'specialised labs') : 'Specialised laboratories'],
                array_slice($tech['lab']['names'] ?? [], 0, 4)
            ))),
            'image' => $img('facility-labs.jpg'), 'image_alt' => 'Students working on desktops in the computer laboratory',
            'link' => ['label' => 'Labs & facilities', 'url' => site_url('academics') . '#facilities'],
        ],
        [
            'id' => 'library', 'name' => 'Central Library', 'category' => 'facility', 'icon' => 'library', 'x' => 600, 'y' => 262,
            'summary' => 'A quiet reading hall with books, journals and e-resources.',
            'description' => 'The library and learning centre overlooks the central lawn — text and reference books, journals and digital resources in a calm, air-conditioned reading hall.',
            'facts' => array_values(array_filter([
                $library && (int) $library['titles'] > 0 ? number_format((int) $library['titles']) . ' titles · ' . number_format((int) $library['copies']) . ' volumes' : 'Books, journals & e-resources',
                'Quiet reading zone', 'Digital resources',
            ])),
            'image' => $img('campus-library.jpg'), 'image_alt' => 'Reading hall of the central library and learning centre',
            'link' => ['label' => 'Campus facilities', 'url' => site_url('campus') . '#facilities'],
        ],
        [
            'id' => 'hostels', 'name' => 'Hostels', 'category' => 'residential', 'icon' => 'bed-double', 'x' => 286, 'y' => 192,
            'summary' => $hostels ? 'Separate, secure hostels for ' . ($boys && $girls ? 'boys and girls' : ($girls ? 'girls' : 'boys')) . ' inside the campus.' : 'Secure on-campus residences.',
            'description' => 'On-campus residences with a mess, study spaces and round-the-clock support, a short walk from the academic blocks.',
            'facts' => array_values(array_filter(array_merge(
                [$hostels ? $plural(count($hostels), 'hostel', 'hostels') . ($boys && $girls ? ' · ' . $boys . ' boys, ' . $girls . ' girls' : '') : null],
                array_slice(array_values($amenities), 0, 5)
            ))),
            'image' => $img('campus-hostel.jpg'), 'image_alt' => 'On-campus hostel building for students',
            'link' => ['label' => 'Hostel details', 'url' => site_url('campus') . '#hostel'],
        ],
        [
            'id' => 'sports', 'name' => 'Sports Complex', 'category' => 'sports', 'icon' => 'trophy', 'x' => 910, 'y' => 205,
            'summary' => 'Sports ground, courts and space for the annual sports meet.',
            'description' => 'The sports ground and outdoor courts host inter-college tournaments, the annual sports meet and everyday fitness.',
            'facts' => ['Sports ground', 'Basketball & volleyball courts', 'Indoor games'],
            'image' => $img('facility-sports.jpg'), 'image_alt' => 'Outdoor basketball and volleyball courts on campus',
            'link' => ['label' => 'Campus life', 'url' => site_url('campus') . '#facilities'],
        ],
        [
            'id' => 'cafeteria', 'name' => 'Cafeteria', 'category' => 'services', 'icon' => 'utensils', 'x' => 306, 'y' => 596,
            'summary' => 'Meals, snacks and a place to meet between classes.',
            'description' => 'A casual space close to the academic blocks for a quick meal, a cup of chai and conversations between classes.',
            'facts' => ['Meals & snacks', 'Seating for groups'],
            'image' => null, 'image_alt' => '',
            'link' => ['label' => 'Campus life', 'url' => site_url('campus')],
        ],
        [
            'id' => 'transport', 'name' => 'Parking & Transport Bay', 'category' => 'services', 'icon' => 'bus', 'x' => 916, 'y' => 590,
            'summary' => $routes ? 'College buses on ' . $routes . ' routes leave from here.' : 'Parking and the college bus bay near the main gate.',
            'description' => 'Visitor and student parking near the main gate, and the bay where the college buses pick up and drop students.',
            'facts' => array_values(array_filter([$routes ? $plural($routes, 'bus route', 'bus routes') : 'College bus service', 'Visitor parking', 'Near the main gate'])),
            'image' => null, 'image_alt' => '',
            'link' => ['label' => 'Transport', 'url' => site_url('campus') . '#transport'],
        ],
        [
            'id' => 'lawn', 'name' => 'Central Lawn & Green Zones', 'category' => 'green', 'icon' => 'trees', 'x' => 600, 'y' => 446,
            'summary' => 'Tree-lined walkways and the lawn at the heart of campus.',
            'description' => 'The central lawn is where fests, celebrations and group study happen, surrounded by tree-lined walkways that connect every block.',
            'facts' => ['Central lawn', 'Tree-lined walkways', 'Celebrations & fests'],
            'image' => $img('facility-green-campus.jpg'), 'image_alt' => 'Tree-lined walkway across the green campus lawns',
            'link' => ['label' => 'Photo gallery', 'url' => site_url('campus') . '#gallery'],
        ],
    ];
    foreach ($zones as $i => &$z) {
        $z['n'] = $i + 1;
    }
    unset($z);

    return $map = [
        'image' => asset('assets/images/site/campus-map/campus-map.svg'),
        'width' => 1200,
        'height' => 760,
        'alt' => 'Illustrated map of the GIMT campus: main building by the main gate, academic blocks A and B to the west, the technology block to the east, the central library above the central lawn, hostels in the north-west, the sports complex in the north-east, the cafeteria and the parking and transport bay near the gate.',
        'categories' => [
            'academic' => 'Academic', 'facility' => 'Library', 'residential' => 'Hostels', 'sports' => 'Sports', 'services' => 'Services', 'green' => 'Green zones',
        ],
        'zones' => $zones,
    ];
}
