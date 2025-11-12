<?php
// schedule.php (mysqli verzia)
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Bratislava');
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

// ==== DB pripojenie (podľa tvojho zadania) ====
require_once 'configdb.php';
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['error'=>true,'message'=>'Chyba pri spojení: '.$mysqli->connect_error]);
  exit;
}

// ---- KONŠTANTY ----
define('SLOT_SECONDS', 1800);                 // 30 min
define('REPORT_ANCHOR_UNIX', 1730227200);     // nastav si (prvá “nulová” hranica)

// Pomôcka: má tabuľka stĺpec 'active'?
function has_active($mysqli, $table) {
  $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE 'active'");
  return $res && $res->num_rows > 0;
}

try {
  $now = time();
  $lastBoundary = intval(floor($now / SLOT_SECONDS) * SLOT_SECONDS);
  $nextBoundary = $lastBoundary + SLOT_SECONDS;

  // --- REPORTÁŽE ---
  $boundaryIndex = intval(floor(($lastBoundary - REPORT_ANCHOR_UNIX) / SLOT_SECONDS));
  if ($boundaryIndex < 0) $boundaryIndex = 0;

  $reportsWhere = has_active($mysqli, 'reports') ? "WHERE active=1" : "";
  $repRes = $mysqli->query("SELECT id, link, duration_sec, seq_index FROM reports $reportsWhere ORDER BY seq_index ASC");
  if (!$repRes) throw new Exception('SQL reports: '.$mysqli->error);

  $reports = [];
  while ($row = $repRes->fetch_assoc()) $reports[] = $row;
  $reportCount = count($reports);

  $report = null;
  if ($reportCount > 0) {
    $idx = $boundaryIndex % $reportCount;
    $report = $reports[$idx];
  }

  if ($report) {
    $reportDur = max(1, intval($report['duration_sec']));
    if ($now < $lastBoundary + $reportDur) {
      $startedAt = $lastBoundary;
      $endsAt = $startedAt + $reportDur;
      echo json_encode([
        'type'=>'report',
        'id'=>intval($report['id']),
        'url'=>$report['link'],
        'started_at'=>$startedAt,
        'position_sec'=>max(0,$now-$startedAt),
        'ends_at'=>$endsAt,
        'refetch_after_sec'=>max(1,$endsAt-$now)
      ], JSON_UNESCAPED_SLASHES);
      exit;
    }
  }

  // --- HUDBA ---
  $musicWhere = has_active($mysqli, 'music') ? "WHERE active=1" : "";
  $musRes = $mysqli->query("SELECT id, link, duration_sec FROM music $musicWhere");
  if (!$musRes) throw new Exception('SQL music: '.$mysqli->error);

  $music = [];
  while ($row = $musRes->fetch_assoc()) $music[] = $row;
  $musicCount = count($music);

  if ($musicCount === 0) {
    echo json_encode(['type'=>'silence','message'=>'Žiadne aktívne skladby v DB.','refetch_after_sec'=>30]);
    exit;
  }

  // LCG RNG deterministicky podľa lastBoundary
  $state = ($lastBoundary & 0x7fffffff);
  $fillStart = $report ? ($lastBoundary + max(1,intval($report['duration_sec']))) : $lastBoundary;
  if ($fillStart > $now) $fillStart = $now;

  $cursor = $fillStart;
  $prevIdx = -1;
  $currentTrack = null;
  $currentStart = $cursor;

  $lcg = function($state) {
    $a=1103515245; $c=12345; $m=0x80000000;
    $state = ($a*$state + $c) % $m;
    $u = $state / $m;
    return [$state,$u];
  };

  while (true) {
    list($state,$u) = $lcg($state);
    $idx = intval(floor($u * $musicCount));
    if ($idx == $prevIdx && $musicCount > 1) $idx = ($idx+1) % $musicCount;

    $track = $music[$idx];
    $dur = max(1, intval($track['duration_sec']));

    if ($cursor >= $now) { $currentTrack=$track; $currentStart=$cursor; break; }
    if ($cursor + $dur > $now) { $currentTrack=$track; $currentStart=$cursor; break; }

    $cursor += $dur;
    $prevIdx = $idx;

    if ($cursor - $fillStart > 24*3600) { $currentTrack=$music[0]; $currentStart=$now; break; }
  }

  $pos = max(0, $now - $currentStart);
  $endsAt = $currentStart + max(1, intval($currentTrack['duration_sec']));
  $refetchAfter = min(max(1,$nextBoundary-$now), max(1,$endsAt-$now));

  echo json_encode([
    'type'=>'music',
    'id'=>intval($currentTrack['id']),
    'url'=>$currentTrack['link'],
    'started_at'=>$currentStart,
    'position_sec'=>$pos,
    'ends_at'=>$endsAt,
    'refetch_after_sec'=>$refetchAfter
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>true,'message'=>$e->getMessage()]);
}
