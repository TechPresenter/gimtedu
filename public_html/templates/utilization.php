<?php
/**
 * Campaign utilisation reports (/utilization-reports/): for every public campaign, verified donations
 * received vs approved and published expenses, the expense list and uploaded utilisation reports.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
page_header($page, ['schema' => [[
    '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $page['title'], 'url' => abs_page_url('utilization-reports'),
    'description' => (string) $page['meta_description'],
]]]);

render_sections($page, function () {
    $rows = campaign_utilisation_rows(true);
    $expenses = published_expenses(null, 1000);
    $byCampaign = [];
    foreach ($expenses as $x) {
        $byCampaign[(int) $x['campaign_id']][] = $x;
    }
    $received = array_sum(array_map(function ($r) { return (float) $r['raised']; }, $rows));
    $spent = array_sum(array_map(function ($r) { return (float) $r['spent']; }, $rows));
    $docs = fundraising_documents(['utilization_report']);
    ?>
    <section class="section fr-util" id="campaign-utilisation">
        <div class="container">
            <?= ui_heading('Campaign-wise utilisation', 'Where the money goes', 'Verified donations received by each campaign and the expenses that have been approved by our finance team and published.') ?>
            <?php if ($rows): ?>
                <div class="fr-kpis fr-kpis-3">
                    <div class="fr-kpi"><span class="icon-circle"><?= icon('rupee') ?></span><div><small>Received by campaigns (verified)</small><strong><?= e(money($received)) ?></strong></div></div>
                    <div class="fr-kpi"><span class="icon-circle blue"><?= icon('pie-chart') ?></span><div><small>Approved spending published</small><strong><?= e(money($spent)) ?></strong></div></div>
                    <div class="fr-kpi"><span class="icon-circle gold"><?= icon('flag') ?></span><div><small>Campaigns reported</small><strong><?= count($rows) ?></strong></div></div>
                </div>
                <div class="fr-util-list">
                    <?php foreach ($rows as $i => $r):
                        $pct = (float) $r['raised'] > 0 ? (int) min(100, round((float) $r['spent'] / (float) $r['raised'] * 100)) : 0;
                        $list = $byCampaign[(int) $r['id']] ?? []; ?>
                        <article class="fr-util-card reveal" style="--d:<?= $i % 3 ?>">
                            <header>
                                <div><h3><a href="<?= e(campaign_url($r['slug'])) ?>"><?= e($r['title']) ?></a></h3>
                                    <small class="muted"><?= e(campaign_category_label($r['category'])) ?> · <?= e(humanize($r['status'])) ?></small></div>
                                <?= status_badge($r['status']) ?>
                            </header>
                            <div class="fr-util-figures">
                                <div><small>Verified received</small><strong><?= e(money($r['raised'])) ?></strong><span class="muted small"><?= (int) $r['donations'] ?> donation<?= (int) $r['donations'] === 1 ? '' : 's' ?></span></div>
                                <div><small>Approved spending published</small><strong><?= e(money($r['spent'])) ?></strong><span class="muted small"><?= (int) $r['expenses'] ?> expense<?= (int) $r['expenses'] === 1 ? '' : 's' ?></span></div>
                            </div>
                            <?php if ((float) $r['raised'] > 0): ?>
                                <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Share of verified donations spent (published)"><span data-progress="<?= $pct ?>" style="width:<?= $pct ?>%"></span></div>
                                <p class="small muted"><?= $pct ?>% of verified donations accounted for in published expenses.</p>
                            <?php endif; ?>
                            <?php if ($list): ?>
                                <details class="fr-util-details">
                                    <summary><?= icon('list') ?>View <?= count($list) ?> published expense<?= count($list) === 1 ? '' : 's' ?></summary>
                                    <div class="table-wrap"><table class="table fr-table">
                                        <caption class="sr-only">Published expenses for <?= e($r['title']) ?></caption>
                                        <thead><tr><th scope="col">Date</th><th scope="col" class="fr-hide-sm">Category</th><th scope="col">Details</th><th scope="col" class="text-right">Amount</th></tr></thead>
                                        <tbody><?php foreach ($list as $x): ?><tr><td class="nowrap"><?= e(format_date($x['expense_date'])) ?></td><td class="fr-hide-sm"><?= e($x['category']) ?></td><td><small class="fr-show-sm muted"><?= e($x['category']) ?></small><?= e($x['description']) ?></td><td class="text-right nowrap"><?= e(money($x['amount'], true)) ?></td></tr><?php endforeach; ?></tbody>
                                    </table></div>
                                </details>
                            <?php else: ?>
                                <p class="small muted">Expenses will be listed here after approval by our finance team.</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?= empty_state('Utilisation reports will be published here once campaigns have received verified donations and their expenses have been approved.', 'See our campaigns', page_url('campaigns')) ?>
            <?php endif; ?>
            <p class="fr-verified-note"><?= icon('shield') ?><?= e(verified_figures_note()) ?></p>
        </div>
    </section>
    <section class="section section-tint" id="reports">
        <div class="container">
            <?= ui_heading('Utilisation reports', 'Documents', 'Detailed utilisation reports prepared for completed campaigns.') ?>
            <?= $docs ? fundraising_document_list($docs) : empty_state('Detailed utilisation reports will be published here once available.', 'Financial transparency', page_url('financial-transparency')) ?>
        </div>
    </section>
    <?php
}, 'before');
page_footer();
