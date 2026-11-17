# SEO system — how it works

Technical SEO for the public website (gimtedu.com): `<head>` tags, structured data, robots.txt, sitemaps, llms.txt,
IndexNow, redirects, crawler activity and the built-in audit. Everything is generated from the database and settings;
nothing here invents institutional facts (rankings, approvals, placement numbers, reviews or dates).

| Piece | Code | Admin |
|---|---|---|
| Meta tags, canonical, hreflang, Open Graph, X (Twitter) card | `seo_page_meta()` — `app/services/seo.php` §3 | Website CMS › SEO Management › *Edit SEO* (per URL) |
| JSON-LD (page schema + admin schema + Organization/WebSite graph + BreadcrumbList) | `seo_jsonld_html()`, `seo_organization_entity()`, `seo_same_as()` | same drawer (schema), Settings › General / Social |
| Verification tags (Google, Bing, Yandex, Baidu, Naver) | `seo_verification_meta()` | Settings › Integrations › Analytics & tags |
| robots.txt + managed AI crawler block | `seo_robots_txt()`, `seo_default_robots_txt()`, `app/services/seo_ai.php` §1 | SEO Management › Robots; Settings › Integrations › AI search & llms.txt |
| Sitemaps (`/sitemap.xml` index + children) | `seo_sitemap_*()` — §6, `cms_sitemap_urls()` | SEO Management › Robots & sitemap |
| `/llms.txt`, `/llms-full.txt` | `seo_llms_txt()` — `app/services/seo_ai.php` §2, `llms.php` | Settings › Integrations › AI search & llms.txt |
| IndexNow | `app/services/seo_indexnow.php`, `indexnow.php`, `/api/integrations/indexnow*` | Settings › Integrations › IndexNow; SEO Management |
| Redirects, 404 log, URL normalisation | §4 `seo_redirect_*()`, `tools/router.php`, `.htaccess` | Website CMS › Redirects |
| Crawler activity (per bot per day) | `seo_bot_hit()` → table `seo_bot_hits` | SEO Management (seo-admin UI) |
| Audit crawler (incl. JSON-LD validation) | §8 `seo_audit_*()` | SEO Management › Audit |

Permissions: module `seo` (view / edit / manage) for SEO screens and endpoints, `settings` (view / edit) for
Settings › Integrations.

## Settings (Settings › Integrations, `seo_integration_sections()`)

Every value can be overridden on the server by its environment variable (the admin then shows "set on the server").
Read them in code with `seo_integration_value($key)`. Defaults are seeded by `database/seed/09_seo_growth.php`
(INSERT IGNORE — never overwrites).

| Key | Section | Default | Notes |
|---|---|---|---|
| `search_console_verification` | Analytics & tags | '' | `google-site-verification` (env `GIMT_GSC_VERIFICATION`) |
| `bing_site_verification` | Analytics & tags | '' | `msvalidate.01` (env `GIMT_BING_VERIFICATION`) |
| `yandex_verification` | Analytics & tags | '' | `yandex-verification` (env `GIMT_YANDEX_VERIFICATION`) |
| `baidu_verification` | Analytics & tags | '' | `baidu-site-verification` (env `GIMT_BAIDU_VERIFICATION`) |
| `naver_verification` | Analytics & tags | '' | `naver-site-verification` (env `GIMT_NAVER_VERIFICATION`) |
| `clarity_project_id` | Analytics & tags | '' | Microsoft Clarity, loaded with Analytics consent (env `GIMT_CLARITY_ID`; loader in `includes/partials/consent.php`) |
| `track_click_events` | Analytics & tags | '1' | phone / WhatsApp / e-mail / apply / brochure dataLayer events (see docs/WEBSITE.md "Analytics events") |
| `seo_block_indexing`, `sitemap_images` | Search engines | '0', '1' | staging switch (robots `Disallow: /`, every page noindex, empty sitemaps, llms.txt 404) |
| `ai_search_crawlers` | AI search & llms.txt | 'allow' | allow \| block — AI search indexes **and** user-triggered fetchers |
| `ai_training_crawlers` | AI search & llms.txt | 'allow' | allow \| block — training crawlers / control tokens |
| `llms_txt_enabled` | AI search & llms.txt | '1' | off → `/llms.txt` and `/llms-full.txt` answer 404 |
| `llms_txt_custom` | AI search & llms.txt | '' | when set, `/llms.txt` serves exactly this text (llms-full stays generated) |
| `indexnow_enabled` | IndexNow | '0' | switching it on generates `indexnow_key` |
| `indexnow_key` | IndexNow | '' | 8–128 `[a-z0-9-]` (stored lower case); public by design |
| `indexnow_endpoint` | IndexNow | `https://api.indexnow.org/indexnow` | shared with all IndexNow engines |
| `pagespeed_api_key` | PageSpeed Insights | '' | secret, optional (env `GIMT_PAGESPEED_KEY`) |
| `gbp_url`, `wikidata_url`, `sameas_extra` | Settings › Social | '' | merged into `sameAs` (one URL per line in `sameas_extra`) |

Internal rows: `_indexnow_last_run`, `_indexnow_retry_after` (unix time after a 429).

## `<head>` and structured data

* **Title / description / keywords**: admin SEO record → page values → institute defaults. `<meta name="keywords">` is
  printed only when the record has keywords.
* **X (Twitter) card**: `twitter_title` / `twitter_description` from the SEO record, else the Open Graph values.
  `og:image:alt` and `twitter:image:alt` come from `image_alt`, else the page's `image_alt`, else the title.
* **Organization** (`CollegeOrUniversity`, `@id` `…/#organization`): name, alternate names, url, logo, email, phone,
  founding year, address, geo (only when latitude/longitude are set), contact point and sameAs — **settings only**;
  an empty setting means the property is left out (no hard-coded city/state, no assumed `areaServed`; set the optional
  setting `area_served` to publish one). The logo is a raster image (Google: PNG/JPG/WebP, at least 112 × 112): the
  uploaded logo when it is raster, else `assets/images/logo-email.png` (198 × 96 — upload a larger square PNG logo to
  meet the 112 px minimum), else the SVG.
* **sameAs** (`seo_same_as()`): Facebook / Instagram / LinkedIn / YouTube / X profiles, Google Business Profile,
  Wikidata (only `/wiki/Q…` URLs) and `sameas_extra`. Only https URLs with a real path (placeholders such as
  `https://facebook.com/` are skipped), never the website itself, de-duplicated.

## robots.txt and the AI crawler policy

`/robots.txt` = the custom rules from SEO Management (or the recommended defaults) + the **managed AI block**:

```
# BEGIN GIMT AI CRAWLERS (managed: …)
User-agent: OAI-SearchBot        ← allowed tokens: one group that repeats the "*" rules
…                                  (a crawler that matches a named group ignores "User-agent: *")
Allow: /
Disallow: /admin/ …
User-agent: GPTBot               ← blocked tokens: Disallow: /
…
Disallow: /
# END GIMT AI CRAWLERS
Sitemap: https://…/sitemap.xml
```

* With custom rules the block is appended (an older block between the markers is replaced). Tokens that the custom
  rules already name in their own `User-agent:` group are left out of the block, so an administrator's explicit rule
  always wins.
* Search engines are never part of the policy and always follow `*`: Googlebot (Google Search incl. AI Overviews /
  AI Mode), Bingbot (Bing + Copilot grounding), YandexBot, Baiduspider, Yeti (Naver), DuckDuckBot, Applebot.
* `seo_ai_crawler_policy()` returns the rows (`bot, vendor, purpose search|user|training, robots yes|partial|unknown,
  allowed, overridden, doc, note`); `GET /api/integrations/ai-policy` exposes them; `GET /api/integrations/robots` and
  `GET /api/seo/robots-txt` show the served file.

| Token | Operator | Purpose | Governed by | Official documentation |
|---|---|---|---|---|
| OAI-SearchBot | OpenAI | search | ai_search_crawlers | developers.openai.com/api/docs/bots |
| Claude-SearchBot | Anthropic | search | ai_search_crawlers | support.claude.com (article 8896518) |
| PerplexityBot | Perplexity | search | ai_search_crawlers | docs.perplexity.ai/guides/bots |
| meta-webindexer | Meta | search | ai_search_crawlers | developers.facebook.com/docs/sharing/webmasters/web-crawlers |
| DuckAssistBot | DuckDuckGo | search (AI answers) | ai_search_crawlers | duckduckgo.com help: duckassistbot |
| Amzn-SearchBot | Amazon | search | ai_search_crawlers | developer.amazon.com/amazonbot |
| ChatGPT-User | OpenAI | user (robots.txt "may not apply") | ai_search_crawlers | OpenAI bots page |
| Claude-User | Anthropic | user (obeys) | ai_search_crawlers | Anthropic article |
| Perplexity-User | Perplexity | user ("generally ignores") | ai_search_crawlers | Perplexity bots page |
| meta-externalfetcher | Meta | user ("may bypass") | ai_search_crawlers | Meta crawlers page |
| Amzn-User | Amazon | user | ai_search_crawlers | Amazonbot page |
| GPTBot | OpenAI | training | ai_training_crawlers | OpenAI bots page |
| ClaudeBot | Anthropic | training | ai_training_crawlers | Anthropic article |
| Google-Extended | Google | training + Gemini Apps / Vertex grounding (control token) | ai_training_crawlers | developers.google.com … google-common-crawlers |
| Applebot-Extended | Apple | training (control token) | ai_training_crawlers | support.apple.com/en-us/119829 |
| Amazonbot | Amazon | training | ai_training_crawlers | Amazonbot page |
| meta-externalagent | Meta | training | ai_training_crawlers | Meta crawlers page |
| CCBot | Common Crawl | open dataset (widely used for training) | ai_training_crawlers | commoncrawl.org/ccbot |
| Bytespider | ByteDance | reported training — **no first-party docs, compliance unverified** | ai_training_crawlers | — |

Notes: blocking training tokens does not remove the site from search or AI answers above; blocking Google-Extended
also stops grounding in the Gemini apps. User-triggered agents may ignore robots.txt. Bing's new AI-training
preference (`__usage_ai-training`, honoured from 1 Feb 2027) is not emitted yet — revisit when Bing documents the
final syntax.

## llms.txt / llms-full.txt

* `/llms.txt` follows llmstxt.org: H1 institute name, blockquote summary (home page meta description from SEO
  Management, else the footer "about" setting), contact facts from settings, "Last updated" (newest sitemap lastmod),
  then H2 link lists: programmes by level (level, duration, fee exactly as the website shows it, eligibility), certificate
  courses, admissions/fees/contact pages, about pages, policies, more pages and an "Optional" section (news, events,
  notices, gallery, certificate verification, sitemap, llms-full.txt). Link notes are the admin meta descriptions.
* `/llms-full.txt` adds per-programme details (department, qualification, duration, semesters, credits, intake, fee,
  total fee of the current session's fee structure, eligibility, overview, highlights, career options, apply link)
  and every active FAQ with its answer (FAQ table). It is a community convention, not part of the llmstxt.org text.
* Only URLs that are in the sitemap (public, indexable, canonical) are listed; no admin, portal, API or personal data.
* `text/plain; charset=utf-8`, ETag, `Cache-Control: public, max-age=3600`, no `X-Robots-Tag` (public resource). Cached
  in `storage/cache/seo/llms-*.txt` by a content fingerprint (sitemap fingerprint + FAQs + settings + fee structures).
* Routing: `tools/router.php` and `.htaccess` map both URLs to `llms.php`; `/llms`, `/llms.php` and `/LLMS.TXT` 301 to
  `/llms.txt` (one URL per document). Google ignores llms.txt; it neither helps nor harms Google rankings.

## IndexNow

* Participating engines (indexnow.org/searchengines.json): Bing, Yandex, Seznam, Naver, Yep, Internet Archive, Amazon.
  **Google does not take part** — use Search Console + accurate sitemap `lastmod` for Google.
* Switching IndexNow on (Settings › Integrations › IndexNow) generates a 32-hex key. The key file is served at
  `https://<host>/{key}.txt` by `indexnow.php` (only the current key, only while enabled; any other `/{x}.txt` gets the
  normal 404 page). `POST /api/integrations/indexnow/key` creates a new key (and resets the submission history).
* Submissions (`seo_indexnow_submit($mode, $urls, $trigger)`): `changed` = sitemap URLs whose lastmod differs from the
  last submission (table `seo_indexnow_urls`) plus URLs that left the sitemap (deleted / noindexed); `all`; `urls`
  (URLs of this site only). One JSON POST per 10,000 URLs: `{host, key, keyLocation, urlList}`. URLs submitted in the
  last 5 minutes wait (protocol rule). A 429 pauses submissions until Retry-After. Every attempt is logged in
  `seo_indexnow_log` (trigger, count, sample, HTTP status, response).
* Triggers: the admin button (`POST /api/integrations/indexnow/submit`), every sitemap rebuild
  (`POST /api/integrations/sitemaps/rebuild`) and the server cron URL:
  `GET /api/integrations/indexnow/cron?token=<seo_indexnow_cron_token()>` (HMAC of the app key; shown to `seo.manage`
  users in `GET /api/integrations/indexnow` → `cron`). Example `/etc/cron.d/gimt-indexnow`:
  `*/30 * * * * www-data curl -fsS "https://gimtedu.com/api/integrations/indexnow/cron?token=…" > /dev/null 2>&1`
* Guard: a site whose origin is local (localhost, 127.0.0.1, private IPs, `*.test`, `*.local`, `*.localhost`) never
  sends anything to a public endpoint; it may only post to a local test endpoint (e.g. a mock on
  `http://127.0.0.1:8099/indexnow`). Refused attempts are logged as "Not sent: …".

## Crawler activity

`seo_bot_hit()` runs once per public page (from `seo_redirect_on_render()`), robots.txt, sitemaps, llms files and the
IndexNow key file. One upsert per bot request into `seo_bot_hits (hit_date, bot, hits, last_path)` — no IP, no user
agent string. Families: Googlebot, Bingbot, YandexBot, Baiduspider, Yeti, DuckDuckBot, Applebot, OAI-SearchBot,
ChatGPT-User, GPTBot, ClaudeBot, Claude-User, Claude-SearchBot, PerplexityBot, Perplexity-User, Amazonbot, CCBot,
Bytespider, Meta-ExternalAgent, meta-webindexer, meta-externalfetcher, Amzn-SearchBot, Amzn-User, DuckAssistBot (every
search / user token of the AI policy has its own family), other-bot (generic bot/crawler/spider/curl UAs). Browsers are not counted. User agents
can be spoofed — treat the numbers as indicative. Rows older than 400 days are pruned.

## URL normalisation (one URL per page, single 301)

| Request | Result |
|---|---|
| `https://www.<host>/x?q` | 301 `https://<host>/x?q` (`.htaccess`, before anything else; PHP `seo_host_redirect()` repeats it for pages, robots, sitemaps, llms, key file). Only when the configured site URL has no `www.` — set `SetEnvIf Host . GIMT_KEEP_WWW=1` if the canonical host is `www.` (not `SetEnv`: it runs after mod_rewrite) |
| `http://…` | 301 to https on the configured host (`force_https`, app/init.php) |
| `/about/`, `/About`, `/ABOUT`, `/About/`, `/About.php` | 301 `/about` (mixed case → 404.php → redirect manager; it runs before the `.php` rule, `[END]`) |
| `/about.php`, `/index.php` | 301 `/about`, `/` |
| `/page/{slug}` of a route page | 301 `/{slug}` |

Known two-hop case: `www.` **and** mixed case together (`https://www.<host>/About`) — rare, acceptable.

## Sitemaps

`cms_sitemap_urls()` lists public, published, indexable URLs; `seo_sitemap_groups()` additionally drops
(`seo_sitemap_excluded_paths()`): `/events` when there are no upcoming events and `/notices` when there are no current
notices (those pages are noindex while empty), and any URL whose admin canonical points to another URL or website.

## Audit crawler

Besides titles, descriptions, headings, links, canonicals and speed, the audit parses every
`<script type="application/ld+json">` block: `schema_types` (column on `seo_audit_pages`, also in the CSV export),
issues `schema_invalid_json` (error), `schema_missing_required` (warning; details in the row's error column) and
`schema_none` (notice). Required properties: Course name/description/provider; Event name/startDate/location;
Article/BlogPosting/NewsArticle headline/datePublished/image; BreadcrumbList itemListElement; FAQPage mainEntity;
Organization (top level) name/url/logo. Items of an ItemList (course / event carousels) are checked too.

## Owner checklist (things only the institute can do)

1. **Google Search Console**: verify the domain (DNS TXT for a Domain property, or the meta tag above), submit
   `https://gimtedu.com/sitemap.xml`, check Pages / URL Inspection.
2. **Bing Webmaster Tools**: "Import from Google Search Console" (imports sites + sitemaps), or the msvalidate.01 tag.
   Then switch IndexNow on and add the cron line above on the server.
3. **Yandex / Baidu / Naver**: optional — Yandex is ~0.1% of Indian search, Baidu and Naver are not relevant for Noida
   admissions; IndexNow already notifies Yandex and Naver.
4. **Logo**: upload a square PNG logo of at least 112 × 112 px (Settings › General) for the Organization markup.
5. **Profiles**: enter only real, owned profiles in Settings › Social (Google Business Profile share URL, Wikidata item,
   directory listings in `sameas_extra`). Placeholders are ignored. Google Business Profile needs an eligible
   address with staff present during stated hours.
6. **AI policy**: decide AI search (recommended: allow, for citations) and AI training (institute's policy choice).
7. **Clarity**: Microsoft says Clarity should not be used on sites aimed at under-18s — confirm with the privacy lead
   before entering a project ID; keep masking at Balanced or Strict.
8. **GA4**: mark `generate_lead`, `submit_application`, `phone_click`, `whatsapp_click` as key events.
9. After launch, check the SEO audit, the 404 report, crawler activity and the IndexNow log monthly.
