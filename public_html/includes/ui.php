<?php
/**
 * UI components: icons, banner, design blocks (all helpers RETURN html unless noted).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// Inline SVG icons (no external icon library)
// ---------------------------------------------------------------------
function icon(string $name, string $class = ''): string
{
    static $icons = [
        'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'map-pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'chevron-left' => '<polyline points="15 18 9 12 15 6"/>',
        'chevrons-right' => '<polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/>',
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'arrow-up' => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'help-circle' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'printer' => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'award' => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'gift' => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'bar-chart' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'list' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'umbrella' => '<path d="M23 12a11.05 11.05 0 0 0-22 0zm-5 7a3 3 0 0 1-6 0v-7"/>',
        'leaf' => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'graduation' => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'tool' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'trending-up' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'refresh' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'send' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'rupee' => '<path d="M6 3h12"/><path d="M6 8h12"/><path d="m6 13 8.5 8"/><path d="M6 13h3"/><path d="M9 13c6.667 0 6.667-10 0-10"/>',
        'package' => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'message' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'youtube' => '<path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/>',
        'twitter' => '<path d="M4 4l11.733 16h4.267l-11.733 -16z"/><path d="M4 20l6.768 -6.768m2.46 -2.46l6.772 -6.772"/>',
        'linkedin' => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
        'instagram' => '<rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'id-card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2"/><path d="M14 10h4M14 14h4M5 17c.5-1.5 1.7-2 3-2s2.5.5 3 2"/>',
        'play' => '<circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>',
        'megaphone' => '<path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/>',
        'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
        'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'life-buoy' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"/><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
        'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'bell' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
        'key' => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68 1.65 1.65 0 0 0 10 3.17V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'pie-chart' => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'smartphone' => '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
        'sun' => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'move' => '<polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/>',
        'whatsapp' => '<path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9L3 21"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>',
        'mail-open' => '<path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6Z"/><path d="m22 10-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 10"/>',
    ];
    $paths = $icons[$name] ?? $icons['info'];
    return '<svg class="icon' . ($class ? ' ' . e($class) : '') . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
}

// ---------------------------------------------------------------------
// Page banner (breadcrumbs, title, subtitle, buttons, illustration)
// $crumbs: [[label, url], ...] between Home and the current page.
// ---------------------------------------------------------------------
function page_banner(string $title, string $subtitle = '', array $crumbs = [], array $opts = []): string
{
    $image = $opts['image'] ?? '';
    $html = '<section class="page-banner' . ($image ? ' has-art' : '') . '"><span class="banner-orb orb-1" aria-hidden="true"></span><span class="banner-orb orb-2" aria-hidden="true"></span>'
        . '<div class="container banner-inner"><div class="banner-text">';
    $html .= '<nav class="breadcrumb" aria-label="Breadcrumb"><a href="' . e(url('/')) . '">' . icon('home') . '<span>Home</span></a>';
    foreach ($crumbs as $crumb) {
        [$label, $href] = array_pad((array) $crumb, 2, '');
        $html .= '<span class="crumb-sep" aria-hidden="true">/</span>' . ($href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : '<span>' . e($label) . '</span>');
    }
    $html .= '<span class="crumb-sep" aria-hidden="true">/</span><span aria-current="page">' . e($title) . '</span></nav>';
    if (!empty($opts['eyebrow'])) {
        $html .= '<span class="banner-eyebrow">' . e($opts['eyebrow']) . '</span>';
    }
    $html .= '<h1>' . e($title) . '</h1>';
    if ($subtitle !== '') {
        $html .= '<p>' . e($subtitle) . '</p>';
    }
    if (!empty($opts['actions'])) {
        $html .= ui_buttons($opts['actions'], 'banner-actions');
    }
    $html .= '</div>';
    if ($image) {
        $html .= '<div class="banner-art float-slow"><img src="' . e(image_url($image)) . '" alt="" width="480" height="360" fetchpriority="high"></div>';
    }
    return $html . '</div></section>';
}

// ---------------------------------------------------------------------
// Design components used to build detailed, responsive page sections.
// Every helper RETURNS html (echo it). Text arguments are escaped;
// arguments named "html" are trusted markup written in the page file.
// ---------------------------------------------------------------------

/** Row of buttons. $actions: [['label' => '', 'url' => '', 'style' => 'donate|primary|outline|light|blue|ghost', 'icon' => '']] */
function ui_buttons(array $actions, string $class = 'btn-row'): string
{
    $html = '<div class="' . e($class) . '">';
    foreach ($actions as $a) {
        $style = in_array($a['style'] ?? 'primary', ['donate', 'primary', 'outline', 'light', 'blue', 'ghost'], true) ? ($a['style'] ?? 'primary') : 'primary';
        $html .= '<a class="btn btn-' . $style . (!empty($a['size']) ? ' btn-' . e($a['size']) : '') . '" href="' . e(safe_link($a['url'] ?? '/')) . '"'
            . (!empty($a['new_tab']) ? ' target="_blank" rel="noopener"' : '') . '>'
            . (!empty($a['icon']) ? icon($a['icon']) : '') . e($a['label'] ?? '') . '</a>';
    }
    return $html . '</div>';
}

/** Section heading: small pill, title and optional intro paragraph. */
function ui_heading(string $title, string $pill = '', string $intro = '', bool $center = false): string
{
    return '<div class="section-head' . ($center ? ' center' : '') . '">'
        . ($pill !== '' ? '<span class="pill">' . e($pill) . '</span>' : '')
        . '<h2>' . e($title) . '</h2>'
        . ($intro !== '' ? '<p>' . e($intro) . '</p>' : '')
        . '</div>';
}

/**
 * Grid of cards.
 * $items: [['icon' => 'book', 'title' => '', 'text' => '', 'points' => [], 'link' => 'page.php', 'link_label' => '', 'tone' => 'green|blue|gold|orange|teal']]
 * $style: card (default) | numbered | plain | soft
 */
function ui_features(array $items, int $cols = 3, string $style = 'card'): string
{
    $tones = ['green', 'blue', 'gold', 'orange', 'teal'];
    $cols = max(1, min(4, $cols));
    $html = '<div class="grid grid-' . $cols . ' feature-grid features-' . e($style) . '">';
    foreach (array_values($items) as $i => $it) {
        $tone = in_array($it['tone'] ?? '', $tones, true) ? $it['tone'] : $tones[$i % count($tones)];
        $html .= '<article class="feature-card tone-' . $tone . ' reveal tilt" style="--d:' . ($i % 4) . '">';
        if ($style === 'numbered') {
            $html .= '<span class="feature-num">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>';
        } elseif (!empty($it['icon'])) {
            $html .= '<span class="work-icon">' . icon($it['icon']) . '</span>';
        }
        $html .= '<h3>' . e($it['title'] ?? '') . '</h3>';
        if (!empty($it['text'])) {
            $html .= '<p>' . e($it['text']) . '</p>';
        }
        if (!empty($it['points'])) {
            $html .= '<ul class="dot-list">';
            foreach ($it['points'] as $pt) {
                $html .= '<li>' . e($pt) . '</li>';
            }
            $html .= '</ul>';
        }
        if (!empty($it['link'])) {
            $html .= '<a class="link-arrow" href="' . e(safe_link((string) $it['link'])) . '">' . e($it['link_label'] ?? 'Learn more') . ' ' . icon('arrow-right') . '</a>';
        }
        $html .= '</article>';
    }
    return $html . '</div>';
}

/** Numbered process / timeline. $steps: [['title' => '', 'text' => '', 'icon' => '']] */
function ui_steps(array $steps): string
{
    $html = '<ol class="steps steps-' . min(5, max(2, count($steps))) . '">';
    foreach (array_values($steps) as $i => $st) {
        $html .= '<li class="step reveal" style="--d:' . $i . '"><span class="step-num">' . ($i + 1) . '</span><div class="step-body">'
            . (!empty($st['icon']) ? '<span class="step-icon">' . icon($st['icon']) . '</span>' : '')
            . '<h3>' . e($st['title'] ?? '') . '</h3>'
            . (!empty($st['text']) ? '<p>' . e($st['text']) . '</p>' : '')
            . '</div></li>';
    }
    return $html . '</ol>';
}

/** FAQ accordion. $faqs: [['q' => '', 'a' => '']] (plain text answers) */
function ui_faqs(array $faqs, bool $openFirst = false): string
{
    $html = '<div class="faq-list">';
    foreach (array_values($faqs) as $i => $f) {
        $html .= '<details class="faq-item"' . ($openFirst && $i === 0 ? ' open' : '') . '><summary>' . e($f['q'] ?? '') . icon('chevron-down') . '</summary>'
            . '<div class="faq-answer">' . e_nl($f['a'] ?? '') . '</div></details>';
    }
    return $html . '</div>';
}

/**
 * Two-column block with an illustration and text.
 * $o: title, pill, text (plain), html (trusted), points (checklist), image, image_alt, reverse (bool), actions (ui_buttons)
 */
function ui_split(array $o): string
{
    $html = '<div class="split-block' . (!empty($o['reverse']) ? ' reverse' : '') . '">'
        . '<div class="split-media reveal"><img src="' . e(image_url($o['image'] ?? null)) . '" alt="' . e($o['image_alt'] ?? '') . '" loading="lazy" width="480" height="360"></div>'
        . '<div class="split-content reveal">'
        . (!empty($o['pill']) ? '<span class="pill">' . e($o['pill']) . '</span>' : '')
        . (!empty($o['title']) ? '<h2>' . e($o['title']) . '</h2>' : '')
        . (!empty($o['text']) ? '<p class="lead">' . e($o['text']) . '</p>' : '')
        . ($o['html'] ?? '')
        . (!empty($o['points']) ? ui_checklist($o['points'], 1) : '')
        . (!empty($o['actions']) ? ui_buttons($o['actions']) : '')
        . '</div></div>';
    return $html;
}

/** Tick-list, optionally in columns. */
function ui_checklist(array $items, int $cols = 2): string
{
    $html = '<ul class="check-list check-cols-' . max(1, min(3, $cols)) . '">';
    foreach ($items as $item) {
        $html .= '<li>' . icon('check') . '<span>' . e($item) . '</span></li>';
    }
    return $html . '</ul>';
}

/** Call-to-action band. */
function ui_cta(string $title, string $text, array $actions, bool $hindiTagline = false): string
{
    return '<div class="cta-band reveal"><span class="cta-orb" aria-hidden="true"></span><div><h2>' . e($title) . '</h2><p>' . e($text) . '</p>'
        . ($hindiTagline ? '<p class="hindi cta-tagline">' . e(setting('site_tagline')) . '</p>' : '')
        . '</div>' . ui_buttons($actions, 'cta-actions') . '</div>';
}

/** Compact strip of icon + title + text items (e.g. trust markers). */
function ui_highlights(array $items): string
{
    $html = '<div class="highlights">';
    foreach ($items as $it) {
        $html .= '<div class="highlight reveal"><span class="icon-circle">' . icon($it['icon'] ?? 'check') . '</span><div><strong>' . e($it['title'] ?? '') . '</strong>'
            . (!empty($it['text']) ? '<span>' . e($it['text']) . '</span>' : '') . '</div></div>';
    }
    return $html . '</div>';
}

/** Sticky in-page navigation. $links: ['section-id' => 'Label'] */
function ui_subnav(array $links): string
{
    $html = '<nav class="subnav" aria-label="On this page"><div class="container"><ul>';
    foreach ($links as $id => $label) {
        $html .= '<li><a href="#' . e($id) . '">' . e($label) . '</a></li>';
    }
    return $html . '</ul></div></nav>';
}

/** Highlighted note. $tone: green|gold|blue */
function ui_callout(string $title, string $text, string $tone = 'green', string $iconName = 'info'): string
{
    return '<div class="callout callout-' . e($tone) . '"><span class="callout-icon">' . icon($iconName) . '</span><div>'
        . ($title !== '' ? '<strong>' . e($title) . '</strong>' : '') . '<p>' . e($text) . '</p></div></div>';
}

/** Large quote / motto band (use only for the foundation's own words). */
function ui_quote(string $text, string $caption = ''): string
{
    return '<figure class="quote-band"><blockquote>' . e($text) . '</blockquote>'
        . ($caption !== '' ? '<figcaption>' . e($caption) . '</figcaption>' : '') . '</figure>';
}

/** Small key/value facts row (only for real, known facts). */
function ui_facts(array $facts): string
{
    $html = '<div class="kv-grid">';
    foreach ($facts as $label => $value) {
        $html .= '<div class="kv"><small>' . e($label) . '</small><strong>' . e($value) . '</strong></div>';
    }
    return $html . '</div>';
}

function empty_state(string $message, string $actionLabel = '', string $actionUrl = ''): string
{
    $html = '<div class="empty-state">' . icon('inbox') . '<p>' . e($message) . '</p>';
    if ($actionLabel !== '') {
        $html .= '<a class="btn btn-outline" href="' . e(safe_link($actionUrl)) . '">' . e($actionLabel) . '</a>';
    }
    return $html . '</div>';
}

/**
 * Donation progress widget. $raised must come from verified (status = success) donations only.
 * $goal 0 = no target: shows the verified amount without a percentage.
 */
function ui_progress(float $raised, float $goal, int $donations = 0, string $size = ''): string
{
    $pct = $goal > 0 ? (int) min(100, floor($raised / $goal * 100)) : 0;
    $html = '<div class="progress-widget' . ($size ? ' progress-size-' . e($size) : '') . '">';
    if ($goal > 0) {
        $html .= '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="Funding progress">'
            . '<span data-progress="' . $pct . '" style="width:' . $pct . '%"></span></div>';
    }
    $html .= '<div class="progress-meta"><span><strong>' . e(money($raised)) . '</strong> raised</span>'
        . ($goal > 0 ? '<span>' . $pct . '% of ' . e(money($goal)) . '</span>' : '<span>' . $donations . ' verified ' . ($donations === 1 ? 'donation' : 'donations') . '</span>')
        . '</div></div>';
    return $html;
}

/** Live countdown to a real end date (shown only when the date is in the future). */
function ui_countdown(?string $endDate, string $label = 'Campaign ends in'): string
{
    if (!$endDate) {
        return '';
    }
    $ts = strtotime(strlen($endDate) <= 10 ? $endDate . ' 23:59:59' : $endDate);
    if (!$ts || $ts <= time()) {
        return '';
    }
    $days = (int) floor(($ts - time()) / 86400);
    return '<div class="countdown" data-countdown="' . e(date('c', $ts)) . '" aria-live="off"><span class="countdown-label">' . icon('clock') . e($label) . '</span>'
        . '<span class="countdown-units"><span><b data-unit="d">' . $days . '</b><small>days</small></span><span><b data-unit="h">--</b><small>hrs</small></span>'
        . '<span><b data-unit="m">--</b><small>min</small></span><span><b data-unit="s">--</b><small>sec</small></span></span></div>';
}

/** Accessible modal dialog markup. Open with <button data-modal-open="ID">. */
function ui_modal(string $id, string $title, string $bodyHtml, string $class = ''): string
{
    return '<dialog class="modal ' . e($class) . '" id="' . e($id) . '" aria-labelledby="' . e($id) . '-title">'
        . '<div class="modal-card"><div class="modal-head"><h2 id="' . e($id) . '-title">' . e($title) . '</h2>'
        . '<button type="button" class="modal-close" data-modal-close aria-label="Close">' . icon('x') . '</button></div>'
        . '<div class="modal-body">' . $bodyHtml . '</div></div></dialog>';
}

/** Skeleton placeholder rows (shown while content loads). */
function ui_skeleton(int $rows = 3): string
{
    $html = '<div class="skeleton-wrap" aria-hidden="true">';
    for ($i = 0; $i < $rows; $i++) {
        $html .= '<span class="skeleton-line" style="width:' . (95 - ($i * 17) % 40) . '%"></span>';
    }
    return $html . '</div>';
}

/** Brand lockup: uploaded horizontal logo, or emblem + wordmark built in HTML. */
function brand_lockup(string $variant = 'header'): string
{
    $wide = site_logo_horizontal();
    if ($wide !== '') {
        return '<img class="brand-wide" src="' . e($wide) . '" alt="' . e(site_name()) . '" width="300" height="100">';
    }
    $name = site_name();
    $first = strtok($name, ' ');
    $rest = trim(substr($name, strlen((string) $first)));
    return '<img class="brand-emblem" src="' . e(site_logo()) . '" alt="" width="64" height="64">'
        . '<span class="brand-word"><span class="brand-name">' . e(mb_strtoupper((string) $first)) . '</span>'
        . '<span class="brand-sub">' . e(mb_strtoupper($rest)) . '</span>'
        . '<span class="brand-rule" aria-hidden="true"></span>'
        . ($variant === 'full' ? '<span class="brand-slogan">' . e(setting('brand_slogan')) . '</span>' : '')
        . '<span class="brand-hindi hindi">' . e(setting('brand_slogan_hi', setting('site_tagline'))) . '</span></span>';
}

