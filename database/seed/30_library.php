<?php
/**
 * Demo data for the Library unit (re-runnable, deterministic, anchored on today's date).
 *   - 21 subject categories (DDC style), ~35 publishers, authors
 *   - ~600 catalogue titles: well-known textbooks + generated syllabus titles, references, journals, project reports
 *   - ~1,500 physical copies (accession register ACC000001…) with condition by age, a few withdrawn / damaged / lost
 *   - Members: active students, faculty and staff (limits from the circulation rules), a few suspended / expired;
 *     one first-semester section, the two newest faculty and the newest staff member are left to enroll from the UI
 *   - ~900 loans over the last ~7 months simulated day by day (copy + member limits respected): on-time and late
 *     returns, renewals, current loans, realistic overdue books, lost and damaged copies
 *   - Fines for late / lost / damaged returns: mostly collected (receipts LFR/…), some waived with reasons, some unpaid
 *
 * Owns: book_categories, authors, publishers, books, book_authors, book_copies, library_members, library_transactions, library_fines.
 */
return function (array $opts): void {
    require_once __DIR__ . '/lib/demo_data.php';
    require_once APP_ROOT . '/app/services/library.php';
    demo_seed(3030);

    // ---------------------------------------------------------------- clear owned tables (children first)
    db_exec('DELETE FROM library_fines');
    db_exec('DELETE FROM library_transactions');
    db_exec('DELETE FROM library_members');
    db_exec('DELETE FROM book_copies');
    db_exec('DELETE FROM book_authors');
    db_exec('DELETE FROM books');
    db_exec('DELETE FROM authors');
    db_exec('DELETE FROM publishers');
    db_exec('DELETE FROM book_categories');
    foreach (['library_fines', 'library_transactions', 'library_members', 'book_copies', 'books', 'authors', 'publishers', 'book_categories'] as $t) {
        db_exec('ALTER TABLE ' . db_quote_ident($t) . ' AUTO_INCREMENT = 1');
    }

    db()->beginTransaction();
    $today = date('Y-m-d');
    $todayTs = strtotime($today);
    $day = fn (int $offset, ?string $from = null) => date('Y-m-d', strtotime(($from ?? $today) . ' ' . ($offset >= 0 ? '+' : '') . $offset . ' days'));
    $session = db_row('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1') ?: db_row('SELECT * FROM academic_sessions ORDER BY start_date DESC LIMIT 1');
    $sessionEnd = $session && $session['end_date'] > $today ? $session['end_date'] : date('Y-m-d', strtotime('+1 year'));
    $librarian = (int) (db_value("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'librarian' ORDER BY u.id LIMIT 1")
        ?: db_value("SELECT id FROM users ORDER BY id LIMIT 1"));
    $admin = (int) (db_value("SELECT id FROM users ORDER BY id LIMIT 1") ?: $librarian);
    $rules = lib_rules();

    // ---------------------------------------------------------------- categories
    $cats = [
        'mgmt' => ['Management & Leadership', '658', 'Principles of management, organisational behaviour, HR, operations and strategy.', 1, 'R-01'],
        'mkt' => ['Marketing & Sales', '658.8', 'Marketing management, consumer behaviour, digital and retail marketing.', 1, 'R-02'],
        'fin' => ['Finance & Accounting', '657', 'Financial, cost and management accounting, corporate finance and markets.', 4, 'R-03'],
        'eco' => ['Economics', '330', 'Micro and macro economics, Indian economy, econometrics and public finance.', 4, 'R-04'],
        'law' => ['Business & Corporate Law', '346', 'Contract, company, labour and cyber law, IPR and consumer protection.', 4, 'R-05'],
        'tax' => ['Taxation & Auditing', '336.2', 'Income tax, GST, indirect taxes, auditing and assurance.', 4, 'R-05'],
        'cs' => ['Computer Science', '004', 'Algorithms, operating systems, databases, architecture and theory of computation.', 2, 'R-06'],
        'prog' => ['Programming & Software Engineering', '005.1', 'Programming languages, web and mobile development, software engineering.', 3, 'R-07'],
        'ai' => ['Data Science & Artificial Intelligence', '006.3', 'Machine learning, deep learning, analytics, big data and NLP.', 2, 'R-08'],
        'net' => ['Networks & Cyber Security', '004.6', 'Computer networks, cryptography, network and information security.', 2, 'R-09'],
        'ece' => ['Electronics & Communication', '621.38', 'Electronic devices, digital logic, signals, communication and embedded systems.', 2, 'R-10'],
        'math' => ['Mathematics & Statistics', '510', 'Engineering and business mathematics, statistics, OR and numerical methods.', 5, 'R-11'],
        'sci' => ['Physics & Chemistry', '530', 'Engineering physics, chemistry and materials science.', 5, 'R-12'],
        'env' => ['Environmental Studies', '363.7', 'Environment, ecology, sustainability and disaster management.', 5, 'R-12'],
        'eng' => ['English Language & Communication', '428', 'Grammar, business and technical communication, soft skills.', 6, 'R-13'],
        'comp' => ['Competitive Exams & Aptitude', '001.4', 'Aptitude, reasoning, CAT, GATE, UGC-NET and placement preparation.', 6, 'R-14'],
        'self' => ['Personality Development & Biographies', '158.1', 'Self-help, leadership, biographies and popular non-fiction.', 6, 'R-15'],
        'lit' => ['Literature & Fiction', '823', 'Indian and world literature in English.', null, 'R-16'],
        'hindi' => ['Hindi Sahitya', '891.43', 'Hindi novels, poetry and classics.', null, 'R-17'],
        'ref' => ['Reference & Encyclopaedias', '030', 'Dictionaries, encyclopaedias, yearbooks and handbooks (in-library use).', null, 'R-18'],
        'jour' => ['Journals & Periodicals', '050', 'Academic journals and current-affairs magazines.', null, 'R-19'],
    ];
    $catId = [];
    foreach ($cats as $k => [$name, $code, $desc]) {
        $catId[$k] = db_insert('book_categories', ['name' => $name, 'code' => $code, 'description' => $desc, 'status' => 'active']);
    }
    db_insert('book_categories', ['name' => 'Rare & Archival Collection', 'code' => '090', 'description' => 'Old and rare books kept in the archive (not in circulation).', 'status' => 'inactive']);

    // ---------------------------------------------------------------- publishers
    $pubs = [
        'pearson' => ['Pearson Education India', 'Noida', 'https://www.pearson.com/en-in.html'], 'mgh' => ['McGraw Hill Education (India)', 'Chennai', 'https://www.mheducation.co.in'],
        'wiley' => ['Wiley India', 'New Delhi', 'https://www.wileyindia.com'], 'oup' => ['Oxford University Press India', 'New Delhi', 'https://india.oup.com'],
        'cengage' => ['Cengage Learning India', 'New Delhi', 'https://www.cengage.co.in'], 'phi' => ['PHI Learning', 'New Delhi', 'https://www.phindia.com'],
        'schand' => ['S. Chand Publishing', 'New Delhi', 'https://www.schandpublishing.com'], 'sultan' => ['Sultan Chand & Sons', 'New Delhi', null],
        'laxmi' => ['Laxmi Publications', 'New Delhi', 'https://www.laxmipublications.com'], 'khanna' => ['Khanna Publishers', 'New Delhi', null],
        'newage' => ['New Age International', 'New Delhi', 'https://www.newagepublishers.com'], 'vikas' => ['Vikas Publishing House', 'Noida', 'https://www.vikaspublishing.com'],
        'himalaya' => ['Himalaya Publishing House', 'Mumbai', 'https://www.himpub.com'], 'taxmann' => ['Taxmann Publications', 'New Delhi', 'https://www.taxmann.com'],
        'bpb' => ['BPB Publications', 'New Delhi', 'https://bpbonline.com'], 'shroff' => ["Shroff Publishers (O'Reilly India)", 'Mumbai', null],
        'up' => ['Universities Press', 'Hyderabad', 'https://www.universitiespress.com'], 'penguin' => ['Penguin Random House India', 'Gurugram', 'https://penguin.co.in'],
        'harper' => ['HarperCollins India', 'Noida', 'https://harpercollins.co.in'], 'rajkamal' => ['Rajkamal Prakashan', 'New Delhi', null],
        'ebc' => ['Eastern Book Company', 'Lucknow', 'https://www.ebc.co.in'], 'springer' => ['Springer Nature India', 'New Delhi', null],
        'mit' => ['MIT Press', 'Cambridge, MA', 'https://mitpress.mit.edu'], 'bharati' => ['Bharati Bhawan', 'Patna', null],
        'dhanpat' => ['Dhanpat Rai & Co.', 'New Delhi', null], 'galgotia' => ['Galgotia Publications', 'New Delhi', null],
        'arihant' => ['Arihant Publications', 'Meerut', 'https://www.arihantbooks.com'], 'bloomsbury' => ['Bloomsbury India', 'New Delhi', null],
        'rupa' => ['Rupa Publications', 'New Delhi', null], 'kalyani' => ['Kalyani Publishers', 'Ludhiana', null],
        'lexis' => ['LexisNexis India', 'Gurugram', null], 'mac' => ['Macmillan Education India', 'New Delhi', null],
        'rajpal' => ['Rajpal & Sons', 'New Delhi', null], 'lokbharti' => ['Lokbharti Prakashan', 'Prayagraj', null],
        'itp' => ['Indian Thought Publications', 'Chennai', null], 'gimt' => ['GIMT Central Library (In-house)', 'Greater Noida', null],
        'publications' => ['Publications Division, Govt. of India', 'New Delhi', 'https://publicationsdivision.nic.in'], 'ieee' => ['IEEE', 'Piscataway, NJ', 'https://www.ieee.org'],
        'acm' => ['Association for Computing Machinery', 'New York', 'https://www.acm.org'], 'epw' => ['Sameeksha Trust', 'Mumbai', null],
        'sage' => ['SAGE Publications India', 'New Delhi', 'https://in.sagepub.com'], 'indiatoday' => ['Living Media India', 'Noida', null],
    ];
    $pubId = [];
    foreach ($pubs as $k => [$name, $city, $web]) {
        $pubId[$k] = db_insert('publishers', ['name' => $name, 'city' => $city, 'website' => $web]);
    }
    $indianPubs = ['schand', 'laxmi', 'khanna', 'newage', 'vikas', 'himalaya', 'phi', 'mgh', 'pearson', 'wiley', 'oup', 'cengage', 'galgotia', 'kalyani', 'dhanpat', 'bpb', 'sultan', 'up'];

    // ---------------------------------------------------------------- authors (created on demand)
    $authorIds = [];
    $author = function (string $name) use (&$authorIds): int {
        if (!isset($authorIds[$name])) {
            $authorIds[$name] = db_insert('authors', ['name' => $name]);
        }
        return $authorIds[$name];
    };
    $isbns = [];
    $isbn = function () use (&$isbns): string {
        do {
            $d = '97893' . str_pad((string) mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $d[$i] * ($i % 2 ? 3 : 1);
            }
            $d .= (10 - $sum % 10) % 10;
        } while (isset($isbns[$d]));
        $isbns[$d] = true;
        return $d;
    };
    $ord = fn (int $n) => $n . (in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th')) . ' Edition';

    // ---------------------------------------------------------------- catalogue: well-known textbooks
    // [title, authors, publisher, category, edition no, year, price, pages, popularity 1-10]
    $classics = [
        ['Marketing Management', ['Philip Kotler', 'Kevin Lane Keller'], 'pearson', 'mkt', 16, 2022, 899, 832, 10],
        ['Principles of Marketing', ['Philip Kotler', 'Gary Armstrong'], 'pearson', 'mkt', 18, 2021, 849, 736, 8],
        ['Consumer Behaviour', ['Leon G. Schiffman', 'Joseph L. Wisenblit'], 'pearson', 'mkt', 12, 2019, 795, 528, 6],
        ['Organizational Behavior', ['Stephen P. Robbins', 'Timothy A. Judge'], 'pearson', 'mgmt', 18, 2019, 829, 744, 9],
        ['Management', ['Stephen P. Robbins', 'Mary Coulter'], 'pearson', 'mgmt', 14, 2020, 779, 704, 8],
        ['Essentials of Management', ['Harold Koontz', 'Heinz Weihrich'], 'mgh', 'mgmt', 11, 2020, 699, 648, 7],
        ['Human Resource Management', ['Gary Dessler', 'Biju Varrkey'], 'pearson', 'mgmt', 16, 2020, 815, 704, 8],
        ['Strategic Management: Concepts and Cases', ['Fred R. David', 'Forest R. David'], 'pearson', 'mgmt', 17, 2019, 765, 688, 7],
        ['Operations Management', ['William J. Stevenson'], 'mgh', 'mgmt', 14, 2021, 845, 912, 6],
        ['Business Research Methods', ['Donald R. Cooper', 'Pamela S. Schindler'], 'mgh', 'mgmt', 12, 2018, 745, 720, 6],
        ['Research Methodology: Methods and Techniques', ['C. R. Kothari', 'Gaurav Garg'], 'newage', 'mgmt', 4, 2019, 425, 448, 9],
        ['Business Communication', ['Meenakshi Raman', 'Prakash Singh'], 'oup', 'eng', 3, 2018, 545, 616, 7],
        ['Financial Management: Theory and Practice', ['Prasanna Chandra'], 'mgh', 'fin', 10, 2019, 895, 1096, 9],
        ['Financial Management', ['I. M. Pandey'], 'vikas', 'fin', 12, 2021, 795, 1032, 9],
        ['Investment Analysis and Portfolio Management', ['Prasanna Chandra'], 'mgh', 'fin', 6, 2021, 865, 864, 6],
        ['Options, Futures and Other Derivatives', ['John C. Hull', 'Sankarshan Basu'], 'pearson', 'fin', 11, 2022, 945, 896, 5],
        ['Corporate Finance', ['Stephen A. Ross', 'Randolph W. Westerfield', 'Jeffrey Jaffe'], 'mgh', 'fin', 12, 2019, 1095, 1040, 4],
        ['Advanced Accountancy (Vol. I)', ['R. L. Gupta', 'M. Radhaswamy'], 'sultan', 'fin', 19, 2022, 895, 1184, 8],
        ['Modern Accountancy (Vol. I)', ['A. Mukherjee', 'M. Hanif'], 'mgh', 'fin', 4, 2019, 775, 976, 7],
        ['Corporate Accounting', ['S. N. Maheshwari', 'Suneel K. Maheshwari'], 'vikas', 'fin', 6, 2021, 650, 904, 7],
        ['Cost Accounting: Principles and Practice', ['M. N. Arora'], 'vikas', 'fin', 13, 2020, 625, 856, 6],
        ['Statistical Methods', ['S. P. Gupta'], 'sultan', 'math', 46, 2021, 795, 1472, 8],
        ['Business Statistics', ['J. K. Sharma'], 'vikas', 'math', 5, 2020, 595, 752, 7],
        ['Elements of Mercantile Law', ['N. D. Kapoor'], 'sultan', 'law', 38, 2020, 645, 1024, 7],
        ['Law of Contract and Specific Relief', ['Avtar Singh'], 'ebc', 'law', 13, 2022, 795, 912, 5],
        ['Company Law', ['Avtar Singh'], 'ebc', 'law', 17, 2018, 695, 1056, 4],
        ['Students\' Guide to Income Tax including GST', ['Vinod K. Singhania', 'Monica Singhania'], 'taxmann', 'tax', 69, 2025, 875, 920, 8],
        ['Indian Economy', ['Ramesh Singh'], 'mgh', 'eco', 16, 2024, 795, 1016, 9],
        ['Economics', ['Paul A. Samuelson', 'William D. Nordhaus'], 'mgh', 'eco', 20, 2019, 925, 720, 6],
        ['Principles of Economics', ['N. Gregory Mankiw'], 'cengage', 'eco', 8, 2020, 899, 864, 6],
        ['Managerial Economics', ['D. N. Dwivedi'], 'vikas', 'eco', 9, 2022, 575, 608, 7],
        ['Introduction to Algorithms', ['Thomas H. Cormen', 'Charles E. Leiserson', 'Ronald L. Rivest', 'Clifford Stein'], 'phi', 'cs', 3, 2010, 995, 1312, 10],
        ['Database System Concepts', ['Abraham Silberschatz', 'Henry F. Korth', 'S. Sudarshan'], 'mgh', 'cs', 7, 2021, 975, 1376, 9],
        ['Fundamentals of Database Systems', ['Ramez Elmasri', 'Shamkant B. Navathe'], 'pearson', 'cs', 7, 2017, 899, 1272, 7],
        ['Operating System Concepts', ['Abraham Silberschatz', 'Peter B. Galvin', 'Greg Gagne'], 'wiley', 'cs', 10, 2018, 925, 976, 9],
        ['Modern Operating Systems', ['Andrew S. Tanenbaum', 'Herbert Bos'], 'pearson', 'cs', 4, 2016, 875, 1136, 6],
        ['Computer Organization and Architecture', ['William Stallings'], 'pearson', 'cs', 11, 2022, 895, 864, 7],
        ['Introduction to the Theory of Computation', ['Michael Sipser'], 'cengage', 'cs', 3, 2014, 745, 480, 5],
        ['Compilers: Principles, Techniques and Tools', ['Alfred V. Aho', 'Monica S. Lam', 'Ravi Sethi', 'Jeffrey D. Ullman'], 'pearson', 'cs', 2, 2007, 845, 1040, 5],
        ['Discrete Mathematics and Its Applications', ['Kenneth H. Rosen'], 'mgh', 'math', 8, 2021, 875, 1120, 8],
        ['Let Us C', ['Yashavant Kanetkar'], 'bpb', 'prog', 19, 2023, 375, 532, 10],
        ['Programming in ANSI C', ['E. Balagurusamy'], 'mgh', 'prog', 9, 2024, 525, 592, 10],
        ['The C Programming Language', ['Brian W. Kernighan', 'Dennis M. Ritchie'], 'pearson', 'prog', 2, 2015, 425, 272, 7],
        ['Object Oriented Programming with C++', ['E. Balagurusamy'], 'mgh', 'prog', 8, 2020, 545, 608, 9],
        ['Java: The Complete Reference', ['Herbert Schildt'], 'mgh', 'prog', 12, 2022, 1095, 1248, 9],
        ['Head First Java', ['Kathy Sierra', 'Bert Bates', 'Trisha Gee'], 'shroff', 'prog', 3, 2022, 1250, 754, 7],
        ['Python Crash Course', ['Eric Matthes'], 'shroff', 'prog', 3, 2023, 899, 552, 9],
        ['Learning Python', ['Mark Lutz'], 'shroff', 'prog', 5, 2013, 1550, 1648, 5],
        ['Data Structures Through C', ['Yashavant Kanetkar'], 'bpb', 'prog', 4, 2022, 395, 568, 8],
        ['Software Engineering: A Practitioner\'s Approach', ['Roger S. Pressman', 'Bruce R. Maxim'], 'mgh', 'prog', 9, 2023, 925, 704, 8],
        ['Clean Code', ['Robert C. Martin'], 'pearson', 'prog', 1, 2012, 699, 464, 6],
        ['Design Patterns: Elements of Reusable Object-Oriented Software', ['Erich Gamma', 'Richard Helm', 'Ralph Johnson', 'John Vlissides'], 'pearson', 'prog', 1, 2015, 795, 416, 4],
        ['Artificial Intelligence: A Modern Approach', ['Stuart Russell', 'Peter Norvig'], 'pearson', 'ai', 4, 2022, 1195, 1136, 9],
        ['Pattern Recognition and Machine Learning', ['Christopher M. Bishop'], 'springer', 'ai', 1, 2013, 1695, 738, 5],
        ['Hands-On Machine Learning with Scikit-Learn, Keras and TensorFlow', ['Aurélien Géron'], 'shroff', 'ai', 3, 2023, 2150, 864, 9],
        ['Deep Learning', ['Ian Goodfellow', 'Yoshua Bengio', 'Aaron Courville'], 'mit', 'ai', 1, 2016, 2995, 800, 6],
        ['Python for Data Analysis', ['Wes McKinney'], 'shroff', 'ai', 3, 2022, 1450, 582, 8],
        ['Computer Networks', ['Andrew S. Tanenbaum', 'David J. Wetherall', 'Nick Feamster'], 'pearson', 'net', 6, 2022, 895, 960, 9],
        ['Data Communications and Networking', ['Behrouz A. Forouzan'], 'mgh', 'net', 5, 2017, 845, 1264, 8],
        ['Cryptography and Network Security: Principles and Practice', ['William Stallings'], 'pearson', 'net', 8, 2023, 875, 832, 7],
        ['Digital Logic and Computer Design', ['M. Morris Mano'], 'pearson', 'ece', 1, 2016, 525, 624, 8],
        ['Electronic Devices and Circuit Theory', ['Robert L. Boylestad', 'Louis Nashelsky'], 'pearson', 'ece', 11, 2015, 845, 912, 6],
        ['Signals and Systems', ['Alan V. Oppenheim', 'Alan S. Willsky', 'S. Hamid Nawab'], 'pearson', 'ece', 2, 2015, 795, 984, 5],
        ['Microelectronic Circuits', ['Adel S. Sedra', 'Kenneth C. Smith'], 'oup', 'ece', 7, 2017, 995, 1488, 4],
        ['Higher Engineering Mathematics', ['B. S. Grewal'], 'khanna', 'math', 44, 2023, 875, 1238, 10],
        ['Advanced Engineering Mathematics', ['Erwin Kreyszig'], 'wiley', 'math', 10, 2015, 995, 1280, 7],
        ['Engineering Mathematics', ['H. K. Dass'], 'schand', 'math', 22, 2021, 745, 1176, 7],
        ['Operations Research: An Introduction', ['Hamdy A. Taha'], 'pearson', 'math', 10, 2019, 825, 848, 6],
        ['Concepts of Physics (Vol. 1)', ['H. C. Verma'], 'bharati', 'sci', 1, 2022, 545, 474, 6],
        ['Fundamentals of Physics', ['David Halliday', 'Robert Resnick', 'Jearl Walker'], 'wiley', 'sci', 10, 2018, 1095, 1328, 5],
        ['Engineering Chemistry', ['Jain & Jain'], 'dhanpat', 'sci', 17, 2018, 525, 1072, 6],
        ['Environmental Studies for Undergraduate Courses', ['Erach Bharucha'], 'up', 'env', 3, 2021, 325, 400, 8],
        ['Indian Polity', ['M. Laxmikanth'], 'mgh', 'comp', 7, 2023, 895, 1200, 9],
        ['Quantitative Aptitude for Competitive Examinations', ['R. S. Aggarwal'], 'schand', 'comp', 2023, 2023, 725, 1056, 10],
        ['A Modern Approach to Verbal & Non-Verbal Reasoning', ['R. S. Aggarwal'], 'schand', 'comp', 2022, 2022, 685, 1136, 9],
        ['How to Prepare for Quantitative Aptitude for the CAT', ['Arun Sharma'], 'mgh', 'comp', 11, 2024, 1065, 1124, 8],
        ['Word Power Made Easy', ['Norman Lewis'], 'penguin', 'eng', 1, 2020, 199, 528, 8],
        ['High School English Grammar and Composition', ['P. C. Wren', 'H. Martin'], 'schand', 'eng', 2023, 2023, 425, 424, 7],
        ['Communication Skills', ['Sanjay Kumar', 'Pushp Lata'], 'oup', 'eng', 2, 2015, 395, 512, 7],
        ['Wings of Fire: An Autobiography', ['A. P. J. Abdul Kalam', 'Arun Tiwari'], 'up', 'self', 1, 1999, 299, 196, 9],
        ['The 7 Habits of Highly Effective People', ['Stephen R. Covey'], 'penguin', 'self', 1, 2020, 499, 432, 7],
        ['Emotional Intelligence', ['Daniel Goleman'], 'bloomsbury', 'self', 1, 2020, 499, 384, 6],
        ['Thinking, Fast and Slow', ['Daniel Kahneman'], 'penguin', 'self', 1, 2012, 599, 512, 7],
        ['Good to Great', ['Jim Collins'], 'harper', 'self', 1, 2019, 699, 320, 6],
        ['Zero to One', ['Peter Thiel', 'Blake Masters'], 'penguin', 'self', 1, 2014, 499, 224, 7],
        ['Sapiens: A Brief History of Humankind', ['Yuval Noah Harari'], 'harper', 'self', 1, 2015, 599, 512, 8],
        ['The Lean Startup', ['Eric Ries'], 'penguin', 'self', 1, 2011, 599, 336, 6],
        ['The Discovery of India', ['Jawaharlal Nehru'], 'penguin', 'lit', 1, 2008, 499, 656, 5],
        ['The Guide', ['R. K. Narayan'], 'itp', 'lit', 1, 2007, 225, 220, 6],
        ['Malgudi Days', ['R. K. Narayan'], 'itp', 'lit', 1, 2006, 199, 256, 7],
        ['The God of Small Things', ['Arundhati Roy'], 'penguin', 'lit', 1, 2002, 399, 340, 6],
        ['Train to Pakistan', ['Khushwant Singh'], 'penguin', 'lit', 1, 2016, 299, 208, 5],
        ['Gitanjali', ['Rabindranath Tagore'], 'mac', 'lit', 1, 2017, 175, 112, 4],
        ['Godaan', ['Premchand'], 'rajkamal', 'hindi', 1, 2019, 250, 320, 6],
        ['Gaban', ['Premchand'], 'rajkamal', 'hindi', 1, 2018, 195, 248, 4],
        ['Madhushala', ['Harivansh Rai Bachchan'], 'rajpal', 'hindi', 1, 2017, 150, 128, 5],
        ['Rashmirathi', ['Ramdhari Singh Dinkar'], 'lokbharti', 'hindi', 1, 2018, 175, 112, 5],
        ['Constitution of India', ['P. M. Bakshi'], 'lexis', 'law', 19, 2023, 450, 584, 5],
        ['Strength of Materials', ['R. K. Bansal'], 'laxmi', 'sci', 6, 2018, 545, 1120, 4],
        ['Business Ethics and Corporate Governance', ['A. C. Fernando'], 'pearson', 'mgmt', 3, 2019, 545, 560, 5],
    ];

    // ---------------------------------------------------------------- catalogue: generated syllabus titles
    $topics = [
        'mgmt' => ['Principles of Management', 'Organisational Behaviour', 'Human Resource Management', 'Strategic Management', 'Operations Management', 'Entrepreneurship Development',
            'Project Management', 'Supply Chain Management', 'International Business', 'Total Quality Management', 'Management Information Systems', 'Business Environment',
            'Leadership and Change Management', 'Industrial Relations and Labour Welfare', 'Performance Management', 'Training and Development', 'Logistics Management', 'Corporate Strategy'],
        'mkt' => ['Marketing Management', 'Consumer Behaviour', 'Digital Marketing', 'Retail Management', 'Sales and Distribution Management', 'Advertising and Brand Management',
            'Services Marketing', 'Marketing Research', 'Rural Marketing', 'Social Media Marketing', 'Customer Relationship Management', 'Integrated Marketing Communication', 'Search Engine Optimisation'],
        'fin' => ['Financial Accounting', 'Cost Accounting', 'Management Accounting', 'Financial Management', 'Investment Management', 'Financial Markets and Services', 'Banking and Insurance',
            'Security Analysis and Portfolio Management', 'Working Capital Management', 'Mergers and Acquisitions', 'Fintech and Digital Payments', 'Financial Derivatives', 'Behavioural Finance', 'Accounting for Managers'],
        'eco' => ['Managerial Economics', 'Microeconomics', 'Macroeconomics', 'Development Economics', 'International Economics', 'Public Finance', 'Econometrics', 'Money and Banking', 'Indian Economic Policy'],
        'law' => ['Business Law', 'Company Law', 'Labour Laws', 'Intellectual Property Rights', 'Cyber Law', 'Consumer Protection Law', 'Corporate Laws and Compliance'],
        'tax' => ['Income Tax Law and Practice', 'Goods and Services Tax', 'Auditing and Assurance', 'Indirect Taxes', 'Corporate Tax Planning', 'Forensic Accounting'],
        'cs' => ['Data Structures', 'Design and Analysis of Algorithms', 'Operating Systems', 'Database Management Systems', 'Computer Organisation and Architecture', 'Theory of Automata',
            'Compiler Design', 'Computer Graphics', 'Cloud Computing', 'Distributed Systems', 'Internet of Things', 'Blockchain Technology', 'Parallel Computing', 'Human Computer Interaction'],
        'prog' => ['Programming in C', 'Object Oriented Programming using C++', 'Programming in Java', 'Python Programming', 'Web Technologies', 'Software Engineering', 'Software Testing',
            'Android Application Development', 'Full Stack Web Development', 'C# and .NET Framework', 'PHP and MySQL', 'Data Structures using Python', 'Linux and Shell Programming', 'JavaScript and React', 'DevOps Practices'],
        'ai' => ['Machine Learning', 'Artificial Intelligence', 'Data Mining and Warehousing', 'Big Data Analytics', 'Deep Learning', 'Natural Language Processing', 'Data Visualisation',
            'Statistics for Data Science', 'Business Analytics', 'Computer Vision', 'Reinforcement Learning', 'Predictive Analytics with R', 'Generative AI', 'Data Engineering'],
        'net' => ['Computer Networks', 'Network Security', 'Cryptography', 'Ethical Hacking', 'Wireless and Mobile Networks', 'Information Security Management', 'Cyber Forensics', 'Network Programming'],
        'ece' => ['Digital Electronics', 'Analog Electronics', 'Signals and Systems', 'Microprocessors and Microcontrollers', 'Communication Systems', 'Electronic Devices and Circuits', 'VLSI Design', 'Embedded Systems', 'Basic Electrical Engineering', 'Control Systems'],
        'math' => ['Engineering Mathematics', 'Linear Algebra', 'Probability and Statistics', 'Numerical Methods', 'Calculus', 'Business Mathematics', 'Operations Research', 'Discrete Structures', 'Differential Equations', 'Quantitative Techniques'],
        'sci' => ['Engineering Physics', 'Engineering Chemistry', 'Applied Physics', 'Modern Physics', 'Materials Science', 'Engineering Mechanics'],
        'env' => ['Environmental Studies', 'Disaster Management', 'Sustainable Development', 'Ecology and Environment', 'Renewable Energy Systems'],
        'eng' => ['Professional Communication', 'Technical Writing', 'English Grammar and Usage', 'Soft Skills', 'Spoken English', 'Report Writing', 'Business Correspondence'],
        'comp' => ['Quantitative Aptitude', 'Logical Reasoning', 'General Studies', 'Verbal Ability', 'Data Interpretation', 'General Knowledge', 'Campus Placement', 'CAT', 'GATE Computer Science', 'UGC NET Commerce', 'Banking Awareness'],
        'self' => ['Time Management', 'Leadership', 'Personality Development', 'Emotional Intelligence at Work', 'Interview Skills', 'Stress Management', 'Public Speaking'],
    ];
    $templates = [
        'default' => ['%s', 'Principles of %s', 'Fundamentals of %s', 'Introduction to %s', 'Essentials of %s', '%s: Text and Cases', '%s: Concepts and Applications', 'A Textbook of %s', 'Handbook of %s', 'Advanced %s', '%s: Theory and Practice'],
        'tech' => ['%s', 'Fundamentals of %s', 'Introduction to %s', '%s: A Practical Approach', 'Learning %s', '%s Simplified', 'Mastering %s', 'A Textbook of %s', 'Advanced %s', '%s: Principles and Practice'],
        'comp' => ['%s for Competitive Examinations', 'Objective %s', '%s Made Easy', 'Mastering %s', 'A Complete Guide to %s', '%s: Solved Papers and Practice Sets'],
        'self' => ['The Art of %s', '%s for Professionals', '%s: A Practical Guide', 'Mastering %s', '%s Made Simple'],
    ];
    $catTemplates = ['cs' => 'tech', 'prog' => 'tech', 'ai' => 'tech', 'net' => 'tech', 'ece' => 'tech', 'comp' => 'comp', 'self' => 'self'];
    $catWeight = ['mgmt' => 13, 'mkt' => 9, 'fin' => 11, 'eco' => 6, 'law' => 4, 'tax' => 4, 'cs' => 12, 'prog' => 13, 'ai' => 10, 'net' => 5, 'ece' => 5, 'math' => 8, 'sci' => 4, 'env' => 3, 'eng' => 4, 'comp' => 6, 'self' => 3];
    $subtitles = ['As per AKTU syllabus', 'For BBA / MBA students', 'For B.Tech, BCA and MCA', 'With solved examples and case studies', 'For B.Com and M.Com', 'Revised as per NEP 2020', 'With MCQs and previous year questions', null, null, null, null];
    $titlesSeen = [];
    foreach ($classics as $c) {
        $titlesSeen[strtolower($c[0])] = true;
    }
    $gen = [];
    $titlePrefixes = ['Dr.', 'Prof.', '', '', '', ''];
    $guard = 0;
    while (count($gen) < 455 && $guard++ < 20000) {
        $cat = demo_weighted($catWeight);
        $topic = demo_pick($topics[$cat]);
        $tpl = demo_pick($templates[$catTemplates[$cat] ?? 'default']);
        $title = sprintf($tpl, $topic);
        if (isset($titlesSeen[strtolower($title)])) {
            continue;
        }
        $titlesSeen[strtolower($title)] = true;
        $n = demo_weighted([1 => 60, 2 => 32, 3 => 8]);
        $names = [];
        for ($i = 0; $i < $n; $i++) {
            $g = mt_rand(0, 1) ? 'male' : 'female';
            $names[] = trim(demo_pick($titlePrefixes) . ' ' . demo_pick(demo_first_names($g)) . ' ' . (mt_rand(0, 4) ? '' : chr(mt_rand(65, 90)) . '. ') . demo_pick(demo_last_names()));
        }
        $year = mt_rand(2012, 2025);
        $gen[] = [$title, array_values(array_unique($names)), demo_pick($indianPubs), $cat, mt_rand(1, 6), $year, mt_rand(18, 95) * 10 - 5, mt_rand(18, 80) * 10, mt_rand(1, 5), demo_pick($subtitles)];
    }

    // References, periodicals, project reports
    $references = [
        ['Oxford Advanced Learner\'s Dictionary', ['A. S. Hornby'], 'oup', 10, 2020, 895, 1952],
        ['Collins English Dictionary', ['Collins Dictionaries'], 'harper', 14, 2023, 1295, 2400],
        ['Oxford Hindi-English Dictionary', ['R. S. McGregor'], 'oup', 1, 2016, 1195, 1104],
        ['Manorama Yearbook ' . date('Y'), ['Mammen Mathew'], 'rupa', 1, (int) date('Y'), 350, 1040],
        ['India ' . date('Y') . ': A Reference Annual', ['Publications Division'], 'publications', 1, (int) date('Y'), 450, 880],
        ['Encyclopaedia of Management', ['Sushila Madan'], 'himalaya', 2, 2017, 2450, 1250],
        ['Dictionary of Computer Science', ['Andrew Butterfield', 'Gerard Ekembe Ngondi'], 'oup', 7, 2016, 595, 640],
        ['Black\'s Law Dictionary', ['Bryan A. Garner'], 'lexis', 11, 2019, 4995, 2016],
        ['Handbook of Mathematical Formulas', ['Arihant Experts'], 'arihant', 3, 2022, 225, 384],
        ['The Oxford Thesaurus', ['Maurice Waite'], 'oup', 2, 2019, 795, 976],
        ['Atlas of India and the World', ['Oxford Cartography'], 'oup', 5, 2021, 650, 196],
        ['Indian Standard Code Book IS 456', ['Bureau of Indian Standards'], 'publications', 4, 2016, 495, 100],
    ];
    $periodicals = [
        ['IEEE Spectrum', 'ieee', 'magazine'], ['Communications of the ACM', 'acm', 'journal'], ['Economic and Political Weekly', 'epw', 'magazine'],
        ['Vikalpa: The Journal for Decision Makers', 'sage', 'journal'], ['Global Business Review', 'sage', 'journal'], ['Business Today', 'indiatoday', 'magazine'],
        ['Yojana', 'publications', 'magazine'], ['Kurukshetra', 'publications', 'magazine'], ['Indian Journal of Marketing', 'sage', 'journal'],
        ['Journal of Management Research', 'sage', 'journal'], ['Competition Success Review', 'arihant', 'magazine'], ['Electronics For You', 'galgotia', 'magazine'],
    ];
    $projects = [
        ['A Study on Consumer Preference towards Electric Two-Wheelers in Greater Noida', 'mkt'], ['Impact of UPI Adoption on Small Retailers in Delhi NCR', 'fin'],
        ['Employee Engagement Practices in IT Companies of Noida', 'mgmt'], ['Working Capital Management of Selected FMCG Companies', 'fin'],
        ['A Comparative Study of Mutual Fund Performance (2019-2024)', 'fin'], ['Role of Social Media in Brand Building of D2C Start-ups', 'mkt'],
        ['Sentiment Analysis of Product Reviews using Transformer Models', 'ai'], ['Smart Attendance System using Face Recognition', 'ai'],
        ['Design of an IoT-based Air Quality Monitoring System', 'cs'], ['Library Management System using Laravel and MySQL', 'prog'],
        ['Predicting Student Dropout using Machine Learning', 'ai'], ['Customer Satisfaction in Quick-Commerce Grocery Delivery', 'mkt'],
    ];

    // ---------------------------------------------------------------- insert books
    $books = []; // id => meta
    $insertBook = function (array $b, array $authors) use (&$books, $author, $catId, $pubId, $cats): int {
        $deptId = $cats[$b['cat']][3] ?? null;
        $id = db_insert('books', [
            'isbn' => $b['isbn'], 'title' => $b['title'], 'subtitle' => $b['subtitle'] ?? null, 'category_id' => $catId[$b['cat']], 'publisher_id' => $pubId[$b['pub']],
            'edition' => $b['edition'], 'publish_year' => $b['year'], 'language' => $b['language'] ?? 'English', 'pages' => $b['pages'], 'price' => $b['price'],
            'rack_no' => $cats[$b['cat']][4], 'shelf_no' => 'S-' . mt_rand(1, 6), 'department_id' => $deptId, 'description' => $b['description'] ?? null,
            'type' => $b['type'] ?? 'book', 'status' => $b['status'] ?? 'active', 'created_at' => $b['created_at'],
        ]);
        foreach (array_values($authors) as $i => $name) {
            db_insert('book_authors', ['book_id' => $id, 'author_id' => $author($name), 'sort_order' => $i]);
        }
        db_update('books', ['author_id' => $author($authors[0])], 'id = ?', [$id]);
        $books[$id] = ['id' => $id, 'cat' => $b['cat'], 'price' => (float) $b['price'], 'year' => (int) $b['year'], 'type' => $b['type'] ?? 'book', 'pop' => $b['pop'],
            'copies' => $b['copies'], 'rack' => $cats[$b['cat']][4], 'status' => $b['status'] ?? 'active'];
        return $id;
    };
    $catalogue = [];
    foreach ($classics as [$title, $authors, $pub, $cat, $ed, $year, $price, $pages, $pop]) {
        $catalogue[] = [['title' => $title, 'pub' => $pub, 'cat' => $cat, 'edition' => $ed > 1000 ? $ed . ' Edition' : $ord($ed), 'year' => $year, 'price' => $price, 'pages' => $pages,
            'pop' => $pop, 'copies' => max(2, min(8, (int) round($pop * 0.55) + mt_rand(0, 2))), 'language' => $cat === 'hindi' ? 'Hindi' : 'English',
            'description' => 'Standard reference text for ' . strtolower($cats[$cat][0]) . ', widely prescribed in university syllabi. Includes worked examples, case studies and end-of-chapter exercises.'], $authors];
    }
    foreach ($gen as [$title, $authors, $pub, $cat, $ed, $year, $price, $pages, $pop, $sub]) {
        $catalogue[] = [['title' => $title, 'subtitle' => $sub, 'pub' => $pub, 'cat' => $cat, 'edition' => $ord($ed), 'year' => $year, 'price' => $price, 'pages' => $pages, 'pop' => $pop,
            'copies' => demo_weighted([1 => 34, 2 => 36, 3 => 20, 4 => 10]),
            'description' => 'Covers ' . strtolower(preg_replace('/^(Principles of|Fundamentals of|Introduction to|Essentials of|A Textbook of|Handbook of|Advanced|Learning|Mastering|Objective|The Art of|A Complete Guide to) /', '', $title)) . ' with illustrations, solved problems and review questions for undergraduate and postgraduate courses.'], $authors];
    }
    foreach ($references as [$title, $authors, $pub, $ed, $year, $price, $pages]) {
        $catalogue[] = [['title' => $title, 'pub' => $pub, 'cat' => 'ref', 'edition' => $ord($ed), 'year' => $year, 'price' => $price, 'pages' => $pages, 'pop' => 0, 'type' => 'reference',
            'copies' => mt_rand(1, 2), 'description' => 'Reference work — for consultation inside the library only.'], $authors];
    }
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    foreach ($periodicals as $i => [$title, $pub, $type]) {
        $m = (int) date('n', strtotime('-' . ($i % 3) . ' months'));
        $catalogue[] = [['title' => $title, 'pub' => $pub, 'cat' => 'jour', 'edition' => 'Vol. ' . mt_rand(12, 71) . ', Issue ' . $m . ' (' . $months[$m - 1] . ' ' . date('Y') . ')',
            'year' => (int) date('Y'), 'price' => mt_rand(8, 60) * 10, 'pages' => mt_rand(60, 160), 'pop' => 2, 'type' => $type, 'copies' => mt_rand(1, 2),
            'description' => 'Latest issue on display in the periodicals section; back issues are bound annually.'], [$title . ' Editorial Board']];
    }
    foreach ($projects as [$title, $cat]) {
        $g = mt_rand(0, 1) ? 'male' : 'female';
        $catalogue[] = [['title' => $title, 'subtitle' => in_array($cat, ['ai', 'cs', 'prog'], true) ? 'MCA Major Project Report' : 'MBA Summer Internship Project Report', 'pub' => 'gimt',
            'cat' => $cat, 'edition' => 'Project Report', 'year' => mt_rand(2023, 2026), 'price' => 0, 'pages' => mt_rand(60, 140), 'pop' => 1, 'type' => 'thesis', 'copies' => 1,
            'description' => 'Bound project report submitted to the institute. Available for reference by current students.'], [demo_pick(demo_first_names($g)) . ' ' . demo_pick(demo_last_names())]];
    }
    // Insert in a shuffled but deterministic order so ids do not cluster by category
    $order = range(0, count($catalogue) - 1);
    for ($i = count($order) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
    }
    foreach ($order as $idx) {
        [$b, $authors] = $catalogue[$idx];
        $b['isbn'] = in_array($b['type'] ?? 'book', ['thesis', 'magazine', 'journal'], true) ? null : $isbn();
        $b['created_at'] = demo_datetime(max($b['year'], 2014) . '-06-01', $day(-40)) ;
        $insertBook($b, $authors);
    }
    // Two archival titles that are inactive (hidden from circulation)
    $archCat = (int) db_value("SELECT id FROM book_categories WHERE code = '090'");
    foreach ([['Gazetteer of the United Provinces (1908 reprint)', 'publications'], ['Annual Report of the Institute 2012-13', 'gimt']] as [$t, $p]) {
        $id = db_insert('books', ['title' => $t, 'category_id' => $archCat, 'publisher_id' => $pubId[$p], 'edition' => 'Archive copy', 'publish_year' => 2013, 'language' => 'English',
            'rack_no' => 'R-20', 'shelf_no' => 'S-1', 'type' => 'reference', 'status' => 'inactive', 'price' => 0, 'created_at' => '2016-03-14 11:00:00']);
        db_insert('book_authors', ['book_id' => $id, 'author_id' => $author('Publications Division'), 'sort_order' => 0]);
        $books[$id] = ['id' => $id, 'cat' => 'ref', 'price' => 0.0, 'year' => 2013, 'type' => 'reference', 'pop' => 0, 'copies' => 1, 'rack' => 'R-20', 'status' => 'inactive'];
    }
    // A few authors without titles yet (newly added to the authority file)
    foreach (['Amartya Sen', 'Raghuram G. Rajan', 'C. K. Prahalad'] as $a) {
        $author($a);
    }

    // ---------------------------------------------------------------- copies (accession register)
    $copies = [];
    foreach ($books as $b) {
        for ($i = 0; $i < $b['copies']; $i++) {
            $from = max($b['year'], 2012) . '-06-01';
            $acq = demo_date($from, $day(-35));
            if ($i > 0 && mt_rand(0, 2) === 0) {
                $acq = demo_date(max($from, $day(-1400)), $day(-35)); // later purchases of popular titles
            }
            $ageYears = ($todayTs - strtotime($acq)) / (365 * 86400);
            $cond = $ageYears > 8 ? demo_weighted(['fair' => 55, 'poor' => 30, 'good' => 15]) : ($ageYears > 3 ? demo_weighted(['good' => 60, 'fair' => 35, 'poor' => 5]) : demo_weighted(['new' => 55, 'good' => 45]));
            $copies[] = ['book_id' => $b['id'], 'acquired' => $acq, 'condition' => $cond, 'price' => $b['price'] > 0 ? round($b['price'] * ($ageYears > 6 ? 0.85 : 1)) : null, 'rack' => $b['rack']];
        }
    }
    usort($copies, fn ($a, $b) => strcmp($a['acquired'], $b['acquired']) ?: $a['book_id'] <=> $b['book_id']);
    $copyRows = [];
    foreach ($copies as $i => $c) {
        $acc = 'ACC' . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT);
        $copyRows[] = ['book_id' => $c['book_id'], 'accession_no' => $acc, 'barcode' => $acc, 'rack_no' => $c['rack'], 'condition_note' => $c['condition'], 'acquired_date' => $c['acquired'],
            'price' => $c['price'], 'status' => 'available', 'remarks' => null, 'created_at' => $c['acquired'] . ' 12:00:00'];
    }
    demo_bulk_insert('book_copies', $copyRows);
    $copyList = db_all('SELECT c.id, c.book_id, c.acquired_date, c.accession_no, c.condition_note, COALESCE(c.price, b.price, 0) AS price FROM book_copies c JOIN books b ON b.id = c.book_id ORDER BY c.id');
    $copiesByBook = [];
    $copyInfo = [];
    foreach ($copyList as $c) {
        $copiesByBook[(int) $c['book_id']][] = (int) $c['id'];
        $copyInfo[(int) $c['id']] = $c;
    }
    // Withdraw a few worn-out old copies (weeding)
    $withdrawn = [];
    foreach ($copyList as $c) {
        if ($c['condition_note'] === 'poor' && count($withdrawn) < 9 && mt_rand(0, 3) === 0) {
            $withdrawn[(int) $c['id']] = true;
        }
    }

    // ---------------------------------------------------------------- members
    $memberRows = [];
    $memberMeta = [];
    $faculty = db_all("SELECT id, department_id, created_at FROM faculty WHERE status IN ('active','on_leave') ORDER BY id");
    $staff = db_all("SELECT id, department_id, category, created_at FROM staff WHERE status IN ('active','on_leave') ORDER BY id");
    $students = db_all("SELECT id, department_id, program_id, section_id, current_semester, admission_date FROM students WHERE status = 'active' ORDER BY id");
    $n = 0;
    $addMember = function (string $type, int $pid, ?int $dept, string $created, array $extra = []) use (&$n, &$memberRows, &$memberMeta, $rules, $sessionEnd) {
        $n++;
        $t = $rules['types'][$type];
        $memberRows[] = array_merge([
            'membership_no' => 'LIB' . str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'member_type' => $type,
            'student_id' => $type === 'student' ? $pid : null, 'faculty_id' => $type === 'faculty' ? $pid : null, 'staff_id' => $type === 'staff' ? $pid : null,
            'max_books' => $t['max_books'], 'loan_days' => $t['loan_days'], 'valid_until' => $sessionEnd, 'status' => 'active', 'remarks' => null, 'created_at' => $created,
        ], $extra);
        $memberMeta[$n] = ['type' => $type, 'dept' => $dept, 'max' => $extra['max_books'] ?? $t['max_books'], 'days' => $extra['loan_days'] ?? $t['loan_days'], 'created' => substr($created, 0, 10)];
    };
    // Not everyone is enrolled yet, so "Add member" / "Bulk enroll" have real candidates: the two newest faculty,
    // the newest staff member and one first-semester section whose cards are still being prepared.
    $pendingFaculty = array_slice(array_column($faculty, 'id'), -2);
    $pendingStaff = array_slice(array_column($staff, 'id'), -1);
    $pendingSection = (int) db_value("SELECT section_id FROM students WHERE status = 'active' AND current_semester = 1 AND section_id IS NOT NULL
                                      GROUP BY section_id HAVING COUNT(*) >= 20 ORDER BY COUNT(*), section_id LIMIT 1");
    foreach ($faculty as $f) {
        if (in_array($f['id'], $pendingFaculty, false)) {
            continue;
        }
        $addMember('faculty', (int) $f['id'], $f['department_id'] ? (int) $f['department_id'] : null, demo_datetime('2023-07-10', $day(-220)), mt_rand(0, 6) ? [] : ['max_books' => 10, 'remarks' => 'Research scholar guide — extended limit']);
    }
    foreach ($staff as $s) {
        if (in_array($s['id'], $pendingStaff, false)) {
            continue;
        }
        $addMember('staff', (int) $s['id'], $s['department_id'] ? (int) $s['department_id'] : null, demo_datetime('2023-07-10', $day(-220)));
    }
    $sessionStart = $session['start_date'] ?? $day(-100);
    foreach ($students as $s) {
        if ($pendingSection && (int) $s['section_id'] === $pendingSection) {
            continue;
        }
        $isNew = (int) $s['current_semester'] <= 2;
        $created = $isNew ? demo_datetime(max($s['admission_date'] ?: $sessionStart, $sessionStart) . ' 10:00:00', min($day(-5), date('Y-m-d', strtotime($sessionStart . ' +60 days'))) . ' 17:00:00')
            : demo_datetime(($s['admission_date'] ?: '2024-07-15') . ' 10:00:00', $day(-230) . ' 17:00:00');
        if ($created > date('Y-m-d H:i:s')) {
            $created = $day(-3) . ' 11:00:00';
        }
        $addMember('student', (int) $s['id'], $s['department_id'] ? (int) $s['department_id'] : null, $created);
    }
    // a few suspended / expired memberships
    $studentNos = array_keys(array_filter($memberMeta, fn ($m) => $m['type'] === 'student'));
    $suspendReasons = ['Repeated late returns — suspended for one month by the Chief Librarian', 'Library card misused by another student', 'Damaged reference book not replaced yet', 'Pending no-dues verification'];
    foreach (array_slice($studentNos, 37, 4) as $i => $no) {
        $memberRows[$no - 1]['status'] = 'suspended';
        $memberRows[$no - 1]['remarks'] = $suspendReasons[$i];
    }
    foreach (array_slice($studentNos, 211, 6) as $no) {
        $memberRows[$no - 1]['valid_until'] = $session ? date('Y-m-d', strtotime($session['start_date'] . ' -1 day')) : $day(-30);
        $memberRows[$no - 1]['remarks'] = 'Membership not renewed for the current session';
    }
    demo_bulk_insert('library_members', $memberRows);
    $memberIds = db_column('SELECT id FROM library_members ORDER BY id'); // index = n - 1
    $memberById = [];
    foreach ($memberIds as $i => $id) {
        $memberById[(int) $id] = $memberMeta[$i + 1];
        $memberById[(int) $id]['status'] = $memberRows[$i]['status'];
    }

    // ---------------------------------------------------------------- circulation simulation
    $deptCats = [
        1 => ['mgmt' => 5, 'mkt' => 4, 'fin' => 3, 'eco' => 2, 'eng' => 2, 'self' => 2, 'comp' => 2, 'math' => 1],
        2 => ['cs' => 5, 'prog' => 5, 'ai' => 4, 'net' => 3, 'ece' => 2, 'math' => 3, 'sci' => 1, 'comp' => 1],
        3 => ['prog' => 6, 'cs' => 4, 'net' => 2, 'ai' => 3, 'math' => 2, 'comp' => 1],
        4 => ['fin' => 6, 'eco' => 3, 'law' => 2, 'tax' => 3, 'mgmt' => 2, 'math' => 1, 'comp' => 1],
        5 => ['ai' => 5, 'math' => 4, 'prog' => 3, 'sci' => 2, 'env' => 1],
        6 => ['mkt' => 3, 'ai' => 3, 'comp' => 3, 'self' => 3, 'eng' => 2],
    ];
    $lendable = [];
    foreach ($books as $b) {
        if ($b['status'] === 'active' && $b['type'] !== 'reference' && !empty($copiesByBook[$b['id']])) {
            $lendable[$b['cat']][] = $b;
        }
    }
    $pickBook = function (?int $dept) use ($lendable, $deptCats) {
        $weights = $deptCats[$dept] ?? ['lit' => 2, 'self' => 3, 'hindi' => 2, 'comp' => 2, 'mgmt' => 1, 'jour' => 1];
        if (mt_rand(1, 100) <= 18) {
            $weights = ['lit' => 3, 'self' => 4, 'hindi' => 2, 'comp' => 3, 'jour' => 1, 'eng' => 1];
        }
        $weights = array_filter($weights, fn ($w, $k) => !empty($lendable[$k]), ARRAY_FILTER_USE_BOTH);
        $cat = demo_weighted($weights);
        $list = $lendable[$cat];
        $w = [];
        foreach ($list as $i => $b) {
            $w[$i] = max(1, $b['pop'] * $b['pop']);
        }
        return $list[demo_weighted($w)];
    };
    // Active reader pool (some students borrow a lot, many rarely)
    $studentMemberIds = array_keys(array_filter($memberById, fn ($m) => $m['type'] === 'student' && $m['status'] === 'active'));
    $facultyMemberIds = array_keys(array_filter($memberById, fn ($m) => $m['type'] === 'faculty'));
    $staffMemberIds = array_keys(array_filter($memberById, fn ($m) => $m['type'] === 'staff'));
    $readerWeight = [];
    foreach ($studentMemberIds as $id) {
        $readerWeight[$id] = demo_weighted([1 => 45, 3 => 30, 8 => 18, 20 => 7]);
    }

    $copyBusyUntil = []; // copy id => date it becomes free ('9999-12-31' = never)
    $memberLoans = [];   // member id => list of [book_id, end date]
    $txns = [];
    $start = date('Y-m-01', strtotime(date('Y-m-01') . ' -7 months')); // 8 full months, matching the dashboard trend window
    $vacationFrom = $session ? date('Y-m-d', strtotime($session['start_date'] . ' -30 days')) : null; // June break before the session
    for ($d = $start; $d <= $today; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $dow = (int) date('N', strtotime($d));
        if ($dow === 7) {
            continue;
        }
        $isVacation = $vacationFrom && $d >= $vacationFrom && $session && $d < $session['start_date'];
        $count = $isVacation ? mt_rand(1, 3) : ($dow === 6 ? mt_rand(2, 5) : mt_rand(5, 11));
        if ($d === $today) {
            $count = 6;
        }
        for ($k = 0; $k < $count; $k++) {
            $r = mt_rand(1, 100);
            $memberId = $r <= 14 ? demo_pick($facultyMemberIds) : ($r <= 18 && $staffMemberIds ? demo_pick($staffMemberIds) : demo_weighted($readerWeight));
            $m = $memberById[$memberId];
            if ($m['created'] > $d) {
                continue;
            }
            $open = array_filter($memberLoans[$memberId] ?? [], fn ($l) => $l[1] > $d);
            if (count($open) >= $m['max']) {
                continue;
            }
            $copyId = null;
            $book = null;
            for ($try = 0; $try < 6 && !$copyId; $try++) {
                $book = $pickBook($m['dept']);
                if (array_filter($open, fn ($l) => $l[0] === $book['id'])) {
                    continue;
                }
                foreach ($copiesByBook[$book['id']] as $cid) {
                    if (isset($withdrawn[$cid]) || $copyInfo[$cid]['acquired_date'] > $d) {
                        continue;
                    }
                    if (($copyBusyUntil[$cid] ?? '0000-00-00') <= $d) {
                        $copyId = $cid;
                        break;
                    }
                }
            }
            if (!$copyId) {
                continue;
            }
            $loanDays = $m['days'];
            $due = date('Y-m-d', strtotime("$d +$loanDays days"));
            $renew = 0;
            $status = 'returned';
            $return = null;
            $condition = 'good';
            // Outcome
            $daysSinceDue = (int) floor(($todayTs - strtotime($due)) / 86400);
            if ($daysSinceDue < 0) {
                // not yet due: returned early (only if issued a while ago) or still on loan
                $elapsed = (int) floor(($todayTs - strtotime($d)) / 86400);
                if ($elapsed >= 3 && mt_rand(1, 100) <= 38) {
                    $return = date('Y-m-d', strtotime("$d +" . mt_rand(2, $elapsed) . ' days'));
                } else {
                    $status = 'issued';
                }
            } else {
                $roll = mt_rand(1, 1000);
                $overdueChance = $daysSinceDue <= 45 ? 150 : ($daysSinceDue <= 90 ? 35 : 8);
                if ($roll <= $overdueChance) {
                    $status = 'issued'; // overdue, not returned yet
                } elseif ($roll <= $overdueChance + 12) {
                    $status = 'lost';
                    $return = min($today, date('Y-m-d', strtotime("$due +" . mt_rand(4, 30) . ' days')));
                } elseif ($roll <= $overdueChance + 70) {
                    // renewed once, then returned (or still on loan if the new due date is ahead)
                    $renew = 1;
                    $due = date('Y-m-d', strtotime("$due +$loanDays days"));
                    if ($due >= $today) {
                        $status = 'issued';
                    } else {
                        $return = date('Y-m-d', strtotime("$due -" . mt_rand(0, max(0, $loanDays - 3)) . ' days'));
                    }
                } elseif ($roll <= $overdueChance + 270) {
                    $late = demo_weighted([1 => 18, 2 => 16, 3 => 14, 5 => 14, 7 => 12, 10 => 10, 15 => 8, 22 => 5, 35 => 3]);
                    $return = min($day(-1), date('Y-m-d', strtotime("$due +$late days")));
                    if ($return <= $due) {
                        $return = $due;
                    }
                } else {
                    $return = date('Y-m-d', strtotime("$d +" . mt_rand(3, $loanDays) . ' days'));
                }
                if ($status === 'returned' && mt_rand(1, 100) <= 2) {
                    $condition = 'damaged';
                }
            }
            if ($return !== null && $return > $today) {
                $return = $today;
            }
            if ($return !== null && $return < $d) {
                $return = $d;
            }
            $end = in_array($status, ['issued', 'lost'], true) || $condition === 'damaged' ? '9999-12-31' : $return;
            $copyBusyUntil[$copyId] = $end;
            $memberLoans[$memberId][] = [$book['id'], $status === 'issued' ? '9999-12-31' : $return];
            $txns[] = ['copy' => $copyId, 'book' => $book['id'], 'member' => $memberId, 'issue' => $d, 'due' => $due, 'return' => $return, 'status' => $status, 'renew' => $renew,
                'condition' => $status === 'returned' ? $condition : null, 'price' => (float) $copyInfo[$copyId]['price']];
        }
    }

    // ---------------------------------------------------------------- write transactions + fines
    $waiveReasons = ['Medical certificate submitted for the overdue period', 'Student was on approved hostel leave during the due date', 'First delay — waived by the Chief Librarian',
        'Library closed on the due date (system downtime)', 'Faculty on official duty / examination work', 'Fee concession case approved by the Dean'];
    $modes = array_keys(lib_payment_modes());
    $modeWeights = array_intersect_key(['cash' => 55, 'upi' => 35, 'card' => 6, 'online' => 4], array_flip($modes)) ?: ['cash' => 1];
    $fineRows = [];
    $receiptSeq = 0;
    $sessionOf = function (string $date): string {
        $y = (int) substr($date, 0, 4);
        $mth = (int) substr($date, 5, 2);
        $start = $mth >= 7 ? $y : $y - 1;
        return $start . '-' . substr((string) ($start + 1), 2);
    };
    $at = fn (string $date, int $h1 = 9, int $h2 = 17) => $date . sprintf(' %02d:%02d:%02d', mt_rand($h1, $h2 - 1), mt_rand(0, 59), mt_rand(0, 59));
    $nowStamp = date('Y-m-d H:i:s');
    $txnRows = [];
    $txnFines = [];
    $txnReturned = [];
    foreach ($txns as $t) {
        $issuedAt = $at($t['issue']);
        if ($issuedAt > $nowStamp) {
            $issuedAt = date('Y-m-d H:i:s', time() - mt_rand(600, 7200));
        }
        $fine = 0.0;
        $fines = [];
        if ($t['status'] === 'returned' && $t['return'] > $t['due']) {
            $late = lib_compute_fine($t['due'], $t['return'], $rules);
            if ($late['amount'] > 0) {
                $fines[] = ['reason' => 'late_return', 'amount' => $late['amount'], 'days' => $late['days_late'], 'remarks' => 'Returned ' . $late['days_late'] . ' day(s) late at ₹' . (int) $rules['fine_per_day'] . '/day'];
            }
        }
        if ($t['condition'] === 'damaged') {
            $fines[] = ['reason' => 'damage', 'amount' => max(50, round($t['price'] * 0.25 / 10) * 10), 'days' => null, 'remarks' => demo_pick(['Water damage on cover and first pages', 'Pages torn / missing', 'Spine broken, needs rebinding'])];
        }
        if ($t['status'] === 'lost') {
            $fines[] = ['reason' => 'lost', 'amount' => round($t['price'] + $rules['lost_processing_fee'], 2), 'days' => null, 'remarks' => 'Book reported lost (replacement cost' . ($rules['lost_processing_fee'] > 0 ? ' + processing fee' : '') . ')'];
            $late = lib_compute_fine($t['due'], $t['return'], $rules);
            if ($late['amount'] > 0) {
                $fines[] = ['reason' => 'late_return', 'amount' => $late['amount'], 'days' => $late['days_late'], 'remarks' => 'Overdue ' . $late['days_late'] . ' day(s) before being reported lost'];
            }
        }
        $returnedAt = $t['return'] ? $at($t['return'], 10, 18) : null;
        if ($returnedAt && $returnedAt > $nowStamp) {
            $returnedAt = date('Y-m-d H:i:s', time() - mt_rand(60, 1800));
        }
        if ($returnedAt && $returnedAt < $issuedAt) {
            $returnedAt = $issuedAt;
        }
        $txnRows[] = [
            'book_copy_id' => $t['copy'], 'book_id' => $t['book'], 'member_id' => $t['member'], 'issue_date' => $t['issue'], 'due_date' => $t['due'], 'return_date' => $t['return'],
            'return_condition' => $t['condition'], 'renew_count' => $t['renew'], 'status' => $t['status'], 'fine_amount' => array_sum(array_column($fines, 'amount')),
            'issued_by' => mt_rand(0, 9) ? $librarian : $admin, 'returned_to' => $t['return'] ? (mt_rand(0, 9) ? $librarian : $admin) : null,
            'remarks' => $t['status'] === 'lost' ? 'Member reported the book lost' : ($t['condition'] === 'damaged' ? 'Returned damaged' : null),
            'reminder_count' => 0, 'last_reminded_at' => null, 'created_at' => $issuedAt, 'updated_at' => $returnedAt,
        ];
        $txnFines[] = $fines;
        $txnReturned[] = $returnedAt;
    }
    // overdue reminders
    foreach ($txnRows as $i => $r) {
        if ($r['status'] === 'issued' && $r['due_date'] < $today) {
            $daysOver = (int) floor(($todayTs - strtotime($r['due_date'])) / 86400);
            $cnt = min(3, 1 + intdiv($daysOver, 7));
            if ($daysOver >= 2) {
                $txnRows[$i]['reminder_count'] = $cnt;
                $txnRows[$i]['last_reminded_at'] = $at($day(-mt_rand(0, min(6, $daysOver - 1))));
                if ($txnRows[$i]['last_reminded_at'] > $nowStamp) {
                    $txnRows[$i]['last_reminded_at'] = $day(-1) . ' 10:15:00';
                }
            }
        }
    }
    demo_bulk_insert('library_transactions', $txnRows);
    $txnIds = db_column('SELECT id FROM library_transactions ORDER BY id');

    // fines: settle by recency (collected at the counter, waived, or pending)
    $pendingFines = [];
    foreach ($txnRows as $i => $r) {
        $fines = $txnFines[$i] ?? [];
        if (!$fines) {
            continue;
        }
        $returnedAt = $txnReturned[$i] ?? $nowStamp;
        $recent = $r['return_date'] >= $day(-10);
        $roll = mt_rand(1, 100);
        $hasLost = in_array('lost', array_column($fines, 'reason'), true);
        $action = $recent ? ($roll <= 55 ? 'paid' : ($roll <= 60 ? 'waived' : 'unpaid')) : ($roll <= ($hasLost ? 70 : 84) ? 'paid' : ($roll <= ($hasLost ? 76 : 91) ? 'waived' : 'unpaid'));
        if ($action === 'paid') {
            $paidAt = mt_rand(0, 4) ? $returnedAt : $at(min($today, date('Y-m-d', strtotime($r['return_date'] . ' +' . mt_rand(1, 12) . ' days'))));
            if ($paidAt > $nowStamp) {
                $paidAt = $returnedAt;
            }
            $pendingFines[] = ['i' => $i, 'fines' => $fines, 'status' => 'paid', 'at' => $paidAt, 'mode' => demo_weighted($modeWeights)];
        } else {
            $pendingFines[] = ['i' => $i, 'fines' => $fines, 'status' => $action, 'at' => $returnedAt, 'reason' => demo_pick($waiveReasons)];
        }
    }
    // A few manual fines (damage noticed in the reading room, duplicate library card)
    foreach (array_slice($studentMemberIds, 120, 4) as $k => $mid) {
        $createdAt = $at($day(-mt_rand(3, 60)));
        $pendingFines[] = ['i' => null, 'member' => $mid, 'status' => $k % 2 === 0 ? 'paid' : 'unpaid', 'at' => $createdAt, 'mode' => 'cash', 'fines' => [[
            'reason' => $k % 2 ? 'other' : 'damage', 'amount' => [100, 50, 150, 50][$k], 'days' => null,
            'remarks' => ['Defaced reading-room copy of a reference book', 'Duplicate library card issued', 'Highlighter marks on a reference copy', 'Duplicate library card issued'][$k],
        ]]];
    }
    usort($pendingFines, fn ($a, $b) => strcmp($a['at'], $b['at']));
    foreach ($pendingFines as $p) {
        $r = $p['i'] !== null ? $txnRows[$p['i']] : null;
        $receipt = null;
        if ($p['status'] === 'paid') {
            $receiptSeq++;
            $receipt = 'LFR/' . $sessionOf(substr($p['at'], 0, 10)) . '/' . str_pad((string) $receiptSeq, 5, '0', STR_PAD_LEFT);
        }
        foreach ($p['fines'] as $f) {
            $fineRows[] = [
                'transaction_id' => $r ? (int) $txnIds[$p['i']] : null, 'member_id' => $r ? $r['member_id'] : $p['member'], 'amount' => $f['amount'], 'reason' => $f['reason'],
                'days_overdue' => $f['days'], 'status' => $p['status'], 'paid_at' => $p['status'] === 'paid' ? $p['at'] : null, 'payment_mode' => $p['status'] === 'paid' ? $p['mode'] : null,
                'receipt_no' => $receipt, 'collected_by' => $p['status'] === 'paid' ? $librarian : null, 'waived_by' => $p['status'] === 'waived' ? $librarian : null,
                'waived_at' => $p['status'] === 'waived' ? $p['at'] : null, 'waive_reason' => $p['status'] === 'waived' ? $p['reason'] : null,
                'remarks' => $f['remarks'], 'created_at' => $r ? ($r['updated_at'] ?? $p['at']) : $p['at'], 'updated_at' => $p['status'] === 'unpaid' ? null : $p['at'],
            ];
        }
    }
    usort($fineRows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
    demo_bulk_insert('library_fines', $fineRows);

    // ---------------------------------------------------------------- final copy statuses + counters
    $copyStatus = [];
    foreach ($txnRows as $r) {
        if ($r['status'] === 'issued') {
            $copyStatus[$r['book_copy_id']] = ['issued', null];
        } elseif ($r['status'] === 'lost') {
            $copyStatus[$r['book_copy_id']] = ['lost', 'Lost by member on ' . date('d M Y', strtotime($r['return_date']))];
        } elseif ($r['return_condition'] === 'damaged') {
            $copyStatus[$r['book_copy_id']] = ['damaged', 'Returned damaged on ' . date('d M Y', strtotime($r['return_date'])) . ' — sent for rebinding'];
        }
    }
    foreach ($withdrawn as $cid => $_) {
        $copyStatus[$cid] = ['withdrawn', 'Weeded out — worn beyond repair'];
    }
    foreach (['issued', 'lost', 'damaged', 'withdrawn'] as $st) {
        $ids = array_keys(array_filter($copyStatus, fn ($v) => $v[0] === $st));
        foreach (array_chunk($ids, 400) as $chunk) {
            db_exec('UPDATE book_copies SET status = ? WHERE id IN (' . implode(',', array_map('intval', $chunk)) . ')', [$st]);
        }
    }
    foreach ($copyStatus as $cid => [$st, $remark]) {
        if ($remark) {
            db_exec('UPDATE book_copies SET remarks = ?, condition_note = IF(? = \'damaged\', \'poor\', condition_note) WHERE id = ?', [$remark, $st, $cid]);
        }
    }
    db_exec("UPDATE books b SET
                total_copies = (SELECT COUNT(*) FROM book_copies c WHERE c.book_id = b.id AND c.status NOT IN ('lost','withdrawn')),
                available_copies = (SELECT COUNT(*) FROM book_copies c WHERE c.book_id = b.id AND c.status = 'available')");

    // ---------------------------------------------------------------- sequences used by the app
    $setSeq = fn (string $name, int $v) => db_exec('INSERT INTO sequences (name, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = ?', [$name, $v, $v]);
    $setSeq('library_accession', count($copyRows));
    $setSeq('library_member', count($memberRows));
    $setSeq('library_fine_receipt', $receiptSeq);
    db()->commit();
};
