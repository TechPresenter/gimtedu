<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('campaigns');

$id = (int) ($_POST['id'] ?? 0);
$back = $id ? 'admin/campaign-edit.php?id=' . $id : 'admin/campaign-add.php';
csrf_verify($back);

$existing = $id ? db_row('SELECT * FROM campaigns WHERE id = ?', [$id]) : null;
if ($id && !$existing) {
    flash('error', 'Campaign not found.');
    admin_redirect('campaigns.php');
}

[$data, $errors] = validate($_POST, [
    'title'             => 'required|min:3|max:200',
    'slug'              => 'max:200',
    'short_description' => 'required|text|min:10|max:300',
    'description'       => 'required|text|min:10|max:100000',
    'status'            => 'required|in:draft,active,completed,closed',
    'category'          => 'required|in:' . implode(',', array_keys(campaign_categories())),
    'goal_amount'       => 'required|numeric|min:0|max:1000000000',
    'location'          => 'max:150',
    'start_date'        => 'date',
    'end_date'          => 'date',
], ['short_description' => 'Short description', 'goal_amount' => 'Goal amount']);

if (!$errors && $data['start_date'] && $data['end_date'] && $data['end_date'] < $data['start_date']) {
    $errors['end_date'] = 'End date cannot be before the start date.';
}
if (!$errors && $data['slug'] !== '' && !preg_match('/^[a-z0-9\-]+$/', $data['slug'])) {
    $errors['slug'] = 'Slug may contain only lowercase letters, numbers and hyphens.';
}
$upload = [];
if (!$errors) {
    $upload = handle_upload('image', 'image', 'campaigns');
    if (isset($upload['error'])) {
        $errors['image'] = $upload['error'];
    }
}
if ($errors) {
    back_with_errors($errors, $back, $_POST);
}

$row = [
    'title'             => $data['title'],
    'slug'              => unique_slug('campaigns', $data['slug'] !== '' ? $data['slug'] : $data['title'], $id),
    'category'          => $data['category'],
    'short_description' => $data['short_description'],
    'description'       => clean_html($data['description']),
    'goal_amount'       => round((float) $data['goal_amount'], 2),
    'location'          => $data['location'] ?: null,
    'start_date'        => $data['start_date'] ?: null,
    'end_date'          => $data['end_date'] ?: null,
    'status'            => $data['status'],
    'is_featured'       => !empty($_POST['is_featured']) ? 1 : 0,
    'is_urgent'         => !empty($_POST['is_urgent']) ? 1 : 0,
    'updated_by'        => admin_id(),
];
if (!empty($upload['path'])) {
    $row['image'] = $upload['path'];
    if ($existing) {
        delete_upload($existing['image']);
    }
} elseif ($existing && !empty($_POST['remove_image'])) {
    $row['image'] = null;
    delete_upload($existing['image']);
}

if ($existing) {
    db_update('campaigns', $row, 'id = :id', ['id' => $id]);
    admin_log('campaign_updated', 'Campaign #' . $id . ' ' . $data['title'] . ' (' . $data['status'] . ')');
    flash('success', 'Campaign updated.');
} else {
    $row['created_by'] = admin_id();
    $id = db_insert('campaigns', $row);
    admin_log('campaign_created', 'Campaign #' . $id . ' ' . $data['title']);
    flash('success', 'Campaign created.' . ($data['status'] === 'draft' ? ' It is saved as a draft — set the status to Active to publish it.' : ''));
}
admin_redirect('campaign-view.php?id=' . $id);
