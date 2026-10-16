# वेबसाइट live करने के आसान स्टेप्स (हिंदी)

> पूरी अंग्रेज़ी गाइड: **README.md**

आपको **दो फ़ाइलें** मिलती हैं:

| फ़ाइल | क्या है |
|---|---|
| `prerit-website.zip` | पूरी वेबसाइट। इसकी सारी फ़ाइलें सीधे zip के अंदर हैं (कोई अलग folder या दूसरी zip नहीं)। साथ में `pwf-config.php` (settings फ़ाइल) भी है। |
| `prerit-database.sql` | पूरा database: सारे pages, menus, settings, programme categories और सारे updates पहले से लगे हुए। इसमें कोई user, password, donation, application या payment/AI key नहीं है। |

> 🔒 `prerit-website.zip` को किसी से share न करें (chat, email, shared drive) — इसकी `pwf-config.php` में आपकी secret keys (`INSTALL_KEY`, `APP_KEY`) हैं। काम पूरा होने पर अपने computer से भी हटा दें या सुरक्षित जगह रखें।

**पहले तय करें कौन सा रास्ता:**

- **रास्ता 1 – नई installation:** नया/खाली database। → ZIP + SQL दोनों।
- **रास्ता 2 – अभी चल रही live वेबसाइट को update करना:** आपके donations, users और applications बचाने हैं। → **सिर्फ़ ZIP**, SQL import **नहीं** करना।

> ⚠️ `prerit-database.sql` को **कभी भी** अभी वाले live database में import न करें। (यह फ़ाइल कुछ delete नहीं करती, पुराने database में पहली table पर ही "already exists" error देकर रुक जाती है, फिर भी ऐसा न करें।)

---

## रास्ता 1: नई installation (4 स्टेप)

**शुरू करने से पहले:**
- hPanel → Websites → Manage → **Backups** → पुरानी files + database का बैकअप डाउनलोड करें (अगर कुछ पहले से है)।
- hPanel → Advanced → **PHP Configuration** → PHP **8.2** चुनें (8.1–8.3 भी चलेगा, कम से कम 8.0)। Extensions `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `dom`, `json`, `openssl` ON रखें (`gd` और `sodium` भी हों तो अच्छा)।

### स्टेप 1 – ZIP upload और extract करें
1. hPanel → **File Manager** → `public_html` खोलें।
2. पुरानी फ़ाइलें हों तो सब delete करें (सिर्फ़ `.well-known` folder रहने दें)।
3. **Upload** → `prerit-website.zip` चुनें।
4. zip पर right-click → **Extract** → जगह `public_html` ही रखें → Extract।
5. zip फ़ाइल delete कर दें।
6. जाँचें: `public_html` के अंदर **सीधे** `index.php`, `install.php`, `pwf-config.php`, `admin`, `assets`, `includes` दिखें। (Settings → "Show hidden files" ON करने पर `.htaccess` भी दिखेगी।)

### स्टेप 2 – Database बनाएँ और SQL import करें
1. hPanel → **Databases → Management** → नया database बनाएँ: database name, username, password (पासवर्ड में **सिर्फ़ अक्षर और अंक** रखें, आसानी रहेगी)।
2. पूरे नाम नोट करें, जैसे `u123456789_prerit` (आगे वाला `u123456789_` हिस्सा भी)।
3. उसी database के आगे **Enter phpMyAdmin** → ऊपर **Import** → `prerit-database.sql` चुनें → नीचे **Import / Go**।
4. "Import has been successfully finished" दिखना चाहिए (लगभग 70 tables)।

### स्टेप 3 – Config फ़ाइल में database की जानकारी डालें
> ⚠️ अगर `public_html` के **एक folder ऊपर** पहले से कोई `pwf-config.php` है (पुरानी/live वेबसाइट की), तो वेबसाइट **वही फ़ाइल** पढ़ती है और `public_html` वाली को अनदेखा करती है। नई installation के लिए database की तीन values उसी ऊपर वाली फ़ाइल में बदलें, या उसका नाम `pwf-config-old.php` कर दें (उसका `APP_KEY` संभालकर रखें)।

1. File Manager → `public_html` → `pwf-config.php` → right-click → **Edit**।
2. सबसे ऊपर ये **तीन लाइनें** बदलें (सिर्फ़ quotes `' '` के अंदर का हिस्सा):
   ```php
   define('DB_NAME', 'u123456789_prerit');      // आपका पूरा database name
   define('DB_USER', 'u123456789_prerit');      // आपका पूरा database user
   define('DB_PASS', 'आपका-database-password');
   ```
3. `BASE_URL` पहले से `https://preritwelfarefoundation.org` है। कोई दूसरा domain हो तो वही लिखें (https के साथ, आख़िर में `/` नहीं)।
4. नीचे की बाकी लाइनें (INSTALL_KEY, APP_KEY) पहले से भरी हैं, उन्हें **न बदलें**। **Save** करें।
   - (वैकल्पिक, ज़्यादा सुरक्षित) यह फ़ाइल `public_html` के **एक folder ऊपर** move कर सकते हैं — वेबसाइट पहले वहीं देखती है। `public_html` में रहे तब भी web से खुल नहीं सकती।

### स्टेप 4 – Domain जोड़ें और वेबसाइट live करें
1. Domain इसी Hostinger hosting से जुड़ा हो (Domain के nameservers Hostinger के हों)।
2. hPanel → Security → **SSL** → certificate **Active** हो → **Force HTTPS** ON। (नया domain जोड़ा है तो SSL Active होने में कुछ घंटे लग सकते हैं — Active होने तक `install.php` न खोलें।)
3. Browser में खोलें: `https://preritwelfarefoundation.org/install.php`
   - सारी जाँचें हरी होनी चाहिए।
   - **Installation key**: `pwf-config.php` में `INSTALL_KEY` वाली लाइन की value copy करके डालें।
   - पहला **Administrator** बनाएँ: नाम, email, मज़बूत पासवर्ड (12+ अक्षर, नाम/email का हिस्सा नहीं) → "Installation complete"।
4. File Manager → `public_html` → **`install.php` delete करें।** (चाहें तो `pwf-config.php` में `INSTALL_KEY` की value को `''` कर दें — उसकी अब ज़रूरत नहीं।)
5. `https://preritwelfarefoundation.org/admin/` पर login करें। ✅ वेबसाइट live है।

---

## रास्ता 2: अभी वाली live वेबसाइट update करें (data सुरक्षित)

1. **बैकअप (ज़रूरी):** hPanel → Backups → files + database डाउनलोड करें। और Admin → System → **Backup & Data Retention** → database backup।
2. File Manager → `public_html` → `prerit-website.zip` upload → **Extract** (जगह `public_html`) → पूछे तो **Replace / Overwrite**। कुछ भी delete न करें — `uploads` folder (आपकी photos/documents) वैसे ही रहेगा।
   - Extract के बाद `public_html` में **सीधे** `install.php` और `pwf-config.php` दिखनी चाहिए। अगर `prerit-website` नाम का नया folder बन गया है, तो उस folder को delete करके दोबारा extract करें — जगह सिर्फ़ `public_html` रखें।
3. Extract के बाद `public_html` से ये **तीन फ़ाइलें delete करें**:
   - `install.php` (वेबसाइट पहले से installed है)
   - `pwf-config.php` (यह सिर्फ़ नई installation के लिए है। आपकी असली settings `public_html` के **एक folder ऊपर** वाली `pwf-config.php` में हैं — उसे न छुएँ।)
   - `prerit-website.zip` (extract हो जाने के बाद इसकी ज़रूरत नहीं; इसमें secret keys हैं)
4. **SQL import न करें।**
5. वेबसाइट एक बार खोलें: database **अपने-आप** update हो जाता है (नई tables/settings जुड़ती हैं, आपका कोई data नहीं हटता)। जाँच: Admin → Dashboard पर कोई लाल सूचना नहीं; Admin → System → Security Centre में "Database up to date"।
   - पहले वाली "Illegal mix of collations" error भी इसी update से ठीक हो जाती है।

---

## Live होने के बाद (Admin में, एक बार)

1. **My Profile → Two-step login** ON करें।
2. **Settings:** logo, फ़ोन/WhatsApp, पता, NGO registration details, social links जाँचें; Email → **Send test email**। (अच्छी email delivery के लिए: hPanel → Emails में `info@…` mailbox बनाएँ, फिर `pwf-config.php` में `SMTP_HOST` = `smtp.hostinger.com` और `SMTP_PASS` = उस mailbox का पासवर्ड।)
3. **Razorpay (ज़रूरी):** आपने live secret key chat में भेजी थी, इसलिए Razorpay Dashboard → API Keys → **Regenerate** करें। फिर Admin → Fundraising → **Payment Gateways** → Key ID + **नई** Key Secret → Save → Test connection। Razorpay → Webhooks: URL `https://preritwelfarefoundation.org/payment-webhook.php`, एक लंबा secret (वही Admin में "Webhook Secret"), events: payment.authorized, payment.captured, order.paid, payment.failed, refund.processed। फिर ₹1–10 का एक असली test donation करके receipt email जाँचें।
4. **Cashfree (वैकल्पिक):** merchant account approve होने के बाद पहले Sandbox keys → Test connection → Webhook `https://preritwelfarefoundation.org/cashfree-webhook.php`।
5. **AI Chatbot (वैकल्पिक):** platform.openai.com → API key + monthly budget limit → Admin → Communication → **AI Assistant** → key → Switch on → Save → Test connection।
6. **Seal:** Admin → People → **Authorization Letters → Settings** → foundation की seal (PNG) अपलोड करें — यही donate page पर दिखेगी।
7. **Hero images:** Admin → Dashboard → "Homepage hero slider" → हर slide में Upload new।
8. **Programs & Events:** Admin → Content → Programme Categories / Events & Calendar में असली कार्यक्रम डालें (बिना असली तारीख़/speaker के कुछ publish न करें)।
9. **Google Search Console:** search.google.com/search-console → Domain property `preritwelfarefoundation.org` → DNS से verify → **Sitemaps** → `sitemap.xml` submit करें। Admin → **SEO** में verification code और Google Analytics ID डाल सकते हैं।

---

## दिक्कत हो तो

| दिखे | कारण / उपाय |
|---|---|
| "We will be back shortly" | `pwf-config.php` में database name / user / password गलत। hPanel में DB user का पासवर्ड reset करके वही डालें। `install.php` खोलने पर सही error दिखता है। |
| 500 error | PHP version 8.2 (या 8.1/8.3) चुनें। |
| Homepage खुले पर बाकी pages 404 | `.htaccess` extract नहीं हुई — zip दोबारा extract करें। |
| phpMyAdmin: "Table 'website_settings' already exists" | आप ऐसे database में import कर रहे हैं जिसमें पहले से tables हैं। नई installation के लिए नया/खाली database लें। (Live database है तो कुछ नहीं बदला — रास्ता 2 अपनाएँ।) |
| install.php पर "already installed" / Forbidden | Administrator पहले से बना है — सीधे `/admin/` खोलें और `install.php` delete करें। |
| Admin Dashboard पर लाल "database update failed" | 10 मिनट में अपने-आप दोबारा चलता है। न ठीक हो तो उस संदेश का screenshot भेजें। |
