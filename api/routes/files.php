<?php
/**
 * Authenticated download of private files (storage/private/...).
 *   GET /api/files/download?path=storage/private/student-docs/2026/10/abc.pdf&name=Marksheet.pdf
 *
 * Access rule: the path must be referenced by a row the user is allowed to view. Each module registers
 * which table/column holds private paths in private_file_owners() below.
 */

function private_file_owners(): array
{
    // table => [column, permission module]
    return [
        'student_documents' => ['file_path', 'students'],
        'admission_documents' => ['file_path', 'admissions'],
        'employee_documents' => ['file_path', 'faculty'],
        'expenses' => ['attachment', 'expenses'],
        'placement_applications' => ['resume_path', 'placement'],
        'placement_offers' => ['offer_letter', 'placement'],
    ];
}

route('GET', '/files/download', function () {
    $path = (string) ($_GET['path'] ?? '');
    if (!str_starts_with($path, 'storage/private/') || str_contains($path, '..') || str_contains($path, "\0")) {
        api_error('Invalid file path.', 400);
    }
    $allowed = false;
    $owners = private_file_owners();
    // CRUD module fields marked 'private' => true are registered automatically.
    foreach (crud_module_keys() as $key) {
        $m = crud_module($key);
        foreach ($m['fields'] as $f) {
            if (!empty($f['private']) && isset($f['name'])) {
                $owners[$m['table'] . '#' . $f['name']] = [$f['name'], $m['permission'], $m['table']];
            }
        }
    }
    foreach ($owners as $table => $def) {
        [$column, $module] = $def;
        $table = $def[2] ?? $table;
        if (can($module, 'view') && db_table_exists($table) && db_value('SELECT COUNT(*) FROM ' . db_quote_ident($table) . ' WHERE ' . db_quote_ident($column) . ' = ?', [$path])) {
            $allowed = true;
            break;
        }
    }
    $abs = APP_ROOT . '/' . $path;
    if (!$allowed || !is_file($abs)) {
        api_error('File not found or access denied.', 404);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($abs) ?: 'application/octet-stream';
    $name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', (string) ($_GET['name'] ?? basename($abs)));
    $inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) && empty($_GET['download']);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($abs);
    exit;
});
