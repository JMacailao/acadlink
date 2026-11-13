# AcadLink v62 — Notifications, storage limits, Data Privacy, parents and student photos

No SQL to import. Copy the files over v61.2 (keep `api/config.php`), sign in to SIS once and press Ctrl+F5.
New tables (`sis_student_photos`, `sis_privacy_consents`, `sis_privacy_reviews`, `central_parent_links`), new columns on
`sis_notifications`, and the **Parent / Guardian** group are created automatically.

## Notifications (LMS, Library, Canteen, Student Portal)
A bell in the LMS, Library, Canteen POS and Student Portal opens **Notifications**: the person's own list, with
unread counts and **Mark all as read**. Clicking one opens what it is about.
- **LMS**:
  - Students are told about new assignments, quizzes and exams (when published, not while still a draft),
    new announcements and materials, and returned work with the score.
  - Teachers and students are told about new private messages.
- **Library**: "Book due tomorrow", "Book overdue" and "Your reserved book is ready" go to the borrower once each.
- **Canteen**: "running low" alerts go to staff who manage canteen stock, once a day for the same items.
- **Photos**: the Registrar is told when a student sends a new photo; the student is told when it is approved or
  declined.
- **Email**: each notification is also emailed when the school has set up email (EmailJS, as for Forgot password)
  and the person has an email on their account. Emails are queued and sent after the page has loaded, so nobody
  waits; a failed email never stops the work.
- Library and canteen checks run at most every 10 minutes while people use AcadLink. No cron job is needed.

## LMS file storage limits
- Each class has a file space limit (default **500 MB**; files are up to 10 MB each). Uploads that don't fit are
  refused with a clear message.
- Teachers see each class's usage in LMS → Settings.
- The LMS coordinator (Power User or "manage every class") sets the limit and sees space used per school year. They
  can **free up space** for an earlier school year by typing the year to confirm. Only the attached files are
  removed; grades, posts and hand-in records stay. The current school year can't be cleaned. Take a backup first.

## Data Privacy (RA 10173)
- **Privacy notice**: shown on the staff admission form and on the Student Portal application. The applicant (or a
  parent or guardian, for a minor) must tick that they agree before the application is saved. The consent is
  kept with the date, who agreed and the notice version. The student record shows it.
- **SIS → Records → Data Privacy**:
  - The notice can be edited (settings permission); each change gets a new version.
  - **Records due for review**: students whose last enrolment ended 5 or more years ago (the number of years can be
    changed). The Registrar marks the ones they keep as reviewed, with a note. **Nothing is ever deleted
    automatically.**
- **Download my data**: in the Student Portal, My profile → Download my data gives one JSON file. It holds the
  student's account, record, admission, registrations, payments, released grades, LMS work, attendance and
  messages, library loans and fines, photos, consents and notifications. Each download is in the Activity Log.
- Student-list exports happen in the Grading System screens, from data the person is already allowed to see. Who
  can see student lists is controlled, as before, by the Students permission in Users and Groups.

## Parent / Guardian accounts
- On a SIS student record, **Parents / guardians**:
  - The Registrar can **create a parent account**: name, username, relationship, optional email and a temporary
    password.
  - Or **link an existing parent account**, so one parent account can cover brothers and sisters.
  - Or **unlink** a parent.
- Parents sign in to the **Student Portal** and see **My children**, read-only:
  - photo and section;
  - balance, attendance rate and missing work;
  - each class this school year (LMS class work %, returned and missing work, present, late and absent);
  - final grades, with the same rules as the student, so grades held for an unpaid balance stay hidden;
  - balance by term and receipts.
- Parents can't open any other page, the SIS or the LMS. Only the Registrar (or the Power User) creates parent
  accounts. Every parent view is in the Activity Log.

## Student photos
- **Admission** (staff form and the Student Portal application): add the student's photo. It becomes the student's
  photo when they are admitted.
- **Student record** (SIS): shows the photo; the Registrar can replace it directly.
- **Student Portal → My profile → My photo**: a student sends a new photo. It waits for the Registrar in
  **SIS → Records → Photo Approvals**, which compares the current and new photo. Approve it, or decline it with a
  reason the student sees. To handle many at once, tick photos (or **Select all**), then **Approve selected**, or
  **Decline selected** with one reason for all of them. Each student is told, as when deciding one by one.
- Photos are stored in the database as bytes, shrunk to at most 600 px. Only staff who can see students or
  admissions, the student, and their linked parents can open a photo.

## Faster LMS
- The LMS reads only the current school year's classes, and a teacher's own classes, using the indexed columns.
- Class lists load in one query, and the timetable loads one schedule query per term instead of one per class.
- The class list is read once per page.

## Source code
- New:
  - `sis/app/lib/{notify,student-photo,privacy,parents}.php`
  - `sis/app/pages/{alerts,photo,photos,privacy}.php`
  - `portal/pages/children.php`
  - `sis/assets/people.css`
  - `sis/tests/v62-features.php` (12 checks, including approving and declining several photos at once)
- Changed:
  - `lms/lib/lms.php`, `lms/pages/settings.php`, `lms/index.php`, `lms/assets/lms.css`
  - `canteen/index.php`, `canteen/lib/canteen.php`, `library/index.php`
  - `portal/index.php`, `portal/views/layout.php`, `portal/pages/{profile,myadmission}.php`
  - `sis/index.php`, `sis/app/views/layout.php`, `sis/app/bootstrap.php`, `sis/app/lib/{auth,sis}.php`
  - `sis/app/pages/{admission,student,receipt}.php`
  - `sis/assets/{app.js,bottom-nav.css}`
  - `shared/central-accounts.php`, `shared/module-access.php`
- Tests:
  - `central-accounts.php` now expects ten default groups (Parent / Guardian added).
  - `v61-lms.php` and `e2e_lifecycle.php` install the v62 tables.
  - `portal-enrollment.php` now runs against the configured MySQL server (`PORTAL_TEST_PORT=3369` still uses a
    separate test server); all 42 checks pass.
