# AcadLink v61 — Learning Management System

No SQL to import. Copy the files over v60 (keep `api/config.php`), open SIS once and press Ctrl+F5.
The LMS tables are created automatically.

## What it does
**Choose a workspace → LMS** opens its own system (`lms/`), on the same sign-in, in the Google Classroom style.
The classes are the Grading System's class assignments (teacher, subject, section, school year and term), so
there is nothing to set up twice: each teacher sees the classes they teach and each student sees the classes they
are enrolled in.

- **My Classes**: a card per class with what is due and what needs checking; today's classes; a school-year picker.
- **Stream**: announcements with file attachments; the "Upcoming" box lists what is due soon.
- **Classwork**, grouped by topic. The teacher creates:
  - **Assignment / activity**: instructions, attached files, points, due date and time, category
    (Assignment, Quiz, Project, Exam), "accept late work" and "allow resubmission"; can be saved as a draft.
  - **Quiz / exam**: the same, plus a **time limit** and the **question builder**: multiple choice, checkboxes,
    true/false, short answer (several accepted answers; capitals and extra spaces are ignored) and essay, each
    with its own points. The quiz is worth the total of its questions.
  - **Material**: a handout or reading with files and no grade.
- **Students hand in work** with **files, text and a link**; can save without handing in, **unsubmit** and hand in
  again (while allowed). Late work is marked **Late**.
- **Taking a quiz**: Start → a countdown timer → Turn in. When the time is up the answers are turned in
  automatically, and the server also refuses answers after the limit. Multiple choice, checkboxes, true/false and
  short answers are **scored automatically**; essays wait for the teacher. If there are no essays the student sees
  the score at once (can be turned off per quiz).
- **Checking work**: To Do → *To grade* lists everything handed in. The teacher opens a student, sees the files,
  text, link or quiz answers, enters the score (per question for quizzes) and feedback, then **Returns** it.
  The student sees the score and feedback.
- **People**: the class roster (teacher and students, from the class record).
- **Attendance**: take attendance for a meeting (Present / Late / Absent / Excused, "mark all present", a topic),
  edit past meetings, and a per-student summary. Students see their own attendance.
- **Grades**: the gradebook — every student × every graded item, with category weights
  (default Assignment 30 · Quiz 30 · Project 20 · Exam 20, changeable in **Settings**; missing work can count as
  zero) and the class average. Students see only their own.
- **Send to Grading System (draft)**: puts each student's LMS average on the Grading System grade sheet **as a
  draft**, converted to the school's grade scale. The teacher still reviews and submits the official grade in
  the Grading System as before. Grades already submitted, completed terms, a passed deadline or locked teachers
  are never overwritten.
- **To Do** (all classes) and **Schedule**: the teacher's or student's weekly timetable from the class schedules,
  with any clashes shown.

## Accounts and access
- Teachers and students use their existing AcadLink accounts. Students open the LMS from their workspace chooser.
- Users and Groups → LMS: "Use the LMS" plus a new **Coordinator** option that opens and manages every class.
  The Power User has full access.
- Files (10 MB each; documents, images, archives, audio and video) are stored in the database, checked by type and
  content, and downloaded only by people in that class.
- Posts, classwork, hand-ins, returns, attendance and sending to the Grading System are in the Activity Log.

## Source code
- New: `lms/lib/lms.php`, `lms/pages/{_ui,home,class,work,todo,schedule,file}.php`,
  `lms/assets/{lms.js,lms.css}`, `sis/tests/v61-lms.php`.
- Changed: `lms/index.php`, `shared/module-access.php`, `sis/app/lib/auth.php`, `sis/app/bootstrap.php`,
  `sis/app/views/layout.php`, `sis/app/pages/{workspaces,login}.php`, `sis/assets/{navigation,standard-toolbar}.js`,
  `sis/tests/v58-finance.php` (no workspace is "coming soon" any more).

## Tests
`php sis/tests/v61-lms.php` (11 checks). Checked in a browser end to end: a teacher posts, creates an assignment and
a four-type quiz; a student hands in and takes the quiz; the teacher returns both, takes attendance and sends the
average to the Grading System, where the draft appears and was submitted normally. All other suites unchanged
and passing.

## v61.1 — LMS design
- New look from the mockup: deep green sidebar with the **AcadLink LMS** brand, blue highlights, a white top bar
  and white cards.
- Menu: **Dashboard · My Classes · Schedule · Attendance · Assignments · Gradebook · Materials · Messages · Reports ·
  Settings**. Reports and Settings show for teachers (and coordinators) only.
- **Dashboard** (new home), with a greeting, "Teacher Workspace" and today's date:
  - Four counters: **Today's Classes** (of the periods in the week), **Attendance (Today)** (present of those
    marked), **Assignments Awaiting Review** (hand-ins to check), **Course Materials** (files shared).
  - **My Weekly Timetable**: periods P1, P2… by time, Mon–Fri (plus Saturday if used), today's column highlighted,
    previous/next week buttons. It comes from the SIS class schedule.
  - **Today's Classes** with a **Take Attendance** button for each meeting. It opens the class's attendance for
    today and that meeting, and changes to "Attendance taken" once saved.
  - **Attendance Summary** (Present / Absent / Late), **Assignments Awaiting Review** and **Course Materials**.
  - Students see the same page for themselves: their classes today, their attendance, work due and materials.
- **Attendance**: the classes of any day with Take/Edit attendance, that day's totals, and each class's rate.
  Students see their own record per class.
- **Assignments**: every assignment, quiz and exam of all classes. Filters: class, "Awaiting review"; for students
  "To do" and "Turned in".
- **Gradebook**: each class's average, graded and passing students, opening the class gradebook. Students see their
  class-work grade per class.
- **Materials**: every file shared in the classes (stream, materials, classwork), searchable, by class.
- **Messages** (new): private conversations between a teacher and one student of a class. Unread counts show on
  the menu; "Seen" when read; only the two of them can read it. Each message is in the Activity Log. The table
  `lms_messages` is created automatically.
- **Reports**: per class: students, classwork, hand-in rate, late and missing work, average, passing (by the school's
  grade scale), meetings and attendance rate. Printable.
- **Settings**: the grading categories of each class, and copying one class's categories to others.
- Source: new `lms/pages/{home,attendance,assignments,gradebook,materials,messages,reports,settings}.php` (the old
  home is now `classes.php`); changed `lms/lib/lms.php`, `lms/pages/{_ui,class,work}.php`, `lms/index.php`,
  `lms/assets/{lms.css,lms.js}`, `sis/app/views/layout.php`. Tests: `v61-lms.php` now has 13 checks.

## v61.2 — Bottom menu on phones and tablets
- **Student Portal, LMS and Canteen POS** get a bottom menu on screens narrower than 992 px (phones and tablets):
  four buttons and a raised round button in the middle that sits in a curved dip of the bar. Each system uses its
  own colour: Portal orange, LMS blue, Canteen orange.
  - Student Portal: **Home · Schedule (or Enrollment/Admission) · Grades (middle) · Fees · More**. More slides up
    a sheet with the whole menu, My profile and Switch system. The orange tab row under the top bar is hidden on
    these screens, so the bottom menu replaces it.
  - LMS: **Home · Classes · Messages (middle, with the unread count) · Tasks · More**. More opens the side menu.
  - Canteen POS: **Sales · Shift · Cashier (middle) · Products · More**, following the cashier's permissions.
    The order total bar of the cashier screen sits just above the menu.
- Computers (992 px and wider) are unchanged. The menu is not printed.
- Source: new `sis/app/views/bottom-nav.php` and `sis/assets/bottom-nav.css`; changed `sis/app/views/layout.php`,
  `portal/views/layout.php` and `lms/assets/lms.css`.

## v62.2 — Bottom menu: the curve follows the selected item
- On phones and tablets, the orange curved dip and the raised white circle now sit under the **selected** item
  (for example Products when you are on Products) instead of always in the middle.
- Tapping another item slides the curve to it, the icon rises into the circle, then the page opens. **More** moves
  it too while the menu is open, and it goes back when the menu is closed.
- On a page that is not in the bottom menu, the curve stays on the middle item.
- Works the same in the Student Portal, the LMS and the Canteen POS. Students now see the LMS bar in the LMS blue (it showed the Portal orange before).
- People who turned on "reduce motion" on their phone get no animation.
- Source: `sis/app/views/bottom-nav.php`, `sis/assets/bottom-nav.css`, `sis/assets/app.js`.
