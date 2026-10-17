<?php
/** Admin layout (sidebar + header) and the icon set. */
declare(strict_types=1);

function icon(string $name, string $class = 'ic'): string
{
    static $p = [
        'gamepad'   => '<path d="M6 11h4M8 9v4"/><path d="M15 12h.01M18 10h.01"/><path d="M17.3 5H6.7a4 4 0 0 0-3.96 3.43l-.94 6.6A3 3 0 0 0 4.77 18.4c.98 0 1.9-.48 2.46-1.29L8.5 15h7l1.27 2.11a3 3 0 0 0 2.46 1.29 3 3 0 0 0 2.97-3.37l-.94-6.6A4 4 0 0 0 17.3 5Z"/>',
        'home'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>',
        'server'    => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/>',
        'live'      => '<circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49M7.76 16.24a6 6 0 0 1 0-8.49M19.07 4.93a10 10 0 0 1 0 14.14M4.93 19.07a10 10 0 0 1 0-14.14"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h2M14 14h2M8 18h2M14 18h2"/>',
        'youtube'   => '<path d="M2.5 17a24 24 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.6 49.6 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24 24 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.6 49.6 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15 5-3-5-3z"/>',
        'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'gear'      => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'cpu'       => '<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v4M15 1v4M9 19v4M15 19v4M1 9h4M1 15h4M19 9h4M19 15h4"/>',
        'ram'       => '<rect x="2" y="7" width="20" height="10" rx="1.5"/><path d="M6 11v2M10 11v2M14 11v2M18 11v2M6 17v3M18 17v3"/>',
        'disk'      => '<path d="M22 12H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><path d="M6 16h.01M10 16h.01"/>',
        'upload'    => '<path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M12 12v9M8 16l4-4 4 4"/>',
        'download'  => '<path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M12 12v9M8 17l4 4 4-4"/>',
        'play'      => '<path d="M6 4l14 8-14 8z" fill="currentColor" stroke="none"/>',
        'stop'      => '<rect x="5" y="5" width="14" height="14" rx="2" fill="currentColor" stroke="none"/>',
        'eye'       => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'pencil'    => '<path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'external'  => '<path d="M15 3h6v6M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'copy'      => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M4 16a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2"/>',
        'trash'     => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>',
        'refresh'   => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M8 16H3v5"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'thermo'    => '<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'logs'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M8 13h8M8 17h8M8 9h2"/>',
        'bolt'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'pie'       => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'mic'       => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5"/>',
        'terminal'  => '<path d="m4 17 6-6-6-6M12 19h8"/>',
        'chevron'   => '<path d="m6 9 6 6 6-6"/>',
        'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'flag'      => '<path d="M4 22V4a1 1 0 0 1 1-1h12l-2 4 2 4H5"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'arrow'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chart'     => '<path d="M3 3v18h18"/><path d="m7 15 4-6 4 4 5-8"/>',
        'x'         => '<path d="M18 6 6 18M6 6l12 12"/>',
        'ball'      => '<circle cx="12" cy="12" r="10"/><path d="m12 7 3.8 2.8-1.5 4.4H9.7L8.2 9.8z" fill="currentColor"/>',
    ];
    $d = $p[$name] ?? '';
    return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/** Small coloured picture for each game in tables. */
function game_badge(string $key): string
{
    if ($key === 'flags') {
        return '<span class="gicon gi-flags" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2" fill="#16a34a"/><circle cx="10" cy="12" r="4" fill="#ef4444"/></svg></span>';
    }
    return '<span class="gicon gi-ball" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#f8fafc"/><path d="m12 7 3.8 2.8-1.5 4.4H9.7L8.2 9.8z" fill="#111827"/><circle cx="12" cy="12" r="10" fill="none" stroke="#94a3b8"/></svg></span>';
}

function nav_items(): array
{
    return [
        'dashboard' => ['Dashboard',           'dashboard.php', 'home'],
        'servers'   => ['VPS Monitor',         'servers.php',   'server'],
        'games'     => ['Game Manager',        'games.php',     'gamepad'],
        'streams'   => ['Live Stream Manager', 'streams.php',   'live'],
        'schedule'  => ['Schedule Manager',    'schedule.php',  'calendar'],
        'youtube'   => ['YouTube Manager',     'youtube.php',   'youtube'],
        'activity'  => ['Logs & Alerts',       'activity.php',  'bell'],
        'voice'     => ['Commentator Voice',   'voice.php',     'mic'],
        'terminal'  => ['Web Terminal',        'terminal.php',  'terminal'],
        'settings'  => ['Settings',            'settings.php',  'gear'],
    ];
}

/** The "Your VPS Info" box at the bottom of the sidebar. */
function sidebar_vps(): string
{
    $o = panel_opts();
    $servers = all_servers();
    $srv = $servers ? reset($servers) : null;
    $i = $srv ? server_info($srv) : [];
    $online = $srv && server_online($srv);
    $rows = [
        'Location'  => $o['location'] ?: '—',
        'Plan'      => $o['plan'] ?: '—',
        'CPU'       => !empty($i['cpus']) ? $i['cpus'] . ' vCPU' : '—',
        'RAM'       => !empty($i['mem_total_mb']) ? round($i['mem_total_mb'] / 1024, 1) . ' GB' : '—',
        'Storage'   => !empty($i['disk_total_gb']) ? round((float) $i['disk_total_gb']) . ' GB' : '—',
        'Bandwidth' => (int) $o['bw_limit_gb'] . ' GB',
    ];
    ob_start(); ?>
    <div class="vps-info">
      <div class="vps-info-h"><?= icon('server') ?> Your VPS Info</div>
      <div class="vps-row"><span><?= e($o['provider'] ?: 'VPS') ?></span>
        <span class="tag <?= $online ? 'tag-green' : 'tag-gray' ?>"><?= $online ? 'Active' : ($srv ? 'Offline' : 'None') ?></span></div>
      <?php foreach ($rows as $k => $v): ?>
        <div class="vps-row"><span><?= e($k) ?></span><span><?= e($v) ?></span></div>
      <?php endforeach; ?>
      <a class="btn btn-blue btn-block" href="<?= e($o['upgrade_url']) ?>" target="_blank" rel="noopener">Upgrade Plan</a>
    </div>
    <?php
    return (string) ob_get_clean();
}

function render_bell(): string
{
    $alerts = alerts_unseen();
    ob_start(); ?>
    <div class="bell-wrap">
      <button class="icon-btn" type="button" id="bell-btn" aria-label="Alerts (<?= count($alerts) ?> new)" aria-expanded="false">
        <?= icon('bell') ?><?php if ($alerts): ?><span class="dot-count"><?= count($alerts) > 9 ? '9+' : count($alerts) ?></span><?php endif; ?>
      </button>
      <div class="dropdown bell-menu" id="bell-menu" hidden>
        <div class="dd-head"><strong>Alerts</strong>
          <?php if ($alerts): ?><form method="post" action="alerts_seen.php"><?= csrf_field() ?><button class="link-btn" type="submit">Mark all read</button></form><?php endif; ?>
        </div>
        <?php if (!$alerts): ?><p class="muted small dd-empty">No new alerts. Everything looks fine.</p><?php endif; ?>
        <?php foreach (array_slice($alerts, 0, 8) as $a): ?>
          <div class="dd-item"><span class="ldot l-<?= e($a['level']) ?>"></span><div><div><?= e($a['message']) ?></div><div class="muted small"><?= e(date('M j, H:i', (int) $a['ts'])) ?></div></div></div>
        <?php endforeach; ?>
        <a class="dd-foot" href="activity.php">View all logs</a>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
