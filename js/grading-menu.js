/* v66: the Grading System's menu, written once for every page (pages/*.html have an empty <nav data-grading-menu>).
   The item of the page you are on is highlighted. Roles (data-roles) and badges are handled by js/app.js as before. */
(function () {
  var nav = document.querySelector('nav[data-grading-menu]');
  if (!nav) return;
  nav.innerHTML = [
    "<a class=\"side-link\" href=\"notifications.html\" data-roles=\"admin registrar teacher\"><i class=\"bi bi-bell\" aria-hidden=\"true\"></i><span>Notifications</span><span class=\"nav-badge d-none\" id=\"notifBadge\" aria-label=\"unread notifications\"></span></a>",
    "<div class=\"nav-group-label\" data-roles=\"admin registrar teacher\">Overview</div>",
    "<a class=\"side-link\" href=\"dashboard.html\" data-roles=\"admin registrar teacher\"><i class=\"bi bi-grid-1x2\" aria-hidden=\"true\"></i><span>Dashboard</span></a>",
    "<div class=\"nav-group-label\" data-roles=\"admin registrar\">Academic setup</div>",
    "<a class=\"side-link\" href=\"subjects.html\" data-roles=\"admin registrar\"><i class=\"bi bi-journal-bookmark\" aria-hidden=\"true\"></i><span>Subjects</span></a>",
    "<a class=\"side-link\" href=\"curriculum.html\" data-roles=\"admin registrar\"><i class=\"bi bi-list-check\" aria-hidden=\"true\"></i><span>Curriculum</span></a>",
    "<a class=\"side-link\" href=\"teachers.html\" data-roles=\"admin registrar\"><i class=\"bi bi-person-badge\" aria-hidden=\"true\"></i><span>Teachers</span></a>",
    "<a class=\"side-link\" href=\"sections.html\" data-roles=\"admin registrar\"><i class=\"bi bi-collection\" aria-hidden=\"true\"></i><span>Sections</span></a>",
    "<a class=\"side-link\" href=\"students.html\" data-roles=\"admin registrar\"><i class=\"bi bi-people\" aria-hidden=\"true\"></i><span>Students</span></a>",
    "<div class=\"nav-group-label\" data-roles=\"admin registrar teacher\">Grading</div>",
    "<a class=\"side-link\" href=\"assignments.html\" data-roles=\"admin registrar\"><i class=\"bi bi-diagram-3\" aria-hidden=\"true\"></i><span>Grading assignments</span></a>",
    "<a class=\"side-link\" href=\"grading.html\" data-roles=\"admin registrar teacher\"><i class=\"bi bi-pencil-square\" aria-hidden=\"true\"></i><span>Enter grades</span></a>",
    "<a class=\"side-link\" href=\"../sis/index.php?r=myschedule\" target=\"_top\" data-roles=\"teacher\"><i class=\"bi bi-calendar-week\" aria-hidden=\"true\"></i><span>My class schedule</span></a>",
    "<a class=\"side-link\" href=\"../sis/index.php?r=clearance\" target=\"_top\" data-roles=\"teacher registrar admin\" data-clearance hidden><i class=\"bi bi-patch-check\" aria-hidden=\"true\"></i><span>Clearance</span></a>",
    "<a class=\"side-link\" href=\"final-grades.html\" data-roles=\"admin registrar\"><i class=\"bi bi-mortarboard\" aria-hidden=\"true\"></i><span>Final grades</span></a>",
    "<div class=\"nav-group-label\" data-roles=\"admin registrar teacher\">School Information System</div>",
    "<a class=\"side-link\" href=\"sis.html\" data-roles=\"admin registrar teacher\"><i class=\"bi bi-buildings\" aria-hidden=\"true\"></i><span>Enrollment and fees (SIS)</span></a>"
  ].join('');
  var here = (location.pathname.split('/').pop() || 'dashboard.html').toLowerCase();
  nav.querySelectorAll('a.side-link').forEach(function (a) {
    if ((a.getAttribute('href') || '').toLowerCase() === here) { a.classList.add('active'); a.setAttribute('aria-current', 'page'); }
  });
})();
