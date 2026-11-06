<?php
/**
 * Rule-based ("offline") chatbot engine — used when no AI provider is configured / reachable, for greetings and
 * abuse (no AI call needed), and as the safe answer after a provider refusal. Same knowledge base and retrieval as
 * the AI path, answers in English, Hindi or Hinglish templates, never invents facts (only DB / settings values).
 *
 *   chatbot_detect_intents($text)                         ordered topic list (fees, scholarship, hostel … ; 'about' = an
 *                                                         overview of the institute, chatbot_is_about_question())
 *   chatbot_offline_answer($question, $ctx)               ['reply', 'sources', 'lead_prompt', 'intent']
 *   chatbot_about_answer($lang)                           overview from settings (name, established, affiliation, address)
 *                                                         + program count per level (chatbot_program_levels())
 *   chatbot_suggestions($intents, $programs, $lang)       follow-up chips
 */

/** Topic patterns, checked on the normalised text and its canonical tokens. Order = priority. */
function chatbot_detect_intents(string $text): array
{
    $n = chatbot_normalize($text);
    $t = array_flip(chatbot_tokens($text));
    $words = preg_split('/[^\p{L}\p{N}]+/u', $n, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $has = fn (string $re) => (bool) preg_match($re . 'u', $n);
    $intents = [];

    $abuse = '/\b(fuck\w*|f\*+k|shit|bitch|stupid|idiot|dumb|moron|bastard|asshole|dick|slut|whore|chutiya|chutiye|madarchod|bhenchod|behenchod|bhosdi\w*|gaand|gandu|harami|kamina|randi|mc|bc)\b|चूतिया|मादरचोद|भेनचोद|हरामी|कमीना/';
    if ($has($abuse)) {
        return ['abuse'];
    }
    $greet = '/^\s*(hi+|hello+|helo|hey+|hiya|namaste|namaskar|namskar|good\s(morning|afternoon|evening|day)|greetings|hola|नमस्ते|नमस्कार|हेलो|हाय)\b/';
    if ($has('/\b(ignore|disregard|forget)\s(all\s|the\s|your\s|any\s)?(previous|above|prior|earlier)?\s?(instructions|rules|prompt)|system\sprompt|your\s(instructions|prompt)|jailbreak|developer\smode|pretend\s(to\sbe|you\sare)|act\sas\s(a|an)\b/')) {
        return ['offtopic'];
    }
    if ($has($greet) && count($words) <= 4) {
        return ['greeting'];
    }
    if ($has('/\b(thanks?|thank\s?you|thanku|thnx|thx|tysm|dhanya[vw]a?a?d|shukriya|great\shelp)\b|धन्यवाद|शुक्रिया/') && count($words) <= 6) {
        return ['thanks'];
    }
    if ($has('/^\s*(ok\s)?(bye|goodbye|good\sbye|see\syou|alvida|tata)\b|अलविदा/') && count($words) <= 5) {
        return ['bye'];
    }

    $add = function (string $i) use (&$intents) {
        if (!in_array($i, $intents, true)) {
            $intents[] = $i;
        }
    };
    if (isset($t['counsellor']) || $has('/\b(call\s?me|call\s?back|ring\sme|contact\sme|reach\sme|talk\s(to|with)|speak\s(to|with)|connect\sme|real\sperson|human|agent|representative)\b|baat\s?(kar|karn|krn)|mujhe\s?call|call\skar|मुझे\sकॉल|बात\sकर|कॉल\sकर/')) {
        $add('counsellor');
    }
    if ($has('/\bwhatsapp\b|व्हाट्सएप|व्हाट्सऐप/')) {
        $add('whatsapp');
    }
    if (isset($t['scholarship'])) {
        $add('scholarship');
    }
    if (isset($t['fee']) || $has('/\b(how\smuch|kitni|kitna|kitne|lakhs?|lacs?|rupees|rs|inr|afford|instal+ments?|emi|loan|refund)\b|₹|कितनी|कितना|किस्त/')) {
        $add('fees');
    }
    if (isset($t['eligibility']) || $has('/\b(percent|percentage|marks|minimum|cut\s?off|am\si\seligible|can\si\s(get|take|apply|join)|qualif\w*)\b|%|अंक|प्रतिशत/')) {
        $add('eligibility');
    }
    if ($has('/\b(deadline|last\s?date|closing\s?date|kab\stak|till\swhen|due\sdate)\b|अंतिम\sतिथि|आखिरी\sतारीख/')
        || ($has('/\b(when|kab)\b|कब/') && (isset($t['admission']) || $has('/\b(open|start|begin|session)\b/')))) {
        $add('dates');
    }
    if (isset($t['document'])) {
        $add('documents');
    }
    if (isset($t['entrance'])) {
        $add('entrance');
    }
    if (isset($t['admission']) || $has('/\b(how\s(do|can|to)\si\s(apply|join|get\sadmission)|want\sto\s(join|apply|study)|admission\s(lena|leni|chahiye|karna|form))\b/')) {
        $add('admission');
    }
    if (isset($t['hostel'])) {
        $add('hostel');
    }
    if (isset($t['transport'])) {
        $add('transport');
    }
    if (isset($t['placement']) || isset($t['internship'])) {
        $add('placement');
    }
    if (isset($t['location']) || $has('/\b(where\sis|located|reach|visit\s(the\s)?campus|campus\s(visit|tour))\b/')) {
        $add('location');
    }
    if (!in_array('counsellor', $intents, true) && (isset($t['contact']) || $has('/\b(office\shours|timings?)\b/'))) {
        $add('contact');
    }
    if ($has('/\b(notices?|circulars?|announcements?|news|updates?)\b|सूचना/')) {
        $add('notices');
    }
    if ($has('/\b(events?|fest|festival|seminars?|workshops?|webinars?|hackathon|convocation)\b/')) {
        $add('events');
    }
    if ($has('/\b(departments?|faculty|teachers?|professors?|hod)\b|विभाग|शिक्षक/')) {
        $add('departments');
    }
    if ($has('/\b(affiliat\w*|approv\w*|aicte|ugc|naac|recogni[sz]ed|accredit\w*|valid\sdegree)\b|मान्यता/')) {
        $add('affiliation');
    }
    if (isset($t['campus']) && !in_array('location', $intents, true)) {
        $add('campus');
    }
    if (isset($t['program']) || $has('/\b(what|which)\b.{0,24}\b(offer|available|study)\b|\bkaun\s?(se|sa|si)\b|कौन\sसे|\b(after\s12th|after\sgraduation|best\scourse|options)\b/')) {
        $add('programs');
    }
    if (!$intents && chatbot_is_about_question($n)) {
        // "Tell me about GIMT", "What is GIMT?", "GIMT ke baare mein batao": the institute overview — only when nothing
        // more specific is asked ("Tell me about GIMT hostels" stays a hostel question)
        return ['about'];
    }
    if (!$intents && $has('/\b(weather|cricket|movie|songs?|jokes?|recipe|bitcoin|crypto|stocks?|politics|election|girlfriend|boyfriend|write\s(me\s)?(a|an)\s(code|program|essay|poem|story)|python\scode|javascript|homework|solve\s(this|the))\b/')) {
        return ['offtopic'];
    }
    return $intents;
}

/**
 * Does the (chatbot_normalize'd) text ask for an overview of the institute itself — "Tell me about GIMT", "What is
 * GIMT?", "About the institute", "When was GIMT established?", "GIMT ke baare mein batao", "कॉलेज के बारे में बताइए"?
 * Every other word must be filler, so "Tell me about GIMT hostels" or "… the GIMT cricket team" are not.
 */
function chatbot_is_about_question(string $n): bool
{
    static $res = null, $allowed = null;
    if ($res === null) {
        $f = chatbot_facts();
        $names = ['gimt', 'college', 'colleges', 'institute', 'institutes', 'institution', 'university', 'sansthan', 'sanstha', 'vidyalaya', 'mahavidyalaya',
            'जीआईएमटी', 'कॉलेज', 'कालेज', 'संस्थान', 'संस्था', 'इंस्टीट्यूट', 'इंस्टिट्यूट', 'विश्वविद्यालय', 'महाविद्यालय'];
        foreach (['short', 'name'] as $k) {
            $raw = chatbot_normalize((string) ($f[$k] ?? ''));
            foreach ([str_replace('&', ' and ', $raw), $raw] as $v) {
                $v = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $v));
                if (mb_strlen($v) >= 2) {
                    $names[] = $v;
                }
            }
        }
        $names = array_values(array_unique($names));
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // longest first ("global institute of …" before "institute")
        $subj = '(?<![\p{L}\p{M}\p{N}])(?:' . implode('|', array_map(fn ($s) => str_replace(' ', '\s+', preg_quote($s, '/')), $names)) . ')(?![\p{L}\p{M}\p{N}])';
        $pre = '(?:(?:the|your|ur|this|our|aapke|apke|aapka|apka|apne|apna|aap\s+ka|aap\s+ke)\s+)?';
        $about = '(?:tell\s+(?:me|us)\s+(?:something\s+|more\s+|a\s+bit\s+|a\s+little\s+|briefly\s+|all\s+)?about|(?:know|learn|hear|read)\s+(?:more\s+|something\s+|a\s+bit\s+)?about'
            . '|(?:some\s+|more\s+|brief\s+|basic\s+|general\s+|full\s+)?(?:info|information|details?|overview|introduction|intro|profile|summary|background)\s+(?:about|on|of|regarding)'
            . '|describe|introduce|explain|about)';
        $res = [
            '/(?:^|\s)' . $about . '\s+' . $pre . $subj . '/u',                                                   // tell me about GIMT, about the institute
            '/^(?:what|who)\s+(?:is|are)\s+' . $pre . $subj . '(?:\s+all\s+about)?$/u',                            // what is GIMT
            '/^(?:when|in\s+which\s+year|which\s+year)\s+(?:was|is|did)\s+' . $pre . $subj . '\s+(?:established|founded|started|set\s+up|establish|found)\b/u',
            '/' . $subj . '\s+(?:ke|ki|k|ka)\s+(?:baare|bare|baarey|barey|bary|baray|vishay|vishey|bareme|baremein|baaremein)(?![\p{L}\p{M}])/u', // GIMT ke baare mein
            '/' . $subj . '\s+(?:kya|kia|kyaa)\s+(?:hai|h|he|hain|hy)(?![\p{L}\p{M}])/u',                          // GIMT kya hai
            '/' . $subj . '\s+(?:kab|kb)\s+(?:bana|bani|bna|bni|shuru|start|established|sthapit)(?![\p{L}\p{M}])/u',
            '/' . $subj . '\s*(?:के|की)\s*(?:बारे|विषय)/u',                                                       // कॉलेज के बारे में
            '/' . $subj . '\s*(?:क्या|कया)\s*(?:है|हैं)/u',                                                        // जीआईएमटी क्या है
            '/' . $subj . '\s*(?:की\s*)?स्थापना/u',
        ];
        $words = 'about overview introduction intro introduce describe explain something little bit brief briefly short quick general overall all everything learn hear read '
            . 'basic basics background profile summary us ur share lot full complete established establish establishment founded found founding started start set up year when which '
            . 'baare bare baarey barey bary baray vishay vishey bareme baremein baaremein jankari jaankari jaankaari janna jaanna chahta chahti chahte chahunga chahenge dijiye dijie '
            . 'dena dein de do kuch thoda thodi zara pls plz apne apna apni hamein humein hume hamko humko sab sabhi puri poori mai main mei btao bta batana batado batayein bataen '
            . 'sthapit sthapna bana bani bna bni shuru hua hui kab kb '
            . 'बारे विषय जानकारी दीजिए दीजिये दें दो कुछ थोड़ा थोड़ी संक्षेप अपने अपना अपनी हमें बताएँ बताएं बताये बताइये बताना जानना चाहता चाहती चाहते कृपया सर जी पूरी सारी स्थापना स्थापित कब हुई हुआ साल';
        $allowed = array_flip(array_merge(explode(' ', $words), chatbot_tokens($words . ' ' . implode(' ', $names) . ' ' . ($f['city'] ?? ''))));
    }
    $s = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $n));
    if ($s === '' || mb_strlen($s) > 160) {
        return false;
    }
    $hit = false;
    foreach ($res as $re) {
        if (preg_match($re, $s)) {
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        return false;
    }
    foreach (chatbot_tokens($s) as $tok) {
        if (!isset($allowed[$tok])) {
            return false;
        }
    }
    return true;
}

/** Programs a question names once the institute's own name is taken out ("Global Institute of Management & Technology" is no program). */
function chatbot_programs_outside_name(string $question): array
{
    $q = chatbot_normalize($question);
    $name = chatbot_normalize((string) (chatbot_facts()['name'] ?? ''));
    if (trim($name) !== '') {
        $q = str_replace([$name, str_replace('&', 'and', $name), str_replace(' & ', ' ', $name)], ' ', $q);
    }
    return chatbot_detect_programs($q);
}

/** Programs per level for the overview: ['count' => 41, 'text' => '6 undergraduate, 3 postgraduate, …'] (website programs only). */
function chatbot_program_levels(string $lang = 'en'): array
{
    $labels = ['UG' => ['undergraduate', 'स्नातक (UG)', 'undergraduate'], 'PG' => ['postgraduate', 'स्नातकोत्तर (PG)', 'postgraduate'], 'Diploma' => ['diploma', 'डिप्लोमा', 'diploma'],
        'Certificate' => ['certificate', 'सर्टिफ़िकेट', 'certificate'], 'PhD' => ['doctoral', 'डॉक्टरेट', 'doctoral']];
    $idx = ['en' => 0, 'hi' => 1, 'hinglish' => 2][$lang] ?? 0;
    $count = [];
    foreach (chatbot_programs_data() as $p) {
        $count[(string) $p['level']] = ($count[(string) $p['level']] ?? 0) + 1;
    }
    $parts = [];
    foreach (array_unique(array_merge(array_keys($labels), array_keys($count))) as $level) {
        if (!empty($count[$level])) {
            $parts[] = $count[$level] . ' ' . ($labels[$level][$idx] ?? mb_strtolower(chatbot_level_label($level)));
        }
    }
    return ['count' => array_sum($count), 'text' => implode(', ', $parts)];
}

/**
 * Institute overview ("about" intent) built only from settings — name, year of establishment, affiliation / approvals,
 * address — and the live program catalogue (count per level). A fact whose setting is empty is left out, never guessed.
 */
function chatbot_about_answer(string $lang): string
{
    $f = chatbot_facts();
    $short = (string) ($f['short'] ?? 'GIMT');
    $name = (string) ($f['name'] ?? $short);
    $title = '**' . $name . '**' . ($short !== '' && mb_stripos($name, $short) === false ? ' (' . $short . ')' : '');
    $lines = [!empty($f['established']) ? chatbot_t('about_est', $lang, ['title' => $title, 'year' => $f['established']]) : chatbot_t('about_name', $lang, ['title' => $title])];
    if (!empty($f['affiliation'])) {
        $lines[] = '- 🎓 ' . chatbot_t('about_affiliation', $lang, ['text' => $f['affiliation']]);
    }
    if (!empty($f['address']) || !empty($f['city'])) {
        $lines[] = '- 📍 ' . ($f['address'] ?? $f['city']);
    }
    $levels = chatbot_program_levels($lang);
    if ($levels['count'] > 0) {
        $lines[] = '- 📚 ' . chatbot_t('about_programs', $lang, ['count' => $levels['count'], 'levels' => $levels['text']]);
    }
    $links = array_filter([
        ($u = chatbot_route('/about')) ? chatbot_md_link(chatbot_t('about_link', $lang, ['short' => $short]), $u) : null,
        ($u = chatbot_route('/programs')) ? chatbot_md_link(chatbot_t('about_programs_link', $lang), $u) : null,
    ]);
    if ($links) {
        $lines[] = chatbot_t('more', $lang, ['link' => implode(' · ', $links)]);
    }
    $lines[] = chatbot_t('about_ask', $lang);
    return implode("\n", $lines);
}

/** Localised template: [en, hi, hinglish]. */
function chatbot_t(string $key, string $lang, array $vars = []): string
{
    static $p = null;
    $p ??= [
        'greet' => ["Hello! 👋 I'm the **{bot}**. I can help you with programs, fees, scholarships, eligibility, admissions, hostel, transport and placements at {short}. What would you like to know?",
            "नमस्ते! 👋 मैं **{bot}** हूँ। मैं {short} के कोर्स, फीस, स्कॉलरशिप, योग्यता, एडमिशन, हॉस्टल, ट्रांसपोर्ट और प्लेसमेंट के बारे में आपकी मदद कर सकता हूँ। आप क्या जानना चाहेंगे?",
            "Namaste! 👋 Main **{bot}** hoon. Main {short} ke courses, fees, scholarships, eligibility, admission, hostel, transport aur placements ke baare mein help kar sakta hoon. Aap kya jaanna chahenge?"],
        'thanks' => ["You're welcome! 😊 Is there anything else I can help you with?", 'आपका स्वागत है! 😊 क्या मैं और कुछ मदद कर सकता हूँ?', 'Aapka swagat hai! 😊 Aur kuch help chahiye?'],
        'bye' => ['Thank you for chatting with {short}! All the best for your admission. 🎓', '{short} से बात करने के लिए धन्यवाद! आपके एडमिशन के लिए शुभकामनाएँ। 🎓', '{short} se baat karne ke liye dhanyavaad! Admission ke liye all the best. 🎓'],
        'abuse' => ["Let's keep our chat respectful. 🙏 I'm here to help with admissions at {short} — ask me about programs, fees, scholarships or the admission process.",
            'कृपया बातचीत सम्मानजनक रखें। 🙏 मैं {short} में एडमिशन से जुड़ी मदद के लिए यहाँ हूँ — कोर्स, फीस, स्कॉलरशिप या एडमिशन प्रक्रिया के बारे में पूछें।',
            'Please baat respectful rakhein. 🙏 Main {short} admissions mein help ke liye hoon — courses, fees, scholarships ya admission process ke baare mein poochiye.'],
        'offtopic' => ["I can only help with questions about {short} — programs, admissions, fees, scholarships, campus life and placements. What would you like to know about studying here?",
            'मैं केवल {short} से जुड़े सवालों में मदद कर सकता हूँ — कोर्स, एडमिशन, फीस, स्कॉलरशिप, कैंपस और प्लेसमेंट। आप क्या जानना चाहेंगे?',
            'Main sirf {short} se jude sawaalon mein help kar sakta hoon — courses, admission, fees, scholarships, campus aur placements. Aap kya jaanna chahenge?'],
        'unknown' => ["I'm not sure about that — let me connect you with our admissions team. Share your name and phone number and a counsellor will call you back, or ask me about programs, fees, scholarships or admissions.",
            'मुझे इसके बारे में पक्की जानकारी नहीं है — मैं आपको हमारी एडमिशन टीम से जोड़ देता हूँ। अपना नाम और फ़ोन नंबर दें, काउंसलर आपको कॉल करेंगे।',
            'Mujhe iske baare mein pakka nahi pata — main aapko hamari admissions team se connect kar deta hoon. Apna naam aur phone number share kijiye, counsellor aapko call karenge.'],
        'programs' => ['{short} offers **{count} programs** for the {session} session:', '{short} में {session} सत्र के लिए **{count} प्रोग्राम** उपलब्ध हैं:', '{short} mein {session} session ke liye **{count} programs** available hain:'],
        'not_offered' => ['We don\'t currently offer a **{degree}** program.', 'फ़िलहाल हमारे यहाँ **{degree}** प्रोग्राम उपलब्ध नहीं है।', 'Abhi hamare yahan **{degree}** program available nahi hai.'],
        'no_match' => ['I couldn\'t find a program matching **{what}** in our current list — an admission counsellor can confirm. Here\'s what we offer:', 'मुझे **{what}** से जुड़ा कोई प्रोग्राम हमारी सूची में नहीं मिला — काउंसलर इसकी पुष्टि कर सकते हैं। हमारे प्रोग्राम:', 'Mujhe **{what}** se juda koi program hamari list mein nahi mila — counsellor confirm kar sakte hain. Hamare programs:'],
        'programs_more' => ["Tell me which one interests you and I'll share the fees, eligibility and seats.", 'जिस प्रोग्राम में आपकी रुचि है, उसका नाम बताइए — मैं फीस, योग्यता और सीटों की जानकारी दूँगा।', "Jis program mein interest hai uska naam bataiye — main fees, eligibility aur seats bata dunga."],
        'program_one' => ['Here are the details of **{name}**:', '**{name}** की जानकारी:', '**{name}** ki details:'],
        'fees_one' => ['The fee for **{name}** is **{fee}**.', '**{name}** की फीस **{fee}** है।', '**{name}** ki fees **{fee}** hai.'],
        'fees_all' => ['Program fees for {session}:', '{session} के लिए प्रोग्राम फीस:', '{session} ke liye program fees:'],
        'fees_note' => ['The admissions office confirms the final fee structure (including any one-time charges).', 'अंतिम फीस संरचना (एक-बार के शुल्क सहित) एडमिशन ऑफिस द्वारा कन्फ़र्म की जाती है।', 'Final fee structure (one-time charges sahit) admissions office confirm karta hai.'],
        'scholarships' => ['{short} offers these scholarships and fee concessions:', '{short} में ये स्कॉलरशिप और फीस छूट उपलब्ध हैं:', '{short} mein ye scholarships aur fee concessions available hain:'],
        'elig_one' => ['Eligibility for **{name}**: {text}', '**{name}** के लिए योग्यता: {text}', '**{name}** ke liye eligibility: {text}'],
        'elig_all' => ['Eligibility at a glance:', 'योग्यता संक्षेप में:', 'Eligibility ek nazar mein:'],
        'dates' => ['{open}The last date to apply is **{deadline}**.', '{open}आवेदन की अंतिम तिथि **{deadline}** है।', '{open}Apply karne ki last date **{deadline}** hai.'],
        'apply_cta' => ['You can [apply online]({apply}) — or I can arrange a call with an admission counsellor.', 'आप [ऑनलाइन आवेदन]({apply}) कर सकते हैं — या मैं काउंसलर से कॉल करवा सकता हूँ।', 'Aap [online apply]({apply}) kar sakte hain — ya main counsellor se call arrange kar sakta hoon.'],
        'hostel' => ['Hostel accommodation is available on campus:', 'कैंपस में हॉस्टल सुविधा उपलब्ध है:', 'Campus mein hostel facility available hai:'],
        'transport' => ['College transport:', 'कॉलेज ट्रांसपोर्ट:', 'College transport:'],
        'placement' => ['Placements at {short}', '{short} में प्लेसमेंट', '{short} mein placements'],
        'location' => ['📍 **{name}**, {address}', '📍 **{name}**, {address}', '📍 **{name}**, {address}'],
        'contact' => ['You can reach the admissions team here:', 'एडमिशन टीम से यहाँ संपर्क करें:', 'Admissions team se yahan contact karein:'],
        'counsellor' => ['Sure! 😊 Share your name and phone number in the form below and an admission counsellor will call you back.', 'ज़रूर! 😊 नीचे फ़ॉर्म में अपना नाम और फ़ोन नंबर दें — एडमिशन काउंसलर आपको कॉल करेंगे।', 'Zaroor! 😊 Neeche form mein apna naam aur phone number dijiye — admission counsellor aapko call karenge.'],
        'whatsapp' => ['You can chat with our admissions team on WhatsApp: [Open WhatsApp]({wa})', 'आप व्हाट्सएप पर हमारी एडमिशन टीम से बात कर सकते हैं: [WhatsApp खोलें]({wa})', 'Aap WhatsApp par hamari admissions team se baat kar sakte hain: [WhatsApp kholiye]({wa})'],
        'notices' => ['Latest notices:', 'ताज़ा सूचनाएँ:', 'Latest notices:'],
        'events' => ['Upcoming events:', 'आगामी कार्यक्रम:', 'Upcoming events:'],
        'departments' => ['Our departments:', 'हमारे विभाग:', 'Hamare departments:'],
        'more' => ['More: {link}', 'अधिक जानकारी: {link}', 'Aur jaankari: {link}'],
        'about_est' => ['{title} was established in **{year}**.', '{title} की स्थापना **{year}** में हुई थी।', '{title} ki sthapna **{year}** mein hui thi.'],
        'about_name' => ['{title}', '{title}', '{title}'],
        'about_affiliation' => ['{text}', 'मान्यता: {text}', '{text}'],
        'about_programs' => ['**{count} programs**: {levels}', '**{count} प्रोग्राम**: {levels}', '**{count} programs**: {levels}'],
        'about_link' => ['About {short}', '{short} के बारे में', '{short} ke baare mein'],
        'about_programs_link' => ['All programs', 'सभी प्रोग्राम', 'Sabhi programs'],
        'about_ask' => ['Ask me about programs, fees, eligibility, scholarships or the admission process.', 'आप कोर्स, फीस, योग्यता, स्कॉलरशिप या एडमिशन प्रक्रिया के बारे में पूछ सकते हैं।',
            'Aap courses, fees, eligibility, scholarships ya admission process ke baare mein pooch sakte hain.'],
    ];
    $idx = ['en' => 0, 'hi' => 1, 'hinglish' => 2][$lang] ?? 0;
    $s = $p[$key][$idx] ?? ($p[$key][0] ?? $key);
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

function chatbot_md_link(string $label, ?string $url): string
{
    return $url ? '[' . str_replace(['[', ']'], '', $label) . '](' . $url . ')' : $label;
}

/**
 * Rule-based answer.
 * @param array $ctx intents, programs, lang, snippets (retrieved), history_programs
 * @return array{reply: string, sources: array, lead_prompt: bool, intent: string}
 */
function chatbot_offline_answer(string $question, array $ctx): array
{
    $intents = $ctx['intents'] ?? chatbot_detect_intents($question);
    $programs = $ctx['programs'] ?? [];
    $lang = $ctx['lang'] ?? 'en';
    $snippets = $ctx['snippets'] ?? chatbot_retrieve($question, $intents, $programs);
    $f = chatbot_facts();
    $bot = (string) setting('chatbot_bot_name', 'GIMT Admission Assistant');
    $base = ['bot' => $bot, 'short' => $f['short'] ?? 'GIMT', 'session' => $f['session'] ?? date('Y') . '-' . substr((string) (date('Y') + 1), 2)];
    $all = chatbot_snippets();
    $byId = [];
    foreach ($all as $s) {
        $byId[$s['id']] = $s;
    }
    $sources = [];
    $useSource = function (?array $s) use (&$sources) {
        if ($s && !empty($s['url'])) {
            $sources[$s['url']] = ['title' => $s['title'], 'url' => $s['url']];
        }
    };
    $lead = false;
    $simple = ['abuse' => 'abuse', 'greeting' => 'greet', 'thanks' => 'thanks', 'bye' => 'bye', 'offtopic' => 'offtopic'];
    if (isset($intents[0], $simple[$intents[0]])) {
        return ['reply' => chatbot_t($simple[$intents[0]], $lang, $base), 'sources' => [], 'lead_prompt' => false, 'intent' => $intents[0]];
    }
    $allPrograms = chatbot_programs_data();
    $sections = [];
    $topical = array_values(array_diff($intents, ['programs']));
    if (!$programs && in_array('fees', $topical, true) && array_intersect($topical, ['hostel', 'transport'])) {
        $topical = array_values(array_diff($topical, ['fees'])); // "hostel fees" = the hostel answer (it lists the charges)
    }
    if ($programs && in_array('about', $topical, true)) {
        $programs = chatbot_programs_outside_name($question); // "… Institute of Management & Technology" names no program
    }
    if ($programs) {
        $topical = array_values(array_diff($topical, ['about'])); // a named program wins over the institute overview
    }
    if (!$topical && $programs) {
        $topical = ['program_detail'];
    } elseif (!$topical && in_array('programs', $intents, true)) {
        $topical = ['programs'];
    }

    foreach (array_slice($topical, 0, 2) as $intent) {
        $lines = [];
        switch ($intent) {
            case 'about':
                $lines[] = chatbot_about_answer($lang);
                if ($u = chatbot_route('/about')) {
                    $sources['/about'] = ['title' => 'About ' . $base['short'], 'url' => $u];
                }
                $useSource($byId['facts:affiliation'] ?? null);
                break;
            case 'program_detail':
                foreach (array_slice($programs, 0, 2) as $p) {
                    $lines[] = chatbot_t('program_one', $lang, ['name' => $p['name'] . ' (' . $p['short_name'] . ')']);
                    $facts = ['Level' => chatbot_level_label($p['level']), 'Duration' => $p['duration_label'], 'Seats' => (int) $p['intake_capacity'] ?: null,
                        'Fee' => chatbot_fee_label($p) ?: null, 'Eligibility' => $p['eligibility'] ? chatbot_plain($p['eligibility'], 260) : null,
                        'Specialisations' => $p['courses'] ? implode(', ', $p['courses']) : null, 'Department' => $p['department_name']];
                    foreach ($facts as $k => $v) {
                        if ($v) {
                            $lines[] = '- **' . $k . ':** ' . $v;
                        }
                    }
                    $links = array_filter([$p['url'] ? chatbot_md_link('View program', $p['url']) : null, $p['apply_url'] ? chatbot_md_link('Apply for ' . $p['short_name'], $p['apply_url']) : null]);
                    if ($links) {
                        $lines[] = implode(' · ', $links);
                    }
                    $useSource($byId['program:' . $p['id']] ?? null);
                }
                break;
            case 'programs':
                $groups = [];
                foreach ($allPrograms as $p) {
                    $groups[chatbot_level_label($p['level'])][] = chatbot_md_link($p['short_name'], $p['url']) . ($p['duration_label'] ? ' (' . $p['duration_label'] . ')' : '');
                }
                if ($groups) {
                    $offered = ' ' . implode(' ', array_map(fn ($p) => chatbot_normalize($p['short_name'] . ' ' . $p['name']), $allPrograms)) . ' ';
                    if (preg_match('/\b(phd|mtech|msc|m\.?a|b\.?a|bed|b\.ed|llb|mbbs|bpharm|b\.?pharm|bdes|barch|polytechnic|bba\sllb)\b/u', chatbot_normalize($question), $dm) && !str_contains($offered, ' ' . $dm[1] . ' ')) {
                        $pretty = ['phd' => 'PhD', 'mtech' => 'M.Tech', 'msc' => 'M.Sc', 'ma' => 'MA', 'ba' => 'BA', 'bed' => 'B.Ed', 'llb' => 'LLB', 'mbbs' => 'MBBS', 'bpharm' => 'B.Pharm', 'bdes' => 'B.Des', 'barch' => 'B.Arch', 'polytechnic' => 'polytechnic', 'bba llb' => 'BBA LLB'];
                        $key = str_replace('.', '', $dm[1]);
                        $lines[] = chatbot_t('not_offered', $lang, ['degree' => $pretty[$key] ?? strtoupper($key)]);
                        $lead = true;
                    } else {
                        // a specific subject that matches no program ("pilot training") → say so and offer a counsellor
                        $generic = array_flip(['program', 'programme', 'course', 'offer', 'offered', 'offering', 'available', 'provide', 'list', 'all', 'best', 'good', 'option', 'options', 'study', 'studies', 'after',
                            '12th', 'graduation', 'kind', 'type', 'types', 'student', 'student', 'join', 'there', 'other', 'which', 'what', 'new', 'top', 'popular', 'admission', 'degree', 'here', 'college',
                            'stream', 'branch', 'subject', 'subjects', 'full', 'time', 'part', 'online', 'regular', 'session', 'year', 'years', 'now', 'currently', 'kaun', 'kon', 'konsa', 'konse', 'kaunse', 'kaunsa']);
                        $extra = array_values(array_filter(chatbot_words($question), fn ($w) => !isset($generic[$w]) && !preg_match('/^\d+$/', $w) && mb_strlen($w) > 2));
                        if ($extra) {
                            $lines[] = chatbot_t('no_match', $lang, ['what' => implode(' ', array_slice($extra, 0, 3))]);
                            $lead = true;
                        }
                    }
                    $lines[] = chatbot_t('programs', $lang, $base + ['count' => count($allPrograms)]);
                    foreach ($groups as $level => $items) {
                        $lines[] = '- **' . $level . ':** ' . implode(', ', $items);
                    }
                    $lines[] = chatbot_t('programs_more', $lang, $base);
                    $sources['/programs'] = ['title' => 'Academic programs', 'url' => chatbot_route('/programs')];
                }
                break;
            case 'fees':
                $payQ = (bool) preg_match('/instal|loan|refund|\bemi\b|\bpay|kist|किस्त|लोन/iu', $question);
                if ($payQ && !$programs) {
                    $faq = chatbot_best_faq($question, array_filter($all, fn ($x) => $x['type'] === 'faq'));
                    if ($faq && $faq['confidence'] >= 0.4) {
                        $lines[] = $faq['snippet']['text'];
                        $useSource($faq['snippet']);
                        $lines[] = chatbot_t('more', $lang, ['link' => chatbot_md_link('Fees & scholarships', chatbot_route('/admissions#fees'))]);
                        break;
                    }
                }
                if ($programs) {
                    foreach ($programs as $p) {
                        if ($fee = chatbot_fee_label($p)) {
                            $lines[] = chatbot_t('fees_one', $lang, ['name' => $p['name'] . ' (' . $p['short_name'] . ')', 'fee' => $fee]);
                            $useSource($byId['program:' . $p['id']] ?? null);
                        }
                    }
                }
                if (!$lines) {
                    $rows = array_filter($allPrograms, fn ($p) => chatbot_fee_label($p) !== '');
                    if ($rows) {
                        $lines[] = chatbot_t('fees_all', $lang, $base);
                        foreach ($rows as $p) {
                            $lines[] = '- ' . chatbot_md_link($p['short_name'], $p['url']) . ': **' . chatbot_fee_label($p) . '**';
                        }
                        $useSource($byId['programs:fees'] ?? null);
                    }
                }
                $faq = chatbot_best_faq($question, $snippets);
                if ($faq && $faq['confidence'] >= 0.5 && $payQ && preg_match('/instal|loan|refund|emi|pay/i', $faq['snippet']['title'])) {
                    $lines[] = $faq['snippet']['text'];
                    $useSource($faq['snippet']);
                }
                if ($lines) {
                    $lines[] = chatbot_t('fees_note', $lang) . ' ' . chatbot_md_link('Fees & scholarships', chatbot_route('/admissions#fees'));
                }
                break;
            case 'scholarship':
                $s = $byId['scholarships:all'] ?? null;
                if ($s) {
                    $lines[] = chatbot_t('scholarships', $lang, $base);
                    foreach (explode("\n", $s['text']) as $row) {
                        [$name, $rest] = array_pad(explode(': ', $row, 2), 2, '');
                        $lines[] = '- **' . $name . '** — ' . $rest;
                    }
                    $useSource($s);
                }
                break;
            case 'eligibility':
                if ($programs) {
                    foreach ($programs as $p) {
                        if ($p['eligibility']) {
                            $lines[] = chatbot_t('elig_one', $lang, ['name' => $p['short_name'], 'text' => chatbot_plain($p['eligibility'], 400)]);
                            $useSource($byId['program:' . $p['id']] ?? null);
                        }
                    }
                } else {
                    $byText = [];
                    foreach ($allPrograms as $p) {
                        if ($p['eligibility']) {
                            $byText[chatbot_plain($p['eligibility'], 300)][] = $p['short_name'];
                        }
                    }
                    if ($byText) {
                        $lines[] = chatbot_t('elig_all', $lang);
                        foreach ($byText as $text => $names) {
                            $lines[] = '- **' . implode(', ', $names) . ':** ' . $text;
                        }
                        $sources['/admissions#eligibility'] = ['title' => 'Eligibility & documents', 'url' => chatbot_route('/admissions#eligibility')];
                    }
                }
                break;
            case 'dates':
                if (!empty($f['deadline'])) {
                    $lines[] = chatbot_t('dates', $lang, ['open' => !empty($f['admissions_open_text']) ? $f['admissions_open_text'] . '. ' : '', 'deadline' => $f['deadline']]);
                    $useSource($byId['facts:dates'] ?? null);
                } else {
                    $faq = chatbot_best_faq($question, $snippets);
                    if ($faq && $faq['confidence'] >= 0.3) {
                        $lines[] = $faq['snippet']['text'];
                        $useSource($faq['snippet']);
                    }
                }
                break;
            case 'admission':
            case 'documents':
            case 'entrance':
            case 'campus':
            case 'affiliation':
                $faq = chatbot_best_faq($question . ($intent === 'admission' ? ' how apply' : ''), $snippets ?: $all);
                if ($faq && $faq['confidence'] >= 0.25) {
                    $lines[] = $faq['snippet']['text'];
                    $useSource($faq['snippet']);
                } elseif ($intent === 'affiliation' && isset($byId['facts:affiliation'])) {
                    $lines[] = $byId['facts:affiliation']['text'];
                    $useSource($byId['facts:affiliation']);
                }
                if ($intent === 'admission') {
                    if (!empty($f['deadline']) && !in_array('dates', $topical, true)) {
                        $lines[] = chatbot_t('dates', $lang, ['open' => '', 'deadline' => $f['deadline']]);
                    }
                    if ($apply = chatbot_route('/apply')) {
                        $lines[] = chatbot_t('apply_cta', $lang, ['apply' => $apply]);
                    }
                    $lead = true;
                } elseif ($intent === 'documents' && ($u = chatbot_route('/admissions#documents'))) {
                    $lines[] = chatbot_t('more', $lang, ['link' => chatbot_md_link('Documents required', $u)]);
                }
                break;
            case 'hostel':
                if ($s = $byId['hostels:all'] ?? null) {
                    $lines[] = chatbot_t('hostel', $lang);
                    foreach (explode("\n", $s['text']) as $row) {
                        $lines[] = '- ' . preg_replace('/^([^(:]+?)\s*(?=\(|:)/u', '**$1** ', $row, 1);
                    }
                    $useSource($s);
                }
                $faq = chatbot_best_faq('hostel rooms allotted ' . $question, array_filter($all, fn ($x) => $x['type'] === 'faq' && str_contains(strtolower($x['title']), 'hostel')));
                if ($faq && $faq['confidence'] >= 0.3) {
                    $lines[] = $faq['snippet']['text'];
                }
                break;
            case 'transport':
                if ($s = $byId['transport:all'] ?? null) {
                    $lines[] = chatbot_t('transport', $lang) . ' ' . $s['text'];
                    $useSource($s);
                }
                break;
            case 'placement':
                if ($s = $byId['placements:stats'] ?? null) {
                    $st = $s['stats'] ?? null;
                    if ($st) {
                        $lines[] = chatbot_t('placement', $lang, $base) . ' (' . $st['year'] . ' season):';
                        $lines[] = '- **' . $st['offers'] . '** job offers from **' . $st['companies'] . '** recruiters · **' . $st['placed'] . '** students placed';
                        $lines[] = '- Highest package **' . $st['max'] . ' LPA**' . ($st['avg'] ? ' · average **' . $st['avg'] . ' LPA**' : '');
                        if ($st['top']) {
                            $lines[] = '- Top recruiters: ' . implode(', ', $st['top']);
                        }
                    } else {
                        $lines[] = chatbot_t('placement', $lang, $base) . ': ' . $s['text'];
                    }
                    $useSource($s);
                }
                $faq = chatbot_best_faq($question, array_filter($all, fn ($x) => $x['type'] === 'faq'));
                if ($faq && $faq['confidence'] >= 0.5 && (!$lines || isset(array_flip(chatbot_tokens($question))['internship']))) {
                    $lines[] = $faq['snippet']['text'];
                    $useSource($faq['snippet']);
                }
                break;
            case 'location':
            case 'contact':
            case 'counsellor':
                if ($intent === 'counsellor') {
                    $lines[] = chatbot_t('counsellor', $lang);
                    $lead = true;
                }
                if ($intent === 'location' && !empty($f['address'])) {
                    $lines[] = chatbot_t('location', $lang, ['name' => $f['name'], 'address' => $f['address']]) . ' · '
                        . chatbot_md_link('Open in Google Maps', 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($f['name'] . ', ' . $f['address']));
                }
                if ($intent !== 'location') {
                    $lines[] = chatbot_t('contact', $lang);
                }
                foreach (['phone' => '📞', 'admission_email' => '✉️', 'office_hours' => '🕘'] as $k => $ic) {
                    if (!empty($f[$k])) {
                        $lines[] = '- ' . $ic . ' ' . $f[$k];
                    }
                }
                if (($wa = chatbot_wa_link()) && $intent !== 'location') {
                    $lines[] = '- ' . chatbot_md_link('WhatsApp us', $wa);
                }
                if ($intent === 'location') {
                    $faq = chatbot_best_faq('visit campus before applying', array_filter($all, fn ($x) => $x['type'] === 'faq'));
                    if ($faq && $faq['confidence'] >= 0.5) {
                        $lines[] = $faq['snippet']['text'];
                    }
                }
                $useSource($byId['facts:contact'] ?? null);
                break;
            case 'whatsapp':
                if ($wa = chatbot_wa_link()) {
                    $lines[] = chatbot_t('whatsapp', $lang, ['wa' => $wa]);
                }
                break;
            case 'notices':
            case 'events':
                $s = $byId[$intent === 'notices' ? 'notices:latest' : 'events:upcoming'] ?? null;
                if ($s) {
                    $lines[] = chatbot_t($intent, $lang);
                    $items = explode('; ', preg_replace('/^[^:]+:\s*/', '', rtrim($s['text'], '.')));
                    foreach (array_slice($items, 0, 5) as $it) {
                        $lines[] = '- ' . $it;
                    }
                    $useSource($s);
                }
                break;
            case 'departments':
                if ($s = $byId['departments:all'] ?? null) {
                    $lines[] = chatbot_t('departments', $lang);
                    foreach (preg_split('/(?<=\.)\s+(?=Department|School|Centre|Center)/u', $s['text']) as $d) {
                        $lines[] = '- ' . preg_replace('/^([^:]+):/u', '**$1:**', trim($d), 1);
                    }
                    $useSource($s);
                }
                break;
        }
        if ($lines) {
            $sections[] = implode("\n", $lines);
        }
    }

    if (!$sections) {
        // generic: best FAQ / knowledge snippet, else hand over to a counsellor
        $faq = chatbot_best_faq($question, $snippets ?: chatbot_retrieve($question));
        if ($faq && $faq['confidence'] >= 0.45) {
            $sections[] = $faq['snippet']['text'];
            $useSource($faq['snippet']);
        } else {
            return ['reply' => chatbot_t('unknown', $lang, $base), 'sources' => [], 'lead_prompt' => true, 'intent' => $intents[0] ?? 'unknown'];
        }
    }
    return ['reply' => implode("\n\n", $sections), 'sources' => array_values(array_slice($sources, 0, 3)), 'lead_prompt' => $lead, 'intent' => $topical[0] ?? ($intents[0] ?? 'faq')];
}

/**
 * Follow-up chips for an answer. Each: {label, message?} | {label, action: 'lead'|'whatsapp'} | {label, action: 'link', url}.
 */
function chatbot_suggestions(array $intents, array $programs, bool $lead = false): array
{
    $p = $programs[0] ?? null;
    $short = $p['short_name'] ?? null;
    $s = [];
    $msg = fn (string $label, ?string $message = null) => ['label' => $label, 'message' => $message ?? $label];
    $main = $intents[0] ?? '';
    if ($p && in_array($main, ['', 'programs', 'program_detail', 'fees', 'eligibility'], true)) {
        if ($main !== 'fees') {
            $s[] = $msg($short . ' fees', 'What is the fee for ' . $short . '?');
        }
        if ($main !== 'eligibility') {
            $s[] = $msg($short . ' eligibility', 'What is the eligibility for ' . $short . '?');
        }
        $s[] = $msg('Scholarships', 'Which scholarships are available?');
        if (!empty($p['apply_url'])) {
            $s[] = ['label' => 'Apply for ' . $short, 'action' => 'link', 'url' => $p['apply_url']];
        }
    } else {
        $map = [
            'programs' => [$msg('Fees', 'What are the fees?'), $msg('Eligibility', 'What is the eligibility?'), $msg('Scholarships', 'Which scholarships are available?')],
            'fees' => [$msg('Scholarships', 'Which scholarships are available?'), $msg('Hostel fees', 'What are the hostel fees?'), $msg('Admission process', 'How do I apply?')],
            'scholarship' => [$msg('Fees', 'What are the fees?'), $msg('Eligibility', 'What is the eligibility?'), $msg('Admission process', 'How do I apply?')],
            'eligibility' => [$msg('Admission process', 'How do I apply?'), $msg('Documents required', 'Which documents are required?'), $msg('Fees', 'What are the fees?')],
            'admission' => [$msg('Documents required', 'Which documents are required?'), $msg('Last date to apply', 'What is the last date to apply?'), ['label' => 'Apply now', 'action' => 'link', 'url' => chatbot_route('/apply')]],
            'dates' => [$msg('Admission process', 'How do I apply?'), ['label' => 'Apply now', 'action' => 'link', 'url' => chatbot_route('/apply')]],
            'documents' => [$msg('Last date to apply', 'What is the last date to apply?'), $msg('Eligibility', 'What is the eligibility?')],
            'hostel' => [$msg('Transport', 'Do you provide transport?'), $msg('Campus facilities', 'What facilities are available on campus?')],
            'transport' => [$msg('Hostel', 'Is hostel accommodation available?'), $msg('Campus location', 'Where is the campus?')],
            'placement' => [$msg('Programs', 'Which programs do you offer?'), $msg('Internships', 'Are internships compulsory?')],
            'location' => [$msg('Campus visit', 'Can I visit the campus before applying?'), $msg('Transport', 'Do you provide transport?')],
            'greeting' => [$msg('Programs & courses', 'Which programs do you offer?'), $msg('Fees & scholarships', 'What are the fees and scholarships?'), $msg('Admission process', 'How do I apply?')],
            'about' => [$msg('Programs & courses', 'Which programs do you offer?'), $msg('Fees & scholarships', 'What are the fees and scholarships?'), $msg('Admission process', 'How do I apply?')],
            'unknown' => [$msg('Programs & courses', 'Which programs do you offer?'), $msg('Fees & scholarships', 'What are the fees and scholarships?')],
            'offtopic' => [$msg('Programs & courses', 'Which programs do you offer?'), $msg('Admission process', 'How do I apply?')],
        ];
        $s = $map[$main] ?? [$msg('Programs & courses', 'Which programs do you offer?'), $msg('Fees & scholarships', 'What are the fees and scholarships?')];
    }
    $s = array_values(array_filter($s, fn ($x) => !isset($x['action']) || $x['action'] !== 'link' || !empty($x['url'])));
    if (!in_array($main, ['counsellor', 'thanks', 'bye', 'abuse'], true) && setting('chatbot_lead_capture', '1') === '1') {
        $s[] = ['label' => 'Talk to a counsellor', 'action' => 'lead'];
    }
    if ($lead && chatbot_wa_link() && !in_array('whatsapp', $intents, true)) {
        $s[] = ['label' => 'WhatsApp us', 'action' => 'whatsapp'];
    }
    return array_slice($s, 0, 5);
}
