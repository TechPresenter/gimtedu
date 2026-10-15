<?php
/**
 * Shared form helpers and option lists.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** India state list for address forms. */
function indian_states(): array
{
    return ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh',
        'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana',
        'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep',
        'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry',
        'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];
}

function state_options(string $selected = ''): string
{
    $html = '<option value="">Select state</option>';
    foreach (indian_states() as $s) {
        $html .= '<option value="' . e($s) . '"' . ($s === $selected ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    return $html;
}

/** Shared lists for forms. */
const GENDERS = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'prefer_not' => 'Prefer not to say'];

const VOLUNTEER_INTERESTS = [
    'Education', 'Health', 'Women Empowerment', 'Youth Empowerment', 'Rural Development', 'Environment Protection',
    'Skill Development', 'Disaster Relief', 'Events & Campaigns', 'Fundraising', 'Social Media & Design', 'Photography & Documentation',
];

const HELP_CATEGORIES = [
    'education' => 'Education support', 'health' => 'Health / medical support', 'women' => 'Women empowerment / SHG support',
    'livelihood' => 'Skill training or livelihood', 'food' => 'Food / ration support', 'disaster' => 'Disaster relief', 'other' => 'Other',
];

const AVAILABILITY_OPTIONS = ['Weekends', 'Weekdays', 'Flexible', 'A few hours a month', 'Full-time (internship)', 'Online / remote only'];

/**
 * Personal detail fields shared by application forms.
 * $req: list of field names that are required.
 */
function render_personal_fields(?array $user, array $req = ['name', 'email', 'phone', 'city'], array $skip = []): void
{
    $field = function (string $name, string $label, string $input) use ($req): void {
        $star = in_array($name, $req, true) ? '<span class="req">*</span>' : '';
        echo '<div class="form-group"><label for="' . $name . '">' . e($label) . $star . '</label>' . $input . field_error($name) . '</div>';
    };
    $r = function (string $name) use ($req): string {
        return in_array($name, $req, true) ? ' required' : '';
    };

    if (!in_array('name', $skip, true)) {
        $field('name', 'Full name', '<input id="name" type="text" name="name" maxlength="120" autocomplete="name"' . $r('name') . ' value="' . e(old('name', $user['name'] ?? '')) . '">');
    }
    $field('email', 'Email', '<input id="email" type="email" name="email" maxlength="190" autocomplete="email"' . $r('email') . ' value="' . e(old('email', $user['email'] ?? '')) . '">');
    $field('phone', 'Mobile number', '<input id="phone" type="tel" name="phone" maxlength="15" autocomplete="tel"' . $r('phone') . ' value="' . e(old('phone', $user['phone'] ?? '')) . '">');
    if (!in_array('date_of_birth', $skip, true)) {
        $field('date_of_birth', 'Date of birth', '<input id="date_of_birth" type="date" name="date_of_birth" max="' . date('Y-m-d') . '"' . $r('date_of_birth') . ' value="' . e(old('date_of_birth')) . '">');
    }
    if (!in_array('gender', $skip, true)) {
        $options = '<option value="">Select</option>';
        foreach (GENDERS as $k => $v) {
            $options .= '<option value="' . $k . '"' . old_selected('gender', $k) . '>' . e($v) . '</option>';
        }
        $field('gender', 'Gender', '<select id="gender" name="gender"' . $r('gender') . '>' . $options . '</select>');
    }
    echo '<div class="form-group full"><label for="address">Address' . (in_array('address', $req, true) ? '<span class="req">*</span>' : '') . '</label>'
        . '<input id="address" type="text" name="address" maxlength="255" autocomplete="street-address"' . $r('address') . ' value="' . e(old('address', $user['address'] ?? '')) . '">' . field_error('address') . '</div>';
    $field('city', 'City / Town', '<input id="city" type="text" name="city" maxlength="80" autocomplete="address-level2"' . $r('city') . ' value="' . e(old('city', $user['city'] ?? '')) . '">');
    $field('state', 'State', '<select id="state" name="state"' . $r('state') . '>' . state_options((string) old('state', $user['state'] ?? '')) . '</select>');
    $field('pincode', 'PIN code', '<input id="pincode" type="text" name="pincode" inputmode="numeric" maxlength="6" data-rule="pincode" autocomplete="postal-code"' . $r('pincode') . ' value="' . e(old('pincode', $user['pincode'] ?? '')) . '">');
}

/** Validation rules matching render_personal_fields(). */
function personal_rules(array $req = ['name', 'email', 'phone', 'city']): array
{
    $rules = [
        'name' => 'min:2|max:120', 'email' => 'email', 'phone' => 'phone', 'date_of_birth' => 'date|past',
        'gender' => 'in:' . implode(',', array_keys(GENDERS)), 'address' => 'min:5|max:255', 'city' => 'min:2|max:80',
        'state' => 'in:' . implode(',', indian_states()), 'pincode' => 'pincode',
    ];
    foreach ($req as $f) {
        $rules[$f] = 'required|' . $rules[$f];
    }
    return $rules;
}

/** Option list helper. */
function options_html(array $options, string $selected, bool $useKeys = true, string $placeholder = 'Select'): string
{
    $html = $placeholder !== '' ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($options as $key => $label) {
        $value = $useKeys ? (string) $key : (string) $label;
        $html .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

/** Checkbox "chips" for multi-select options. */
function chips_html(string $name, array $options, array $selected, bool $groupRequired = false): string
{
    $html = '<div class="check-group">';
    foreach ($options as $opt) {
        $html .= '<label class="check-chip"><input type="checkbox" name="' . e($name) . '[]" value="' . e($opt) . '"'
            . (in_array($opt, $selected, true) ? ' checked' : '') . ($groupRequired ? ' data-group-required="1"' : '') . '><span>' . e($opt) . '</span></label>';
    }
    return $html . '</div>';
}

/** Open staff positions configured by the admin (one per line: Title | Description). */
function staff_positions(): array
{
    $positions = [];
    foreach (preg_split('/\r?\n/', setting('staff_positions')) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        [$title, $desc] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        $positions[] = ['title' => mb_substr($title, 0, 120), 'description' => $desc];
    }
    return $positions;
}
