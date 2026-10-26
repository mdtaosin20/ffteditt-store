<?php
// Home page: the games website.
require __DIR__ . '/_boot.php';

$site  = site_config();
[$n1, $n2] = site_name_parts($site['name']);
$games = games_list();
$watch = site_watch_url();
$liveN = site_live_count();
$cats  = [
    'football'  => ['Football', '⚽', '#16a34a'],
    'countries' => ['Countries', '🏳️', '#1f6bff'],
    'battle'    => ['Battles', '🏆', '#f59e0b'],
    'arcade'    => ['Arcade', '🕹️', '#db2777'],
];
$catCount = [];
foreach ($games as $g) {
    foreach ($g['tags'] as $t) $catCount[$t] = ($catCount[$t] ?? 0) + 1;
}
$liveGame = [];
foreach (live_streams() as $l) {
    if ($l['live']) $liveGame[$l['game']] = $l['url'] ?: ($liveGame[$l['game']] ?? '');
}
$ic = fn ($n) => icon($n);
header('Cache-Control: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($site['name']) ?> · Play free games, watch live 24/7</title>
<meta name="description" content="<?= e($site['hero_text']) ?>">
<meta property="og:title" content="<?= e($site['name']) ?>">
<meta property="og:description" content="<?= e($site['hero_text']) ?>">
<meta property="og:image" content="/assets/site/hero.jpg">
<meta name="theme-color" content="#070d1c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=1">
</head>
<body>
<header class="hdr" id="top">
  <button class="icon-btn menu-btn" type="button" id="menu-btn" aria-label="Open menu" aria-controls="side" aria-expanded="false"><?= $ic('menu') ?></button>
  <a class="brand" href="/">
    <span class="pad"><?= $ic('gamepad') ?></span>
    <span><span class="bt"><?= e($n1) ?> <b><?= e($n2) ?></b></span><span class="bs"><?= e($site['tagline']) ?></span></span>
  </a>
  <label class="search"><?= $ic('search') ?><span class="sr">Search games</span><input type="search" id="q" placeholder="Search games…" autocomplete="off"></label>
  <div class="hr">
    <div class="live-n" data-live="count"><?php if ($liveN): ?><span class="dot"></span><?= $liveN ?> LIVE now<?php endif; ?></div>
    <?php if ($watch): ?><a class="yt-btn" href="<?= e($watch) ?>" target="_blank" rel="noopener"><?= $ic('play') ?><span>Watch on YouTube</span></a><?php endif; ?>
  </div>
</header>

<div class="layout">
  <aside class="side" id="side">
    <nav class="nav">
      <a href="#top" class="on" data-filter="all"><?= $ic('home') ?>Home</a>
      <a href="#games" data-filter="all"><?= $ic('grid') ?>All Games</a>
      <a href="#live"><?= $ic('live') ?>Live on YouTube</a>
      <a href="#games" data-filter="football"><?= $ic('ball') ?>Football Games</a>
      <a href="#games" data-filter="countries"><?= $ic('flag') ?>Country Games</a>
      <a href="#games" data-filter="fav"><?= $ic('heart') ?>Favorites</a>
      <hr>
      <a href="#about"><?= $ic('info') ?>About</a>
      <a href="#contact"><?= $ic('mail') ?>Contact</a>
    </nav>
    <div class="box">
      <h3><span aria-hidden="true">👑</span> Live Scoreboard</h3>
      <div data-live="score"><?= site_score_html() ?></div>
    </div>
    <div class="box">
      <h3><span aria-hidden="true">💬</span> Join the fun</h3>
      <p class="muted small">Comment your team or country on the YouTube live!</p>
    </div>
  </aside>
  <div class="shade" id="shade" hidden></div>

  <main class="main">
    <section class="hero">
      <img src="/assets/site/hero.jpg" alt="" width="900" height="583">
      <div class="hero-tx">
        <div class="w1">WELCOME TO</div>
        <h1 class="w2"><?= e($n1) ?> <b><?= e($n2) ?></b></h1>
        <div class="w3"><?= e($site['hero_sub']) ?></div>
        <p><?= e($site['hero_text']) ?></p>
        <div class="btns">
          <a class="b b-blue" href="#games"><?= $ic('play') ?>Start Playing</a>
          <?php if ($watch): ?><a class="b b-red" href="<?= e($watch) ?>" target="_blank" rel="noopener"><span class="rec"></span>Watch Live</a><?php endif; ?>
        </div>
      </div>
    </section>

    <section class="sec" id="games">
      <div class="sh"><h2><?= $ic('gamepad') ?>Our Games</h2>
        <div class="chips" role="group" aria-label="Filter games">
          <button type="button" class="chip on" data-filter="all">All</button>
          <?php foreach ($cats as $k => [$label]): if (empty($catCount[$k])) continue; ?>
            <button type="button" class="chip" data-filter="<?= e($k) ?>"><?= e($label) ?></button>
          <?php endforeach; ?>
          <button type="button" class="chip" data-filter="fav">♥ Favorites</button>
        </div>
      </div>
      <div class="cards" id="cards">
        <?php foreach ($games as $g):
            $live = array_key_exists($g['key'], $liveGame);
            $liveUrl = $liveGame[$g['key']] ?? '';
            $playCls = ['flags' => 'b-green', 'war' => 'b-red', 'tug' => 'b-red', 'keeper' => 'b-blue', 'race' => 'b-green', 'cupkick' => 'b-green'][$g['key']] ?? 'b-blue'; ?>
        <article class="card" data-game="<?= e($g['key']) ?>" data-tags="<?= e(implode(' ', $g['tags'])) ?>" data-search="<?= e(strtolower($g['title'] . ' ' . $g['name'] . ' ' . implode(' ', $g['tags']))) ?>">
          <a class="th" href="<?= e($g['url']) ?>"><img src="<?= e($g['thumb']) ?>" alt="<?= e($g['title']) ?>" loading="lazy" width="480" height="311">
            <?php if ($live): ?><span class="badge"><i></i>LIVE</span><?php endif; ?></a>
          <button class="fav" type="button" aria-pressed="false" aria-label="Add <?= e($g['title']) ?> to favorites"><?= $ic('heart') ?></button>
          <div class="cb">
            <h3 class="cn"><?= e($g['title']) ?></h3>
            <div class="cc"><?= e($g['name']) ?> · <?= e(ucfirst($g['category'])) ?></div>
            <p class="cd"><?= e($g['desc']) ?></p>
            <div class="cbtn">
              <a class="b <?= $playCls ?>" href="<?= e($g['url']) ?>"><?= $ic('play') ?>Play</a>
              <?php if ($live && $liveUrl): ?><a class="b b-red" href="<?= e($liveUrl) ?>" target="_blank" rel="noopener"><span class="rec"></span>Live</a>
              <?php elseif ($watch): ?><a class="b b-dark" href="<?= e($watch) ?>" target="_blank" rel="noopener"><?= $ic('youtube') ?>YouTube</a><?php endif; ?>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
        <?php foreach (['A new game is on the way. Tell us in the live chat what you want to see!', 'More games are coming — subscribe so you don\'t miss them.'] as $txt): ?>
        <article class="card soon" data-tags="soon" data-search="new game coming soon">
          <div class="th" aria-hidden="true">?</div>
          <div class="cb"><h3 class="cn">New Game</h3><div class="cc">Coming soon</div><p class="cd"><?= e($txt) ?></p>
            <div class="cbtn"><span class="b b-off">Coming soon</span></div></div>
        </article>
        <?php endforeach; ?>
      </div>
      <p class="muted none" id="none" hidden>No games found.</p>
    </section>

    <section class="sec">
      <div class="sh"><h2><?= $ic('trophy') ?>Latest Results</h2></div>
      <div class="results" data-live="results"><?= site_results_html() ?></div>
    </section>

    <section class="sec two" id="about">
      <div><h2><?= $ic('info') ?>About</h2><p><?= e($site['about']) ?></p></div>
      <div id="contact"><h2><?= $ic('mail') ?>Contact</h2>
        <p><?php if ($site['contact'] !== ''):
            $c = $site['contact'];
            $href = filter_var($c, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $c : (preg_match('#^https?://#', $c) ? $c : '');
            echo $href ? '<a href="' . e($href) . '" target="_blank" rel="noopener">' . e($c) . '</a>' : e($c);
          else: ?>Say hi in the YouTube live chat — we read every comment!<?php endif; ?></p>
        <?php if ($watch): ?><p><a class="b b-red" href="<?= e($watch) ?>" target="_blank" rel="noopener"><?= $ic('youtube') ?>Our YouTube channel</a></p><?php endif; ?>
      </div>
    </section>
  </main>

  <aside class="rc">
    <div class="box">
      <h3><?= $ic('bolt') ?>Quick Access</h3>
      <div class="qa">
        <a class="q q-green" href="#games" data-filter="all"><?= $ic('gamepad') ?>Play Games</a>
        <a class="q q-blue" href="#games" data-filter="all"><?= $ic('grid') ?>All Games</a>
        <?php if ($watch): ?><a class="q q-red" href="<?= e($watch) ?>" target="_blank" rel="noopener"><?= $ic('play') ?>YouTube</a>
        <?php else: ?><a class="q q-red" href="#live"><?= $ic('play') ?>YouTube</a><?php endif; ?>
        <a class="q q-purple" href="#games" data-filter="fav"><?= $ic('heart') ?>Favorites</a>
      </div>
    </div>
    <div class="box">
      <h3><?= $ic('gamepad') ?>Game Categories</h3>
      <?php foreach ($cats as $k => [$label, $emo, $col]): ?>
        <a class="cat" href="#games" data-filter="<?= e($k) ?>"><span class="ci" style="background:<?= e($col) ?>" aria-hidden="true"><?= $emo ?></span><span class="cl"><?= e($label) ?></span><b><?= (int) ($catCount[$k] ?? 0) ?> ›</b></a>
      <?php endforeach; ?>
    </div>
    <div class="box" id="live">
      <h3><span class="rec"></span>Live on YouTube</h3>
      <div data-live="live"><?= site_live_html() ?></div>
    </div>
  </aside>
</div>

<footer class="foot">
  <div class="fb"><?= e($n1) ?> <b><?= e($n2) ?></b></div>
  <nav class="fl"><a href="#top">Home</a><a href="#games">Games</a><a href="#live">Live</a><a href="#about">About</a><a href="#contact">Contact</a></nav>
  <div class="muted">Made with <span style="color:#ff4f6e">♥</span> for Gamers</div>
</footer>
<script src="/assets/site.js?v=1"></script>
</body>
</html>
