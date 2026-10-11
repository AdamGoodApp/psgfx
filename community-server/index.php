<?php
// GET index.php -> every game that has community icons, and its icons, in one file.
// Made for apps that show the whole library (for example on the PS5), so they don't have to ask per game.
// { "ok": true, "updated": <unix time>, "titles": { "PPSA12345": [ {id, url, thumb, mirror?, mirror_thumb?, by, time}, ... ] } }
require __DIR__ . '/lib.php';
cors();
$reports = data_read('reports');
$titles = [];
foreach (all_icons() as $i) {
    if (is_hidden($reports, $i['id'])) continue;
    $titles[$i['title']][] = icon_out($i);
}
ksort($titles);
out(['ok' => true, 'updated' => time(), 'titles' => (object)$titles]);
