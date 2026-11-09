<?php
// 🎞 Video Live: the VPS downloads a video from the panel's library here (signed per video; resumable with Range).
require __DIR__ . '/../_boot.php';
$id = (string) ($_GET['v'] ?? '');
if (!preg_match('/^[a-z0-9]{6,20}$/', $id) || !hash_equals(vl_sig($id), (string) ($_GET['sig'] ?? ''))) { http_response_code(403); exit('not allowed'); }
$v = vl_video($id);
if (!$v || ($v['kind'] ?? 'file') !== 'file' || !is_file($f = vl_file($v))) { http_response_code(404); exit('no video'); }
@set_time_limit(0);
while (ob_get_level()) ob_end_clean();
$size = filesize($f); $start = 0; $end = $size - 1;
header('Accept-Ranges: bytes');
header('Content-Type: video/mp4');
header('Cache-Control: no-store');
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); } else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */{$size}"); exit; }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}
header('Content-Length: ' . ($end - $start + 1));
$fh = fopen($f, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) { $chunk = fread($fh, (int) min(1048576, $left)); echo $chunk; flush(); $left -= strlen($chunk); }
fclose($fh);
