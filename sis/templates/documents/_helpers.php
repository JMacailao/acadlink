<?php
declare(strict_types=1);
/**
 * Small helpers every document template can use (see README.md).
 * Templates only read $doc; they never touch the database.
 */

/** Escapes text for HTML. Use it for every value you print: <?= e($doc['student']['name']) ?> */
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** "2026-10-22" → "October 22, 2026" ("" stays ""). */
function doc_date(?string $ymd): string
{
    if (!$ymd) return '';
    $t = strtotime(substr($ymd, 0, 10));
    return $t ? date('F j, Y', $t) : $ymd;
}

/** A filled-in value, or a blank line to write on. */
function doc_blank($v, string $width = '14em'): string
{
    $v = trim((string) $v);
    return $v !== '' ? e($v) : '<span style="display:inline-block;min-width:' . e($width) . ';border-bottom:1px solid #000">&nbsp;</span>';
}

/** Page setup: paper size and margins, plus a few shared styles. */
function doc_page_css(string $paper = 'A4', string $margin = '12mm'): string
{
    $size = ['A4' => 'A4', 'Legal' => '8.5in 13in', 'Letter' => 'letter'][$paper] ?? 'A4';
    return '<style>@page{size:' . $size . ';margin:' . $margin . '}
      *{box-sizing:border-box}body{font-family:"Times New Roman",Times,serif;color:#000;margin:0;font-size:12.5px}
      .doc{max-width:190mm;margin:0 auto;padding:8mm 0}
      @media screen{body{background:#e9edf2}.doc{background:#fff;padding:14mm;margin:12px auto;box-shadow:0 2px 14px #0002;max-width:' . ($paper === 'A4' ? '210mm' : '216mm') . '}}
      .doc-letterhead{display:flex;align-items:center;justify-content:center;gap:14px;text-align:center;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:10px}
      .doc-letterhead img{height:64px}.doc-school{font-size:19px;font-weight:bold;text-transform:uppercase}.doc-sub{font-size:12px}
      .doc-title{text-align:center;font-size:17px;font-weight:bold;letter-spacing:.06em;margin:10px 0}
      .doc-control{text-align:right;font-size:11px}
      table.doc-table{width:100%;border-collapse:collapse;margin:4px 0 8px}table.doc-table th,table.doc-table td{border:1px solid #000;padding:3px 5px;vertical-align:top}
      table.doc-table th{background:#f0f0f0;font-size:11.5px}.n{text-align:center;white-space:nowrap}
      .doc-sign{display:inline-block;min-width:200px;max-width:100%;text-align:center;margin-top:28px;position:relative}
      .doc-sign img{height:58px;display:block;margin:0 auto -14px;position:relative}
      .doc-sign .line{border-top:1px solid #000;padding-top:2px;font-weight:bold;text-transform:uppercase}
      .doc-sign .pos,.doc-sign .when{font-size:11px}.doc-stamp{display:inline-block;border:3px double #1b5e20;color:#1b5e20;font:bold 15px Arial;letter-spacing:.12em;padding:2px 10px;transform:rotate(-4deg);margin-bottom:4px}
      .doc-preview{color:#b42318;font:italic 11px Arial}.doc-legend{font-size:10.5px;border:1px solid #000;padding:4px 6px;margin-top:8px}
      .doc-foot{font-size:10.5px;margin-top:10px;display:flex;justify-content:space-between}
      h3.doc-term{font-size:12.5px;margin:8px 0 2px}
      @media print{.doc{padding:0;max-width:none}tr{break-inside:avoid}h3.doc-term{break-after:avoid}}
    </style>';
}

/** School letterhead: logo, name, address, contact. */
function doc_letterhead(array $doc): string
{
    $s = $doc['school'];
    return '<div class="doc-letterhead">' . ($s['logo'] ? '<img src="' . e($s['logo']) . '" alt="">' : '')
        . '<div><div class="doc-school">' . e($s['name']) . '</div>'
        . ($s['address'] ? '<div class="doc-sub">' . e($s['address']) . '</div>' : '')
        . ($s['contact'] ? '<div class="doc-sub">' . e($s['contact']) . '</div>' : '') . '</div></div>';
}

/**
 * The signatory's block: e-signature image, "Approved" stamp (Power User), or a note while not yet signed.
 * $label: printed above the name, e.g. "Certified correct:".
 */
function doc_signature(array $doc, string $label = ''): string
{
    $s = $doc['signature'] ?? ['mode' => 'PREVIEW'];
    $top = $label !== '' ? '<div style="text-align:left;font-size:11.5px;margin-bottom:6px">' . e($label) . '</div>' : '';
    if (($s['mode'] ?? '') === 'ESIGN') {
        return '<div class="doc-sign">' . $top . '<img src="' . e($s['image']) . '" alt="Signature"><div class="line">' . e($s['name']) . '</div>'
            . '<div class="pos">' . e($s['position']) . '</div><div class="when">E-signed ' . e($s['signed_at']) . '</div></div>';
    }
    if (($s['mode'] ?? '') === 'APPROVED') {
        return '<div class="doc-sign">' . $top . '<div class="doc-stamp">APPROVED</div><div class="line">' . e($s['name']) . '</div>'
            . '<div class="pos">' . e($s['position']) . '</div><div class="when">' . e($s['signed_at']) . '</div></div>';
    }
    $note = ($s['mode'] ?? '') === 'PENDING' ? 'Waiting for the signatory' : 'Preview: signed when issued';
    return '<div class="doc-sign">' . $top . '<div class="doc-preview" style="height:44px;padding-top:20px">' . $note . '</div><div class="line">&nbsp;</div><div class="pos">Signatory</div></div>';
}

/** Grading legend from the Grading System's settings. */
function doc_legend(array $doc): string
{
    return '<div class="doc-legend"><strong>Grading system:</strong> ' . implode(' · ', array_map('e', $doc['scale']['legend'])) . '</div>';
}

/** Control number, date issued and "not valid without seal" line. */
function doc_footer(array $doc): string
{
    $c = $doc['control_no'] !== '' ? 'Control no. ' . e($doc['control_no']) : ($doc['preview'] ? '<span class="doc-preview">PREVIEW: not issued</span>' : '');
    return '<div class="doc-foot"><span>' . $c . '</span><span>Not valid without the school seal' . ($doc['issued_at'] ? ' · Issued ' . e(doc_date($doc['issued_at'])) : '') . '</span></div>';
}
