<?php
/** Generic CRUD endpoints for every module in app/modules (see app/crud.php). */

function crud_api_module(string $key, string $action): array
{
    if (!crud_module_exists($key)) {
        api_error('Unknown module: ' . $key, 404);
    }
    $m = crud_module($key);
    if (!crud_can($m, $action)) {
        api_error('You do not have permission to ' . $action . ' ' . strtolower($m['title']) . '.', 403);
    }
    return $m;
}

route('GET', '/crud/{module}/meta', function ($p) {
    $m = crud_api_module($p['module'], 'view');
    api_ok(crud_meta($m));
});

route('GET', '/crud/{module}', function ($p) {
    $m = crud_api_module($p['module'], 'view');
    api_ok(crud_list($m, $_GET));
});

route('GET', '/crud/{module}/export', function ($p) {
    $m = crud_api_module($p['module'], 'export');
    $format = in_array($_GET['format'] ?? 'csv', ['csv', 'xlsx', 'print', 'pdf'], true) ? $_GET['format'] : 'csv';
    crud_export($m, $_GET, $format);
});

route('GET', '/crud/{module}/import-template', function ($p) {
    $m = crud_api_module($p['module'], 'import');
    crud_import_template($m);
});

route('POST', '/crud/{module}/import', function ($p) {
    $m = crud_api_module($p['module'], 'import');
    if (empty($m['import'])) {
        api_error('Import is not available for ' . strtolower($m['title']) . '.', 400);
    }
    if (empty($_FILES['file'])) {
        api_error('Choose a CSV or Excel file to import.', 422, ['file' => 'Choose a CSV or Excel file to import.']);
    }
    $summary = crud_import($m, $_FILES['file'], api_param('mode', 'insert') === 'upsert' ? 'upsert' : 'insert');
    api_ok($summary, sprintf('Import finished: %d added, %d updated, %d skipped, %d failed.', $summary['inserted'], $summary['updated'], $summary['skipped'], $summary['failed']));
});

route('GET', '/crud/{module}/options/{field}', function ($p) {
    $m = crud_api_module($p['module'], 'view');
    $name = $p['field'];
    $def = null;
    if (str_starts_with($name, 'filter:')) {
        foreach ($m['filters'] as $f) {
            if ($f['key'] === substr($name, 7)) {
                $def = $f;
            }
        }
    } else {
        $def = crud_field($m, $name);
    }
    if (!$def || (!isset($def['source']) && !isset($def['options']))) {
        api_error('Unknown field.', 404);
    }
    $params = $_GET;
    // Map dependent parent values: depends = ['program_id' => 'program_id'] (source filter param => form field)
    api_ok(crud_definition_options($def, $params));
});

route('POST', '/crud/{module}/bulk', function ($p) {
    $in = api_input();
    $action = (string) ($in['action'] ?? '');
    $ids = (array) ($in['ids'] ?? []);
    if (!$ids) {
        api_error('Select at least one record.', 422);
    }
    if ($action === 'delete') {
        $m = crud_api_module($p['module'], 'delete');
        if (empty($m['bulk']['delete'])) {
            api_error('Bulk delete is not allowed here.', 400);
        }
        $res = crud_delete($m, $ids);
        $msg = $res['deleted'] . ' ' . strtolower($res['deleted'] === 1 ? $m['singular'] : $m['title']) . ' deleted.';
        if ($res['errors']) {
            $msg .= ' ' . count($res['errors']) . ' could not be deleted.';
        }
        api_ok($res, $msg);
    }
    if ($action === 'status') {
        $m = crud_api_module($p['module'], 'edit');
        $n = crud_bulk_update($m, $ids, (string) ($in['field'] ?? ($m['bulk']['status_field'] ?? 'status')), (string) ($in['value'] ?? ''));
        api_ok(['updated' => $n], $n . ' record(s) updated.');
    }
    $m = crud_api_module($p['module'], 'edit');
    if ($hook = crud_hook($m, 'bulk_action')) {
        $result = $hook($action, array_map('intval', $ids), $in);
        api_ok($result, is_array($result) && isset($result['message']) ? $result['message'] : 'Done.');
    }
    api_error('Unknown bulk action.', 400);
});

route('GET', '/crud/{module}/{id:\d+}', function ($p) {
    $m = crud_api_module($p['module'], 'view');
    $row = crud_find($m, (int) $p['id']);
    if (!$row) {
        api_error($m['singular'] . ' not found.', 404);
    }
    api_ok(['row' => crud_public_row($m, $row)] + crud_form_payload($m, $row));
});

route('POST', '/crud/{module}', function ($p) {
    $m = crud_api_module($p['module'], 'create');
    $res = crud_save($m, api_input(), $_FILES);
    $row = crud_find($m, $res['id']);
    api_ok(['id' => $res['id'], 'row' => $row ? crud_public_row($m, $row) : null], $m['singular'] . ' created successfully.', 201);
});

$update = function ($p) {
    $m = crud_api_module($p['module'], 'edit');
    $res = crud_save($m, api_input(), $_FILES, (int) $p['id']);
    $row = crud_find($m, $res['id']);
    api_ok(['id' => $res['id'], 'row' => $row ? crud_public_row($m, $row) : null], $m['singular'] . ' updated successfully.');
};
route('POST', '/crud/{module}/{id:\d+}', $update);
route('PUT', '/crud/{module}/{id:\d+}', $update);
route('PATCH', '/crud/{module}/{id:\d+}', $update);

route('DELETE', '/crud/{module}/{id:\d+}', function ($p) {
    $m = crud_api_module($p['module'], 'delete');
    $res = crud_delete($m, [(int) $p['id']]);
    if ($res['errors']) {
        api_error(reset($res['errors']), 409);
    }
    if (!$res['deleted']) {
        api_error($m['singular'] . ' not found.', 404);
    }
    api_ok(null, $m['singular'] . ' deleted successfully.');
});
