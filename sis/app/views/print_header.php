<?php
/** The header of a printed document (Settings → header). Expects $docTitle; optional $docRight (HTML). */
$school = Settings::get('print_school_name') !== '' ? Settings::get('print_school_name') : School::name();
$logo = School::logo() ?: '../icons/acadlink-mark.png';
?>
<div class="print-head">
  <?php if (Settings::on('print_show_logo')): ?><img class="print-logo" src="<?= h($logo) ?>" alt=""><?php endif; ?>
  <div class="print-school min-w-0">
    <div class="print-school-name"><?= h($school) ?></div>
    <?php if (Settings::get('print_address') !== ''): ?><div class="print-line"><?= h(Settings::get('print_address')) ?></div><?php endif; ?>
    <?php if (Settings::get('print_contact') !== ''): ?><div class="print-line"><?= h(Settings::get('print_contact')) ?></div><?php endif; ?>
    <div class="print-title"><?= h($docTitle) ?></div>
  </div>
  <?php if (!empty($docRight)): ?><div class="print-right"><?= $docRight ?></div><?php endif; ?>
</div>
