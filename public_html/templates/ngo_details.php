<?php
/**
 * NGO Details: legal & registration facts entered in Admin > Settings (blank values are never shown),
 * the organisation description, contact details, registration certificates (Admin > Certificates & Reports)
 * and links to transparency and policy pages. Organisation JSON-LD is printed site-wide by the header.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
page_header($page);

render_sections($page, function () {
    $details = ngo_details();
    $registration = ngo_registration_details();
    $certificates = resources_by_category('certification');
    $links = [
        ['Financial Transparency', 'financial-transparency', 'shield', 'Verified donation totals and accountability'],
        ['Certificates', 'certifications', 'award', 'Registration and recognition documents'],
        ['Impact Reports', 'impact-reports', 'bar-chart', 'Reports on our programmes'],
        ['Management Body', 'management-body', 'users', 'Governance of the foundation'],
        ['Privacy Policy', 'privacy-policy', 'lock', 'How we protect your information'],
        ['Donation & Refund Policy', 'donation-refund-policy', 'file-text', 'How donations are received and used'],
    ];
    ?>
    <section class="section" id="details">
        <div class="container ngo-layout">
            <div class="ngo-main">
                <div class="section-head"><span class="pill">Organisation</span><h2>Legal &amp; registration details</h2>
                    <p>These details are entered by the foundation from its official records.</p></div>
                <div class="table-wrap reveal">
                    <table class="info-table ngo-table">
                        <caption class="sr-only">Registration and organisation details of <?= e(site_name()) ?></caption>
                        <tbody>
                        <?php foreach ($details as $label => $value): ?>
                            <tr><th scope="row"><?= e($label) ?></th><td>
                                <?php if ($label === 'Email'): ?><a href="mailto:<?= e($value) ?>"><?= e($value) ?></a>
                                <?php elseif ($label === 'Phone'): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $value)) ?>"><?= e($value) ?></a>
                                <?php elseif ($label === 'Website'): ?><a href="<?= e(abs_url('')) ?>"><?= e($value) ?></a>
                                <?php else: ?><?= e($value) ?><?php endif; ?>
                            </td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!$registration): ?>
                    <div class="notice notice-gold mt-2"><?= icon('info') ?><div>Registration numbers (registration certificate, PAN, 12A, 80G, CSR-1, NITI Aayog Darpan) will be published here once they are verified and entered by the foundation. Please do not rely on numbers shared through any other channel.</div></div>
                <?php elseif (setting('reg_80g_number') === ''): ?>
                    <p class="muted small mt-2"><?= icon('info') ?> Tax benefits under Section 80G apply only when an 80G registration is listed above.</p>
                <?php endif; ?>

                <div class="ngo-certs mt-4" id="certificates">
                    <h2 class="h3">Registration certificates</h2>
                    <?php if ($certificates): ?>
                        <?= document_list($certificates, false) ?>
                    <?php else: ?>
                        <?= empty_state('Copies of registration certificates will be published here once available.') ?>
                    <?php endif; ?>
                </div>
            </div>

            <aside class="ngo-aside">
                <div class="aside-card reveal">
                    <h2 class="h3">About the foundation</h2>
                    <p><?= e(setting('footer_about_text', setting('site_description'))) ?></p>
                    <p class="hindi ngo-tagline"><?= e(setting('site_tagline')) ?></p>
                </div>
                <?php $cards = contact_cards_html(true); if ($cards !== ''): ?>
                    <div class="aside-card reveal"><h2 class="h3">Contact</h2><?= $cards ?><?= social_links_html() ?></div>
                <?php endif; ?>
                <div class="aside-card reveal">
                    <h2 class="h3">Transparency &amp; policies</h2>
                    <ul class="link-list">
                        <?php foreach ($links as [$label, $slug, $ic, $desc]): if (!get_page($slug)) { continue; } ?>
                            <li><a href="<?= e(page_url($slug)) ?>"><?= icon($ic) ?><?= e($label) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        </div>
    </section>
    <?php
});
page_footer();
