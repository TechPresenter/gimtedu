<?php
/**
 * Financial transparency (/financial-transparency/): verified donation totals by financial year and by
 * campaign, utilisation summary, registration facts entered in settings (non-empty only) and published
 * financial documents. Every figure is calculated live from verified donations / approved expenses.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
page_header($page, ['schema' => [[
    '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $page['title'], 'url' => abs_page_url('financial-transparency'),
    'description' => (string) $page['meta_description'], 'publisher' => ['@type' => 'NGO', '@id' => abs_url('#organization'), 'name' => site_name(), 'url' => abs_url('')],
]]]);

render_sections($page, function () {
    $byYear = verified_totals_by_fy();
    $rows = campaign_utilisation_rows(true);
    $general = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM donations WHERE status = 'success' AND campaign_id IS NULL");
    $totals = verified_donation_totals();
    $spentAll = (float) db_value("SELECT COALESCE(SUM(e.amount), 0) FROM campaign_expenses e JOIN campaigns c ON c.id = e.campaign_id WHERE e.status = 'approved' AND e.is_published = 1 AND c.status IN ('active','completed','closed')");
    $facts = [];
    foreach (['legal_name' => 'Legal name', 'registration_type' => 'Registered as', 'registration_number' => 'Registration number', 'registration_date' => 'Registration date',
                 'pan_number' => 'PAN of the foundation', 'reg_12a_number' => '12A registration', 'reg_80g_number' => '80G registration', 'csr1_number' => 'CSR-1 registration',
                 'darpan_id' => 'NITI Aayog Darpan ID', 'fcra_number' => 'FCRA registration', 'contact_address' => 'Office address'] as $key => $label) {
        if (trim(setting($key)) !== '') {
            $facts[$label] = trim(setting($key));
        }
    }
    $hasRegistration = count(array_diff_key($facts, ['Legal name' => 1, 'Office address' => 1])) > 0;
    $docs = fundraising_documents(['financial_statement', 'annual_report', 'utilization_report']);
    ?>
    <section class="section fr-transparency" id="verified-totals">
        <div class="container">
            <?= ui_heading('Donations received', 'Verified figures', 'Calculated live from donations confirmed by our payment gateway or reconciled by our finance team. Refunded donations are excluded.') ?>
            <div class="fr-kpis">
                <div class="fr-kpi"><span class="icon-circle"><?= icon('rupee') ?></span><div><small>Total received (verified)</small><strong><?= $totals['total'] > 0 ? e(money($totals['total'])) : '—' ?></strong></div></div>
                <div class="fr-kpi"><span class="icon-circle gold"><?= icon('heart') ?></span><div><small>Verified donations</small><strong><?= $totals['count'] > 0 ? number_format($totals['count']) : '—' ?></strong></div></div>
                <div class="fr-kpi"><span class="icon-circle blue"><?= icon('pie-chart') ?></span><div><small>Approved spending published</small><strong><?= $spentAll > 0 ? e(money($spentAll)) : '—' ?></strong></div></div>
                <div class="fr-kpi"><span class="icon-circle"><?= icon('file-text') ?></span><div><small>Receipts issued</small><strong><?= $totals['receipts'] > 0 ? number_format($totals['receipts']) : '—' ?></strong></div></div>
            </div>
            <?php if ($totals['count'] === 0): ?>
                <?= ui_callout('No verified donations recorded yet', 'This page fills in automatically as soon as donations are verified. We never publish estimated or unverified amounts.', 'blue', 'shield') ?>
            <?php endif; ?>

            <div class="fr-two">
                <div class="fr-block">
                    <h3><?= icon('calendar') ?>By financial year</h3>
                    <?php if ($byYear): ?>
                        <div class="table-wrap"><table class="table fr-table">
                            <caption class="sr-only">Verified donations by financial year</caption>
                            <thead><tr><th scope="col">Financial year</th><th scope="col">Donations</th><th scope="col" class="fr-hide-sm">Online / offline</th><th scope="col" class="text-right">Total</th></tr></thead>
                            <tbody>
                            <?php foreach ($byYear as $y): ?>
                                <tr><td><strong>FY <?= e($y['fy_start'] . '-' . substr((string) ($y['fy_start'] + 1), -2)) ?></strong></td><td><?= number_format((int) $y['donations']) ?></td>
                                    <td class="fr-hide-sm"><?= (int) $y['online_count'] ?> / <?= (int) $y['offline_count'] ?></td><td class="text-right nowrap"><strong><?= e(money($y['total'], true)) ?></strong></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                    <?php else: ?>
                        <p class="muted">Year-wise totals will appear here once donations are verified.</p>
                    <?php endif; ?>
                </div>
                <div class="fr-block">
                    <h3><?= icon('flag') ?>By campaign</h3>
                    <?php if ($rows || (int) $general['n'] > 0): ?>
                        <div class="table-wrap"><table class="table fr-table">
                            <caption class="sr-only">Verified donations and published spending by campaign</caption>
                            <thead><tr><th scope="col">Campaign</th><th scope="col" class="text-right">Received</th><th scope="col" class="text-right">Spent (published)</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr><td><a href="<?= e(campaign_url($r['slug'])) ?>"><?= e($r['title']) ?></a><br><small class="muted"><?= (int) $r['donations'] ?> verified donation<?= (int) $r['donations'] === 1 ? '' : 's' ?> · <?= e(humanize($r['status'])) ?></small></td>
                                    <td class="text-right nowrap"><?= e(money($r['raised'])) ?></td><td class="text-right nowrap"><?= (float) $r['spent'] > 0 ? e(money($r['spent'])) : '<span class="muted">—</span>' ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ((int) $general['n'] > 0): ?>
                                <tr><td>General fund (where it is needed most)<br><small class="muted"><?= (int) $general['n'] ?> verified donation<?= (int) $general['n'] === 1 ? '' : 's' ?></small></td>
                                    <td class="text-right nowrap"><?= e(money($general['total'])) ?></td><td class="text-right"><span class="muted">—</span></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table></div>
                        <p class="small mt-1"><a class="link-arrow" href="<?= e(page_url('utilization-reports')) ?>">Campaign utilisation details <?= icon('arrow-right') ?></a></p>
                    <?php else: ?>
                        <p class="muted">Campaign-wise totals will appear here once donations to campaigns are verified.</p>
                    <?php endif; ?>
                </div>
            </div>
            <p class="fr-verified-note"><?= icon('shield') ?><?= e(verified_figures_note()) ?></p>
        </div>
    </section>

    <section class="section section-tint" id="registration">
        <div class="container">
            <?= ui_heading('Registration & legal details', 'Who we are', 'Published from the foundation\'s records. Only details that have been entered and verified are shown.') ?>
            <?php if ($facts): ?><?= ui_facts($facts) ?><?php endif; ?>
            <?php if (!$hasRegistration): ?>
                <p class="muted mt-2">Registration numbers and certificates will be published here once verified copies are uploaded. You can ask us for any detail through the <a href="<?= e(page_url('contact')) ?>">contact page</a>.</p>
            <?php endif; ?>
            <div class="btn-row mt-3">
                <a class="btn btn-outline" href="<?= e(page_url('ngo-details')) ?>"><?= icon('info') ?>NGO details</a>
                <a class="btn btn-outline" href="<?= e(page_url('certifications')) ?>"><?= icon('award') ?>Certificates</a>
                <a class="btn btn-ghost" href="<?= e(page_url('donation-refund-policy')) ?>"><?= icon('file-text') ?>Donation &amp; refund policy</a>
            </div>
        </div>
    </section>

    <section class="section" id="documents">
        <div class="container">
            <?= ui_heading('Financial documents & reports', 'Reports', 'Audited statements, annual reports and campaign utilisation reports.') ?>
            <?= $docs ? fundraising_document_list($docs) : empty_state('Financial statements and reports will be published here once available.', 'Ask for a document', page_url('contact')) ?>
        </div>
    </section>
    <?php
}, 'before');
page_footer();
