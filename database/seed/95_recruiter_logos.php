<?php
/**
 * Demo seed (Placement — recruiter logos): attaches the bundled official logos in assets/images/recruiters/ to the
 * real recruiter companies created by 40_placement.php (matched by name, case-insensitive, with common variants such
 * as "HCL Technologies" / "HCLTech", "EY GDS" / "Ernst & Young", "Deloitte USI", "KPMG India", "ITC Limited").
 * Fictional demo companies have no entry here and keep the text wordmark fallback.
 *
 * - companies.logo is set only when it is empty or already points at a bundled recruiter logo, so a logo uploaded by
 *   an admin (uploads/...) is never overwritten.
 * - companies.website is filled with the official domain only when it is empty.
 * Idempotent: safe to re-run at any time (also after 40_placement.php re-creates the companies).
 * Logo sources and trademark notes: assets/images/recruiters/SOURCES.md.
 */

return function (array $opts): void {
    $dir = 'assets/images/recruiters/';

    // asset file => [official website, accepted company names]
    $logos = [
        'tata-consultancy-services.svg' => ['https://www.tcs.com', ['Tata Consultancy Services', 'TCS', 'Tata Consultancy']],
        'infosys.svg' => ['https://www.infosys.com', ['Infosys', 'Infosys Technologies']],
        'wipro.svg' => ['https://www.wipro.com', ['Wipro', 'Wipro Technologies']],
        'hcltech.svg' => ['https://www.hcltech.com', ['HCLTech', 'HCL Tech', 'HCL Technologies', 'HCL']],
        'tech-mahindra.svg' => ['https://www.techmahindra.com', ['Tech Mahindra', 'TechM']],
        'accenture.svg' => ['https://www.accenture.com', ['Accenture', 'Accenture India']],
        'capgemini.svg' => ['https://www.capgemini.com', ['Capgemini', 'Capgemini India']],
        'cognizant.svg' => ['https://www.cognizant.com', ['Cognizant', 'Cognizant Technology Solutions']],
        'ltimindtree.svg' => ['https://www.ltm.com', ['LTIMindtree', 'LTI Mindtree', 'LTM']],
        'nagarro.svg' => ['https://www.nagarro.com', ['Nagarro']],
        'coforge.png' => ['https://www.coforge.com', ['Coforge', 'NIIT Technologies']],
        'genpact.png' => ['https://www.genpact.com', ['Genpact']],
        'deloitte-usi.svg' => ['https://www.deloitte.com', ['Deloitte', 'Deloitte USI', 'Deloitte India', 'Deloitte US India']],
        'kpmg-india.svg' => ['https://kpmg.com/in', ['KPMG', 'KPMG India', 'KPMG Global Services']],
        'ey-gds.svg' => ['https://www.ey.com', ['EY', 'EY GDS', 'EY India', 'EY Global Delivery Services', 'Ernst & Young', 'Ernst and Young']],
        'zs-associates.svg' => ['https://www.zs.com', ['ZS', 'ZS Associates']],
        'hdfc-bank.svg' => ['https://www.hdfcbank.com', ['HDFC Bank', 'HDFC']],
        'icici-bank.png' => ['https://www.icicibank.com', ['ICICI Bank', 'ICICI']],
        'axis-bank.svg' => ['https://www.axisbank.com', ['Axis Bank']],
        'bajaj-finserv.svg' => ['https://www.bajajfinserv.in', ['Bajaj Finserv']],
        'kotak-mahindra-bank.svg' => ['https://www.kotak.com', ['Kotak Mahindra Bank', 'Kotak', 'Kotak Bank']],
        'amazon.svg' => ['https://www.amazon.in', ['Amazon', 'Amazon India', 'Amazon.in']],
        'flipkart.png' => ['https://www.flipkart.com', ['Flipkart']],
        'hindustan-unilever.svg' => ['https://www.hul.co.in', ['Hindustan Unilever', 'HUL']],
        'itc-limited.svg' => ['https://www.itcportal.com', ['ITC', 'ITC Limited']],
        'asian-paints.png' => ['https://www.asianpaints.com', ['Asian Paints']],
        'maruti-suzuki.svg' => ['https://www.marutisuzuki.com', ['Maruti Suzuki', 'Maruti Suzuki India', 'Maruti']],
        'reliance-retail.png' => ['https://www.relianceretail.com', ['Reliance Retail']],
        'fractal-analytics.png' => ['https://fractal.ai', ['Fractal Analytics', 'Fractal', 'Fractal.ai']],
        'mu-sigma.png' => ['https://www.mu-sigma.com', ['Mu Sigma', 'Mu-Sigma']],
    ];

    // "HCL Technologies Ltd." -> "hcltechnologies", "Ernst & Young LLP" -> "ernstandyoung"
    $norm = static function (string $name): string {
        $n = strtolower(str_replace('&', ' and ', $name));
        $n = preg_replace('/\b(limited|ltd|pvt|private|inc|llp|plc|corp|corporation)\b\.?/', ' ', $n);
        return preg_replace('/[^a-z0-9]/', '', $n);
    };

    $byName = [];
    foreach ($logos as $file => [$web, $names]) {
        if (!is_file(APP_ROOT . '/' . $dir . $file)) {
            continue;
        }
        foreach ($names as $n) {
            $byName[$norm($n)] = [$dir . $file, $web];
        }
    }

    $logoSet = 0;
    $webSet = 0;
    $matched = 0;
    foreach (db_all('SELECT id, name, logo, website FROM companies') as $c) {
        $hit = $byName[$norm((string) $c['name'])] ?? null;
        if (!$hit) {
            continue;
        }
        $matched++;
        [$path, $web] = $hit;
        $data = [];
        $logo = trim((string) $c['logo']);
        if (($logo === '' || str_starts_with($logo, $dir)) && $logo !== $path) {
            $data['logo'] = $path;
            $logoSet++;
        }
        if (trim((string) $c['website']) === '') {
            $data['website'] = $web;
            $webSet++;
        }
        if ($data) {
            db_update('companies', $data, 'id = ?', [(int) $c['id']]);
        }
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, sprintf("    recruiter logos: %d companies matched, %d logos set, %d websites filled\n", $matched, $logoSet, $webSet));
    }
};
