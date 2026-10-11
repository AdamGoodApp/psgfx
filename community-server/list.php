<?php
// GET list.php?title=PPSA12345 -> the community icons for that game, newest first.
require __DIR__ . '/lib.php';
cors();
$title = strtoupper((string)($_GET['title'] ?? ''));
if (!valid_title($title)) fail('Unknown title ID.');
$reports = data_read('reports');
$list = [];
foreach (all_icons() as $i) {
    if ($i['title'] !== $title || is_hidden($reports, $i['id'])) continue;
    $list[] = icon_out($i);
}
out(['ok' => true, 'icons' => $list]);
