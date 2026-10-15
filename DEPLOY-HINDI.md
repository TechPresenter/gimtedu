# Hostinger पर नई वेबसाइट लगाने के आसान स्टेप्स (हिंदी)

> पूरी अंग्रेज़ी गाइड: **README.md** (सेक्शन 4)

1. **बैकअप लें:** hPanel → Websites → Manage → **Backups** → पुरानी WordPress साइट (files + database) का बैकअप डाउनलोड करें।
2. **पुरानी WordPress फ़ाइलें हटाएँ:** File Manager → `public_html` → `wp-admin`, `wp-content`, `wp-includes`, `wp-*.php`, `index.php`, `.htaccess` आदि डिलीट करें (`.well-known` रहने दें)।
3. **नया Database बनाएँ:** hPanel → Databases → MySQL Databases → नया database + user बनाएँ। पासवर्ड में **सिर्फ़ अक्षर और अंक** रखें। पूरा नाम नोट करें (जैसे `u623595918_prerit`)।
4. **database.sql इम्पोर्ट करें:** phpMyAdmin → वही database चुनें → **Import** → `database.sql` → **Go**। 51 tables दिखनी चाहिए।
5. **फ़ाइलें अपलोड करें:** `public_html` फ़ोल्डर के **अंदर की सारी फ़ाइलें** (zip बनाकर) Hostinger के `public_html` में अपलोड करके **Extract** करें। छुपी हुई `.htaccess` फ़ाइलें भी होनी चाहिए।
6. **pwf-config.php बनाएँ:** `pwf-config.sample.php` को `pwf-config.php` नाम से कॉपी करें, उसमें `BASE_URL`, `DB_NAME`, `DB_USER`, `DB_PASS` और एक लंबी random `INSTALL_KEY` भरें, और इसे **public_html के एक फ़ोल्डर ऊपर** अपलोड करें (जैसे `/home/u623595918/domains/preritwelfarefoundation.org/pwf-config.php`)।
7. **Installer चलाएँ:** `https://preritwelfarefoundation.org/install.php` खोलें → INSTALL_KEY डालें → पहला **Administrator** बनाएँ (कोई default पासवर्ड नहीं है)।
8. इसके बाद `pwf-config.php` में `INSTALL_KEY` को `''` कर दें और `install.php` डिलीट कर दें।
9. **SSL चालू करें:** hPanel में SSL ऑन करें, फिर `public_html/.htaccess` में "Force HTTPS" वाली 3 लाइनों से `#` हटाएँ।
10. **Admin में:** `https://preritwelfarefoundation.org/admin/` → My Profile में **Two-step login** चालू करें → Settings में **horizontal logo**, फ़ोन/WhatsApp, सत्यापित (verified) रजिस्ट्रेशन विवरण भरें → Campaigns में 5 draft campaigns की जानकारी देखकर publish करें।

**"We will be back shortly" दिखे तो:** database का नाम/यूज़र/पासवर्ड गलत है — hPanel में database user का पासवर्ड reset करके वही `pwf-config.php` में डालें। `install.php` पर सही error दिखेगा।

**Razorpay:** merchant account approve होने के बाद ही keys डालें (पहले test keys)। Webhook URL: `https://preritwelfarefoundation.org/payment-webhook.php`।
