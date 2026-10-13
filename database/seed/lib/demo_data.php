<?php
/**
 * Shared helpers for demo seeders: deterministic random Indian names, phones, dates, picks.
 * Usage inside a seeder:  require_once __DIR__ . '/lib/demo_data.php';
 */

if (function_exists('demo_pick')) {
    return;
}

function demo_seed(int $seed): void
{
    mt_srand($seed);
}

function demo_pick(array $items)
{
    return $items[mt_rand(0, count($items) - 1)];
}

/** Weighted pick: ['present' => 85, 'absent' => 10, 'late' => 5] */
function demo_weighted(array $weights)
{
    $total = array_sum($weights);
    $r = mt_rand(1, (int) $total);
    foreach ($weights as $key => $w) {
        $r -= $w;
        if ($r <= 0) {
            return $key;
        }
    }
    return array_key_first($weights);
}

function demo_first_names(string $gender): array
{
    return $gender === 'female'
        ? ['Aanya', 'Ananya', 'Aditi', 'Anjali', 'Ishita', 'Kavya', 'Meera', 'Neha', 'Pooja', 'Priya', 'Riya', 'Sakshi', 'Sanya', 'Shreya', 'Sneha', 'Tanvi', 'Vanshika', 'Divya', 'Nisha', 'Simran', 'Muskan', 'Khushi', 'Aarohi', 'Diya', 'Isha', 'Jhanvi', 'Kriti', 'Mansi', 'Navya', 'Palak', 'Radhika', 'Sanjana', 'Tanya', 'Yashika', 'Zoya', 'Bhavya', 'Charu', 'Gauri', 'Hina', 'Nandini']
        : ['Aarav', 'Aditya', 'Akash', 'Aman', 'Arjun', 'Rohit', 'Rahul', 'Vikram', 'Karan', 'Kunal', 'Manish', 'Nikhil', 'Pranav', 'Rohan', 'Sahil', 'Siddharth', 'Varun', 'Vivek', 'Yash', 'Harsh', 'Ishaan', 'Kabir', 'Dev', 'Dhruv', 'Ayush', 'Abhishek', 'Ankit', 'Gaurav', 'Mohit', 'Naveen', 'Pankaj', 'Raghav', 'Saurabh', 'Shubham', 'Tushar', 'Utkarsh', 'Vansh', 'Aryan', 'Kartik', 'Lakshay'];
}

function demo_last_names(): array
{
    return ['Sharma', 'Verma', 'Gupta', 'Singh', 'Kumar', 'Agarwal', 'Jain', 'Mehta', 'Patel', 'Mishra', 'Pandey', 'Srivastava', 'Yadav', 'Chauhan', 'Rathore',
        'Malhotra', 'Kapoor', 'Khanna', 'Bansal', 'Goel', 'Saxena', 'Tiwari', 'Dubey', 'Joshi', 'Rawat', 'Negi', 'Bhatia', 'Arora', 'Sethi', 'Chopra',
        'Nair', 'Iyer', 'Reddy', 'Rao', 'Das', 'Bose', 'Sen', 'Ahmed', 'Khan', 'Siddiqui', 'Thakur', 'Tyagi', 'Garg', 'Mittal', 'Kohli', 'Bhardwaj'];
}

function demo_city(): array
{
    return demo_pick([
        ['Greater Noida', 'Uttar Pradesh', '201310'], ['Noida', 'Uttar Pradesh', '201301'], ['Ghaziabad', 'Uttar Pradesh', '201001'], ['Delhi', 'Delhi', '110092'],
        ['Delhi', 'Delhi', '110085'], ['Meerut', 'Uttar Pradesh', '250001'], ['Agra', 'Uttar Pradesh', '282001'], ['Lucknow', 'Uttar Pradesh', '226001'],
        ['Gurugram', 'Haryana', '122001'], ['Faridabad', 'Haryana', '121001'], ['Aligarh', 'Uttar Pradesh', '202001'], ['Dehradun', 'Uttarakhand', '248001'],
        ['Jaipur', 'Rajasthan', '302001'], ['Patna', 'Bihar', '800001'], ['Bulandshahr', 'Uttar Pradesh', '203001'], ['Mathura', 'Uttar Pradesh', '281001'],
    ]);
}

function demo_phone(): string
{
    return '+91 ' . demo_pick(['98', '97', '96', '95', '94', '93', '91', '88', '87', '86', '85', '78', '79', '70', '73', '76']) . mt_rand(10000000, 99999999);
}

function demo_date(string $from, string $to): string
{
    $a = strtotime($from);
    $b = strtotime($to);
    return date('Y-m-d', mt_rand(min($a, $b), max($a, $b)));
}

function demo_datetime(string $from, string $to): string
{
    $a = strtotime($from);
    $b = strtotime($to);
    return date('Y-m-d H:i:s', mt_rand(min($a, $b), max($a, $b)));
}

function demo_email(string $first, string $last, string $domain = 'gmail.com', int $n = 0): string
{
    return strtolower(preg_replace('/[^a-z]/i', '', $first) . '.' . preg_replace('/[^a-z]/i', '', $last) . ($n ? $n : '') . '@' . $domain);
}

/** Bulk insert rows (all rows must share the same keys). */
function demo_bulk_insert(string $table, array $rows, int $chunk = 300): void
{
    if (!$rows) {
        return;
    }
    $cols = array_keys($rows[0]);
    $colSql = implode(', ', array_map('db_quote_ident', $cols));
    foreach (array_chunk($rows, $chunk) as $part) {
        $placeholders = [];
        $params = [];
        foreach ($part as $row) {
            $placeholders[] = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            foreach ($cols as $c) {
                $params[] = $row[$c];
            }
        }
        db_query('INSERT INTO ' . db_quote_ident($table) . " ($colSql) VALUES " . implode(', ', $placeholders), $params);
    }
}
