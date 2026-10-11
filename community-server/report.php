<?php
// POST report.php  id=<icon path>  -> one report per person per icon.
require __DIR__ . '/lib.php';
cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
$id = (string)($_POST['id'] ?? '');
if (!preg_match('#^icons/[A-Z]{4}\d{5}/[^/]+\.png$#', $id)) fail('Unknown icon.');
$me = who();
data_update('reports', function (array &$r) use ($id, $me) {
    $e = $r[$id] ?? ['by' => [], 'ok' => false];
    if (!in_array($me, $e['by'], true)) $e['by'][] = $me;
    $r[$id] = $e;
});
out(['ok' => true]);
