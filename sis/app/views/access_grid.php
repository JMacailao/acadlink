<?php
declare(strict_types=1);

/**
 * The None / View / Edit grid per system and module (Users and Groups).
 * $current: permissions (code => scope) the grid starts from. $group: the group's permissions when editing one
 * person; each module then also offers "Same as group" and shows CUSTOM where the person differs.
 */
function access_grid(array $systems, array $current, ?array $group = null, array $overrides = []): string
{
    $over = array_column($overrides, null, 'module_key');
    $out = '';
    foreach ($systems as $system) {
        $rows = '';
        foreach (ModuleAccess::catalog() as $key => [$sys, $label, , , $extras]) {
            if ($sys !== $system) continue;
            $now = ModuleAccess::level($current, $key);
            $id = 'acc_' . str_replace('.', '_', $key);
            $opts = '';
            if ($group !== null) {
                $g = ModuleAccess::level($group, $key);
                $opts .= '<option value=""' . (!isset($over[$key]) ? ' selected' : '') . '>Same as group: ' . h(ModuleAccess::levelsFor($key)[$g['level']] ?? 'No access') . '</option>';
            }
            foreach (ModuleAccess::levelsFor($key) as $lv => $text) {
                $sel = $group !== null ? (isset($over[$key]) && $over[$key]['level'] === $lv) : $now['level'] === $lv;
                $opts .= '<option value="' . $lv . '"' . ($sel ? ' selected' : '') . '>' . h($text) . '</option>';
            }
            $x = '';
            foreach ($extras as $code => $text) {
                $x .= '<label class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" name="x[' . h($key) . '][]" value="' . h($code) . '"' . (in_array($code, $now['extras'], true) ? ' checked' : '') . '> ' . h($text) . '</label>';
            }
            $custom = $group !== null && isset($over[$key]) ? ' <span class="badge text-bg-warning">CUSTOM</span>' : '';
            $rows .= '<tr><th scope="row" class="fw-normal"><label for="' . $id . '">' . h($label) . '</label>' . $custom . '</th>'
                . '<td><select class="form-select form-select-sm" id="' . $id . '" name="m[' . h($key) . ']">' . $opts . '</select>'
                . ($x !== '' ? '<div class="mt-1 access-extras" title="Extra actions; they apply with View and edit">' . $x . '</div>' : '') . '</td></tr>';
        }
        $out .= '<h3 class="h6 mt-3 mb-1">' . h(ModuleAccess::SYSTEMS[$system]) . '</h3><div class="table-responsive"><table class="table table-sm align-middle mb-1 access-grid"><thead><tr><th>Module</th><th style="width:55%">Access</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
    return $out;
}

/** The systems a group or account can be given: students the Student Portal, staff the Grading System and SIS. */
function access_systems(string $kindOrGroup): array
{
    return in_array($kindOrGroup, ['STUDENT'], true) ? ['PORTAL', 'LMS'] : ['GRADING', 'SIS', 'LMS'];
}

/** Reads the posted grid into module => [level, extras]. Blank level = "Same as group". */
function access_posted(array $post): array
{
    $out = [];
    foreach ((array) ($post['m'] ?? []) as $key => $level) {
        if (!is_string($key) || !isset(ModuleAccess::catalog()[$key])) continue;
        $level = is_string($level) ? $level : '';
        if ($level !== '' && !isset(ModuleAccess::levelsFor($key)[$level])) throw new AppError('Choose an access level for ' . ModuleAccess::catalog()[$key][1] . '.');
        $extras = array_values(array_filter((array) ($post['x'][$key] ?? []), 'is_string'));
        $out[$key] = [$level, $extras];
    }
    return $out;
}
