<?php
// mod.php - your moderation page. Log in with ADMIN_PASSWORD from config.php.
// Reported icons come first. Delete removes an icon from GitHub (and this site), so it's gone from PSGFX.
require __DIR__ . '/lib.php';
session_start();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (isset($_POST['password'])) {
    if (hash_equals(ADMIN_PASSWORD, (string)$_POST['password']) && ADMIN_PASSWORD !== 'change-me-to-something-long') {
        session_regenerate_id(true); $_SESSION['mod'] = true; $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } else { sleep(2); $err = 'Wrong password.'; }
}
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: mod.php'); exit; }

$msg = '';
if (!empty($_SESSION['mod']) && isset($_POST['act']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    $id = (string)($_POST['id'] ?? '');
    if (preg_match('#^icons/[A-Z]{4}\d{5}/[^/]+\.png$#', $id)) {
        if ($_POST['act'] === 'keep') {
            data_update('reports', function (array &$r) use ($id) { $r[$id] = ['by' => [], 'ok' => true]; });
            $msg = 'Kept. It is visible again and reports were cleared.';
        } else {
            $deleted = 0;
            foreach ([$id, 'thumbs/' . substr($id, 6)] as $p) {
                [$c, $j] = gh('GET', '/repos/' . GITHUB_REPO . '/contents/' . $p . '?ref=' . GITHUB_BRANCH);
                if ($c !== 200 || empty($j['sha'])) continue;
                [$c2] = gh('DELETE', '/repos/' . GITHUB_REPO . '/contents/' . $p, ['message' => "Remove $p", 'sha' => $j['sha'], 'branch' => GITHUB_BRANCH]);
                if ($c2 === 200) $deleted++;
            }
            mirror_delete($id);
            data_update('reports', function (array &$r) use ($id) { unset($r[$id]); });
            if ($_POST['act'] === 'block') {
                $owner = data_read('owners')[$id] ?? null;
                if ($owner) data_update('blocked', function (array &$b) use ($owner) { if (!in_array($owner, $b, true)) $b[] = $owner; });
                $msg = $owner ? 'Deleted, and that person can no longer upload.' : 'Deleted. (Who uploaded it is unknown, so nobody was blocked.)';
            } else $msg = 'Deleted.';
            forget_icons();
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PSGFX community - moderation</title>
<style>
body{margin:0;font:15px/1.5 system-ui,"Segoe UI",sans-serif;background:#070b16;color:#e8eefb}
.wrap{max-width:1100px;margin:0 auto;padding:24px 16px}
h1{font-size:22px;margin:0 0 4px}.m{color:#8c98ae}
form.login{max-width:340px;margin:12vh auto;display:grid;gap:10px}
input[type=password]{padding:10px 12px;border-radius:10px;border:1px solid #2a3552;background:#0f1628;color:inherit;font:inherit}
button{padding:7px 12px;border-radius:9px;border:1px solid #2a3552;background:#16203a;color:inherit;font:inherit;cursor:pointer}
button.p{background:#3d7bff;border-color:transparent}button.d{background:#5a1d26;border-color:#7a2a35}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-top:18px}
.c{background:#0f1628;border:1px solid #1f2a45;border-radius:14px;padding:10px}
.c.rep{border-color:#a0553a}.c img{width:100%;aspect-ratio:1;border-radius:10px;background:#1a2440;display:block}
.c .t{font-weight:600;margin-top:8px}.c .s{color:#8c98ae;font-size:13px}.c .r{color:#ffb547;font-size:13px}
.c form{display:inline}.acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.msg{background:#13304a;border:1px solid #2f6bff;padding:8px 12px;border-radius:10px;margin-top:12px}
</style></head><body><div class="wrap">
<?php if (empty($_SESSION['mod'])): ?>
<form class="login" method="post"><h1>PSGFX moderation</h1>
<?php if (ADMIN_PASSWORD === 'change-me-to-something-long'): ?><p class="m">Set ADMIN_PASSWORD in config.php first.</p><?php endif; ?>
<?php if (!empty($err)): ?><p class="r"><?= $h($err) ?></p><?php endif; ?>
<input type="password" name="password" placeholder="Password" autofocus><button class="p">Log in</button></form>
<?php else:
    $icons = all_icons(true); $reports = data_read('reports');
    $n = fn($i) => count($reports[$i['id']]['by'] ?? []);
    usort($icons, fn($a, $b) => ($n($b) <=> $n($a)) ?: ($b['time'] <=> $a['time']));
    $csrf = $_SESSION['csrf'];
?>
<h1>Community icons</h1>
<p class="m"><?= count($icons) ?> icons. Reported ones come first; at <?= REPORTS_TO_HIDE ?> reports an icon is hidden until you keep or delete it. <a href="?logout" style="color:#5b90ff">Log out</a></p>
<?php if ($msg): ?><div class="msg"><?= $h($msg) ?></div><?php endif; ?>
<div class="grid">
<?php foreach (array_slice($icons, 0, 300) as $i): $r = $n($i); $hidden = is_hidden($reports, $i['id']);
    $src = is_file(MIRROR_DIR . '/' . $i['id']) ? 'files/' . $i['id'] : raw_url($i['id']); ?>
<div class="c<?= $r ? ' rep' : '' ?>"><img src="<?= $h($src) ?>" alt="" loading="lazy">
<div class="t"><?= $h($i['title']) ?></div>
<div class="s"><?= $h($i['by'] !== '' ? $i['by'] : 'Anonymous') ?> · <?= $h(date('Y-m-d H:i', $i['time'])) ?></div>
<?php if ($r): ?><div class="r"><?= $r ?> report<?= $r > 1 ? 's' : '' ?><?= $hidden ? ' · hidden' : '' ?></div><?php endif; ?>
<div class="acts">
<?php foreach (($r ? ['keep' => 'Keep'] : []) + ['delete' => 'Delete', 'block' => 'Delete + block'] as $act => $label): ?>
<form method="post" <?= $act !== 'keep' ? 'onsubmit="return confirm(\'Delete this icon?\')"' : '' ?>><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= $h($i['id']) ?>"><button name="act" value="<?= $act ?>" class="<?= $act === 'keep' ? 'p' : 'd' ?>"><?= $label ?></button></form>
<?php endforeach; ?>
</div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div></body></html>
