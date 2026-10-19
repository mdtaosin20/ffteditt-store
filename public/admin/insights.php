<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

$hours = (int) ($_GET['h'] ?? 24);
if (!in_array($hours, [3, 6, 24, 72], true)) $hours = 24;
$in = insights($hours);
$report = insights_report($in);
$names = war_known_codes();

/** Little line chart of viewers (one point a minute). */
function spark(array $v): string
{
    if (count($v) < 2) return '<p class="muted small">Not enough data yet.</p>';
    $w = 600; $h = 80; $max = max(1, max($v)); $n = count($v) - 1;
    $pts = [];
    foreach ($v as $i => $y) $pts[] = round($i / $n * $w, 1) . ',' . round($h - $y / $max * ($h - 6) - 3, 1);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="Viewers over time">'
        . '<polyline fill="none" stroke="currentColor" stroke-width="2.5" points="' . implode(' ', $pts) . '"/></svg>'
        . '<div class="muted small">last ' . count($v) . ' min · top ' . max($v) . ' viewers</div>';
}

page_start('Audience Insights', 'insights', $admin);
?>
<style>
.ins-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 12px 0; }
.ins-k { padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); }
.ins-k span { display: block; font-size: 12px; opacity: .7; } .ins-k b { font-size: 22px; }
.spark { width: 100%; height: 80px; color: #60a5fa; }
.tip { padding: 10px 12px; border-radius: 10px; margin: 8px 0; border-left: 4px solid #64748b; background: rgba(255,255,255,.04); }
.tip.bad { border-color: #ef4444; } .tip.warn { border-color: #f59e0b; } .tip.good { border-color: #22c55e; } .tip.info { border-color: #3b82f6; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; } .chip { padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,.07); font-size: 13px; }
.req { font-size: 14px; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,.06); } .req b { opacity: .8; }
#report { width: 100%; min-height: 220px; font-family: ui-monospace, monospace; font-size: 12px; }
</style>
<div class="page-head">
  <div>
    <h1>Audience Insights</h1>
    <p class="muted">What your live viewers ask for and why a live is or is not growing. Viewers and likes are saved every minute while a live runs (YouTube channel connected); chat from the last 3 days.</p>
  </div>
  <div class="head-actions seg">
    <?php foreach ([3 => '3 h', 6 => '6 h', 24 => '24 h', 72 => '3 days'] as $h => $l): ?>
      <a class="btn btn-sm <?= $h === $hours ? 'btn-blue' : 'btn-ghost' ?>" href="insights.php?h=<?= $h ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
</div>

<section class="card">
  <h2>Why it is (not) growing</h2>
  <?php foreach ($in['tips'] as [$k, $t]): ?><div class="tip <?= e($k) ?>"><?= e($t) ?></div><?php endforeach; ?>
</section>

<?php foreach ($in['streams'] as $r): ?>
<section class="card">
  <h2><?= game_badge($r['game']) ?> <?= e($r['name']) ?> <?= $r['live'] ? '<span class="tag tag-green">LIVE ' . (int) $r['run_min'] . ' min</span>' : '<span class="tag tag-gray">not live</span>' ?></h2>
  <p class="muted small"><?= e($r['title']) ?></p>
  <div class="ins-grid">
    <div class="ins-k"><span>Watching now</span><b><?= (int) $r['now'] ?></b></div>
    <div class="ins-k"><span>Average viewers</span><b><?= e((string) $r['avg']) ?></b></div>
    <div class="ins-k"><span>Peak</span><b><?= (int) $r['peak'] ?></b></div>
    <div class="ins-k"><span>Last hour</span><b><?= $r['trend'] === null ? '—' : (($r['trend'] >= 0 ? '+' : '') . e((string) $r['trend'])) ?></b></div>
    <div class="ins-k"><span>Live videos (restarts)</span><b><?= (int) $r['videos'] ?></b></div>
    <div class="ins-k"><span>Chat messages</span><b><?= (int) $r['msgs'] ?></b></div>
    <div class="ins-k"><span>People chatting</span><b><?= (int) $r['chatters'] ?></b></div>
    <div class="ins-k"><span>New likes</span><b><?= (int) $r['likes'] ?></b></div>
    <div class="ins-k"><span>New subscribers seen</span><b><?= (int) $r['subs'] ?></b></div>
  </div>
  <?= spark($r['spark']) ?>
  <?php if ($r['ask_share'] || $r['ask_sub'] || $r['gift_list']): ?>
  <h3 style="margin-top:14px">Share &amp; subscribe gifts — did they really work?</h3>
  <div class="ins-grid">
    <div class="ins-k"><span>"Share" messages</span><b><?= (int) $r['ask_share'] ?></b></div>
    <div class="ins-k"><span>"Subscribed" messages</span><b><?= (int) $r['ask_sub'] ?></b></div>
    <div class="ins-k"><span>Countries brought back</span><b><?= (int) $r['gifts']['revive'] ?></b></div>
    <div class="ins-k"><span>Extra flags given</span><b><?= (int) $r['gifts']['flag'] ?></b></div>
  </div>
  <?php if ($r['gifts']['other']): ?><p class="muted small">No gift (and why): <?= e(implode(' · ', array_map(fn ($k, $n) => str_replace('-', ' ', $k) . " {$n}", array_keys($r['gifts']['other']), $r['gifts']['other']))) ?></p><?php endif; ?>
  <?php foreach ($r['gift_list'] as $g): ?><div class="req"><span class="muted"><?= e(date('H:i', (int) $g['ts'])) ?></span> <b><?= e($g['author']) ?></b> · <?= e($g['kind']) ?> · <?= e($names[$g['country']] ?? strtoupper($g['country'])) ?> → <b><?= e(str_replace('-', ' ', $g['result'])) ?></b></div><?php endforeach; ?>
  <?php if ($r['ask_share'] + $r['ask_sub'] > 0 && !$r['gift_list']): ?><div class="tip warn">Viewers wrote share/subscribed but the game logged no gifts yet — the stream is still running the old game. Deploy (Update from Remote → Deploy HEAD Commit); the game updates itself within a minute.</div><?php endif; ?>
  <?php endif; ?>
  <?php if ($r['countries']): ?><h3 style="margin-top:14px">Countries they type</h3><div class="chips">
    <?php foreach ($r['countries'] as $c => $n): ?><span class="chip"><?= e($names[$c] ?? strtoupper($c)) ?> · <?= (int) $n ?></span><?php endforeach; ?></div><?php endif; ?>
  <?php if ($r['scripts']): ?><h3 style="margin-top:14px">How they write (where they are from)</h3><div class="chips">
    <?php foreach ($r['scripts'] as $c => $n): ?><span class="chip"><?= e($c) ?> · <?= (int) $n ?></span><?php endforeach; ?></div><?php endif; ?>
  <?php if ($r['words']): ?><h3 style="margin-top:14px">Common words</h3><div class="chips">
    <?php foreach ($r['words'] as $c => $n): ?><span class="chip"><?= e($c) ?> · <?= (int) $n ?></span><?php endforeach; ?></div><?php endif; ?>
  <?php if ($r['requests']): ?><h3 style="margin-top:14px">Questions and wishes</h3>
    <?php foreach ($r['requests'] as $q): ?><div class="req"><span class="muted"><?= e(date('H:i', $q['ts'])) ?></span> <b><?= e($q['a']) ?>:</b> <?= e($q['t']) ?></div><?php endforeach; ?><?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>Report for Claude</h2>
  <p class="muted">Press <b>Copy</b> and paste it in your chat with Claude — it shows everything above as text, so Claude can tell you what to change.</p>
  <textarea id="report" readonly><?= e($report) ?></textarea>
  <div class="form-actions"><button class="btn btn-blue" type="button" id="copy-report"><?= icon('copy') ?>Copy report</button></div>
</section>
<script>
document.getElementById('copy-report').addEventListener('click', async (e) => {
  const t = document.getElementById('report');
  try { await navigator.clipboard.writeText(t.value); } catch (err) { t.select(); document.execCommand('copy'); }
  e.currentTarget.textContent = 'Copied ✓';
});
</script>
<?php page_end();
