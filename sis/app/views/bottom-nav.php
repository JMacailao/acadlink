<?php
declare(strict_types=1);
/**
 * v61.2 Bottom navigation for phones and tablets (under 992 px): four links and a raised round button in the
 * middle that sits in a curved dip of the bar. Used by the Student Portal, the LMS and the Canteen POS, coloured
 * with each system's own theme (bottom-nav.css). Items: ['href','label','icon','active'(bool),'badge'(int)];
 * an item with 'sheet' => id opens that offcanvas instead of linking.
 */
function bottom_nav(array $left, array $center, array $right, string $label = 'Quick menu'): void
{
    // v62.2: the curved dip and the raised circle sit under the selected item and slide to whichever item is tapped.
    $items = array_values(array_filter(array_merge($left, [$center], $right)));
    $current = null;
    foreach ($items as $k => $it) if (!empty($it['active'])) { $current = $k; break; }
    $home = count($left); // nothing selected (a page that is not in this menu): the middle item keeps the dip
    $at = $current ?? $home;
    echo '<nav class="al-bnav d-print-none" aria-label="' . h($label) . '" data-bnav data-home="' . $at . '" style="--n:' . count($items) . ';--i:' . $at . '"><div class="al-bnav-row">'
        . '<span class="al-bnav-ind" aria-hidden="true"><svg class="al-bnav-dip" viewBox="0 0 150 54" focusable="false"><path d="M0 0 C24 0 33 5 42 20 C52 38 60 48 75 48 C90 48 98 38 108 20 C117 5 126 0 150 0 Z"/></svg><span class="al-bnav-bubble"></span></span>';
    foreach ($items as $k => $it) {
        $attrs = isset($it['sheet']) ? ' role="button" data-bs-toggle="offcanvas" data-bs-target="#' . h($it['sheet']) . '" aria-controls="' . h($it['sheet']) . '" href="#' . h($it['sheet']) . '"'
            : ' href="' . h($it['href']) . '"' . (!empty($it['active']) ? ' aria-current="page"' : '');
        $badge = !empty($it['badge']) ? '<span class="al-bnav-badge" aria-label="' . (int) $it['badge'] . ' new">' . ((int) $it['badge'] > 99 ? '99+' : (int) $it['badge']) . '</span>' : '';
        echo '<a class="al-bnav-item' . ($k === $at ? ' is-up' : '') . (!empty($it['active']) ? ' active' : '') . '" data-i="' . $k . '"' . $attrs . '>'
            . '<span class="al-bnav-icon"><i class="bi bi-' . h($it['icon']) . '" aria-hidden="true"></i>' . $badge . '</span><span class="al-bnav-label">' . h($it['label']) . '</span></a>';
    }
    echo '</div></nav>';
}

/** A "More" sheet sliding up from the bottom with every menu entry (for layouts without a side menu). */
function bottom_sheet(string $id, string $title, array $items): void
{
    echo '<div class="offcanvas offcanvas-bottom al-bsheet" tabindex="-1" id="' . h($id) . '" aria-labelledby="' . h($id) . 'T">'
        . '<div class="offcanvas-header"><h2 class="offcanvas-title fs-6" id="' . h($id) . 'T">' . h($title) . '</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>'
        . '<div class="offcanvas-body"><div class="al-bsheet-grid">';
    foreach ($items as $it) echo '<a href="' . h($it['href']) . '"' . (!empty($it['active']) ? ' class="active" aria-current="page"' : '') . '><i class="bi bi-' . h($it['icon']) . '" aria-hidden="true"></i><span>' . h($it['label']) . '</span></a>';
    echo '</div></div></div>';
}
