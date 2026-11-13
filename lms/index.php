<?php
/**
 * Learning Management System (v61) — its own system and entry point, sharing the SIS's sign-in, database, services and look.
 * index.php?r=<route>. Teachers teach the classes the Grading System assigns them; students see the classes they are
 * enrolled in. Stream, classwork (assignments, activities, quizzes and exams), submissions and feedback, attendance,
 * a weighted gradebook (sent to the Grading System as drafts), and the class schedule from the SIS.
 */
declare(strict_types=1);

const APP_SYSTEM = 'LMS';
const LOCAL_ROUTES = ['dashboard', 'todo', 'schedule', 'class', 'work', 'file', 'notifications', 'search', 'profile', 'password', 'navstate'];
require __DIR__ . '/../sis/app/bootstrap.php';
sis_boot();

const ROUTES = [
    'dashboard'    => [__DIR__ . '/pages/home.php', ['lms.view']],
    'todo'         => [__DIR__ . '/pages/todo.php', ['lms.view']],
    'schedule'     => [__DIR__ . '/pages/schedule.php', ['lms.view']],
    'class'        => [__DIR__ . '/pages/class.php', ['lms.view']],
    'work'         => [__DIR__ . '/pages/work.php', ['lms.view']],
    'file'         => [__DIR__ . '/pages/file.php', ['lms.view']],
    'notifications'=> ['notifications', ['admission', 'registration', 'payments.view', 'accounting']],
    'search'       => ['search', ['students', 'admission', 'registration', 'payments.view']],
    'profile'      => ['profile', []],
    'password'     => ['password', []],
    'navstate'     => ['navstate', []],
];
const MODULE_ROUTE_FILES = [];

$route = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';
if (!isset(ROUTES[$route])) {
    $q = $_GET; $q['r'] = $route;
    header('Location: ../sis/index.php?' . http_build_query($q), true, 307); exit;
}
[$page, $perms] = ROUTES[$route];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
require __DIR__ . '/pages/_ui.php';

require SIS_ROOT . '/app/front.php';
