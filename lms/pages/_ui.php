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

// ------------------------------------------------------------------ v61.1 design pieces

/** Reports and Settings are teacher pages; everything else is for everyone in the LMS. */
function lms_nav_allows(string $route): bool
{
    if (!in_array($route, ['reports', 'settings'], true)) return true;
    $me = Lms::me();
    return (bool) ($me['teacher'] || $me['admin']);
}

/** Unread messages for the menu badge (counted once per page). */
function lms_unread(): int
{
    static $n = null;
    if ($n === null) { try { $n = Auth::can('lms.view') ? Lms::unreadMessages(Lms::myClasses()) : 0; } catch (Throwable) { $n = 0; } }
    return $n;
}

/** Whether the signed-in person teaches (or coordinates) any of these classes. */
function lms_teaches(array $classes): bool { return (bool) array_filter($classes, fn($c) => $c['role'] !== 'STUDENT'); }

/** Page heading: small kicker, big title, a line under it, and optional actions on the right. */
function lms_head(string $title, string $sub = '', string $kicker = '', string $actions = ''): void
{
    echo '<header class="lms-head"><div>' . ($kicker !== '' ? '<span class="lms-kicker">' . h($kicker) . '</span>' : '') . '<h1 class="lms-h1">' . h($title) . '</h1>'
        . ($sub !== '' ? '<p class="lms-muted mb-0">' . h($sub) . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="lms-head-actions">' . $actions . '</div>' : '') . '</header>';
}

/** A number card: icon, label, big value, small line under it; a link when $href is given. */
function lms_stat(string $icon, string $label, string $value, string $sub, string $href = '', string $labelSmall = ''): string
{
    $in = '<span class="lms-stat-icon"><i class="bi bi-' . h($icon) . '" aria-hidden="true"></i></span><span class="lms-stat-text"><span class="lms-stat-label">' . h($label)
        . ($labelSmall !== '' ? ' <small>(' . h($labelSmall) . ')</small>' : '') . '</span><b>' . h($value) . '</b><small>' . h($sub) . '</small></span>';
    return $href !== '' ? '<a class="lms-stat" href="' . h($href) . '">' . $in . '</a>' : '<div class="lms-stat">' . $in . '</div>';
}

/** A panel heading with an icon and an optional "View all ›" link. */
function lms_panel_head(string $icon, string $title, string $link = '', string $linkText = 'View all', string $small = ''): string
{
    return '<div class="lms-ph"><h2><i class="bi bi-' . h($icon) . '" aria-hidden="true"></i>' . h($title) . ($small !== '' ? ' <small>(' . h($small) . ')</small>' : '') . '</h2>'
        . ($link !== '' ? '<a href="' . h($link) . '">' . h($linkText) . '<i class="bi bi-chevron-right" aria-hidden="true"></i></a>' : '') . '</div>';
}

/** File type badge (PDF, DOCX, PPTX, …) coloured like the file kind. */
function lms_file_badge(string $name, string $mime): string
{
    $ext = strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'FILE');
    $kind = str_contains($mime, 'pdf') ? 'pdf' : (str_contains($mime, 'word') ? 'doc' : (str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint') ? 'ppt'
        : (str_contains($mime, 'sheet') || str_contains($mime, 'excel') || str_contains($mime, 'csv') ? 'xls' : (str_starts_with($mime, 'image/') ? 'img' : 'any'))));
    return '<span class="lms-fbadge is-' . $kind . '" aria-hidden="true">' . h(mb_substr($ext, 0, 4)) . '</span>';
}

function lms_size(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

/** "English 10 (10-A)": the class as the timetable writes it. */
function lms_class_label(array $c): string { return ($c['subjectName'] !== '' ? $c['subjectName'] : $c['subjectCode']) . ' (' . $c['sectionName'] . ')'; }

/** The school-year picker used on cross-class pages; returns the chosen year. */
function lms_year(): string
{
    $years = GS::schoolYears();
    return in_array($_GET['sy'] ?? '', $years, true) ? (string) $_GET['sy'] : (string) ($years[0] ?? '');
}

function lms_year_picker(string $route, string $sy, array $keep = []): string
{
    $years = GS::schoolYears();
    if (count($years) < 2) return '';
    $h = '<form method="get" class="lms-year"><input type="hidden" name="r" value="' . h($route) . '">';
    foreach ($keep as $k => $v) $h .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    $h .= '<select class="form-select form-select-sm" name="sy" aria-label="School year" data-autosubmit>';
    foreach ($years as $y) $h .= '<option' . ($y === $sy ? ' selected' : '') . '>' . h($y) . '</option>';
    return $h . '</select></form>';
}

/** Class filter chips for cross-class pages ("All classes", then each class). */
function lms_class_chips(string $route, array $classes, string $current, array $keep = []): string
{
    if (count($classes) < 2) return '';
    $h = '<nav class="lms-subtabs" aria-label="Class"><a href="' . h(url($route, $keep)) . '"' . ($current === '' ? ' class="active" aria-current="true"' : '') . '>All classes</a>';
    foreach ($classes as $c) $h .= '<a href="' . h(url($route, $keep + ['class' => $c['id']])) . '"' . ($current === $c['id'] ? ' class="active" aria-current="true"' : '') . '>' . h($c['subjectCode'] . ' · ' . $c['sectionName']) . '</a>';
    return $h . '</nav>';
}
