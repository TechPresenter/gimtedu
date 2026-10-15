<?php
/**
 * Team / management body page. Page settings: {"group":"management"} or {"group":"team"}
 * (optional {"groups":["team","volunteer"]}). "management" also lists advisors; "team" also lists volunteers.
 * Members come from Admin > Team & Management (published only).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$groups = page_setting($page, 'groups');
if (!is_array($groups) || !$groups) {
    $group = (string) page_setting($page, 'group', 'team');
    $groups = $group === 'management' ? ['management', 'advisor'] : ($group === 'team' ? ['team', 'volunteer'] : [$group]);
}
$groups = array_values(array_intersect(array_map('strval', $groups), array_keys(TEAM_GROUPS)));
$members = team_members($groups ?: ['team']);
$byGroup = [];
foreach ($members as $m) {
    $byGroup[$m['member_group']][] = $m;
}

$groupIntro = [
    'management' => 'The management body is responsible for the governance, direction and accountability of the foundation.',
    'advisor'    => 'Advisors guide our programmes with their experience. They support the foundation voluntarily.',
    'team'       => 'The people who plan, coordinate and deliver our programmes every day.',
    'volunteer'  => 'Volunteers who give their time and skills to our programmes and campaigns.',
];

page_header($page);
render_sections($page, function () use ($byGroup, $groups, $groupIntro, $page) {
    echo '<section class="section team-section" id="members"><div class="container">';
    if (!$byGroup) {
        echo empty_state('Details will be published here soon.', 'Contact us', '/contact/');
    }
    $first = true;
    foreach ($groups as $g) {
        if (empty($byGroup[$g])) {
            continue;
        }
        echo '<div class="team-group' . ($first ? '' : ' mt-4') . '">';
        echo ui_heading(TEAM_GROUPS[$g], '', $groupIntro[$g] ?? '');
        echo team_grid_html($byGroup[$g]);
        echo '</div>';
        $first = false;
    }
    echo '</div></section>';
});
page_footer();
