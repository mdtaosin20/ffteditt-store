<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$cfg = site_config();
$errors = [];
$fields = [
    'name'      => ['Website name', 40, 'The last word is shown in blue, e.g. “FFTEdit Games”.'],
    'tagline'   => ['Small text under the name', 60, ''],
    'hero_sub'  => ['Purple line in the big banner', 60, ''],
    'hero_text' => ['Banner text', 220, ''],
    'youtube'   => ['YouTube channel link (Watch on YouTube buttons)', 200, 'e.g. https://www.youtube.com/@yourchannel — if empty, the channel link of a live stream is used.'],
    'about'     => ['About text', 500, ''],
    'contact'   => ['Contact (e-mail or link, optional)', 120, ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['private'])) {
    check_csrf();
    $on = $_POST['private'] === '1';
    setting_set('site_private', $on ? '1' : '0');
    log_activity($on ? 'Website set to private (only you and the live streams)' : 'Website set to public', 'info');
    flash($on ? 'The website and games are private now. Only you (logged in here) and the live streams can open them.' : 'The website and games are open to everyone now.');
    redirect('site.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $new = [];
    foreach ($fields as $k => [$label, $max]) {
        $v = trim(str_replace(["\r", "\n"], ' ', (string) ($_POST[$k] ?? '')));
        if (mb_strlen($v) > $max) {
            $errors[] = "{$label}: too long (max {$max} letters).";
        }
        $new[$k] = $v;
    }
    if ($new['name'] === '') $errors[] = 'Website name is needed.';
    if ($new['youtube'] !== '' && !preg_match('#^https://(www\.|m\.)?youtube\.com/[^\s]+$#i', $new['youtube'])) {
        $errors[] = 'YouTube link must look like https://www.youtube.com/@yourchannel';
    }
    if ($new['contact'] !== '' && !filter_var($new['contact'], FILTER_VALIDATE_EMAIL) && !preg_match('#^https://[^\s]+$#', $new['contact'])) {
        $errors[] = 'Contact must be an e-mail address or a link starting with https://';
    }
    foreach (['tagline', 'hero_sub', 'hero_text', 'about'] as $k) {
        if ($new[$k] === '') $new[$k] = site_defaults()[$k];
    }
    if (!$errors) {
        site_save($new);
        log_activity('Website texts saved');
        flash('Saved. The website shows the changes right away.');
        redirect('site.php');
    }
    $cfg = array_merge($cfg, $new);
}

page_start('Website', 'site', $admin);
?>
<div class="page-head">
  <div>
    <h1>Website</h1>
    <p class="muted">Texts and links of the public games website (ffteditt.store). New games appear there automatically.</p>
  </div>
  <div class="head-actions"><a class="btn btn-ghost" href="/" target="_blank" rel="noopener"><?= icon('external') ?>Open website</a></div>
</div>
<?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= e($err) ?></div><?php endforeach; ?>

<?php $priv = site_private(); ?>
<form method="post" class="panel form">
  <?= csrf_field() ?>
  <h2 style="margin-top:0">Who can open the website?</h2>
  <?php if ($priv): ?>
    <p><strong class="ok-text">🔒 Private</strong> — visitors see a “Members only” page where they can ask for access. Only you (logged in here), the devices you approve in <a href="devices.php">Device Access</a> and your live streams can open the website and the games.</p>
    <input type="hidden" name="private" value="0">
    <div class="form-actions"><button class="btn btn-ghost" type="submit">Open the website to everyone</button></div>
  <?php else: ?>
    <p><strong class="warn-text">🌍 Public</strong> — everyone can open the website and play the games.</p>
    <input type="hidden" name="private" value="1">
    <div class="form-actions"><button class="btn btn-blue" type="submit">Make it private</button></div>
  <?php endif; ?>
</form>

<form method="post" class="panel form">
  <?= csrf_field() ?>
  <?php foreach ($fields as $k => [$label, $max, $help]): ?>
    <label class="field"><span><?= e($label) ?></span>
      <?php if ($max > 150 && $k !== 'youtube'): ?>
        <textarea name="<?= e($k) ?>" maxlength="<?= $max ?>" rows="3"><?= e($cfg[$k]) ?></textarea>
      <?php else: ?>
        <input name="<?= e($k) ?>" maxlength="<?= $max ?>" value="<?= e($cfg[$k]) ?>" <?= $k === 'youtube' ? 'type="url" placeholder="https://www.youtube.com/@yourchannel"' : '' ?>>
      <?php endif; ?>
      <?php if ($help): ?><small class="muted"><?= e($help) ?></small><?php endif; ?>
    </label>
  <?php endforeach; ?>
  <div class="form-actions"><button class="btn btn-blue" type="submit">Save</button></div>
</form>
<p class="muted small">Live scores and winners on the website come from the games running on your streams. Each stream's channel link (YouTube Manager → ⚙️) is used for its “Live” button.</p>
<?php page_end();
