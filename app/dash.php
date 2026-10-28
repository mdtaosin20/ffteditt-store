<?php
/** Pieces of the dashboard. Each returns HTML so the page and the 5-second refresh share the same code. */
declare(strict_types=1);

function dash_server(): ?array
{
    $servers = all_servers();
    $id = (int) ($_GET['server'] ?? 0);
    if ($id && isset($servers[$id])) {
        return $servers[$id];
    }
    foreach ($servers as $s) { // prefer one that is online
        if (server_online($s)) return $s;
    }
    return $servers ? reset($servers) : null;
}

function bar(?float $pct, string $cls): string
{
    $w = $pct === null ? 0 : max(0, min(100, $pct));
    return '<div class="meter"><i class="' . $cls . '" style="width:' . $w . '%"></i></div>';
}

function n1($v): string
{
    return $v === null ? '—' : (string) round((float) $v);
}

function dash_cards(?array $srv): string
{
    $i = $srv ? server_info($srv) : [];
    $online = $srv && server_online($srv);
    $cpu = $online ? ($i['cpu_pct'] ?? null) : null;
    $ram = $online ? pct($i['mem_used_mb'] ?? null, $i['mem_total_mb'] ?? null) : null;
    $disk = pct($i['disk_used_gb'] ?? null, $i['disk_total_gb'] ?? null);
    $up = $online ? ($i['up_kbps'] ?? null) : null;
    $down = $online ? ($i['down_kbps'] ?? null) : null;
    // lives on THIS server (it used to count every server's lives)
    $lq = db()->prepare("SELECT COUNT(*) FROM streams WHERE status = 'live' AND server_id = ?");
    $lq->execute([(int) ($srv['id'] ?? 0)]);
    $live = (int) $lq->fetchColumn();
    $gb = fn ($mb) => number_format($mb / 1024, 2);
    ob_start(); ?>
    <div class="kcard k-green">
      <span class="kicon"><?= icon('server') ?></span>
      <div class="kbody"><div class="klabel">VPS Status</div>
        <div class="kval"><?= $srv ? ($online ? 'Online' : 'Offline') : 'Not added' ?></div>
        <div class="ksub"><?= $online && !empty($i['uptime_s']) ? 'Running for ' . e(fmt_uptime((int) $i['uptime_s'])) : ($srv ? 'Last seen ' . e(ago((int) $srv['last_seen'])) : '<a href="servers.php">Add a server</a>') ?></div>
        <div class="ktiny"><?= $live ? $live . ' Live on this VPS' : ($online ? 'Ready · no live here' : '') ?></div></div>
    </div>
    <div class="kcard k-blue">
      <span class="kicon"><?= icon('cpu') ?></span>
      <div class="kbody"><div class="klabel">CPU Usage</div><div class="kval"><?= $cpu === null ? '—' : n1($cpu) . '%' ?></div></div>
      <?= bar($cpu, 'f-blue') ?>
      <div class="kfoot"><?= (int) ($i['cpus'] ?? 0) ?: '—' ?> vCPU</div>
    </div>
    <div class="kcard k-purple">
      <span class="kicon"><?= icon('ram') ?></span>
      <div class="kbody"><div class="klabel">RAM Usage</div><div class="kval"><?= $ram === null ? '—' : n1($ram) . '%' ?></div></div>
      <?= bar($ram, 'f-purple') ?>
      <div class="kfoot"><?= isset($i['mem_total_mb']) ? $gb((int) $i['mem_used_mb']) . ' GB / ' . round($i['mem_total_mb'] / 1024) . ' GB' : '—' ?></div>
    </div>
    <div class="kcard k-orange">
      <span class="kicon"><?= icon('disk') ?></span>
      <div class="kbody"><div class="klabel">Disk Usage</div><div class="kval"><?= $disk === null ? '—' : n1($disk) . '%' ?></div></div>
      <?= bar($disk, 'f-orange') ?>
      <div class="kfoot"><?= isset($i['disk_total_gb']) ? e($i['disk_used_gb']) . ' GB / ' . round((float) $i['disk_total_gb']) . ' GB' : '—' ?></div>
    </div>
    <div class="kcard k-cyan">
      <span class="kicon"><?= icon('upload') ?></span>
      <div class="kbody"><div class="klabel">Network (Upload)</div><div class="kval"><?= e(fmt_mbps($up)) ?></div>
        <div class="ksub">Live Streaming</div></div>
    </div>
    <div class="kcard k-pink">
      <span class="kicon"><?= icon('download') ?></span>
      <div class="kbody"><div class="klabel">Network (Download)</div><div class="kval"><?= e(fmt_mbps($down)) ?></div>
        <div class="ksub">Game &amp; System</div></div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function stream_form(int $id, string $do, string $label, string $cls, string $ic, string $back, bool $disabled = false, string $confirm = ''): string
{
    $c = $confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '';
    return '<form method="post" action="stream_action.php"' . $c . '>' . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="do" value="' . e($do) . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">'
        . '<button class="btn btn-sm ' . $cls . '" type="submit"' . ($disabled ? ' disabled' : '') . '>' . icon($ic) . e($label) . '</button></form>';
}

function dash_overview(string $back = 'dashboard.php'): string
{
    $servers = all_servers();
    $streams = db()->query('SELECT * FROM streams ORDER BY id')->fetchAll();
    $games = games_list();
    ob_start();
    if (!$streams): ?>
      <tr><td colspan="6" class="td-empty">No streams yet. <a href="stream_edit.php">Add your first stream</a>.</td></tr>
    <?php endif;
    foreach ($streams as $n => $s):
        $srv = $servers[(int) $s['server_id']] ?? null;
        [$code, $label] = stream_view_status($s, $srv);
        $live = $code === 'live';
        $g = game_of_url($s['page_url']);
        $tag = ['live' => 'tag-green', 'warn' => 'tag-orange', 'error' => 'tag-red', 'muted' => 'tag-gray'][$code] ?? 'tag-gray';
        $watch = watch_url($s['channel_url']);
    ?>
      <tr>
        <td class="num"><?= sprintf('%02d', $n + 1) ?></td>
        <td><span class="cell-game"><?= game_badge($g) ?><span><?= e($games[$g]['name']) ?><small><?= e($s['name']) ?></small></span></span></td>
        <td><span class="cell-ch"><span class="yt"><?= icon('play') ?></span><span><?= e($s['channel_name'] ?: $s['name']) ?>
          <?php if ($s['channel_url'] !== ''): ?><small><?= e(channel_short($s['channel_url'])) ?></small><?php endif; ?></span></span></td>
        <td><span class="tag <?= $tag ?>"><?= e($live ? 'LIVE' : ($label === 'Stopped' ? 'Offline' : $label)) ?></span>
          <?php if ($live): $yh = yt_health_state((int) $s['id']);
            if ($s['yt_refresh'] === ''): ?><small class="yth yth-q" title="Connect YouTube for this stream so the panel can check it on YouTube">YouTube: not checked</small>
            <?php elseif ($yh && $yh[2] < 900): ?><small class="yth <?= $yh[0] === 'ok' ? 'yth-ok' : 'yth-bad' ?>" title="<?= e($yh[1]) ?>"><?= $yh[0] === 'ok' ? '✓ On YouTube' : '✗ Not on YouTube' ?></small>
          <?php endif; endif; ?></td>
        <td class="mono"><?= $live && $s['started_at'] ? e(hms((int) $s['started_at'])) : '--' ?></td>
        <td><div class="acts">
          <?php if ($s['desired'] === 'on'): ?>
            <?= stream_form((int) $s['id'], 'stop', 'Stop', 'btn-red', 'stop', $back) ?>
            <?php if ($watch): ?><a class="btn btn-sm btn-blue" href="<?= e($watch) ?>" target="_blank" rel="noopener"><?= icon('eye') ?>Watch</a>
            <?php else: ?><a class="btn btn-sm btn-blue" href="stream_edit.php?id=<?= (int) $s['id'] ?>" title="Add the channel link to use Watch"><?= icon('eye') ?>Watch</a><?php endif; ?>
          <?php else: ?>
            <?= stream_form((int) $s['id'], 'start', 'Start', 'btn-green', 'play', $back, !$srv || $s['stream_key'] === '') ?>
            <a class="icon-sq" href="stream_edit.php?id=<?= (int) $s['id'] ?>" aria-label="Settings for <?= e($s['name']) ?>"><?= icon('gear') ?></a>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach;
    return (string) ob_get_clean();
}

function dash_procs(?array $srv): string
{
    $i = $srv ? server_info($srv) : [];
    $procs = $srv && server_online($srv) ? ($i['procs'] ?? []) : [];
    ob_start();
    if (!$procs): ?>
      <tr><td colspan="2" class="muted small">No data yet<?= $srv && empty($i['cpu_pct']) && server_online($srv) ? ' — update the worker (Web Terminal → Update worker)' : '' ?>.</td></tr>
    <?php endif;
    foreach (array_slice($procs, 0, 5) as $p): ?>
      <tr><td><?= e($p['name']) ?></td><td class="r"><?= e(round((float) $p['cpu'])) ?>% / <?= (int) $p['mem_mb'] ?>MB</td></tr>
    <?php endforeach;
    return (string) ob_get_clean();
}

function dash_uptime(?array $srv): string
{
    $i = $srv ? server_info($srv) : [];
    $on = $srv && server_online($srv);
    ob_start(); ?>
    <div class="ut"><?= icon('clock') ?><div><div class="ut-k">Uptime</div><div class="ut-v"><?= $on && !empty($i['uptime_s']) ? e(fmt_uptime((int) $i['uptime_s'])) : '—' ?></div></div></div>
    <div class="ut"><?= icon('thermo', 'ic t-green') ?><div><div class="ut-k">Temperature</div><div class="ut-v t-green"><?= $on && isset($i['temp_c']) && $i['temp_c'] !== null ? e(round((float) $i['temp_c'])) . '°C' : '—' ?></div></div></div>
    <?php
    return (string) ob_get_clean();
}

function dash_logs(int $n = 6): string
{
    ob_start();
    $rows = recent_logs($n);
    if (!$rows): ?><li class="muted small">Nothing yet.</li><?php endif;
    foreach ($rows as $r): ?>
      <li><span class="ldot l-<?= e($r['level']) ?>"></span><time><?= e(date('H:i:s', (int) $r['ts'])) ?></time><span class="lt l-<?= e($r['level']) ?>"><?= e($r['message']) ?></span></li>
    <?php endforeach;
    return (string) ob_get_clean();
}

function toggle_form(string $action, array $hidden, bool $on, string $label): string
{
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return '<form method="post" action="' . e($action) . '" class="tgl-form">' . csrf_field() . $h
        . '<input type="hidden" name="to" value="' . ($on ? 'off' : 'on') . '">'
        . '<button type="submit" class="tgl ' . ($on ? 'is-on' : '') . '" aria-pressed="' . ($on ? 'true' : 'false') . '" aria-label="' . e($label) . '">'
        . '<span>' . ($on ? 'ON' : 'OFF') . '</span><i></i></button></form>';
}

function dash_live_data(?array $srv): array
{
    $i = $srv ? server_info($srv) : [];
    $on = $srv && server_online($srv);
    return [
        'hist' => $srv ? metrics_history((int) $srv['id']) : null,
        'now' => [
            'cpu'  => $on ? ($i['cpu_pct'] ?? null) : null,
            'ram'  => $on ? pct($i['mem_used_mb'] ?? null, $i['mem_total_mb'] ?? null) : null,
            'disk' => pct($i['disk_used_gb'] ?? null, $i['disk_total_gb'] ?? null),
            'up'   => $on && isset($i['up_kbps']) ? round($i['up_kbps'] / 1000, 1) : null,
            'down' => $on && isset($i['down_kbps']) ? round($i['down_kbps'] / 1000, 1) : null,
        ],
    ];
}

function dash_games(string $back = 'dashboard.php', int $limit = 6): string
{
    $games = games_list();
    ob_start(); ?>
      <div class="tbl-wrap"><table class="tbl tbl-sm">
        <thead><tr><th>ID</th><th>Game Name</th><th>Auto Play</th><th>Speed</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($games as $g): ?>
          <tr>
            <td class="num"><?= sprintf('%02d', $g['id']) ?></td>
            <td><span class="cell-game"><?= game_badge($g['key']) ?><?= e($g['name']) ?></span></td>
            <td><?= toggle_form('games_action.php', ['game' => $g['key'], 'do' => 'autoplay', 'back' => $back], $g['autoplay'], 'Auto play ' . $g['name']) ?></td>
            <td><form method="post" action="games_action.php"><?= csrf_field() ?><input type="hidden" name="game" value="<?= e($g['key']) ?>"><input type="hidden" name="do" value="pace"><input type="hidden" name="back" value="<?= e($back) ?>">
              <select class="sel-sm" name="pace" data-autosubmit aria-label="Speed of <?= e($g['name']) ?>">
                <?php foreach (paces() as $pk => $pl): ?><option value="<?= $pk ?>" <?= $g['pace'] === $pk ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?>
              </select></form></td>
            <td><span class="tag <?= $g['autoplay'] ? 'tag-green' : 'tag-gray' ?>"><?= $g['autoplay'] ? 'Active' : 'Stopped' ?></span></td>
            <td><div class="icons">
              <a class="ia ia-blue" href="<?= e($g['settings']) ?>" aria-label="Edit <?= e($g['name']) ?>" title="Settings"><?= icon('pencil') ?></a>
              <a class="ia ia-blue" href="<?= e($g['url']) ?>" target="_blank" rel="noopener" aria-label="Open <?= e($g['name']) ?>" title="Open game"><?= icon('copy') ?></a>
              <form method="post" action="games_action.php" onsubmit="return confirm('Restart <?= e($g['name']) ?> from the beginning (scores / tournament)?')"><?= csrf_field() ?>
                <input type="hidden" name="game" value="<?= e($g['key']) ?>"><input type="hidden" name="do" value="reset"><input type="hidden" name="back" value="<?= e($back) ?>">
                <button class="ia ia-red" type="submit" aria-label="Restart <?= e($g['name']) ?>" title="Restart game"><?= icon('refresh') ?></button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php
    return (string) ob_get_clean();
}

function dash_schedules(string $back = 'dashboard.php', int $limit = 6): string
{
    $scheds = db()->query('SELECT sc.*, s.name, s.page_url FROM schedules sc JOIN streams s ON s.id = sc.stream_id ORDER BY sc.start_time LIMIT ' . $limit)->fetchAll();
    ob_start(); ?>
      <div class="tbl-wrap"><table class="tbl tbl-sm">
        <thead><tr><th>Game</th><th>Start Time</th><th>End Time</th><th>Repeat</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$scheds): ?><tr><td colspan="6" class="td-empty">No schedules. <a href="schedule.php#add">Add one</a> to start and stop a stream at set times.</td></tr><?php endif; ?>
        <?php foreach ($scheds as $sc): ?>
          <tr>
            <td><span class="cell-game"><?= game_badge(game_of_url($sc['page_url'])) ?><?= e($sc['name']) ?></span></td>
            <td><?= e(date('h:i A', strtotime($sc['start_time']))) ?></td>
            <td><?= e(date('h:i A', strtotime($sc['end_time']))) ?></td>
            <td><?= e(days_label($sc['days'])) ?></td>
            <td><span class="tag <?= $sc['enabled'] ? 'tag-green' : 'tag-gray' ?>"><?= $sc['enabled'] ? 'Active' : 'Paused' ?></span></td>
            <td><div class="icons">
              <a class="ia ia-blue" href="schedule.php?edit=<?= (int) $sc['id'] ?>#add" aria-label="Edit schedule"><?= icon('pencil') ?></a>
              <form method="post" action="schedule.php" onsubmit="return confirm('Delete this schedule?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $sc['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                <button class="ia ia-red" type="submit" aria-label="Delete schedule"><?= icon('trash') ?></button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php
    return (string) ob_get_clean();
}

function dash_youtube(string $back = 'dashboard.php', int $limit = 6): string
{
    $streams = db()->query('SELECT * FROM streams ORDER BY id LIMIT ' . $limit)->fetchAll();
    ob_start(); ?>
      <div class="tbl-wrap"><table class="tbl tbl-sm">
        <thead><tr><th>Channel Name</th><th>Stream Key</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$streams): ?><tr><td colspan="4" class="td-empty">No channels yet.</td></tr><?php endif; ?>
        <?php foreach ($streams as $s): ?>
          <tr>
            <td><span class="cell-game"><?= game_badge(game_of_url($s['page_url'])) ?><?= e($s['channel_name'] ?: $s['name']) ?></span></td>
            <td class="dots">••••••••••••</td>
            <td><?php if ($s['stream_key'] === ''): ?><span class="tag tag-red">No key</span>
                <?php elseif ($s['status'] === 'live'): ?><span class="tag tag-green">Live</span>
                <?php else: ?><span class="tag tag-green">Connected</span><?php endif; ?></td>
            <td><div class="icons">
              <a class="ia ia-gray" href="stream_edit.php?id=<?= (int) $s['id'] ?>" aria-label="Settings"><?= icon('gear') ?></a>
              <form method="post" action="stream_edit.php" onsubmit="return confirm('Delete this channel and its stream?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="do" value="delete">
                <button class="ia ia-red" type="submit" aria-label="Delete"><?= icon('trash') ?></button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php
    return (string) ob_get_clean();
}

function dash_monitor(?array $srv, array $live): string
{
    ob_start(); ?>
    <section class="panel">
      <div class="ph"><h2><?= icon('chart') ?>VPS Resource Monitor</h2><span class="tag tag-green rt">Real-time</span></div>
      <div class="mon">
        <div class="charts">
          <?php foreach ([['cpu', 'CPU Usage', '%', 'c-blue'], ['ram', 'RAM Usage', '%', 'c-purple'], ['disk', 'Disk Usage', '%', 'c-orange'],
                          ['up', 'Network (Upload)', ' Mbps', 'c-green'], ['down', 'Network (Download)', ' Mbps', 'c-pink']] as [$k, $label, $unit, $cls]):
              $v = $live['now'][$k]; ?>
          <div class="chart <?= $cls ?>">
            <div class="chart-h"><span><?= e($label) ?></span><span class="chart-r" data-now="<?= $k ?>-r"><?= $v === null ? '—' : e($v . $unit) ?></span></div>
            <div class="chart-v" data-now="<?= $k ?>"><?= $v === null ? '—' : e(($k === 'up' || $k === 'down') ? $v . $unit : round($v) . $unit) ?></div>
            <canvas data-chart="<?= $k ?>" data-unit="<?= e($unit) ?>" height="44"></canvas>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="procs">
          <h3>Current Processes</h3>
          <table class="tbl tbl-mini"><thead><tr><th>Process</th><th class="r">CPU / RAM</th></tr></thead>
            <tbody data-live="procs"><?= dash_procs($srv) ?></tbody></table>
          <div class="uptime" data-live="uptime"><?= dash_uptime($srv) ?></div>
        </div>
      </div>
    </section>
    <?php
    return (string) ob_get_clean();
}
