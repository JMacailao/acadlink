# Document templates (TOR and certificates)

Each folder is one document type. Every `.php` file in it is a template the registrar can choose in
**SIS → student → Documents**. An administrator can set the default for each document type in
**TOR & Certificates → Settings**. To add your own, copy `standard.php` to a new name in the same
folder (lowercase letters, numbers, `-` or `_`, e.g. `tor/lgc-legal-2026.php`) and edit it. It
appears in both the issue screen and its settings selector right away; nothing else needs to change.

| Folder | Document |
|---|---|
| `tor/` | Transcript of Records |
| `enrollment/` | Certificate of Enrollment (needs a confirmed enrollment for the chosen term) |
| `grades/` | Certificate of Grades (one term's final grades) |
| `good-moral/` | Certificate of Good Moral Character |
| `honorable-dismissal/` | Certificate of Honorable Dismissal |

## First lines of a template

```php
<?php /* Template: LGC Official TOR (Legal)
   Paper: Legal */ ?>
```

`Template:` is the name shown in the list. `Paper:` is `A4`, `Legal` (8.5 × 13 in) or `Letter`.
Use the same size in `doc_page_css('Legal')`.

## Rules

- Print every value with `e(...)` so names with `&` or `<` print correctly: `<?= e($doc['student']['name']) ?>`.
- Templates only read `$doc`. Don't connect to the database or include other files.
- A mistake in a template shows **"This template has an error: file, line N"** in Preview and
  stops issuing with that template. The other templates keep working.
- Issued documents keep the copy made when they were signed, so editing a template later never
  changes a document already issued (Reprint shows the original).

## Helpers (from `_helpers.php`)

| Helper | Prints |
|---|---|
| `e($text)` | the text, made safe for HTML |
| `doc_date('2026-10-22')` | October 22, 2026 |
| `doc_blank($value, '14em')` | the value, or a blank line to write on when it's empty |
| `doc_page_css('A4' \| 'Legal' \| 'Letter', '12mm')` | paper size, margins and the shared styles |
| `doc_letterhead($doc)` | logo, school name, address and contact |
| `doc_signature($doc, 'Certified correct:')` | the signatory's e-signature, the "APPROVED" stamp of a Power User, or "Preview: signed when issued" |
| `doc_legend($doc)` | the grading legend from the Grading System's settings |
| `doc_footer($doc)` | control number, date issued, "Not valid without the school seal" |

## Everything in `$doc`

```
$doc['type']          tor | enrollment | grades | good-moral | honorable-dismissal
$doc['title']         "Transcript of Records", …
$doc['control_no']    "TOR-2026-00012" ("" in Preview)
$doc['purpose']       "Employment", "Board exam", …
$doc['issued_at']     "2026-10-22"
$doc['preview']       true in Preview, false when issued

$doc['school']        name, address, contact, logo (image data, or "")

$doc['student']       id, name, email, program, year_level, section, status ("Active" | "Graduated"),
                      sex, birth_date, birth_place, home_address, date_admitted,
                      entrance_credentials, graduation_date, so_number   (Student → Records for documents)

$doc['periods']       every term with final grades, oldest first (Certificate of Grades: the chosen term only)
  [n]['school_year'], ['term'], ['year_level'], ['section']
  [n]['subjects'][m]  code, title, units, grade ("88", "1.75", "INC"), equivalent (final-grade scale, or ""),
                      remarks ("Passed", "Failed", "Conditional", "Incomplete", "Dropped")
  [n]['units'], ['gwa_text'], ['equivalent_text'], ['remarks']     term GWA (INC and DRP not counted)

$doc['totals']        units, units_earned (passed subjects), gwa_text, equivalent_text, remarks

$doc['scale']         grading ("0–100"), final ("1.00–5.00"), differs (true when the final grade uses
                      another scale: show an "Equivalent" column), legend (lines of text)

$doc['term']          school_year, term (Certificate of Enrollment / of Grades)
$doc['enrollment']    program, year_level, section, units, confirmed_at, subjects [code, title, units]  (or null)
$doc['last_attended'] school_year, term  (or null)

$doc['signature']     mode: PREVIEW | PENDING | ESIGN | APPROVED, and when signed: image, name, position, signed_at
$doc['signatories']   prepared_by, checked_by  (SIS → Documents → Settings)
```

Grades and remarks follow the Grading System's **Setup and options**: the grading scale
(0–100, 1.00–5.00 or 5.00–1.00), "Show the final grade as", and the conversion table.
