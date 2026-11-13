<?php
/** Small shared pieces of the LMS pages: class colours and banner, tabs, dates, status pills, file lists. */
declare(strict_types=1);

function lms_hue(string $key): int { return (int) (hexdec(substr(md5($key), 0, 2)) * 360 / 255); }

function lms_when(?string $dt, bool $time = true): string
{
    if (!$dt) return '';
    $ts = strtotime($dt); $today = date('Y-m-d');
    $day = date('Y-m-d', $ts) === $today ? 'Today' : (date('Y-m-d', $ts) === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('M j' . (date('Y', $ts) !== date('Y') ? ', Y' : ''), $ts));
    return $day . ($time ? ', ' . date('g:i A', $ts) : '');
}

function lms_pts($n): string { return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'); }

/** The coloured class header with tabs (Stream, Classwork, People, Attendance, Grades, Settings). */
function lms_class_header(array $c, string $tab): void
{
    $teach = in_array($c['role'], ['TEACHER', 'ADMIN'], true);
    $tabs = ['stream' => 'Stream', 'classwork' => 'Classwork', 'people' => 'People', 'attendance' => 'Attendance', 'grades' => 'Grades'] + ($teach ? ['settings' => 'Settings'] : []);
    echo '<div class="lms-banner" style="--h:' . lms_hue($c['subjectCode'] . $c['sectionName']) . '"><div><span class="lms-banner-code">' . h($c['subjectCode']) . ' · ' . h($c['sectionName']) . '</span>'
        . '<h1>' . h($c['subjectName']) . '</h1><p>' . h($c['teacherName']) . ' · ' . h($c['term']) . ' ' . h($c['schoolYear']) . ($c['role'] === 'ADMIN' ? ' · <b>coordinator view</b>' : '') . '</p></div>'
        . '<i class="bi bi-mortarboard lms-banner-art" aria-hidden="true"></i></div>';
    echo '<nav class="lms-tabs" aria-label="Class">';
    foreach ($tabs as $k => $label) echo '<a href="' . h(url('class', ['id' => $c['id'], 'tab' => $k])) . '"' . ($k === $tab ? ' class="active" aria-current="page"' : '') . '>' . h($label) . '</a>';
    echo '</nav>';
}

function lms_files_html(array $files, bool $removable = false, string $classId = '', int $workId = 0): string
{
    if (!$files) return '';
    $out = '<ul class="lms-files">';
    foreach ($files as $f) {
        $icon = str_starts_with($f['mime'], 'image/') ? 'file-earmark-image' : (str_contains($f['mime'], 'pdf') ? 'file-earmark-pdf' : (str_contains($f['mime'], 'word') ? 'file-earmark-word' : (str_contains($f['mime'], 'sheet') || str_contains($f['mime'], 'excel') ? 'file-earmark-excel' : (str_contains($f['mime'], 'presentation') || str_contains($f['mime'], 'powerpoint') ? 'file-earmark-slides' : 'file-earmark'))));
        $out .= '<li><a href="' . h(url('file', ['id' => $f['id']])) . '"><i class="bi bi-' . $icon . '" aria-hidden="true"></i><span>' . h($f['name']) . '<small>' . h(number_format($f['size_bytes'] / 1024, 0)) . ' KB</small></span></a>';
        if ($removable) $out .= '<form method="post"><input type="hidden" name="_csrf" value="' . h(Auth::csrfToken()) . '"><input type="hidden" name="action" value="remove_file"><input type="hidden" name="file" value="' . (int) $f['id'] . '"><button class="lms-x" aria-label="Remove ' . h($f['name']) . '"><i class="bi bi-x-lg"></i></button></form>';
        $out .= '</li>';
    }
    return $out . '</ul>';
}

function lms_state_pill(array $state): string { return '<span class="lms-pill is-' . h($state[1]) . '">' . h($state[0]) . '</span>'; }

function lms_text(?string $t): string { return $t === null || $t === '' ? '' : '<div class="lms-text">' . nl2br(h($t)) . '</div>'; }
