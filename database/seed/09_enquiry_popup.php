<?php
/**
 * Core seed: website enquiry popup (enquiry_modal() in includes/components.php + frontend/site/core/popup.ts).
 * INSERT IGNORE only — never overwrites values an administrator has changed (Settings › Website › Enquiry popup).
 * Re-runnable:  php tools/seed.php 09_enquiry_popup
 *
 * enquiry_modal_image   uploaded photo (empty = bundled assets/images/site/popup-counsellor.webp cut-out); a transparent PNG
 *                       cut-out is shown standing on the gold shape, other photos in a rounded frame
 * enquiry_popup_title   heading; words in [square brackets] get the yellow-gold highlight
 * enquiry_popup_ribbon  ribbon text; {session} = the admission session (e.g. 2026-27)
 * enquiry_popup_auto    1 = open automatically on the homepage once per visit (never after an enquiry was sent)
 * enquiry_popup_delay   seconds before it opens (3–60; phones additionally wait for 35 % scroll or 20 s)
 */
return function (array $opts): void {
    $settings = [
        'enquiry_modal_image' => '',
        'enquiry_popup_title' => 'Get [Free Counselling]',
        'enquiry_popup_ribbon' => 'Admissions Open {session}',
        'enquiry_popup_auto' => '1',
        'enquiry_popup_delay' => '8',
    ];
    foreach ($settings as $key => $value) {
        db_exec('INSERT IGNORE INTO settings (`group`, `key`, `value`) VALUES (?, ?, ?)', ['website', $key, $value]);
    }
};
