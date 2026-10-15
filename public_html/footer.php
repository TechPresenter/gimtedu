<?php
/** Public site footer. */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$siteName = site_name();
$contactEmail = setting('contact_email');
$contactPhone = setting('contact_phone');
$whatsapp = preg_replace('/\D/', '', setting('whatsapp_number'));
unset($_SESSION['_old'], $_SESSION['_errors']);
?>
</main>

<section class="contact-band">
    <div class="container">
        <div class="contact-band-inner">
            <div class="contact-band-item">
                <span class="contact-band-icon"><?= icon('map-pin') ?></span>
                <div><small>Address</small><strong><?= e(setting('contact_address')) ?></strong></div>
            </div>
            <?php if ($contactPhone): ?>
                <div class="contact-band-item">
                    <span class="contact-band-icon"><?= icon('phone') ?></span>
                    <div><small>Call Us</small><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"><strong><?= e($contactPhone) ?></strong></a></div>
                </div>
            <?php endif; ?>
            <?php if ($contactEmail): ?>
                <div class="contact-band-item">
                    <span class="contact-band-icon"><?= icon('mail') ?></span>
                    <div><small>Send Email</small><a href="mailto:<?= e($contactEmail) ?>"><strong><?= e($contactEmail) ?></strong></a></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-about">
            <img src="<?= e(site_logo()) ?>" alt="<?= e($siteName) ?> logo" width="96" height="96" loading="lazy">
            <p><strong><?= e($siteName) ?></strong> is a nonprofit organization dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care, and skill development. We strive to build a fair and inclusive society where everyone can live with dignity and equal opportunity.</p>
            <?php if (setting('registration_number')): ?>
                <p class="footer-reg"><?= e(setting('registration_type', 'Registration')) ?> No: <?= e(setting('registration_number')) ?></p>
            <?php endif; ?>
        </div>

        <div class="footer-col">
            <h3>About</h3>
            <ul>
                <li><a href="<?= e(url('about.php')) ?>"><?= icon('chevrons-right') ?>About Us</a></li>
                <li><a href="<?= e(url('about.php#mission')) ?>"><?= icon('chevrons-right') ?>Mission &amp; Vision</a></li>
                <li><a href="<?= e(url('our-team.php#management')) ?>"><?= icon('chevrons-right') ?>Management Body</a></li>
                <li><a href="<?= e(url('financial-transparency.php')) ?>"><?= icon('chevrons-right') ?>NGO Details</a></li>
                <li><a href="<?= e(url('our-team.php')) ?>"><?= icon('chevrons-right') ?>Our Team</a></li>
                <li><a href="<?= e(url('impact-reports.php')) ?>"><?= icon('chevrons-right') ?>Impact Reports</a></li>
                <li><a href="<?= e(url('gallery.php')) ?>"><?= icon('chevrons-right') ?>Our Gallery</a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h3>Get Involved</h3>
            <ul>
                <li><a href="<?= e(url('volunteer.php')) ?>"><?= icon('chevrons-right') ?>Volunteer with Us</a></li>
                <li><a href="<?= e(url('apply-volunteer.php?type=intern')) ?>"><?= icon('chevrons-right') ?>Internships</a></li>
                <li><a href="<?= e(url('donate.php')) ?>"><?= icon('chevrons-right') ?>Donate / Fundraise</a></li>
                <li><a href="<?= e(url('campaigns.php')) ?>"><?= icon('chevrons-right') ?>Campaigns</a></li>
                <li><a href="<?= e(url('skill-development.php')) ?>"><?= icon('chevrons-right') ?>Skill Development &amp; Training</a></li>
                <li><a href="<?= e(url('blog.php')) ?>"><?= icon('chevrons-right') ?>Blogs &amp; Articles</a></li>
                <li><a href="<?= e(url('success-stories.php')) ?>"><?= icon('chevrons-right') ?>Success Stories</a></li>
                <li><a href="<?= e(url('blog.php?category=press')) ?>"><?= icon('chevrons-right') ?>Media / Press Releases</a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h3>Contact &amp; Legal</h3>
            <ul>
                <li><a href="<?= e(url('contact.php?type=feedback')) ?>"><?= icon('chevrons-right') ?>Feedback Form</a></li>
                <li><a href="<?= e(url('privacy-policy.php')) ?>"><?= icon('chevrons-right') ?>Privacy Policy</a></li>
                <li><a href="<?= e(url('terms.php')) ?>"><?= icon('chevrons-right') ?>Terms &amp; Conditions</a></li>
                <li><a href="<?= e(url('donation-policy.php')) ?>"><?= icon('chevrons-right') ?>Donation &amp; Refund Policy</a></li>
                <li><a href="<?= e(url('child-protection-policy.php')) ?>"><?= icon('chevrons-right') ?>Child Protection Policy</a></li>
                <li><a href="<?= e(url('grievance.php')) ?>"><?= icon('chevrons-right') ?>Grievance Redressal</a></li>
                <li><a href="<?= e(url('events.php')) ?>"><?= icon('chevrons-right') ?>Upcoming Events</a></li>
                <li><a href="<?= e(url('sitemap.php')) ?>"><?= icon('chevrons-right') ?>Sitemap</a></li>
            </ul>
        </div>

        <div class="footer-col footer-contact">
            <h3>Contact Us</h3>
            <ul class="footer-contact-list">
                <li><?= icon('map-pin') ?><span><?= e(setting('contact_address')) ?></span></li>
                <?php if ($contactEmail): ?><li><?= icon('mail') ?><a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></li><?php endif; ?>
                <?php if ($contactPhone): ?><li><?= icon('phone') ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a></li><?php endif; ?>
            </ul>
            <?php $socials = social_links(); if ($socials): ?>
                <div class="footer-social">
                    <?php foreach ($socials as $socialKey => $social): ?>
                        <a href="<?= e($social['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= e($social['label']) ?>"><?= icon($socialKey) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="newsletter-form" action="<?= e(url('process-contact.php')) ?>" method="post" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="form_type" value="newsletter">
                <input type="hidden" name="return_to" value="<?= e(current_page()) ?>">
                <?= honeypot_field() ?>
                <label for="newsletter-email">Subscribe to our newsletter</label>
                <div class="newsletter-row">
                    <input id="newsletter-email" type="email" name="email" placeholder="Your email address" required maxlength="190" autocomplete="email">
                    <button class="btn btn-donate btn-sm" type="submit" aria-label="Subscribe"><?= icon('send') ?></button>
                </div>
            </form>
        </div>
    </div>
    <div class="footer-bottom">
        <div class="container">
            &copy; <?= date('Y') ?> Copyrights by <strong><?= e($siteName) ?></strong>. All Rights Reserved.
        </div>
    </div>
</footer>

<div class="floating-actions">
    <?php if ($whatsapp): ?>
        <a class="float-btn float-whatsapp" href="https://wa.me/<?= e(strlen($whatsapp) === 10 ? '91' . $whatsapp : $whatsapp) ?>" target="_blank" rel="noopener noreferrer" aria-label="Chat on WhatsApp"><?= icon('whatsapp') ?></a>
    <?php endif; ?>
    <?php if ($contactPhone): ?>
        <a class="float-btn float-call" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>" aria-label="Call us"><?= icon('phone') ?></a>
    <?php endif; ?>
    <button class="float-btn back-to-top" type="button" aria-label="Back to top"><?= icon('arrow-up') ?></button>
</div>

<div class="lightbox" id="lightbox" hidden>
    <button class="lightbox-close" type="button" aria-label="Close"><?= icon('x') ?></button>
    <button class="lightbox-prev" type="button" aria-label="Previous image"><?= icon('chevron-left') ?></button>
    <figure><img src="" alt=""><figcaption></figcaption></figure>
    <button class="lightbox-next" type="button" aria-label="Next image"><?= icon('chevron-right') ?></button>
</div>

<?php foreach ($extraScripts ?? [] as $src): ?>
    <script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
<script src="<?= e(asset('assets/js/script.js')) ?>"></script>
<script src="<?= e(asset('assets/js/form-validation.js')) ?>"></script>
</body>
</html>
