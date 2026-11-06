<?php
declare(strict_types=1);
/** A document on its own page, to print: an issued copy (?id=) or a preview (?student=&doc=&term=&purpose=). */
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) $html = Documents::copy($id);
else {
    $s = GS::get('students', (string) ($_GET['student'] ?? '')) ?: throw new AppError('Student not found.');
    [$type, $template] = array_pad(explode(':', (string) ($_GET['doc'] ?? '')), 2, '');
    $html = Documents::render($type, $template, Documents::data($type, $s, ['template' => $template, 'term' => (string) ($_GET['term'] ?? ''),
        'purpose' => (string) ($_GET['purpose'] ?? ''), 'preview' => true, 'signature' => ['mode' => 'PREVIEW']]));
}
// A print button above the page (not printed); inside the SIS preview frame it's hidden
$bar = '<div class="doc-toolbar" style="position:sticky;top:0;z-index:5;display:flex;gap:8px;justify-content:center;padding:8px;background:#1f2a44">'
    . '<button type="button" id="docPrint" style="font:600 14px system-ui;padding:6px 16px;border-radius:8px;border:0;background:#fff;cursor:pointer">Print</button>'
    . '<span style="font:13px system-ui;color:#dfe6f2;align-self:center">Choose the paper size in the print window to match the template.</span></div>'
    . '<style>@media print{.doc-toolbar{display:none!important}}</style><script src="assets/doc-print.js?v=50"></script>';
echo preg_replace('/<body([^>]*)>/i', '<body$1>' . $bar, $html, 1);
