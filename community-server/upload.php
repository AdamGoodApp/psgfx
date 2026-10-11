<?php
// POST upload.php (multipart): title, name (optional), agree=1, image (PNG or JPEG file).
// Checks the image, turns it into a clean 512 x 512 PNG, and saves it to GitHub at
// icons/<title>/<time>_<hash>_<name>.png, plus a 128 x 128 copy under thumbs/, and keeps a copy of both
// on this site (files/) for consoles that can only use plain http. It shows up in PSGFX straight away.
require __DIR__ . '/lib.php';
cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
if (($_POST['agree'] ?? '') !== '1') fail('Please accept the community guidelines first.');
$title = strtoupper((string)($_POST['title'] ?? ''));
if (!valid_title($title)) fail('Unknown title ID.');
$name = preg_replace('/[^A-Za-z0-9 ._]/', '', (string)($_POST['name'] ?? ''));
$name = trim(substr(preg_replace('/\s+/', ' ', $name), 0, 24));
$f = $_FILES['image'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('No image was received.');
if ($f['size'] > MAX_UPLOAD_BYTES) fail('That image is too large.');

$me = who();
if (in_array($me, data_read('blocked'), true)) fail('Uploads from you are turned off.', 403);

// Rate limit: drop timestamps older than a day, then count.
$now = time();
$allowed = data_update('uploads', function (array &$u) use ($me, $now) {
    foreach ($u as $k => $times) { $u[$k] = array_values(array_filter($times, fn($t) => $t > $now - 86400)); if (!$u[$k]) unset($u[$k]); }
    $mine = $u[$me] ?? [];
    $hour = count(array_filter($mine, fn($t) => $t > $now - 3600));
    if ($hour >= UPLOADS_PER_HOUR || count($mine) >= UPLOADS_PER_DAY) return false;
    $u[$me][] = $now;
    return true;
});
if (!$allowed) fail("You've shared a lot of icons recently. Try again later.", 429);

// Decode and re-encode, so only real pixels reach GitHub (nothing hidden inside the file).
$info = @getimagesize($f['tmp_name']);
if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) fail("That file isn't a PNG or JPEG image.");
if ($info[0] < 128 || $info[1] < 128) fail('That image is too small. Use one at least 256 x 256.');
if ($info[0] * $info[1] > 40000000) fail('That image is too large.');
$src = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($f['tmp_name']) : @imagecreatefromjpeg($f['tmp_name']);
if (!$src) fail("That image couldn't be read.");
$w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
$dst = imagecreatetruecolor(512, 512);
imagealphablending($dst, false); imagesavealpha($dst, true);
imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 512, 512, $side, $side);
ob_start(); imagepng($dst, null, 9); $png = (string)ob_get_clean();
$th = imagecreatetruecolor(128, 128);
imagealphablending($th, false); imagesavealpha($th, true);
imagecopyresampled($th, $dst, 0, 0, 0, 0, 128, 128, 512, 512);
ob_start(); imagepng($th, null, 9); $thumb = (string)ob_get_clean();
imagedestroy($src); imagedestroy($dst); imagedestroy($th);

$hash = substr(sha1($png), 0, 8);
foreach (all_icons() as $i) if ($i['title'] === $title && str_contains($i['id'], '_' . $hash)) fail('That icon is already shared for this game.');

$slug = str_replace(' ', '-', $name);
$path = "icons/$title/{$now}_{$hash}" . ($slug !== '' ? "_$slug" : '') . '.png';
[$code, $j] = gh('PUT', '/repos/' . GITHUB_REPO . '/contents/' . $path, [
    'message' => "Add icon for $title" . ($name !== '' ? " by $name" : ''),
    'content' => base64_encode($png),
    'branch' => GITHUB_BRANCH,
]);
if ($code !== 201 && $code !== 200) fail("Couldn't save the icon to GitHub (error $code). Try again later.", 502);
// The small version for PS5 apps and quick lists. If this one fails, the mirror still has it.
$tpath = 'thumbs/' . substr($path, 6);
gh('PUT', '/repos/' . GITHUB_REPO . '/contents/' . $tpath, ['message' => "Thumbnail for $path", 'content' => base64_encode($thumb), 'branch' => GITHUB_BRANCH]);
mirror_put($path, $png); mirror_put($tpath, $thumb);
data_update('owners', function (array &$o) use ($path, $me) { $o[$path] = $me; });
forget_icons();
out(['ok' => true, 'icon' => icon_out(['id' => $path, 'title' => $title, 'by' => $name, 'time' => $now])]);
