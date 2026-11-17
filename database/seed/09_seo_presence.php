<?php
/**
 * Core seed (SEO growth — seo-admin): the digital presence checklist (SEO Management › Presence). INSERT IGNORE by
 * item_key: rows an administrator has already worked on are never changed, and no row is ever seeded as verified or
 * submitted — every status starts as "not_started" (or "not_applicable" where the platform clearly does not apply).
 * The list itself lives in app/services/seo_presence.php (seo_presence_checklist()).
 */
require_once APP_ROOT . '/app/services/seo_presence.php';

return function (array $opts): void {
    try {
        db_value('SELECT COUNT(*) FROM seo_presence');
    } catch (Throwable $e) {
        return; // schema 57 not applied yet
    }
    foreach (seo_presence_checklist() as $row) {
        db_exec('INSERT IGNORE INTO seo_presence (item_key, category, title, platform_url, status, method, next_action, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $row['item_key'], $row['category'], $row['title'], $row['platform_url'], in_array($row['status'], ['not_started', 'not_applicable'], true) ? $row['status'] : 'not_started',
            $row['method'], mb_substr((string) $row['next_action'], 0, 255), $row['sort_order'],
        ]);
    }
};
