<?php
/**
 * Cookie consent (DPDP / GDPR-friendly) + Google Consent Mode v2 + consent-gated tags (unit seo_platform).
 *
 *   consent_head()           <head>: dataLayer, Consent Mode v2 defaults (everything except security/functionality
 *                            "denied"), stored choice → consent update, and the loader that injects GA4 / GTM (Analytics
 *                            or advanced mode) and Meta Pixel (Marketing) only when allowed and only when an ID is set;
 *                            plain dataLayer conversion events ({event: 'generate_lead', form: 'enquiry'}) are forwarded
 *                            to GA4 (when loaded directly, not through GTM) and to the Meta Pixel (Lead, Contact …).
 *   consent_banner()         footer: banner (Accept all · Reject all · Customise, equal prominence), preferences modal
 *                            (Necessary always on · Analytics · Marketing, cookie list per category) and assets/js/consent.js.
 *   consent_settings_link()  "Cookie settings" link for the footer (opens the modal; without JS it goes to the policy).
 *
 * Choice: first-party cookie gimt_consent = v{version}.a{0|1}.m{0|1}.{unix time} (Path=/, SameSite=Lax, Secure on
 * HTTPS, consent_expiry_days). Bumping consent_version (admin "Ask everyone again") re-prompts every visitor.
 * JS API: window.gimtConsent.open() / .get() / .set(analytics, marketing); event "gimt:consent" on document.
 * Settings (group integrations): consent_banner auto|always|off, consent_mode basic|advanced, consent_title,
 * consent_text, consent_policy_url, consent_expiry_days, consent_version. Preview: any page with ?consent_preview=1
 * while signed in with settings permission.
 */
if (!defined('APP_ROOT')) {
    http_response_code(404);
    exit;
}
require_once APP_ROOT . '/app/services/seo.php';

/** Effective consent configuration for this request (cached). */
function consent_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $ga = trim((string) seo_integration_value('google_analytics_id', ''));
    $gtm = trim((string) seo_integration_value('gtm_id', ''));
    $px = trim((string) seo_integration_value('meta_pixel_id', ''));
    $mode = (string) seo_integration_value('consent_banner', 'auto');
    $mode = in_array($mode, ['auto', 'always', 'off'], true) ? $mode : 'auto';
    $preview = isset($_GET['consent_preview']) && function_exists('is_logged_in') && is_logged_in() && can('settings', 'view');
    $policy = trim((string) seo_integration_value('consent_policy_url', '/cookie-policy')) ?: '/cookie-policy';
    $cfg = [
        'mode' => $mode,
        'v' => max(1, (int) setting('consent_version', '1')),
        'days' => min(395, max(30, (int) seo_integration_value('consent_expiry_days', '180'))),
        'ga' => $ga !== '' ? $ga : null,
        'gtm' => $gtm !== '' ? $gtm : null,
        'px' => $px !== '' ? $px : null,
        'adv' => $mode === 'off' || seo_integration_value('consent_mode', 'basic') === 'advanced',
        'off' => $mode === 'off',
        'banner' => $mode !== 'off' && ($preview || $mode === 'always' || ($ga !== '' || $gtm !== '' || $px !== '')),
        'preview' => $preview,
        'path' => base_path() . '/',
        'secure' => seo_is_https(),
        'policy' => preg_match('#^https?://#i', $policy) ? $policy : base_url(ltrim($policy, '/')),
    ];
    return $cfg;
}

/** <head> script: Consent Mode v2 defaults + stored choice + consent-gated tag loader + dataLayer conversion bridge. */
function consent_head(): string
{
    $c = consent_config();
    $js = ['v' => $c['v'], 'ga' => $c['ga'], 'gtm' => $c['gtm'], 'px' => $c['px'], 'adv' => $c['adv'], 'off' => $c['off']];
    $script = '(function(w,d,C){var dl=w.dataLayer=w.dataLayer||[];function gtag(){dl.push(arguments)}if(!w.gtag)w.gtag=gtag;var G=!!(C.ga||C.gtm),L={},'
        . 'PX={generate_lead:"Lead",submit_application:"SubmitApplication",apply:"SubmitApplication",contact:"Contact",sign_up:"CompleteRegistration",event_registration:"CompleteRegistration",newsletter_signup:"Lead",schedule:"Schedule"};'
        . 'if(G){gtag("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",personalization_storage:"denied",functionality_storage:"granted",security_storage:"granted",wait_for_update:500});gtag("set","ads_data_redaction",true)}'
        . 'function inject(s){var e=d.createElement("script");e.async=true;e.src=s;(d.head||d.documentElement).appendChild(e)}'
        . 'function read(){var m=d.cookie.match(/(?:^|;\\s*)gimt_consent=v(\\d+)\\.a([01])\\.m([01])\\.(\\d+)/);if(!m||+m[1]!==C.v)return null;return{a:m[2]==="1",m:m[3]==="1",t:+m[4]}}'
        . 'function load(c){var g=function(v){return v?"granted":"denied"};if(G)gtag("consent","update",{analytics_storage:g(c.a),ad_storage:g(c.m),ad_user_data:g(c.m),ad_personalization:g(c.m),personalization_storage:g(c.m)});'
        . 'if(C.gtm&&!L.gtm&&(C.adv||c.a||c.m)){L.gtm=1;dl.push({"gtm.start":new Date().getTime(),event:"gtm.js"});inject("https://www.googletagmanager.com/gtm.js?id="+encodeURIComponent(C.gtm))}'
        . 'if(C.ga&&!L.ga&&(C.adv||c.a)){L.ga=1;inject("https://www.googletagmanager.com/gtag/js?id="+encodeURIComponent(C.ga));gtag("js",new Date());gtag("config",C.ga)}'
        . 'if(C.px&&!L.px&&c.m&&!C.off){L.px=1;var n=w.fbq=w.fbq||function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!w._fbq)w._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=n.queue||[];'
        . 'inject("https://connect.facebook.net/en_US/fbevents.js");w.fbq("init",C.px);w.fbq("track","PageView")}else if(L.px&&!c.m&&w.fbq){w.fbq("consent","revoke")}}'
        . 'var push=dl.push;dl.push=function(){var r=push.apply(dl,arguments);for(var i=0;i<arguments.length;i++)fwd(arguments[i]);return r};'
        . 'function fwd(o){if(!o||typeof o!=="object"||Object.prototype.toString.call(o)==="[object Arguments]"||typeof o.event!=="string"||/^(gtm\\.|consent_)/.test(o.event))return;'
        . 'var p={},k;for(k in o)if(k!=="event"&&Object.prototype.hasOwnProperty.call(o,k))p[k]=o[k];if(L.ga&&!C.gtm)gtag("event",o.event,p);if(L.px&&PX[o.event]&&w.fbq)w.fbq("track",PX[o.event],p)}'
        . 'w.__gimtConsent={cfg:C,read:read,apply:load,loaded:L};var c=read();if(c)load(c);else if(C.adv)load({a:false,m:false})})(window,document,' . js_json($js) . ');';
    return '  <script' . site_nonce_attr() . '>' . $script . "</script>\n";
}

/** Cookies used by the website, per category (only the services that are configured). */
function consent_cookie_list(): array
{
    $c = consent_config();
    $days = $c['days'];
    $necessary = [
        ['GIMTSESSID', 'Keeps the website secure (protects forms against forgery) and remembers your session. Deleted when you close the browser.'],
        ['gimt_consent', 'Remembers the cookie choices you make here (' . $days . ' days).'],
    ];
    if (setting('visitor_counter_enabled', '1') === '1') {
        $necessary[] = ['gimt_vid, gimt_vs', 'Anonymous visit counter shown in the footer: a random id without any personal data (1 year / 30 minutes).'];
    }
    if ((string) setting('site_theme_toggle', '1') !== '0') {
        $necessary[] = ['gimt_theme', 'Remembers the Light / Dark / System colour theme you pick, so pages open in it straight away (1 year). Only set when you choose a theme.'];
    }
    // not cookies, but stored on your device by this website (never sent to us or to anyone else)
    $necessary[] = ['Browser storage: gimt.theme, gimt.view.*, gimt.popup.*, gimt.notice.*, gimt.bar.*, gimt.alert.*, gimt.visited, gimt.apply.draft.v1, gimt-chat:*', 'Saved in your browser only: your colour theme, the Grid / List / Compact / Detailed view you chose for a listing, whether you already saw or answered the enquiry pop-up (this visit / 30 days) or saw an announcement pop-up (this visit), dismissed notice bars, the first-visit loading screen, an unfinished online application draft (text fields only) and your conversation with the admissions assistant. Clearing your browser data removes them.'];
    $analytics = [];
    if ($c['ga'] || $c['gtm']) {
        $analytics[] = ['_ga, _ga_*', 'Google Analytics: counts visits and shows which pages are useful, without identifying you (up to 2 years).'];
    }
    $marketing = [];
    if ($c['px']) {
        $marketing[] = ['_fbp', 'Meta Pixel: measures our admission campaigns on Facebook and Instagram (3 months).'];
    }
    if ($c['gtm']) {
        $marketing[] = ['_gcl_au', 'Google Ads conversion measurement through Google Tag Manager (3 months).'];
    }
    return ['necessary' => $necessary, 'analytics' => $analytics, 'marketing' => $marketing];
}

/** "Cookie settings" link (opens the preferences; without JavaScript it leads to the cookie policy). */
function consent_settings_link(string $class = '', string $label = 'Cookie settings'): string
{
    $c = consent_config();
    if ($c['off']) {
        return '';
    }
    return '<a href="' . e($c['policy']) . '#cookie-settings" data-consent-open class="' . e(trim('cc-open ' . $class)) . '">' . e($label) . '</a>';
}

/** Banner + preferences modal + styles + script (footer). Empty when consent is handled elsewhere (mode "off"). */
function consent_banner(): string
{
    $c = consent_config();
    if ($c['off']) {
        return '';
    }
    $title = trim((string) seo_integration_value('consent_title', 'We value your privacy')) ?: 'We value your privacy';
    $text = trim((string) seo_integration_value('consent_text', '')) ?: (string) (seo_integration_fields()['consent_text']['default'] ?? '');
    $list = consent_cookie_list();
    $cookieIcon = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/><path d="M11 17v.01"/><path d="M7 14v.01"/></svg>';
    $cookies = function (array $items, string $empty): string {
        if (!$items) {
            return '<p class="cc-none">' . e($empty) . '</p>';
        }
        $h = '<ul class="cc-cookies">';
        foreach ($items as [$name, $purpose]) {
            $h .= '<li><code>' . e($name) . '</code><span>' . e($purpose) . '</span></li>';
        }
        return $h . '</ul>';
    };
    $cat = function (string $key, string $label, string $desc, string $body, bool $locked) {
        $id = 'cc-' . $key;
        $control = $locked
            ? '<span class="cc-always">Always on</span><input type="checkbox" class="cc-switch" role="switch" id="' . $id . '" checked disabled aria-describedby="' . $id . '-d">'
            : '<input type="checkbox" class="cc-switch" role="switch" id="' . $id . '" data-cc-cat="' . $key . '" aria-describedby="' . $id . '-d">';
        return '<div class="cc-cat"><div class="cc-cat-head"><label for="' . $id . '"><span class="cc-cat-title">' . e($label) . '</span></label><span class="cc-cat-ctl">' . $control . '</span></div>'
            . '<p id="' . $id . '-d">' . e($desc) . '</p><details class="cc-details"><summary>Cookies used</summary>' . $body . '</details></div>';
    };
    ob_start();
    ?>
<style>
.cc-banner,.cc-modal{--cc-surface:var(--c-surface,#fff);--cc-text:var(--c-text,#4A5568);--cc-heading:var(--c-heading,#0B2A5B);--cc-border:var(--c-border,#EDE8E2);--cc-fill:var(--c-flame-fill,#D03E16);--cc-navy:var(--c-navy,#0B2A5B);--cc-link:var(--c-flame-text,#C93C14);--cc-soft:var(--c-surface-warm,#F5F2EE)}
.cc-banner[hidden],.cc-modal[hidden]{display:none!important}
.cc-banner{position:fixed;z-index:95;left:50%;bottom:calc(12px + var(--mobile-cta-h,0px));width:min(1000px,calc(100% - 24px));padding:18px 20px;border:1px solid var(--cc-border);border-radius:20px;background:var(--cc-surface);color:var(--cc-text);box-shadow:0 24px 60px -18px rgba(7,26,59,.38),0 4px 14px rgba(7,26,59,.08);opacity:0;-webkit-transform:translate(-50%,16px);transform:translate(-50%,16px);transition:opacity .3s ease,transform .3s cubic-bezier(.2,.8,.2,1)}
.cc-banner.is-in{opacity:1;-webkit-transform:translate(-50%,0);transform:translate(-50%,0)}
.cc-inner{display:flex;flex-wrap:wrap;align-items:center;gap:14px 24px}
.cc-copy{flex:1 1 420px;min-width:0}
.cc-title{display:flex;align-items:center;gap:8px;margin:0 0 4px;font:700 1rem/1.3 'Plus Jakarta Sans',Inter,system-ui,sans-serif;color:var(--cc-heading)}
.cc-title svg{flex:none;color:var(--c-flame,#F05A28)}
.cc-text{margin:0;font-size:.875rem;line-height:1.55}
.cc-text a,.cc-body a{color:var(--cc-link);font-weight:600;text-decoration:underline;text-underline-offset:2px}
.cc-actions{display:flex;flex:0 0 auto;flex-wrap:wrap;gap:8px}
.cc-btn{display:inline-flex;min-height:44px;align-items:center;justify-content:center;padding:0 18px;border:1.5px solid transparent;border-radius:9999px;background:none;font:600 .875rem/1.1 Inter,system-ui,sans-serif;cursor:pointer;transition:background-color .2s ease,color .2s ease,border-color .2s ease,transform .2s ease}
.cc-btn:focus-visible,.cc-x:focus-visible,.cc-switch:focus-visible,.cc-details summary:focus-visible{outline:3px solid var(--c-flame,#F05A28);outline-offset:2px}
.cc-btn:active{-webkit-transform:translateY(1px);transform:translateY(1px)}
.cc-btn-primary{background:var(--cc-fill);color:#fff}
.cc-btn-primary:hover{background:var(--cc-navy)}
.cc-btn-outline{border-color:var(--cc-heading);color:var(--cc-heading)}
.cc-btn-outline:hover{background:var(--cc-navy);border-color:var(--cc-navy);color:#fff}
.cc-btn-ghost{padding:0 10px;color:var(--cc-heading);text-decoration:underline;text-underline-offset:3px}
.cc-btn-ghost:hover{color:var(--cc-link)}
.cc-modal{position:fixed;inset:0;z-index:96;display:flex;align-items:center;justify-content:center;padding:16px}
.cc-scrim{position:absolute;inset:0;background:rgba(7,26,59,.55);opacity:0;transition:opacity .25s ease}
.cc-panel{position:relative;display:flex;width:min(640px,100%);max-height:calc(100vh - 32px);flex-direction:column;border-radius:22px;background:var(--cc-surface);color:var(--cc-text);box-shadow:0 30px 80px -20px rgba(7,26,59,.5);opacity:0;-webkit-transform:translateY(12px) scale(.98);transform:translateY(12px) scale(.98);transition:opacity .25s ease,transform .25s cubic-bezier(.2,.8,.2,1)}
.cc-panel:focus{outline:none}
.cc-modal.is-in .cc-scrim{opacity:1}
.cc-modal.is-in .cc-panel{opacity:1;-webkit-transform:none;transform:none}
.cc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:20px 22px 14px;border-bottom:1px solid var(--cc-border)}
.cc-head h2{margin:0;font:800 1.15rem/1.3 'Plus Jakarta Sans',Inter,system-ui,sans-serif;color:var(--cc-heading)}
.cc-head p{margin:4px 0 0;font-size:.85rem}
.cc-x{display:inline-flex;flex:none;width:40px;height:40px;align-items:center;justify-content:center;border:0;border-radius:9999px;background:var(--cc-soft);color:var(--cc-heading);font-size:1.4rem;line-height:1;cursor:pointer}
.cc-x:hover{background:var(--cc-border)}
.cc-body{overflow-y:auto;padding:6px 22px 16px;font-size:.9rem;line-height:1.55}
.cc-cat{margin-top:12px;padding:14px 16px;border:1px solid var(--cc-border);border-radius:16px}
.cc-cat-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.cc-cat-title{font:700 .95rem/1.3 'Plus Jakarta Sans',Inter,system-ui,sans-serif;color:var(--cc-heading)}
.cc-cat-ctl{display:inline-flex;align-items:center;gap:10px}
.cc-cat p{margin:6px 0 0;font-size:.85rem}
.cc-always{font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#047857}
html[data-theme="dark"] .cc-always{color:#6EE7B7}
.cc-switch{position:relative;flex:none;width:46px;height:26px;margin:0;border-radius:9999px;background:var(--c-muted,#6B7280);-webkit-appearance:none;appearance:none;cursor:pointer;transition:background-color .2s ease}
.cc-switch::after{content:'';position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:9999px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .2s ease}
.cc-switch:checked{background:var(--cc-fill)}
.cc-switch:checked::after{-webkit-transform:translateX(20px);transform:translateX(20px)}
.cc-switch:disabled{background:#047857;cursor:not-allowed;opacity:.75}
.cc-details{margin-top:8px;font-size:.8rem}
.cc-details summary{cursor:pointer;width:max-content;font-weight:600;color:var(--cc-heading)}
.cc-cookies{margin:8px 0 0;padding:0;list-style:none}
.cc-cookies li{display:grid;grid-template-columns:minmax(0,9.5rem) minmax(0,1fr);gap:4px 12px;padding:6px 0;border-top:1px dashed var(--cc-border)}
.cc-cookies code{font-size:.75rem;font-weight:600;color:var(--cc-heading);word-break:break-word}
.cc-none{margin:8px 0 0;font-style:italic}
.cc-more{margin:14px 0 0;font-size:.85rem}
.cc-foot{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;padding:14px 22px;border-top:1px solid var(--cc-border)}
.cc-open{cursor:pointer}
@media (max-width:639px){.cc-banner{bottom:calc(8px + var(--mobile-cta-h,0px));padding:16px;border-radius:18px}.cc-actions{display:grid;width:100%;grid-template-columns:1fr 1fr}.cc-btn-ghost{order:3;grid-column:1/-1}
.cc-modal{align-items:flex-end;padding:0}.cc-panel{max-height:92vh;border-radius:22px 22px 0 0}.cc-foot{display:grid;grid-template-columns:1fr 1fr}.cc-foot .cc-btn-primary{grid-column:1/-1}.cc-cookies li{grid-template-columns:1fr}}
@media (prefers-reduced-motion:reduce){.cc-banner,.cc-panel,.cc-scrim,.cc-btn,.cc-switch,.cc-switch::after{transition:none!important}}
@media print{.cc-banner,.cc-modal{display:none!important}}
</style>
<?php if ($c['banner']): ?>
<div id="cc-banner" class="cc-banner no-print" role="region" aria-label="Cookie consent" hidden<?= $c['preview'] ? ' data-preview' : '' ?>>
  <div class="cc-inner">
    <div class="cc-copy">
      <p class="cc-title" id="cc-title"><?= $cookieIcon ?><?= e($title) ?></p>
      <p class="cc-text" id="cc-desc"><?= e($text) ?> <a href="<?= e($c['policy']) ?>">Cookie Policy</a></p>
    </div>
    <div class="cc-actions">
      <button type="button" class="cc-btn cc-btn-ghost" data-cc-customize>Customise</button>
      <button type="button" class="cc-btn cc-btn-outline" data-cc-reject>Reject all</button>
      <button type="button" class="cc-btn cc-btn-primary" data-cc-accept>Accept all</button>
    </div>
  </div>
</div>
<?php endif; ?>
<div id="cc-modal" class="cc-modal no-print" role="dialog" aria-modal="true" aria-labelledby="cc-modal-title" aria-describedby="cc-modal-desc" hidden>
  <div class="cc-scrim" data-cc-close></div>
  <div class="cc-panel" tabindex="-1">
    <div class="cc-head">
      <div>
        <h2 id="cc-modal-title">Cookie preferences</h2>
        <p id="cc-modal-desc">Choose which optional cookies we may use. You can change this any time from “Cookie settings” at the bottom of every page.</p>
      </div>
      <button type="button" class="cc-x" data-cc-close aria-label="Close cookie preferences">&times;</button>
    </div>
    <div class="cc-body">
      <?= $cat('necessary', 'Necessary', 'Required for the website to work and to keep it secure. They do not track you.', $cookies($list['necessary'], ''), true) ?>
      <?= $cat('analytics', 'Analytics', 'Help us understand how visitors use the website so we can improve pages and information.', $cookies($list['analytics'], 'No analytics cookies are used on this website at the moment.'), false) ?>
      <?= $cat('marketing', 'Marketing', 'Measure our admission campaigns on other websites and social media.', $cookies($list['marketing'], 'No marketing cookies are used on this website at the moment.'), false) ?>
      <p class="cc-more">More details: <a href="<?= e($c['policy']) ?>">Cookie Policy</a></p>
    </div>
    <div class="cc-foot">
      <button type="button" class="cc-btn cc-btn-outline" data-cc-reject>Reject all</button>
      <button type="button" class="cc-btn cc-btn-outline" data-cc-save>Save my choices</button>
      <button type="button" class="cc-btn cc-btn-primary" data-cc-accept>Accept all</button>
    </div>
  </div>
</div>
<div class="sr-only" role="status" aria-live="polite" data-cc-live></div>
<script src="<?= e(asset('assets/js/consent.js')) ?>" defer<?= site_nonce_attr() ?>></script>
<?php
    return (string) ob_get_clean();
}
