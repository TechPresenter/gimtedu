# WhatsApp Automation & Student Communication System (Interakt)

Module of GIMT SmartCampus that runs the institute's WhatsApp communication through the **Interakt WhatsApp Business
API**: shared inbox, template designer, event-based automation, visual workflows, admission follow-ups, bulk
campaigns, a WhatsApp contact CRM, an AI chatbot answering only from verified GIMT data, reports, team permissions
and settings. Research behind the design: Interakt capabilities, existing messaging, event hook points, RBAC/SPA
conventions, chatbot and CRM data (`scratchpad/wa/report_*.md` during the build).

## 1. What Interakt does and what GIMT does

| Interakt (public API, `https://api.interakt.ai/v1/public/`, `Authorization: Basic <key>`) | GIMT (this module) |
|---|---|
| Send template messages (`POST message/` `type:Template`, positional `bodyValues`, `headerValues`, `buttonValues` by button index, `template_category` guard) | Event engine, workflows, follow-ups, campaigns decide **what** to send and **when** |
| Send session messages inside the 24-hour window (`Text`, `Image`, `Document`, `Video`, `Audio`, `Sticker`, `InteractiveButton`, `InteractiveList`) | Window tracking per contact; composer locks to templates when the window is closed |
| Create templates (`POST track/templates/`, Utility/Marketing), list + status (`GET track/organization/templates`), sample media upload (`track/files/upload_to_fb/`) | Template designer, variable mapping (GIMT named keys ↔ positional `{{n}}`), library, analytics. Edit/delete exist only in the Interakt dashboard (archive locally) |
| Webhooks (dashboard-configured, HTTPS, 200 within 3 s, **no retries** — missed events are never resent, 5 failures in 10 min disable it): `message_api_sent|delivered|read|failed|clicked`, `message_received` (Advanced plan+), `message_template_status_update`; header `Interakt-Signature: sha256=<hmac>` | Signature check before anything is stored, store-then-process endpoint, async processing from the worker, webhook-health monitor (§9), simulated webhooks for local testing |
| Track User (traits, tags, `whatsapp_opted_in`) | Contact CRM is the source of truth; opt-in/opt-out enforced here and pushed to Interakt |
| Rate limit 300/min (Growth) or 600/min (Advanced); 429 on excess | Throttled outbox with backoff and retries |
| — (no history, inbox, workflow, bot, reports, team or scheduling APIs) | All built here |

Response rule: always check `result === true` (HTTP codes are unreliable). Phones: send `countryCode:"+91"` + `phoneNumber:"9876543210"`;
store `919876543210`. Webhook timestamps are UTC without zone → convert to Asia/Kolkata.

**Modes.** `wa_mode()` is `test` unless Settings › WhatsApp › Mode = live **and** an API key is saved **and** not `is_dev()`.
In test mode nothing is sent to Interakt: the outbox marks messages `simulated` (fake id `test-…`), logs the request in
`wa_api_logs` with `mode='test'`, and status webhooks can be simulated. The API key and webhook secret are never sent
to the browser (settings masking) and never logged.

### 1.1 Scope: single institute

The module serves **one institute per installation** (one Interakt account, one WhatsApp business number). There is
no tenant key: the `wa_*` tables, the `whatsapp` settings group, the webhook secret, the webhook URL and the cron token
are all install-wide. Extension path if GIMT ever hosts several institutes in one database: add `institute_id` to every
`wa_*` table (and to its unique keys, e.g. `wa_contacts.phone`, `wa_templates` name + language, `wa_events.code`),
scope the WhatsApp settings per institute, give each institute its own webhook secret and webhook URL (e.g.
`/api/whatsapp/webhook/{institute}`, so the signature check picks the right secret before storing) and its own cron
token, and filter every query, report and worker handler by the institute.

## 2. Data model — `database/schema/48_whatsapp.sql`

`wa_contacts` (phone-keyed CRM; links `student_id`, `enquiry_id`, `admission_id`, program, counsellor, `stage`, opt-in,
`last_inbound_at`) · `wa_tags`, `wa_contact_tags` · `wa_conversations` (one per contact; open|pending|resolved,
assigned_to, unread, bot_active) · `wa_messages` (in/out, every type, template + vars, Interakt id, status ticks, cost,
sender_type, links to campaign / workflow run / event log / follow-up) · `wa_outbox` (send queue) · `wa_notes` ·
`wa_quick_replies` · `wa_agent_activity` · `wa_templates` · `wa_events`, `wa_event_logs` · `wa_workflows`,
`wa_workflow_runs`, `wa_workflow_steps` · `wa_followup_stages`, `wa_followups` · `wa_campaigns`,
`wa_campaign_recipients` · `wa_bot_sessions` · `wa_webhook_logs`, `wa_api_logs`, `wa_api_tokens`.
Read the SQL file for every column. A feature that needs more columns/tables adds its own schema file
`database/schema/49_whatsapp_<feature>.sql` (ALTER/CREATE only, portable SQL) — coordinate numbers: bot 49, events 50,
workflows 51, campaigns 52, contacts 53, templates 54, inbox 55, admin 56. Feature tables / columns worth knowing:
`50_whatsapp_events` adds `wa_event_fanouts` (background queue of bulk events, §5) and the scan columns of `wa_events`;
`52_whatsapp_campaigns` adds `wa_campaign_recipients.skip_reason` (`no_opt_in|no_consent|opted_out|blocked|
invalid_number|duplicate|missing_variables|template|cancelled`). **Every campaign, MARKETING or UTILITY, needs a
recorded WhatsApp opt-in** (`wa_contacts.opt_in = 1`, or a "yes" in the consent column of the uploaded list —
`wa_cmp_skip_reason()`); everyone else is skipped as `no_consent`. There is no "opted-in only" switch any more (the
`opt_in_only` flag is always true and kept only for old clients); transactional notices to students without opt-in go
through events, not campaigns. `no_opt_in` appears on recipients recorded before that rule, and on a recipient whose
message the outbox cancelled because its template became MARKETING after it was queued (§4 `wa_send()` details).
`54_whatsapp_templates` adds `wa_templates.archived_from` / `archived_at` (local archive; Restore puts `archived_from`
back).
`wa_followups.status` also takes the short-lived value `processing` while one follow-up is being sent (claimed with an
atomic UPDATE; stale claims go back to `scheduled` after 10 minutes) — reports counting follow-ups by status can ignore it.

Settings (`settings` table, group `whatsapp`; secrets auto-masked when the key contains `api_key|secret|token`):
`wa_mode` (test|live), `wa_interakt_api_key`, `wa_interakt_base_url` (default `https://api.interakt.ai/v1/public/`),
`wa_webhook_secret`, `wa_interakt_plan` (growth|advanced|enterprise), `wa_rate_per_minute` (250), `wa_business_number`,
`wa_business_name`, `wa_default_language` (en), `wa_business_hours` (JSON `{"mon":["09:00","18:00"],…}`),
`wa_bot_enabled`, `wa_bot_*`, `wa_auto_sync_contacts`, `wa_retention_days`, `wa_max_attempts` (5), `wa_optout_reply`,
`wa_optin_reply`, `wa_allow_simulate_live` (0), `wa_brochure_url` (optional institute brochure for `{{brochure_link}}`).
Admin part (Settings › Notifications / Automation / Security): `wa_notify_new_message` (1), `wa_notify_assignment` (1),
`wa_notify_handover` (1), `wa_daily_digest` (0), `wa_digest_time` (09:00), `wa_automation_paused` (0),
`wa_quiet_enabled` (0), `wa_quiet_start` (21:00), `wa_quiet_end` (08:00), `wa_max_per_contact_day` (0 = no cap),
`wa_export_scope` (permission|managers), `wa_mask_phones` (0). Bot part: `wa_bot_create_enquiry` (1 — a lead the
bot finishes qualifying gets an ERP admission enquiry, §5.1) next to the other `wa_bot_*` keys. All defaults live in
`wa_setting_defaults()` and are seeded with INSERT IGNORE by `09_wa_0_core` (bot keys: `wa_bot_defaults()`, seeded by
`09_wa_5_bot`). Keys starting with `_` (`_wa_last_cron`, `_wa_last_cron_stats`, `_wa_last_light_tick`, `_wa_cron_secret`, `_wa_tpl_last_sync`,
`_wa_crm_sync_cursor` (resume point of the ERP → CRM contact sync), `_wa_webhook_last_signed_at` (last accepted signed
webhook, written at most once a minute), `_wa_webhook_health` / `_wa_webhook_health_notified` (§9 webhook health) …)
are internal markers (group `internal`) and never shown in settings forms.
`wa_interakt_base_url` is honoured only for `https://` on `interakt.ai` or a subdomain (port 443, no credentials) —
any other value falls back to the default, so the API key can never be sent to another host.
Legacy `whatsapp_*` keys belong to the old generic gateway — do not reuse them.

## 3. Ownership (parallel build — edit only your own files)

| Part | Backend | Routes (`/api/whatsapp/…`) | SPA (`frontend/src/modules/whatsapp/…`) | Seeds |
|---|---|---|---|---|
| **core** | `app/services/whatsapp.php` | `api/routes/whatsapp/00_core.php`: `webhook`, `cron`, `status`, `simulate/*`, `media/{id}` | `shared/*` (types, api helpers, chat bubble, phone frame, status ticks, window badge) | `09_wa_0_core.php` (settings defaults, roles + permissions) |
| **inbox** | `app/services/whatsapp_inbox.php` | `inbox.php`: `inbox/*`, `quick-replies/*` | `inbox/*` | `98_wa_2_inbox.php` |
| **templates** | `app/services/whatsapp_templates.php` | `templates.php`: `templates/*` | `templates/*` | `09_wa_1_templates.php`, `98_wa_0_templates.php` |
| **events** | `app/services/whatsapp_events.php` + one-line `wa_event()` calls in existing ERP code | `events.php`: `events/*`, `ingest/*` | `events/*` | `09_wa_2_events.php` |
| **automation** | `app/services/whatsapp_workflows.php` | `workflows.php`: `workflows/*` | `automation/*` | `09_wa_4_workflows.php`, `98_wa_4_runs.php` |
| **contacts** | `app/services/whatsapp_contacts.php` (+ follow-up engine) | `contacts.php`: `contacts/*`, `tags/*`, `leads/*`, `followups/*` | `contacts/*`, `leads/*`, `followups/*` | `09_wa_3_followups.php`, `98_wa_1_contacts.php` |
| **campaigns** | `app/services/whatsapp_campaigns.php` | `campaigns.php`: `campaigns/*` | `campaigns/*` | `98_wa_3_campaigns.php` |
| **bot** | `app/services/whatsapp_bot.php` | `bot.php`: `bot/*` | `bot/*` | `09_wa_5_bot.php` (production: settings + knowledge base), `98_wa_5_bot.php` (demo sessions + turns) |
| **admin** | `app/services/whatsapp_admin.php` (dashboard, reports, team, settings, tokens, docs) | `admin.php`: `dashboard`, `reports/*`, `settings`, `integration/*`, `logs/*`, `tokens/*`, `team/*`, `docs` | `dashboard/*`, `reports/*`, `admin/*` | `98_wa_6_users.php` (demo logins for the WhatsApp roles) |

Already wired (do not change without need): `app/registry.php` permission keys, `api/routes/whatsapp.php` (loads
`api/routes/whatsapp/*.php` in name order), `frontend/src/router.tsx`, `frontend/src/navigation.ts` (WhatsApp group),
`frontend/src/modules/whatsapp/routes.tsx` (21 routes → placeholder pages you replace). If you truly need another route,
add one line to `routes.tsx` (keep it valid; others edit it too — re-read before editing).

Feature services are loaded by core: the last lines of `app/services/whatsapp.php` `require_once` every
`app/services/whatsapp_*.php`. A feature service must therefore only **define functions and register hooks**
(`wa_on`, `wa_on_tick`) at include time — no queries at include time.

## 4. Core PHP API (`app/services/whatsapp.php`, prefix `wa_`) — the contract every part codes against

```php
// settings / mode
wa_setting(string $key, $default = null);              // settings group whatsapp
wa_mode(): string;                                     // 'test' | 'live'
wa_is_configured(): bool;                              // API key present
wa_rate_per_minute(): int;
wa_in_business_hours(?DateTimeInterface $at = null): bool;
wa_next_business_time(DateTimeInterface $from): DateTimeImmutable;

// phones / contacts
wa_normalize_phone(?string $raw, string $defaultCc = '91'): ?array;   // ['phone'=>'919876543210','country_code'=>'+91','national'=>'9876543210'] or null
wa_contact_upsert(array $data, array $opts = []): int;  // by phone (or 'id'); fills only given non-empty fields unless $opts['overwrite']; links student/enquiry/admission ids; returns id
wa_contact(int $id): ?array;
wa_contact_by_phone(string $phone): ?array;
wa_contact_window_open(array $contact): bool;           // last_inbound_at within 24 h
wa_contact_window_expires(array $contact): ?string;     // Y-m-d H:i:s or null
wa_contact_set_stage(int $contactId, string $stage, array $opts = []): void;  // updates stage + stage_changed_at, fires internal event WA_STAGE_CHANGED
//   $opts: reason, silent (no event), log (activity log), source (workflow|event|…), workflow_id, run_id (copied into the event payload)
wa_contact_tag(int $contactId, array $tagNames): void;  wa_contact_untag(int $contactId, array $tagNames): void;
wa_contact_opt(int $contactId, bool $optIn, string $source): void;           // opt-in / opt-out (+ Interakt Track User when live)
wa_conversation_for(int $contactId): array;             // creates if missing

// sending (everything is queued; the worker delivers)
wa_send(array $msg, array $opts = []): array;
//   $msg: contact_id | phone, type (text|image|video|document|audio|sticker|interactive_button|interactive_list|template),
//         body, media_url, file_name, interactive (array), template (id|name), language (default: the contact's language),
//         vars (named map or ['header'=>[],'body'=>[],'buttons'=>[]]),
//         sender_type (agent|bot|automation|event|campaign|followup|system), sent_by, campaign_id, workflow_run_id,
//         event_log_id, followup_id, reply_to_id
//   $opts: at (Y-m-d H:i:s schedule), priority (1..9), skip_opt_in_check, respect_business_hours, dedupe_key, allow_repeat
//   returns ['ok'=>bool, 'message_id'=>?int, 'status'=>'queued'|..., 'error'=>?string, 'code'=>?string]
//   refusals (ok=false, no row): invalid_phone, blocked, opted_out (marketing needs opt_in=1; a contact who replied STOP
//   gets no automation / campaign / followup / bot message — see §4.1), window_closed (non-template outside 24 h),
//   template_not_approved, template_not_found, missing_variables, invalid_message (e.g. a full URL for a dynamic URL button)
//   ok=true without a new row: code 'duplicate' (same dedupe_key) or 'duplicate_recent' (10-minute repeat guard, below)
wa_send_template($contactOrPhone, $template, array $vars = [], array $opts = []): array;  // $vars named (student_name=>…) or positional
wa_send_text(int $contactId, string $text, array $opts = []): array;
wa_template_find($idOrName, ?string $language = null): ?array;
wa_render_template(array $tpl, array $vars): array;     // ['header'=>[],'body'=>[],'buttons'=>[], 'text'=>rendered preview, 'missing'=>[], 'invalid'=>[]]
wa_variable_catalog(): array;                           // key => label, sample (student_name, application_number, course_name, …)
wa_variables_for_contact(array $contact, array $extra = []): array;   // named values resolved from CRM + linked ERP rows

// Interakt client (logs every call to wa_api_logs, never logs the key)
wa_interakt_request(string $method, string $path, ?array $body = null, array $opts = []): array; // ['ok','status','data','error','duration_ms']
wa_interakt_track_user(array $contact): array;
wa_interakt_list_templates(array $query = []): array;
wa_interakt_create_template(array $payload): array;
wa_interakt_upload_media(string $absPath, string $fileName): array;

// worker / queue
wa_process_outbox(float $budget = 15.0, int $max = 200): array;   // throttled by wa_rate_per_minute, 429 backoff, retries
wa_worker_tick(float $budget = 20.0): array;            // GET_LOCK; webhooks → outbox → tick handlers (priority order, guaranteed slices, §6); sets _wa_last_cron
wa_maybe_tick(): void;                                  // opportunistic 2-3 s tick when the cron has not run for 2 min (call from dashboard/inbox polling)
wa_cron_token(): string;  wa_cron_info(): array;

// webhooks
wa_webhook_receive(string $raw, array $server): array;  // size / JSON checks, signature verdict BEFORE storing (§9), store wa_webhook_logs, return quickly
wa_process_webhooks(float $budget = 5.0): array;        // statuses, inbound messages, clicks, template status
wa_simulate_webhook(array $payload): array;             // signs with the configured secret and runs receive + process (test tools)

// event bus (also used by every feature)
wa_event(string $code, array $payload = [], array $opts = []): ?int;   // NEVER throws; logs wa_event_logs (dedupe), resolves contact, dispatches listeners; default source 'worker' inside worker context (§6)
wa_on(string $code, callable $listener, int $priority = 10): void;     // $code or '*'; listener(array $event, array $payload, ?array $contact): ?array (action summary)
wa_on_tick(string $name, callable $handler, ?int $priority = null): void; // handler(float $budget): array stats; lower priority runs first (§6)
wa_agent_log(string $action, ?int $conversationId, ?int $contactId, ?string $detail = null): void;
wa_can(string $module, string $action = 'view'): bool;                 // can() wrapper
```

Internal events raised by core (listen with `wa_on`): `WA_MESSAGE_RECEIVED` (payload: message_id, conversation_id,
contact_id, type, text, button_payload, phone, name, opt_keyword), `WA_BUTTON_CLICKED` (message_id = the original
outbound message, inbound_message_id, click_type QR|CTA, button_text, button_link, button_payload, campaign_id,
template_id), `WA_MESSAGE_STATUS` (message_id, status, error_code, error, campaign_id, workflow_run_id, followup_id — not
written to wa_event_logs; `simulated` = sent in test mode), `WA_STAGE_CHANGED` (contact_id, from, to, reason, by,
source, workflow_id, run_id — the last three null unless the caller passed them, e.g. a workflow's "Update stage" step),
`WA_OPTED_OUT`, `WA_OPTED_IN`, `WA_TEMPLATE_STATUS` (template_id, name, status = the template's local status, previous,
remote_status = Meta's status when it came from the webhook, reason).
Business events from the ERP use `GIMT_*` codes (catalogue in `09_wa_2_events.php`; the events part owns it).

### 4.1 More core helpers (beyond the contract above)

```php
wa_retry_message(int $messageId, array $opts = []): array;  // THE retry primitive: re-queues a failed outbound row in place
//   (message → queued, outbox → pending / attempts 0 / due now, inserted when missing; failed campaign recipient → queued).
//   Also re-queues a 'queued' message whose outbox row failed or is stuck in processing > 10 min. Checks blocked contact,
//   24-h window (session messages), template still sendable, marketing opt-in, STOP (automation / campaign / followup /
//   bot rows). Returns ['ok','message_id','error','code'], codes not_found | not_failed | blocked | window_closed |
//   template_not_found | template_not_approved | opted_out.
wa_cancel_queued(array $messageIds, string $reason): int;   // pending outbox → cancelled, message → cancelled, linked campaign
//   recipient 'queued' → 'skipped' (skip_reason 'cancelled', error = $reason). $messageIds are wa_messages ids. Campaign pause
//   (wa_cmp_recall_queued) then moves its own recipients back to 'pending'.
wa_require_any([[module, action], …], 'what'): void;         // 403 guard; wa_can_any(); wa_any_view_pairs()
wa_automated_sender_types(): array;                         // automation, event, followup, campaign (held by pause / quiet hours)
wa_optout_blocked_sender_types(): array;                    // automation, campaign, followup, bot (refused after STOP)
wa_contact_opted_out(array $contact): bool;                 // replied STOP: opt_in = 0 and opted_out_at set (≠ never opted in)
wa_tick_handlers(): array;  wa_tick_default_priority($name); // worker run order (§6)
wa_interakt_base_url(): string;                             // https://*.interakt.ai only, else the default (API key never leaves Interakt)
wa_support_number(): string;                                // business WhatsApp number (+91 90600 31540), else Settings › phone
wa_brochure_link(?array $programRow): string;               // programme brochure → wa_brochure_url → /programs/{slug} → /programs
wa_find_reply_target(int $contactId, ?string $replyId, ?string $title): ?int;  // outbound message a button / list reply answers
wa_public_target(string $url): ?array;                      // SSRF guard for server-side fetches (public IPs only, ports 80/443, pinned; §9)
wa_ip_is_public(string $ip): bool;  wa_ip_in_cidr();        // same blocked ranges as the workflow HTTP step (wa_wf_ip_is_public)
wa_template_param($v, int $max = 0): string;                // one template parameter as Meta accepts it (see wa_send() details)
wa_send_recent_duplicate(…);  WA_REPEAT_WINDOW_MINUTES (10)  // same-template repeat guard of automated senders
wa_webhook_verdict(string $raw, array $server): array;      // before storing: ['valid' 1|0|null, 'trusted', 'reason' unsigned|invalid_signature, 'simulated'] (§9)
wa_webhook_log_can_reprocess(array $row): array;            // ['ok', 'reason', 'verified_now'?] — the Reprocess rule (§9)
wa_webhook_health(): array;  wa_webhook_health_watch();     // n/a | never | silent | bad_signature | ok + plain-English note (§9)
wa_as_worker(callable $fn);  wa_in_worker(): bool;  wa_worker_depth(); // background context: no human actor (§6)
wa_user_id(): ?int;                                         // the signed-in user, null inside worker context — use it for audit columns
wa_status_summary(); wa_media_store_upload($_FILES['file'], kind); wa_media_download($url); wa_template_decode();
wa_template_slots(); wa_vars_positional(); wa_normalize_interactive(); wa_encrypt() / wa_decrypt(); wa_json() / wa_json_decode();
wa_preview(); wa_status_rank(); wa_utc_to_local(); wa_simulate_inbound_payload(); wa_simulate_status_payload();
wa_process_webhook_log($id); wa_sync_pending_contacts(); wa_purge_logs(); wa_respond_then_continue($payload);
wa_purge_rate_limits(int $max = 20000): int;                // expired 'wa-*' rate_limits windows (hourly, from wa_purge_logs; §6)
```

`wa_send()` details:
- Extra refusal codes: `template_not_found`, `invalid_message` (bad / non-http(s) media URL, empty body, > 3 buttons …).
  `code: 'duplicate'` with `ok: true` when `dedupe_key` matches an earlier message. Extra opts: `use_samples` (test
  sends), `max_attempts`. Extra message keys: `language`, `media_url` / `file_name` (media template headers; site-relative
  `/assets/…` paths are made absolute), `media_mime`, `name` (when the contact is created from a phone).
- **STOP / opt-out policy** (`wa_contact_opted_out()`: replied STOP, `opt_in = 0` and `opted_out_at` set): the contact
  gets no `automation` (workflow), `campaign`, `followup` or `bot` message, whatever the template category — `wa_send()`
  refuses with `opted_out`, the outbox cancels such a row that was queued before the STOP arrived (campaign recipient →
  `skipped` / `opted_out`), and `wa_retry_message()` refuses to re-queue it. **Transactional `event` messages still go
  out** (payment receipt, admission / document / exam / result notices — UTILITY templates the student needs), as do
  agent replies inside the 24-hour window and `system` messages (the STOP confirmation). MARKETING templates always need
  `opt_in = 1`, whoever sends them. Replying START (`wa_opt_keyword()`) opts the contact back in. `skip_opt_in_check`
  bypasses the rule (test sends only; workflows never pass it for a STOP contact). A contact who simply never opted in
  is not "opted out": UTILITY templates reach them, MARKETING ones do not.
- **Admin send gate** (`wa_adm_send_gate()`, Settings › Automation) for automated senders (`automation`, `event`,
  `followup`, `campaign`): *paused* → the message is queued and held by the outbox until resumed; *quiet hours* and the
  *per-contact daily cap* → scheduled at the end of the quiet period / tomorrow. A message scheduled later (`at`) that
  would land in quiet hours is moved to their end. Agent, bot and system replies always pass. The result then carries
  `held: 'paused'|'quiet_hours'|'daily_limit'` and `scheduled_at` (still `ok: true`, status queued).
- Named variables: `wa_variables_for_contact()` resolves contact / student / admission / enquiry / counsellor values plus
  the institute keys `institute_name`, `support_number`, `website_url` / `website_link`, `apply_link` / `admission_link`,
  `brochure_link`, `academic_session`, `date_today`, and `course_duration`, `admission_status`, `payment_status` from the
  linked records. For a student with an outstanding balance `due_amount` **and** `fee_amount` (the key the built-in fee
  templates map) are both the balance formatted with `money()` (`₹55,000`), `due_date` the next due date. Event payloads
  / campaign mappings override them (e.g. an admission fee passed as `fee_amount`).
- Template status webhook (`message_template_status_update`): a template **archived in GIMT stays archived** — Meta's
  new status is only stored in `archived_from` (what Restore puts back). When Meta deletes a template
  (`PENDING_DELETION` / `DELETED`) it is archived with `archived_from = 'disabled'`, so a Restore can never make it
  sendable again. Other templates follow Meta's status.
- Button / list replies: core sets `reply_to_id` of the inbound `button_reply` / `list_reply` to the outbound template
  or interactive message it answers (Interakt id or callback data first, else `wa_find_reply_target()` by payload / title).
  One tap reported twice (`message_api_clicked` + `message_received`) is stored once.
- **Language preference**: a template sent by name uses `$msg['language']`, else the contact's `language` (`en` / `hi`;
  set on the contact record or by import, and by the bot when the contact writes in Devanagari). `wa_template_find()`
  picks, in order: an **approved** row in that language, an approved row in `wa_default_language`, then — outside
  live mode only — a sendable row (not archived / disabled / rejected) in that language, then in the default language,
  and finally any row so a refusal can name the blocking one. A draft or pending Hindi copy therefore never hides the
  approved English template.
- **Template parameters** (`wa_template_param()`, applied in `wa_build_payload()` to every header, body and button value,
  so retries of rows queued earlier are covered too): `\r \n \t \v \f`, U+0085, U+2028 and U+2029 become one space,
  runs of spaces collapse to one, the value is trimmed (Meta error 132018 rejects new lines, tabs and more than 4
  consecutive spaces). A text-header value is capped (ending in "…") so the filled header stays within Meta's 60
  characters. The stored message text (the preview) keeps its line breaks; quick-reply payloads are left as they are.
- **Current category at delivery**: `wa_outbox_deliver()` re-reads the template's **current** category and status from
  `wa_templates` (Meta may re-categorise a template after the message was queued); the queue-time snapshot in
  `template_vars` is used only when the template row is gone. `template_category: 'utility'` is sent only when the
  current category is UTILITY. If the template is now MARKETING, the contact has no opt-in and the sender is
  automation, event, followup, campaign or bot, the message is **cancelled** (`error_code` `no_opt_in`, with an
  explanation that names the queue-time category), nothing is posted to Interakt, and a linked campaign recipient
  becomes `skipped` / `no_opt_in` (`wa_update_campaign_recipient()` moves a pending or queued recipient to `skipped`
  when its message is cancelled). Test sends queued with `skip_opt_in_check` (`template_vars.skip_opt_in`) pass; agent
  and system messages are not re-checked.
- **Dynamic URL buttons**: Meta appends the variable to the button's fixed base URL. A value that starts with that base
  URL (case-insensitive) keeps only the suffix (`https://gimtedu.com/verify-receipt/RCPT/2026-27/000123` →
  `RCPT/2026-27/000123`); a value that is still an absolute URL (`scheme://` or `//`) is left out and explained in
  `wa_render_template()`'s `invalid`, and `wa_send()` refuses with `invalid_message` naming the button, the variable and
  the base URL. Samples used by test sends (`_use_samples`) follow the same rule.
- **Repeat guard** (`wa_send_recent_duplicate()`): for `event`, `automation` (workflows) and `followup` senders, when
  the same template (by name, any language) with the same rendered text, header media, header values and button values
  was queued or sent to the contact in the last `WA_REPEAT_WINDOW_MINUTES` (10) and did not fail or get cancelled,
  nothing new is queued — e.g. the Event Manager and the "New Admission Enquiry" workflow both sending
  `enquiry_received` for one website enquiry. The result is `ok: true`, `code`/`status` `duplicate_recent`,
  `message_id` / `duplicate_of` = the earlier message and a `note`, so a workflow run or follow-up carries on. Not
  applied to campaign, agent, bot or system sends, test sends (`skip_opt_in_check`, `use_samples`, test workflow runs),
  `$opts['allow_repeat']`, or a message scheduled more than the window ahead. Different texts on one template (a
  parent's number on two siblings' fee reminders) both go out. How the callers report it:
  - **Event Manager** (`wa_evt_handle()`): the action summary of the event log keeps `status: 'queued'` and adds
    `send_status: 'duplicate_recent'`, `duplicate_of` (the earlier message id) and `note`. Execution logs show it as
    "Already sent recently" with a link to the earlier message, never offer Retry for it, and count it under the
    "Duplicate / already sent" filter and the "Duplicates blocked" KPI (§5.2).
  - **Workflows** (`wa_wf_send_result()`): the template step is recorded as **skipped** (not failed, the run is not
    cancelled and goes on to the next step) with output `{summary, code: 'duplicate_recent', message_id, duplicate_of}`.
    The summary comes from `wa_wf_recent_duplicate_summary()`, which reads the earlier message's `sent_at` (else
    `created_at`) and `sender_type`, e.g. "Skipped — the same template was sent to this contact 2 min ago by the Event
    Manager (message #2839)". Run history and test runs (`RunTimeline`) show it as "Earlier message #…". Before this
    change the step read "Template … queued".

## 5. Integration of business events

**ERP code uses the wrapped one-line hook from `app/whatsapp_hook.php`** (events part). Outside `/api/whatsapp/*` the
WhatsApp services are not loaded; the hook loads them lazily, enriches the payload from the ids it carries
(`wa_evt_enrich()`: student / admission / enquiry / counsellor → name, phone, programme …) and skips spreadsheet
imports. Every call site is wrapped in `try/catch`, so even an error while building the payload or loading the file can
never break an admission, payment or form (failures go to the PHP error log):

```php
try {
    require_once APP_ROOT . '/app/whatsapp_hook.php';
    gimt_wa_event('GIMT_PAYMENT_SUCCESS', ['student_id' => $sid, 'receipt_no' => $no, 'amount' => $total], ['dedupe_key' => 'pay:' . $paymentId]);
} catch (\Throwable $waE) {
    error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage());
}

// (every call site below is wrapped in the same try/catch)
// several codes, one payload
gimt_wa_event(['GIMT_ADMISSION_STAGE_CHANGED', 'GIMT_ADMISSION_CONFIRMED'], $payload, ['dedupe_key' => 'adm:' . $id . ':confirmed']);

// bulk: one event per id — the request only stores ONE wa_event_fanouts job, the worker expands it (§6)
gimt_wa_fanout($codes, $common, $ids, 'res:' . $examId . ':{id}', ['source' => 'admin', 'id_key' => 'student_id']);
//   $codes string|string[]; $common payload shared by every item; $ids int[] (max 5,000 per job)
//   dedupe pattern: '{id}' is replaced by each id, otherwise ':<id>' is appended
//   opts: source, id_key = payload key of each id: student_id (default) | enquiry_id | ticket_id | contact_id
```

`gimt_wa_event()` → `wa_event()` is cheap (inserts a log row, queues messages) and never throws. The core namespaces
`dedupe_key` with the event code (`GIMT_PAYMENT_SUCCESS:pay:15`), so one key can serve several codes. Single events run
inside the ERP request (≈ 25 ms each plus ≈ 75 ms to boot the WhatsApp services once per request).

`gimt_wa_fanout()` (results / admit cards / timetable / notice / holiday, bulk assignments …) inserts one row in
**`wa_event_fanouts`** (schema 50: `codes`, `id_key`, `common_payload`, `ids`, `item_dedupe`, `total`, `processed`
cursor, `fired`, `duplicates`, `status` pending|running|done|failed|cancelled, `attempts`, `locked_until` lease). The
worker's `events` tick handler claims a job with a 2-minute lease and fires one `wa_event()` per id in budgeted batches,
saving the cursor as it goes, so publishing results for 3,000 students stays a fast request. Codes nobody acts on
(event inactive, no listener, no active workflow) are logged once as a summary row instead. Without the table (schema
50 not imported) the fan-out falls back to synchronous processing capped at 500 ids. Finished jobs are purged after 30
days. More helpers in the same file: `gimt_wa_student_status_codes()`, `gimt_wa_enquiry_code()` (website form → event
code), `gimt_wa_followup()`, `gimt_wa_stage_codes()`, `gimt_wa_crud()` (the CRUD engine's after-save tap).

External systems post to `POST /api/whatsapp/ingest/event` with header `X-GIMT-Token: <token>` (tokens created in API &
Webhooks; `data` keys starting with `_` and `contact_id` are ignored). Website JavaScript posts to
`POST /api/whatsapp/ingest/track` with the page's CSRF token (`*_id` keys except `program_id` and phone / mobile /
whatsapp / email / contact keys in `data` are ignored — the message can only go to the visitor's own number). The Event
Manager shows copy-ready PHP / JavaScript / cURL.

### 5.1 WhatsApp lead → ERP admission enquiry (the other direction)

Leads that start on WhatsApp (inbox, Admission Leads board, bot, workflows) reach Admissions › Enquiries through one
helper of the contacts part, **`wa_crm_create_enquiry(int $contactId, array $opts = [])`** (never throws; returns
`['ok', 'created', 'enquiry_id', 'enquiry_no', 'error', 'message']`, `enquiry_no` = `#<id>` as the ERP shows it):

- **Idempotent**: a contact already linked to an enquiry returns it; when the number already has an open enquiry (new /
  contacted / interested — the ERP's own duplicate rule) the contact is linked to that one (`created: false`). The
  contact row is locked (`FOR UPDATE`), so two clicks or a bot and an agent at the same moment create one enquiry.
- **Refused** for contacts that are students, alumni or staff or already linked to an application / student record,
  for numbers shorter than 10 digits, and when the contact has no name.
- A new enquiry is written like one added in the ERP: source `whatsapp` (or `$opts['source']` when it is an
  `admission_sources()` key), programme / city / e-mail / counsellor from the contact, status from the lead stage
  (`wa_crm_enquiry_status_for_stage()`), priority high from "Interested" on, follow-up tomorrow, the lead's latest
  WhatsApp text as the message, notes with a link to the chat, activity log, and the "New enquiry" / "Enquiry assigned
  to you" notifications (`quiet` skips them). The contact gets `enquiry_id` and fills only its blank fields.
- **No WhatsApp event is raised**: `GIMT_ENQUIRY_RECEIVED` / `GIMT_ENQUIRY_CREATED_ADMIN` would send the website welcome
  to someone who is already chatting.
- `$opts`: name, email, city, program_id, program_interest, assigned_to, priority, status, message, note, source,
  origin (`inbox|leads|contact|bot|workflow|api` — wording of notes and logs only), quiet.

Callers:
- **Endpoints**: `POST whatsapp/contacts/{id}/enquiry` (inbox contact panel and contact record — `CreateEnquiryDialog`;
  validated name, email, city, program_id, program_interest, assigned_to, priority, message, note) and
  `POST whatsapp/contacts` with `create_enquiry` (Admission Leads › Add lead › "Also create an admission enquiry").
  Both need `whatsapp_contacts.edit` **and** `enquiries.create`; the inbox exposes the result as the
  `create_enquiry` permission flag.
- **Workflow node**: the Create / Link Contact step's option "Also create an ERP admission enquiry"
  (`data.create_enquiry`, `wa_wf_contact_enquiry()`): its id and number become `{{enquiry_id}}` / `{{enquiry_no}}` for
  the next steps; skipped (not failed) for students / staff / applicants; a test run with CRM actions off only reports
  what it would do.
- **AI bot**: when the bot finishes qualifying a lead (`wa_bot_on_qualified()`) and `wa_bot_create_enquiry` is on
  (default 1), the enquiry is created or linked (assigned to the lead owner, quiet), and its number is quoted in the
  owner's notification, the inbox trail and the activity log.

### 5.2 Event Manager — variable mapping, defaults, event reminder, execution log

- **Variable mapping** (`wa_events.variable_map`, JSON `{template variable: entry}`). An entry is `payload:key` |
  `contact:field` | `text:literal` | a bare payload key, optionally followed by **`|default:<text>`**; `default:<text>`
  on its own means Automatic plus a default. Parser: `wa_evt_map_parts()` (`'payload:program_name|default:our
  programmes'` → `['payload:program_name', 'our programmes']`). `wa_evt_build_vars()` applies the default **only after**
  the mapped source, the payload key of the same name and the contact's CRM / ERP data
  (`wa_variables_for_contact()`) have all left the variable empty, so a send that would be refused with "Missing
  template variables" goes out with neutral wording instead; real data always wins. A `text:` literal never takes a
  default. `wa_evt_clean()` normalises the default to one line and refuses one longer than 200 characters or containing
  `{{ }}` (422, shown in the `errors.variable_map` line above the mapping table). In the Event Manager drawer it is the
  **"If empty"** text on each mapping row ("+ Text if empty" opens it; the reset button clears it).
- **Catalogue defaults** (`09_wa_2_events`): the lead events — website, landing-page, chatbot, callback, counselling and
  demo-class enquiries, repeat / admin-created / converted enquiries, enquiry assigned / status changed / follow-up
  logged, counselling scheduled, admission deadline (`GIMT_ENQUIRY_RECEIVED`, `CALLBACK_REQUEST`, `COUNSELLING_REQUEST`,
  `COURSE_ENQUIRY`, `LANDING_LEAD`, `CHATBOT_LEAD`, `ENQUIRY_REPEAT`, `ENQUIRY_CREATED_ADMIN`, `ENQUIRY_CREATED`,
  `ENQUIRY_ASSIGNED`, `ENQUIRY_STATUS_CHANGED`, `FOLLOWUP_LOGGED`, `COUNSELLING_SCHEDULED`, `ADMISSION_DEADLINE`,
  `DEMO_CLASS_REQUEST`, all `GIMT_*`) map `course_name` to `payload:program_name|default:our programmes` — the same
  wording as the starter workflow's welcome. A website enquiry without a programme therefore gets the welcome instead of
  a refused send. Applicant and student events get **no** default: the programme is mandatory there
  (`admissions.program_id` / `students.program_id` NOT NULL). Neither do counsellor names (those events fire on
  assignment), batch names (the event is the allocation) or dates (they come from the ERP; inventing one would be
  untruthful). The existing enrichment fallback `last_date = 'the last date'` in `wa_evt_enrich()` is unchanged.
- **Event reminder** (`GIMT_EVENT_REMINDER`, ships **inactive**): `wa_evt_scan_event_reminder()` is a daily scan (send
  time from the event's `schedule_config`, default 17:00) that messages every registration with a phone and a status
  other than `cancelled` (`event_registrations`) for **published** events starting the next day. Payload: name, phone,
  e-mail, `event_title`, `event_date` (written out, e.g. "07 Nov 2026, 10:30 AM" — never "tomorrow", so a message
  delivered after midnight is still right), `venue`, `event_link`, `event_registration_id`, `event_id`; dedupe per
  registration. It uses the built-in UTILITY template **`event_reminder`** (`09_wa_1_templates`, header + footer, no
  buttons; draft on production, linked by name). An event with no venue in the ERP (`events.venue` is nullable) is
  refused with "Missing template variables: event_venue" until the venue is filled in or an admin sets an "If empty"
  text for it — there is deliberately no default, because no neutral venue wording would be true.
- **Execution log for repeat-guarded sends** (§4 repeat guard): `GET whatsapp/events/logs` returns, per row, an
  `outcome` with `send_status`, `duplicate_of`, `note` and `earlier_message {id, conversation_id, status, sender_type,
  created_at}` (one extra query per page); `GET whatsapp/events/logs/{id}` adds `duplicate_message` (helper
  `wa_evt_duplicate_of()`). The list shows "Already sent recently" (cyan) and e.g. "Not sent again: enquiry_received
  went out 3 min earlier via the Event Manager · message #2819"; the message number links to the chat
  (`/whatsapp/inbox?c=<conversation>`) for users who can view the inbox, else to Message tracking for settings users,
  else it is plain text. The detail modal shows "Why no new message", the earlier message bubble with its status and
  an **Open chat** button, and no Retry. Filter "Duplicate / already sent" includes these rows, "Message queued"
  excludes them, and the "Duplicates blocked" KPI counts them. Rows logged before this change (only `send_status`) get
  the label and link through `message_id`.

## 6. Background worker

`GET|POST /api/whatsapp/cron?token=…` (token = `wa_cron_token()`), **every minute** from hPanel:
`curl -fsS "https://gimtedu.com/api/whatsapp/cron?token=…" > /dev/null`. One run of `wa_worker_tick()`
(`GET_LOCK('gimt_wa_worker')` — an overlapping run exits at once; budget 20 s):

1. **Webhooks** stored by the endpoint but not yet processed (statuses, inbound messages, clicks, template status).
2. **Outbox** — throttled delivery of queued messages.
3. **Tick handlers** (`wa_on_tick`) in priority order (`wa_tick_default_priority()`, lower first; a handler may pass its
   own priority as the 3rd argument):
   `events` 10 — **event fan-out jobs** (`wa_event_fanouts`, most of its slice) then the daily time-based scanners (fee
   due / overdue … at most every 10 min) · `workflows` 20 — due workflow steps (delays, waits, resumes) ·
   `crm_followups` 30 — due admission follow-ups (each row claimed as `processing` while it is sent) · `campaigns` 40 —
   campaign batches · `admin` 60 — digests / notifications · `templates_sync` 70 — hourly status sync from Interakt
   (live only) · `crm_sync` 90 — ERP → CRM contact sync, resumable from `_wa_crm_sync_cursor` · unknown names 50.
4. **Outbox again** (messages the handlers just queued go out in the same run when time allows).
5. **Interakt contact sync** (opt-in changes, live mode) and the **hourly log purge** (`wa_purge_logs()`:
   `wa_retention_days` for webhook / API logs and finished outbox rows, plus `wa_purge_rate_limits()`, which deletes
   expired `rate_limits` windows whose key starts with `wa-` — every key the module writes, e.g. `wa-webhook:<ip>`, and no
   other module's — so an attacker rotating IP addresses cannot grow the table; a primary-key range, at most 20,000 rows
   per run in batches of 5,000, with a minute of slack; an expired row behaves exactly like a missing one, so no limit
   changes) — cron runs only. Saves `_wa_last_cron` and `_wa_last_cron_stats` (incl. `handlers`, `overran` and
   `purged.rate_limits`).

Time slices: every handler gets at least `min(1.5 s, budget / handlers)` (≥ 0.25 s); a handler may use the time left
minus that minimum for each handler after it (the reserve is capped at half the time left). A handler that overruns
delays the others but cannot starve them; only once the whole run passes its hard stop (budget + 5 s; light ticks
budget + min(2 s, budget)) are the remaining handlers skipped (`'skipped' => 'no time left'`) until the next run.
Overruns are written to the PHP error log and to the run stats. Handlers receive their budget and **must** stop when it
is spent (the worker cannot interrupt PHP code).

- `wa_maybe_tick($budget = 2.5)` runs a *light* tick (max once per 30 s, only when the cron has not run for 2 min) from
  dashboard / inbox polling, so local development and a missing cron still work: same order and slices, no log purge /
  contact sync, and it does **not** update `_wa_last_cron` — the worker-health warning stays until the real cron runs
  (`_wa_last_light_tick` records it). `GET whatsapp/status` sends its response first (`wa_respond_then_continue()`:
  fastcgi / litespeed_finish_request, else Content-Length + flush) and ticks afterwards, so polling is never slowed.
  Without the cron, bulk events and scheduled messages only move while someone has a WhatsApp page open.
- The outbox holds automated messages (`wa_automated_sender_types()`) while automations are paused or quiet hours run;
  agent / bot / system replies keep flowing. Throttle: `wa_rate_per_minute` counted over the last 60 s of `message/`
  calls (test-mode rows included), 429 → reschedule + stop, exponential retries up to `max_attempts`; an outbox row that
  keeps throwing fails its message instead of leaving it queued.
- Cron token: HMAC of the install's app key; when the key is empty or still the public default from `config.php`, a
  random per-install secret (`_wa_cron_secret`) is used instead. The cron URL is only returned to `whatsapp_settings.manage`.
- Send queue tools: `POST whatsapp/logs/queue/retry {ids}` and `POST whatsapp/logs/queue/cancel {ids}` (API & Webhooks
  › Retry queue) take **`wa_messages` ids** and go through `wa_retry_message()` / `wa_cancel_queued()`; the inbox retry uses
  `wa_retry_message()` too.
- **Worker actor (audit attribution)**: background work has no human actor — the cron run, "Run worker now", light
  ticks inside a staff request, the webhook route's post-processing and `wa_process_webhook_log()` (used by the
  simulators and Reprocess) all run inside `wa_as_worker()` (`wa_worker_tick()` enters it after taking its lock and
  leaves it in `finally`; nesting is counted by `wa_worker_depth()`, the previous context is restored even after an
  exception). Inside it `wa_user_id()` returns null and `wa_event()`'s default `source` is `worker` (a new source
  value; outside it is `admin` for a signed-in user, else `core`). `wa_event_logs`, `wa_agent_log()`, stage history and `sent_by`
  use `wa_user_id()`, so a STOP processed during a staff member's page load is not attributed to them. `log_activity()`
  (app/functions.php) writes `user_id` NULL while `wa_in_worker()` is true (e.g. the bot creating an ERP enquiry during
  a light tick), and `wa_evt_fanout()` stores `created_by` from `wa_user_id()`, so scanner bulk jobs queued in a light
  tick no longer show "by Administrator" in Bulk deliveries. Normal staff actions are still logged as that user.
- **Webhook-health watch**: every tick runs `wa_webhook_health_watch()` (tick stats key `webhook_health`), which stores
  the state and raises the alerts described in §9.

### 6.1 Automation runner (workflows part) — safety rules

- **API / webhook steps**: `https://` only, ports 443 / 8443, no credentials in the URL, the host must resolve to public
  IPs only (resolved once and pinned against DNS rebinding), never through a proxy, no redirects, 10 s timeout, response
  capped at 64 KB (request body too), and a request that contains a GIMT secret (Interakt key, webhook secret, worker
  token, app key) is refused. The webhook step sends `X-GIMT-Workflow` / `X-GIMT-Run` headers (no HMAC signature).
- **Limits**: 25 visits per step and 250 steps per run (loops through Delay / Wait for reply included), 80 steps per
  pass; re-entry (when a workflow allows it) at least 10 minutes apart; at most 10 runs of one workflow per contact per
  24 h (event floods).
- **Loop protection**: a workflow is never restarted by an event its own steps raised, and workflow → event → workflow
  chains stop at depth 4 (in-process chain, independent of the `WA_STAGE_CHANGED` payload).
- **Opt-out**: a contact who replied STOP gets no workflow message; the first refused send cancels the run
  ("Contact opted out of WhatsApp — workflow stopped").
- **Repeat guard**: a template step that `wa_send()` answers with `duplicate_recent` (the same template and text reached
  the contact in the last 10 minutes, e.g. from the Event Manager) is **skipped**, the run carries on, and the step
  summary names the earlier message ("Skipped — the same template was sent to this contact 2 min ago by the Event
  Manager (message #2839)", `wa_wf_recent_duplicate_summary()`; §4). With `GIMT_ENQUIRY_RECEIVED` and "New Admission
  Enquiry" both active, the event sends the welcome and the workflow's welcome step shows as skipped.
- **Deadline variables**: `{{admissions_deadline}}` / `{{last_date}}` come from the setting `admissions_deadline`
  (else `admission_last_date`; Settings › Website › Campaign landing pages › Application deadline). A template step
  that needs them fails with a clear message while the deadline is missing, invalid or in the past.
- Per-workflow analytics: `GET /api/whatsapp/workflows/{id}/stats?days=7|30|90`.
- **Starter workflows** (`wa_wf_starters()`, seeded as **drafts** by `09_wa_4_workflows`): "New Admission Enquiry"
  (trigger `GIMT_ENQUIRY_RECEIVED`) → Create / link contact **+ ERP enquiry ID** (`create_enquiry`, §5.1) → welcome
  (`enquiry_received`) → wait 10 min → course information (`brochure_requested`) → wait 24 h for a reply → replied:
  assign a counsellor (round robin, keeps an existing one, syncs the enquiry, notifies the counsellor with the lead's
  name, phone and course) → `callback_requested` ("a counsellor will call you" — **no date or time is promised**; the
  earlier starter's made-up "counselling tomorrow at 11:00 AM" step is gone) | no reply: follow-up → wait 48 h → final
  follow-up. Plus "Admission Fee Payment Pending — Nudges" and "Application Incomplete Reminder" (both on
  `WA_STAGE_CHANGED`). The seeder refreshes a seeded workflow only while it is untouched (version 1) and inactive — or,
  when active, only if its stored definition no longer validates; an edited workflow (version > 1) is never changed.
  Runs keep the definition they started with.

### 6.2 AI bot (bot part) — notes

- **"About the institute" intent**: `chatbot_detect_intents()` (`app/services/chatbot/offline.php`, shared with the
  website chatbot) returns `['about']` for overview questions — "Tell me about GIMT", "What is GIMT?", "GIMT ke baare
  mein batao", "कॉलेज के बारे में बताइए" — only when every other word is filler and nothing more specific is asked
  ("Tell me about GIMT hostels" stays a hostel question; a named programme wins over the overview). The WhatsApp bot
  answers with the verified "About" answer of its knowledge base (`wa_bot_kb_about()`: the first active answer whose own
  question is an about-question, category `about` first; `09_wa_5_bot` seeds "Tell me about GIMT" with placeholders
  filled from live settings and the programme catalogue), else with `chatbot_about_answer()` built from settings
  (name, established, affiliation, address) and the programme count per level — never anything else.
- **Admission enquiry on qualification**: `wa_bot_create_enquiry` (default 1), see §5.1.
- **Language**: the bot answers in English, Hindi or Hinglish and sets the contact's `language` to `hi` when they write
  in Devanagari, so later templates prefer the Hindi copy when one is approved (§4 language preference).

## 7. UI conventions

GIMT admin look (navy `brand-900` + orange-red `accent-600`, white cards, `dark:` variants), components from
`@/components/ui`, `@/components/crud`, `@/components/charts`; every page: `PageHeader` with breadcrumbs
Dashboard › WhatsApp › Page, loading/empty/error states, responsive, no new npm dependencies. Permission-gate actions
with `useAuth().can(module, action)`. WhatsApp-specific visuals come from `modules/whatsapp/shared` (import from
`'../shared'`): `WaBubble` / `WaText` (every message type, template snapshot, ticks), `WaPhoneFrame`, `StatusTicks`,
`WindowBadge` (24-h window countdown, IST), `PhoneText`, `VariableChips`, `ModeBanner` (test mode; a stopped worker in
live mode; "Webhook secret missing" on non-dev installs for users who can see WhatsApp settings — amber in test mode,
red in live; in live mode a red notice when the webhook is `silent` or fails the signature check and an amber one while
no signed webhook has ever arrived (§9); a paused-automation notice in any mode), `useWaStatus()`, `useWaVariables()`,
`waMediaUrl()`, `waSafeUrl()`, `waUploadMedia()`, `waRunWorker()`, `waSimulateInbound()`, `waSimulateStatus()`,
`waSendError()`, `parseServerDate()` (server DATETIMEs are IST).

## 8. Seed order

File names sort in dependency order, so the installer (and `php tools/seed.php` with the names in `ls` order) runs them
correctly. Production seeds (fresh install): `09_wa_0_core` (settings defaults, permission rows, WhatsApp roles and
grants — additive; ERP modules can be granted too: Admission Manager and Admission Counsellor also get **Enquiries ›
view + create**, which "Create admission enquiry" needs together with WhatsApp contacts › edit, §5.1) →
`09_wa_1_templates` (69 built-in templates, all draft: the original 42 plus 27 starter templates for the other brief
events — `contact_form`, `enquiry_assigned`, `payment_pending`, `exam_announced`, `certificate_generated`,
`ticket_assigned_staff`, `student_status_changed`, `event_reminder` …) → `09_wa_2_events` (106 events; the 25 core
transactional ones are activated when their template is linked, the starter-template events — `GIMT_EVENT_REMINDER`
included — and the website-JavaScript events such as `GIMT_BROCHURE_DOWNLOAD` stay off; lead events carry the
`course_name` default of §5.2) → `09_wa_3_followups` → `09_wa_4_workflows` (3 starter workflows, draft; §6.1) →
`09_wa_5_bot` (bot settings incl. `wa_bot_create_enquiry` + 15 starter knowledge-base answers, among them "Tell me
about GIMT"). With this order events, follow-ups and workflows find the built-in templates on a fresh install. Demo
seeds afterwards (never on production / live): `98_wa_0_templates` (built-in templates approved in test mode + demo
templates in every state) → `98_wa_1_contacts` → `98_wa_2_inbox` → `98_wa_3_campaigns` → `98_wa_4_runs` →
`98_wa_5_bot` (bot demo sessions / turns; needs the contacts and conversations) → `98_wa_6_users` (demo logins for the
four WhatsApp roles) → `98_wa_7_logs` (runs after every other `98_wa_*` seeder: the last 14 days of business events
`wa_event_logs`, Interakt webhooks `wa_webhook_logs` and API calls `wa_api_logs`, anchored on the day it runs, so the
Event Manager, API & Webhooks logs, error monitor and dashboard charts look live; it owns only marked rows). Every
09_wa_* and 98_wa_* seeder is re-runnable on its own, e.g.
`php tools/seed.php 09_wa_2_events`. Schema files run before any seed: `48_whatsapp.sql`, then the feature ALTER/CREATE
files `49_whatsapp_bot`, `50_whatsapp_events` (incl. `wa_event_fanouts`), `52_whatsapp_campaigns`,
`53_whatsapp_contacts`, `54_whatsapp_templates` — include all of them in a fresh install and in a live import.

## 9. Security notes

- **Webhook** (`POST /api/whatsapp/webhook`, no login / CSRF):
  - **Size and format**: the body is capped at **128 KB** (`wa_webhook_max_bytes()`; Content-Length is checked before
    reading, then a bounded read) → HTTP **413**; invalid JSON → HTTP **400**; neither is stored.
  - **Signature before storage**: `Interakt-Signature: sha256=<hex HMAC-SHA256 of the raw body>` (hex case-insensitive)
    is checked with `hash_equals` against `wa_webhook_secret` **before anything is stored** (`wa_webhook_verdict()`).
    On every non-dev install, **unsigned webhooks are ignored in test AND live mode while no secret is saved** (forged
    inbound messages, ticks, template status or STOP / START keywords would otherwise be stored as genuine, consent
    records included, and carried into live mode). Only GIMT's own simulators (the internal `WA_SIMULATED` marker, set
    by `wa_simulate_webhook()` — the route strips it from the request, so an HTTP request can never carry it) and dev
    installs bypass this. A wrong signature is ignored whenever a secret is saved. The endpoint still answers **200**
    for valid JSON (`received: false` when it ignored the request), because Interakt disables a webhook after 5 failures
    in 10 minutes; accepted webhooks are processed after the response when the SAPI allows, else from the worker.
  - **Rate limits**: 300 requests/min per IP for requests that will not be processed (unsigned or wrong signature),
    3,000/min per IP for requests signed with the saved secret (a busy campaign's delivery ticks never get 429) → HTTP
    429 above them.
  - **Rejected rows keep metadata only**: unsigned, invalid-signature and over-size webhooks are stored in
    `wa_webhook_logs` as `ignored` with the event type, `payload` = the first 1 KB, and `headers` JSON `{size, sha256, ip,
    interakt-signature, rejected: unsigned|invalid_signature|too_large|invalid_json, payload_stored: 'excerpt'}`.
    Exception: a request that carries a signature while no secret is saved yet keeps its full payload
    (`payload_stored: 'full'`, up to 2 MB per hour) so it can be verified and reprocessed once the secret is saved. At
    most 500 rejected rows are stored per hour; the rest are only counted (`rate_limits` key `wa-webhook-rejected`).
    `system_logs` gets at most one line per reason per 10 minutes.
  - **Reprocess rule** (`wa_webhook_log_can_reprocess()`, used by API & Webhooks › Webhook logs › Reprocess): allowed
    only for simulated rows, rows verified on arrival (`signature_valid = 1`), and rows that arrived while **no secret
    was saved** (`signature_valid` NULL) when either their stored `interakt-signature` verifies against the stored full
    payload with the secret saved **now**, or this is a dev install that still has no secret. A row that **failed the
    signature check on arrival** (`signature_valid = 0`: a secret was saved and the signature was wrong or missing) can
    **never** be reprocessed — on every install, dev included, whatever secret is saved now; the reason says whether it
    carried a non-matching signature or none. Excerpt-only rows, invalid JSON and unsigned rows are refused too.
    Recovery after saving the secret late: Reprocess the `ignored` rows that kept their signed payload.
  - Expired `wa-*` rate-limit windows (per-IP webhook counters and the rest) are deleted by the hourly purge (§6).
- **Webhook health** (`wa_webhook_health()`; `GET whatsapp/status` fields `webhook_health`, `webhook_health_note`,
  `last_valid_webhook_at`, and for settings managers `webhooks_rejected_hour` / `webhooks_dropped_hour`): `n/a` in test
  mode. Rejected rows of the last hour are split by their stored headers: **wrongly signed** = carried an
  `interakt-signature` that did not verify against the saved secret (`invalid_1h`); **unsigned** = carried no signature
  at all — scanners, forged POSTs, or a secret never entered in Interakt (`unsigned_1h`); signed while no secret was
  saved = kept for Reprocess (`unverified_1h`). In live mode, checked in this order: `bad_signature` = at least 3
  **signed** webhooks failed the signature check in the last hour and they outnumber the accepted ones; `never` = no
  signed webhook has ever arrived; `silent` = a message was accepted by Interakt (sent_at) 5 minutes to 24 hours ago and
  no signed webhook has arrived since; else `ok`. **Unsigned requests never turn the state red** (so a few scanner hits
  on a quiet install no longer raise a false "failing the signature check" alert); they only add "N unsigned requests
  were ignored in the last hour — if Interakt is sending them, enter the same secret there" to the `never` / `silent`
  note. It reads the latest 2,000 log rows by primary key plus `_wa_webhook_last_signed_at`, never the whole table. Interakt
  auto-disables a webhook after 5 failed deliveries in 10 minutes and **never resends missed events**, so a `silent`
  webhook must be re-enabled in Interakt › Developer Settings › Webhooks. Each worker tick (`wa_webhook_health_watch()`)
  stores `_wa_webhook_health` and, when the state turns `silent` or `bad_signature`, notifies `whatsapp_settings.manage`
  users and Super Admins (in-app notification plus a `system_logs` warning) at most once per 6 hours per state
  (`_wa_webhook_health_notified`); recovery lines are throttled to one per 10 minutes. `ModeBanner`, the Dashboard and
  WhatsApp › Integrations show the state with its plain-English note. The same rule applies to the counters: the
  Dashboard / Integrations "webhook failures in 24 h" (`wa_adm_connection` → `webhooks_24h.bad_signature`) and the
  Reports › Errors webhook series and top list count an ignored row as a signature failure only when its stored headers
  carry `interakt-signature` (`WA_ADM_SIGNED_SQL`); unsigned scanner requests are not counted as failures.
- **Interakt host**: the API key goes only to `wa_interakt_base_url()`, which accepts nothing but `https://` on
  `interakt.ai` / `*.interakt.ai` (else the default `https://api.interakt.ai/v1/public/`), whoever wrote the setting.
- **Inbound media** is downloaded server-side only from public addresses (`wa_public_target()`: http(s), no credentials,
  on non-dev installs only ports 80 / 443, no trailing-dot hosts or zone ids, and **every** resolved A / AAAA address
  must pass `wa_ip_is_public()` — the same blocked ranges as the workflow HTTP step: private, loopback, link-local,
  reserved, CGNAT `100.64/10`, benchmarking `198.18/15`, NAT64 `64:ff9b::/96` and `64:ff9b:1::/48`, IPv4-mapped
  addresses; the address is pinned against DNS rebinding and every redirect hop is re-checked) into private
  `storage/whatsapp/`. `GET whatsapp/media/{id}` checks the WhatsApp permission and the inbox assignment rule,
  realpath-confines the file to `storage/whatsapp`, sends `nosniff`, a sandbox CSP (except verified PDFs) and serves only
  images / audio / video / PDF inline — everything else downloads as `application/octet-stream`.
- **Uploads** for sending (`POST whatsapp/media/upload`): extension allow-list matched against finfo MIME, size limits per
  type, executable / SVG / HTML names refused, JPEG / PNG re-encoded with GD, random file names.
- **Simulators** (`simulate/*`) need `whatsapp_settings.manage` or `whatsapp.manage` and are refused in live mode unless
  `wa_allow_simulate_live = 1`.
- **Secrets** (API key, webhook secret, tokens) are never sent to the browser or written to `wa_api_logs` /
  `wa_event_logs` / activity logs; event payload keys matching password|secret|api_key|token|cvv|card_number are redacted.
  The **webhook secret is generated (Web Crypto) or typed in the browser** — it has to be pasted into Interakt anyway —
  and `POST whatsapp/integration/webhook-secret {secret}` (16–200 characters, no spaces, `whatsapp_settings.manage`)
  only stores it encrypted; afterwards GIMT shows its last 4 characters only.
- **UI**: `WaText` / `WaBubble` render message text as React text nodes (WhatsApp `*bold*` etc. parsed into elements, no
  `dangerouslySetInnerHTML`); links only for `http(s)://`; media `href` / `src` pass `waSafeUrl()` (http(s) or
  site-relative only).

## 10. Go-live checklist

1. Interakt plan: **Growth** or higher for the API and delivery webhooks; the **Advanced plan (or Enterprise) is
   required for inbound messages** (`message_received`), i.e. the shared inbox, the chatbot, STOP / START keywords and
   "wait for reply" workflow steps. On Growth, outbound sending and delivery ticks work but replies never reach GIMT.
2. Schema + seeds: every file listed in §8 (48, 49, 50 incl. `wa_event_fanouts`, 52, 53, 54) and the `09_wa_*`
   production seeds — never the `98_wa_*` demo seeds.
3. `config/local.php`: `env` = `production`, a real `url` (https) and a unique `key` (the cron token derives from it —
   set it **before** copying the cron command).
4. WhatsApp › Integrations: paste the Interakt **API key** (app.interakt.ai → Settings → Developer Settings → Secret
   Key), set the plan (rate limit 300 / 600 per minute) and the business number. Do not expect "Test connection" to
   prove anything yet: in test mode Interakt is not contacted and the result says "not tested".
5. Webhook — **save the secret in GIMT before Interakt sends anything**: in WhatsApp › Integrations › Webhook setup
   press **Generate** (the secret is created in your browser) or paste your own (16–200 characters, no spaces), copy
   it, and **Save** — the server only stores it and afterwards shows its last 4 characters. Then in the Interakt
   dashboard → Developer Settings → Webhooks enter the URL `https://gimtedu.com/api/whatsapp/webhook` (shown in API &
   Webhooks), the **same secret key**, and tick `message_api_sent / delivered / read / failed / clicked`,
   `message_received` and `message_template_status_update`. On the production install every webhook (messages,
   delivery ticks, template status) is **ignored until the secret is saved — in test mode as well as in live mode**.
   A webhook Interakt had already signed but that arrived before the secret was saved keeps its payload and can be
   recovered with **Reprocess** (API & Webhooks › Webhook logs) once the matching secret is saved.
6. hPanel → Advanced → Cron Jobs: **every minute** (`* * * * *`)
   `curl -fsS "https://gimtedu.com/api/whatsapp/cron?token=<token>" > /dev/null` (the exact command is in API &
   Webhooks). Check that the "Background worker" status turns healthy (opportunistic page ticks do not count).
7. Review Settings › Automation (quiet hours, daily cap) and Team permissions (Admission Manager and Admission
   Counsellor include Enquiries › view + create, so they can turn WhatsApp leads into ERP enquiries), and check which
   events, workflows and campaigns are active — they start sending as soon as the mode is live. Lead events fill an
   empty `course_name` with "our programmes" (§5.2); change or clear that "If empty" text per event if needed.
8. **Pause automations before switching**: Settings › Automation › "Pause all automated messages" (event, workflow,
   follow-up and campaign messages are held, not cancelled; agent replies, Template Library test sends and bot replies
   still go out). Then switch **Mode → Live** in WhatsApp › Integrations and **press Test connection** (only now does
   it call Interakt with the key; 401 / 403 = wrong key).
9. Templates (live only — in test mode Interakt is never contacted): templates created in Facebook Business Manager /
   WhatsApp Manager need **Sync** in app.interakt.ai first, then **Import from Interakt** in GIMT's Template Library
   (and Sync statuses). Submit the built-in library templates that events, follow-ups, workflows and campaigns use and
   wait until they are **approved**. The 27 starter templates (including `event_reminder` for `GIMT_EVENT_REMINDER`)
   are drafts: submit each for Meta approval before switching on the event that uses it. Until then live sends that need them are refused (`template_not_approved`,
   visible in Event Manager / run history).
10. Send one approved template to a staff number (Template Library › Send test — an Event Manager test is held while
    automations are paused), confirm the delivery ticks update, reply from that phone and confirm the reply appears in
    the inbox (no reply = webhook secret missing in GIMT, wrong secret in Interakt, or not on the Advanced plan). Check
    that WhatsApp › Integrations shows webhook health **ok**. Then review API & Webhooks › Retry queue (messages held
    since the pause), cancel stale ones, and **Resume** (switch the pause off).
11. After go-live: watch the webhook-health banner — Interakt auto-disables a webhook after 5 failures in 10 minutes
    and never resends missed events; re-enable it in Interakt › Developer Settings › Webhooks. Failed or stuck sends
    are handled in API & Webhooks › Retry queue — retry / cancel act on message ids (`wa_messages`) through
    `wa_retry_message()` / `wa_cancel_queued()`, so the 24-hour window, template approval, opt-in and STOP rules are
    checked again.

The step-by-step runbook for gimtedu.com (import file, exact upload list, rollback) is
`storage/private/deploy/WHATSAPP_GO_LIVE.md` (not deployed, not web-reachable).
