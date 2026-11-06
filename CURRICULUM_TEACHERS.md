# Curriculum teachers and automatic Grading Assignments

In SIS **Curriculum** or Grading **Curriculum**, select subjects and choose a teacher beside each one. The default choice is **Professor X · Needs teacher**.

Saving creates the missing Grading Assignments for sections with the same program and year level. Each assignment uses the section's school year and the curriculum's term. The program is derived from the section name: `BSIT 1A` belongs to `BSIT`. When there is no matching section yet, saving a matching section later creates its assignments automatically. **v56:** every new curriculum and grading assignment needs a semester (1st, 2nd, 3rd Semester or Summer); there is no Whole Year option. Older records without a semester stay readable and editable, but create no new classes.

In Grading **Grading Assignments**, find Professor X and click **Edit** on the subject you want to assign. Choose the real teacher and save. Only that assignment changes. Other subjects and other sections stay with their current teacher, including Professor X. Empty automatically created classes can have their teacher changed before enrollment.

The assignment ID, students and saved grade values remain intact when only its teacher changes. Grade ownership is updated on the server, so the new teacher can see the saved grades and the previous teacher loses access. If the assignment already has grades, the existing confirmation explains the transfer.

Saving a curriculum again creates only missing assignments. It never replaces rosters or deletes grades. **v56:** changing the teacher beside a curriculum subject also changes the *active* class of that subject (the section's current school year and the curriculum's semester) while that class still has the previous curriculum teacher. A teacher chosen in Grading Assignments stays, and a class whose grades were already submitted keeps its teacher (change it in Grading Assignments). Draft grades move to the new teacher; grade values and history are never changed. Removing a subject from the curriculum does not delete classes or their academic history.

Assignments created before enrollment begin with empty rosters. Confirm Enrollment adds students using the existing enrollment workflow. If a curriculum is saved after enrollment, new assignments include students with confirmed registrations for that section, school year, term and subject, excluding students already taking that subject in another class.

Professor X is one protected directory record, not a user account. SIS account creation, the teacher shortcut and bulk account creation cannot give it a login. Both systems prevent renaming or deleting that reserved record. No real account or teacher is merged by matching names.

Fresh installations still need only root **INSTALL.sql**, which includes the Professor X record. Existing centralized installations create it automatically when a curriculum or matching section is saved. To create missing classes for an existing curriculum, open it and save it once. No extra SQL import is required.
