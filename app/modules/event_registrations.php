<?php
/** Event registrations (embedded under an event; scope event_id). Check-in actions: bulk_action hook + api/routes/events.php */
require_once APP_ROOT . '/app/services/communication.php';

return [
    'table' => 'event_registrations',
    'title' => 'Registrations',
    'singular' => 'Registration',
    'permission' => 'events',
    'icon' => 'ticket',
    'description' => 'Participants registered for events.',
    'select' => 't.*, e.title AS event_title, e.start_datetime AS event_start, u.name AS checked_in_by_name',
    'joins' => 'JOIN events e ON e.id = t.event_id LEFT JOIN users u ON u.id = t.checked_in_by',
    'search' => ['t.name', 't.email', 't.phone', 't.organization'],
    'order' => 't.created_at DESC, t.id DESC',
    'search_placeholder' => 'Search by name, e-mail, phone or organisation…',
    'scopes' => ['event_id' => 't.event_id'],
    'per_page' => 25,
    'columns' => [
        ['key' => 'name', 'label' => 'Participant', 'format' => 'person', 'sub' => 'email', 'sortable' => true],
        ['key' => 'phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'event_title', 'label' => 'Event', 'format' => 'text', 'sortable' => 'e.title'],
        ['key' => 'organization', 'label' => 'Organisation', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'participant_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true],
        ['key' => 'source', 'label' => 'Source', 'format' => 'badge', 'sortable' => true, 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Registered', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'checked_in_at', 'label' => 'Checked in', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'event_title', 'label' => 'Event'], ['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'phone', 'label' => 'Phone'],
        ['key' => 'organization', 'label' => 'Organisation'], ['key' => 'participant_type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'source', 'label' => 'Source', 'format' => 'badge'],
        ['key' => 'created_at', 'label' => 'Registered on', 'format' => 'datetime'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ['key' => 'checked_in_at', 'label' => 'Checked in at', 'format' => 'datetime'], ['key' => 'notes', 'label' => 'Notes'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['registered' => 'Registered', 'attended' => 'Checked in', 'cancelled' => 'Cancelled']],
        ['key' => 'participant_type', 'label' => 'Type', 'options' => comm_participant_types()],
        ['key' => 'source', 'label' => 'Source', 'options' => ['website' => 'Website', 'admin' => 'Added by staff', 'import' => 'Imported']],
        ['key' => 'event_id', 'label' => 'Event', 'source' => ['table' => 'events ev', 'value' => 'ev.id', 'label' => 'ev.title', 'sub' => "DATE_FORMAT(ev.start_datetime, '%d %b %Y')", 'order' => 'ev.start_datetime DESC', 'search' => ['ev.title']]],
    ],
    'fields' => [
        ['name' => 'event_id', 'label' => 'Event', 'type' => 'combobox', 'required' => true, 'col' => 12, 'readonly_on_edit' => true,
            'source' => ['table' => 'events ev', 'value' => 'ev.id', 'label' => 'ev.title', 'sub' => "DATE_FORMAT(ev.start_datetime, '%d %b %Y')", 'where' => "ev.status IN ('published', 'draft', 'completed')", 'order' => 'ev.start_datetime DESC', 'search' => ['ev.title']]],
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'col' => 6],
        ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'maxlength' => 190, 'col' => 6],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'col' => 6],
        ['name' => 'organization', 'label' => 'Organisation / college', 'type' => 'text', 'maxlength' => 190, 'col' => 6],
        ['name' => 'participant_type', 'label' => 'Participant type', 'type' => 'select', 'default' => 'guest', 'options' => comm_participant_types(), 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'registered', 'options' => ['registered' => 'Registered', 'attended' => 'Checked in', 'cancelled' => 'Cancelled'], 'col' => 6],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $eventId = (int) ($data['event_id'] ?? ($old['event_id'] ?? 0));
            $email = $data['email'] ?? ($old['email'] ?? null);
            if (!$eventId) {
                return $errors;
            }
            $event = db_row('SELECT * FROM events WHERE id = ?', [$eventId]);
            if (!$event) {
                $errors['event_id'] = 'Select a valid event.';
                return $errors;
            }
            if ($email && (int) db_value('SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND email = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$eventId, $email, $id] : [$eventId, $email])) {
                $errors['email'] = 'This e-mail address is already registered for the event.';
            }
            $status = $data['status'] ?? ($old['status'] ?? 'registered');
            $wasActive = $old && $old['status'] !== 'cancelled';
            if ($status !== 'cancelled' && !$wasActive) {
                if ($event['status'] === 'cancelled') {
                    $errors['event_id'] = 'This event is cancelled and cannot accept registrations.';
                } elseif ($event['max_participants']) {
                    $taken = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [$eventId]);
                    if ($taken >= (int) $event['max_participants']) {
                        $errors['event_id'] = 'The event is full (' . (int) $event['max_participants'] . ' seats). Increase the capacity to add more participants.';
                    }
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($id === null) {
                $importing = PHP_SAPI === 'cli' || (function_exists('api_path') && str_ends_with(api_path(), '/import'));
                $data['source'] = $importing ? 'import' : 'admin';
            }
            $status = $data['status'] ?? ($old['status'] ?? 'registered');
            if ($status === 'attended' && empty($old['checked_in_at'])) {
                $data['checked_in_at'] = date('Y-m-d H:i:s');
                $data['checked_in_by'] = user_id();
            } elseif ($status !== 'attended' && array_key_exists('status', $data)) {
                $data['checked_in_at'] = null;
                $data['checked_in_by'] = null;
            }
            if (!empty($data['email'])) {
                $data['email'] = strtolower((string) $data['email']);
            }
            return $data;
        },
        'bulk_action' => function (string $action, array $ids, array $input) {
            $map = ['check_in' => 'checked in', 'undo_check_in' => 'reverted to registered', 'cancel' => 'cancelled', 'restore' => 'restored'];
            if (!isset($map[$action])) {
                throw new CrudException('Unknown action.');
            }
            $n = comm_registration_action($ids, $action);
            return ['updated' => $n, 'message' => $n . ' registration' . ($n === 1 ? '' : 's') . ' ' . $map[$action] . '.'];
        },
        'describe' => fn (array $r) => '"' . ($r['name'] ?? '') . '" (' . ($r['email'] ?? '') . ')',
    ],
];
