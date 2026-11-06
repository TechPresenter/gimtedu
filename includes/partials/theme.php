<?php
/**
 * Website Light / Dark / System colour theme (unit theme_viewmodes). Implements the theme hooks of includes/header.php:
 *   site_theme_html_attrs()  ' data-theme="light|dark" data-theme-pref="light|dark|system"' on <html> (server-side, so
 *                            a returning visitor gets the right theme in the first byte — no flash)
 *   site_theme_head()        first thing in <head>: theme-color meta + a tiny (≈ 0.7 KB, keeps <meta charset> in the
 *                            first 1024 bytes) inline script that resolves "system" (prefers-color-scheme, followed
 *                            live) and the stored preference before the first paint; window.__gimtTheme {get, theme, apply}
 *   site_theme_toggle($o)    accessible segmented control (radiogroup: sun · moon · monitor) for the header bar and
 *                            the side drawer (navbar.php); '' when the toggle is switched off. The first one also
 *                            prints site_theme_toggle_script(): __gimtTheme.set / sync + delegated clicks, so the
 *                            control works even when the website bundle is missing
 *
 * Preference order: localStorage "gimt.theme" (per device) → first-party cookie "gimt_theme" (1 year, SameSite=Lax;
 * only written when the visitor picks a theme — lets PHP render data-theme server-side) → setting site_theme_default
 * (light | dark | system; seeded "system" by database/seed/09_theme.php). With setting site_theme_toggle = 0 the control
 * is hidden and every visitor gets the admin default (stored choices are ignored: <html data-theme-lock>).
 * "system" follows the OS live (matchMedia change) and, without JavaScript, the Sec-CH-Prefers-Color-Scheme client hint
 * when the browser sends it (requested with Accept-CH below).
 *
 * Behaviour: clicks + the radiogroup keyboard pattern live in the inline toggle script (work without the site bundle);
 * frontend/site/core/theme.ts adds the cross-fade, cross-tab sync and a screen-reader announcement. Styling: the dark map in
 * tailwind.config.cjs (utility colours) + the "Dark theme" block of assets/css/src/style.css (tokens + components).
 * Dark styles only apply on screen — printing a page is always light; admin print views never carry data-theme.
 */

/** Ask Chromium browsers to send their colour-scheme preference with the next requests (used for "system" without JS). */
if (!headers_sent()) {
    header('Accept-CH: Sec-CH-Prefers-Color-Scheme', false);
    header('Vary: Sec-CH-Prefers-Color-Scheme', false);
}

/** @return string[] the selectable modes, in toggle order */
function site_theme_modes(): array
{
    return ['light', 'dark', 'system'];
}

/** Admin switch (setting site_theme_toggle, default on): visitors may choose their theme. */
function site_theme_toggle_enabled(): bool
{
    return (string) setting('site_theme_toggle', '1') !== '0';
}

/** Admin default (setting site_theme_default): light | dark | system. */
function site_theme_default(): string
{
    $d = strtolower(trim((string) setting('site_theme_default', 'system')));
    return in_array($d, site_theme_modes(), true) ? $d : 'system';
}

/** The visitor's preference for this request: cookie (when the toggle is enabled) → admin default. */
function site_theme_pref(): string
{
    static $pref = null;
    if ($pref === null) {
        $pref = site_theme_default();
        $cookie = is_string($_COOKIE['gimt_theme'] ?? null) ? $_COOKIE['gimt_theme'] : '';
        if (site_theme_toggle_enabled() && in_array($cookie, site_theme_modes(), true)) {
            $pref = $cookie;
        }
    }
    return $pref;
}

/** Light or dark for the server render ("system" uses the client hint when present, else light). */
function site_theme_resolved(): string
{
    $pref = site_theme_pref();
    if ($pref !== 'system') {
        return $pref;
    }
    $hint = strtolower(trim((string) ($_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] ?? ''), " \t\""));
    return $hint === 'dark' ? 'dark' : 'light';
}

/** theme-color (browser UI) per resolved theme — matches the header bar (light) / page background (dark). */
function site_theme_colors(): array
{
    return ['light' => '#F5F2EE', 'dark' => '#0B1426'];
}

/** Attributes for <html> (header.php hook). */
function site_theme_html_attrs(): string
{
    return ' data-theme="' . site_theme_resolved() . '" data-theme-pref="' . site_theme_pref() . '"'
        . (site_theme_toggle_enabled() ? '' : ' data-theme-lock');
}

/**
 * First thing in <head> (header.php hook): theme-color meta + the pre-paint resolver. Deliberately tiny (≈ 0.7 KB) so the
 * page's <meta charset> still sits inside the first 1024 bytes. The full API (set / sync / clicks) is added by
 * site_theme_toggle_script() next to the first toggle, and enhanced by core/theme.ts.
 */
function site_theme_head(): string
{
    $c = site_theme_colors();
    $js = '(function(d,w){var h=d.documentElement,D="data-theme",P=D+"-pref",p=h.getAttribute(P),q=w.matchMedia&&w.matchMedia("(prefers-color-scheme: dark)"),'
        . 'T=function(){return p=="system"?q&&q.matches?"dark":"light":p},'
        . 'A=function(x){if(x)p=x;var t=T(),m=d.getElementById("theme-color");h.setAttribute(P,p);h.setAttribute(D,t);if(m)m.content=t=="dark"?"' . $c['dark'] . '":"' . $c['light'] . '"};'
        . 'if(!h.hasAttribute(D+"-lock"))try{var v=w.localStorage.getItem("gimt.theme");if(/^(light|dark|system)$/.test(v))p=v}catch(e){}'
        . 'A();h.classList.add("theme-js");q&&q.addEventListener&&q.addEventListener("change",function(){p=="system"&&A()});'
        . 'w.__gimtTheme={get:function(){return p},theme:T,apply:A}})(document,window)';
    return '<meta name="theme-color" id="theme-color" content="' . e($c[site_theme_resolved()]) . '">' . "\n" . '  <script' . (function_exists('site_nonce_attr') ? site_nonce_attr() : '') . '>' . $js . '</script>' . "\n";
}

/**
 * Completes window.__gimtTheme once per page (printed right after the first toggle): set(pref) stores the choice
 * (localStorage + cookie for the server render), applies it, keeps aria-checked / tabindex in sync and fires a
 * "gimt:theme" event; clicks and arrow keys on [data-theme-set] are delegated, so the control works without the site
 * bundle too (the no-bundle fallback assets/js/main.js).
 */
function site_theme_toggle_script(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    $js = '(function(d,w){var g=w.__gimtTheme,h=d.documentElement,R=/^(light|dark|system)$/;if(!g||g.set)return;'
        . 'function ck(x){try{d.cookie="gimt_theme="+x+";path=/;max-age=31536000;samesite=lax"+(location.protocol=="https:"?";secure":"")}catch(e){}}'
        . 'var N={light:"Light",dark:"Dark",system:"System"},X={light:"dark",dark:"system",system:"light"};'
        . 'function y(){var b=d.querySelectorAll("[data-theme-set]"),p=g.get();for(var i=0;i<b.length;i++){var o=b[i].getAttribute("data-theme-set")==p;b[i].setAttribute("aria-checked",o?"true":"false");b[i].tabIndex=o?0:-1}'
        // the header's one-button control (variant "cycle"): its label names the current mode and the next one
        . 'var c=d.querySelectorAll("[data-theme-cycle]"),t="Colour theme: "+N[p]+". Switch to "+N[X[p]];for(var j=0;j<c.length;j++){c[j].setAttribute("aria-label",t);c[j].title=t}}'
        . 'g.sync=y;g.set=function(x){if(!R.test(x||""))return;try{w.localStorage.setItem("gimt.theme",x)}catch(e){}ck(x);h.classList.add("theme-switching");g.apply(x);y();'
        . 'w.setTimeout(function(){h.classList.remove("theme-switching")},90);try{d.dispatchEvent(new CustomEvent("gimt:theme",{detail:{pref:x,theme:g.theme()}}))}catch(e){}};'
        // the browser kept a choice the server did not see (cookie cleared / expired): write it back for the next render
        . 'if(g.get()!=' . js_json(site_theme_pref()) . ')ck(g.get());'
        . 'd.addEventListener("click",function(e){var b=e.target&&e.target.closest?e.target.closest("[data-theme-set]"):null,c=!b&&e.target&&e.target.closest?e.target.closest("[data-theme-cycle]"):null;'
        . 'if(b){e.preventDefault();w.__gimtTheme.set(b.getAttribute("data-theme-set"))}else if(c){e.preventDefault();w.__gimtTheme.set(X[g.get()]||"light")}});'
        // radiogroup keyboard pattern (roving tabindex: only the checked option is tabbable) — ←/→/↑/↓ select + move focus, Home/End
        . 'd.addEventListener("keydown",function(e){var b=e.target&&e.target.closest?e.target.closest("[data-theme-set]"):null,r=b&&b.closest("[data-theme-toggle]"),k=e.key,n=-1;if(!r||e.defaultPrevented||e.altKey||e.ctrlKey||e.metaKey)return;'
        . 'var l=r.querySelectorAll("[data-theme-set]"),i=Array.prototype.indexOf.call(l,b),c=l.length;if(k=="ArrowRight"||k=="ArrowDown")n=(i+1)%c;else if(k=="ArrowLeft"||k=="ArrowUp")n=(i-1+c)%c;else if(k=="Home")n=0;else if(k=="End")n=c-1;'
        . 'if(n<0)return;e.preventDefault();w.__gimtTheme.set(l[n].getAttribute("data-theme-set"));l[n].tabIndex=0;l[n].focus()});'
        . 'y();d.addEventListener("DOMContentLoaded",y)})(document,window)';
    return '<script' . (function_exists('site_nonce_attr') ? site_nonce_attr() : '') . '>' . $js . '</script>';
}

/**
 * Light / Dark / System segmented control (radiogroup). The checked state is styled from <html data-theme-pref>
 * (correct before any script runs); aria-checked + roving tabindex are kept in sync by the head script and
 * the inline toggle script (←/→/↑/↓, Home/End). Hidden without JavaScript (it needs storage to work): the head script
 * adds <html class="theme-js"> before the first paint, so the control is shown in the first frame (no header shift).
 * Variant "cycle" (site header, client request 2026-10-26): one round button showing the current mode's icon; each press
 * moves Light → Dark → System; aria-label / title name the current and the next mode (kept in sync by the toggle script).
 * @param array $o variant: header (icons only, compact) | drawer (icons + labels, full width) | cycle (one button), class, id
 */
function site_theme_toggle(array $o = []): string
{
    if (!site_theme_toggle_enabled()) {
        return '';
    }
    $pref = site_theme_pref();
    if (($o['variant'] ?? '') === 'cycle') {
        $names = ['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'];
        $next = ['light' => 'dark', 'dark' => 'system', 'system' => 'light'];
        $label = 'Colour theme: ' . $names[$pref] . '. Switch to ' . $names[$next[$pref]];
        return '<button type="button" class="header-round theme-cycle' . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '" data-theme-cycle aria-label="' . e($label) . '" title="' . e($label) . '"'
            . (!empty($o['id']) ? ' id="' . e($o['id']) . '"' : '') . '>'
            . icon('sun', 'tc tc-light h-[18px] w-[18px]') . icon('moon', 'tc tc-dark h-[18px] w-[18px]') . icon('monitor', 'tc tc-system h-[18px] w-[18px]')
            . '</button>' . site_theme_toggle_script();
    }
    $variant = ($o['variant'] ?? 'header') === 'drawer' ? 'drawer' : 'header';
    $opts = [
        'light' => ['sun', 'Light', 'Light theme'],
        'dark' => ['moon', 'Dark', 'Dark theme'],
        'system' => ['monitor', 'System', 'Use the device setting (System)'],
    ];
    $html = '<div class="theme-toggle ' . ($variant === 'drawer' ? 'theme-toggle-drawer' : 'theme-toggle-header') . (!empty($o['class']) ? ' ' . e($o['class']) : '') . '" role="radiogroup" aria-label="Colour theme" data-theme-toggle'
        . (!empty($o['id']) ? ' id="' . e($o['id']) . '"' : '') . '>';
    foreach ($opts as $mode => [$ic, $label, $title]) {
        $on = $mode === $pref;
        $html .= '<button type="button" role="radio" class="theme-opt" data-theme-set="' . $mode . '" aria-checked="' . ($on ? 'true' : 'false') . '" tabindex="' . ($on ? '0' : '-1') . '" title="' . e($title) . '">'
            . icon($ic, 'h-[17px] w-[17px]')
            . ($variant === 'drawer' ? '<span>' . e($label) . '</span>' : '<span class="sr-only">' . e($label) . '</span>')
            . '</button>';
    }
    return $html . '</div>' . site_theme_toggle_script();
}

/**
 * Listing view modes: print right after a view_mode_toggle($key) (programs, faculty, notices, events, news). The
 * listing's own bootstrap (view_mode_open() / sa_view_scope_script()) applies the visitor's saved mode to the items
 * before they are parsed; this tiny inline script does the same for the segmented control, so the active Grid / List /
 * Compact / Detailed button is right in the first paint too (core/viewmode.ts takes over once the bundle runs).
 */
function site_view_sync_script(string $key): string
{
    return '<script' . (function_exists('site_nonce_attr') ? site_nonce_attr() : '') . '>(function(k){try{var v=localStorage.getItem("gimt.view."+k),b=document.querySelectorAll("[data-view-toggle=\""+k+"\"] [data-view-mode]"),i,f=0;'
        . 'if(!/^(grid|list|compact|detailed)$/.test(v||""))return;for(i=0;i<b.length;i++)if(b[i].getAttribute("data-view-mode")==v)f=1;if(!f)return;'
        . 'for(i=0;i<b.length;i++){var o=b[i].getAttribute("data-view-mode")==v;b[i].setAttribute("aria-checked",o?"true":"false");b[i].tabIndex=o?0:-1;b[i].classList.toggle("is-active",o)}}catch(x){}})('
        . js_json(preg_replace('/[^a-z0-9_-]/', '', strtolower($key))) . ')</script>';
}
