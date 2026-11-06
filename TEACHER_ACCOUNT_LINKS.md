# Teacher account reference repair

If SIS says a teacher has a legacy account but **Migration review** shows no unlinked accounts, the directory record may contain a `userUid` reference left over from a deleted or unimported Grading profile.

Upload this release, sign in as a Power User, then open **SIS → Teachers → ⋯ → Create account**. No extra SQL import or manual deletion is required.

Before creating anything, SIS checks the teacher's authoritative central person link, Grading profiles linked by Teacher record ID, the directory's old `userUid`, and verified Grading-to-central account links.

- If the referenced account does not exist and no verified central link owns its UID, account creation can proceed. The new account links to the existing teacher record, and the outdated `userUid` is replaced with its new Grading UID in the same transaction. An audit entry records the repair.
- If a real legacy profile exists, creation remains blocked for migration review.
- If the UID already belongs to a centralized identity, the message identifies that account ID and username for review, even if the old Grading profile is missing. It is never silently reassigned.
- Existing linked accounts, including disabled accounts, are never duplicated or reset.

Teacher ID, Teacher record ID, assignments, rosters and saved grades stay unchanged. The new temporary password is shown once and requires a change at first sign-in. If any part of account creation or reference repair fails, everything rolls back.

Bulk Teacher account preview follows the same checks. A confirmed dangling reference is marked Ready with an explanation; previewing changes no records. Student-account behavior is unchanged. Professor X remains a protected placeholder without a login.
