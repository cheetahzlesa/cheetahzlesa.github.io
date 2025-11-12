<?php
// auth.php
// Očakáva: session_start() už beží, $mysqli je mysqli spojenie

header('Content-Type: text/html; charset=utf-8');
require_once 'configdb.php';

/* ======== DB INIT ======== */
/* === DB INIT (auto-create DB + tables) === */
// --- Helpere (rovnaké ako v main.php) ---
function auth_fetch_team_by_id(mysqli $db, int $id): ?array {
  $st = $db->prepare("SELECT id, name, pass_hash FROM timy WHERE id=?");
  $st->bind_param("i", $id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row ?: null;
}

function auth_set_login_cookies(int $teamId, string $teamName, string $plainPass): void {
  $exp = time() + 3600*24*30; // 30 dní
  setcookie('team_id',   (string)$teamId, $exp, '/', '', false, true);
  setcookie('team_name', $teamName,        $exp, '/', '', false, true);
  setcookie('team_pass', $plainPass,       $exp, '/', '', false, true);
}

function auth_clear_login_cookies(): void {
  foreach (['team_id','team_name','team_pass'] as $c) {
    setcookie($c, '', time()-3600, '/');
  }
}

// --- Výstupné premenné pre volajúci kód ---
$isLoggedIn      = false;
$currentUserId   = null;
$currentTeamName = null;

// 1) Ak je už session, použijeme ju
if (isset($_SESSION['user_id'], $_SESSION['team_name'])) {
  $isLoggedIn      = true;
  $currentUserId   = (int)$_SESSION['user_id'];
  $currentTeamName = (string)$_SESSION['team_name'];
} else {
  // 2) Skús auto-login z cookies
  if (isset($_COOKIE['team_id'], $_COOKIE['team_pass'])) {
    $cid  = (int)$_COOKIE['team_id'];
    $cpass = (string)$_COOKIE['team_pass'];

    if ($cid > 0 && $cpass !== '') {
      $row = auth_fetch_team_by_id($mysqli, $cid);
      if ($row && password_verify($cpass, $row['pass_hash'])) {
        // úspech → nastav session a (voliteľne) obnov cookies
        $_SESSION['user_id']   = (int)$row['id'];
        $_SESSION['team_name'] = (string)$row['name'];

        $isLoggedIn      = true;
        $currentUserId   = (int)$row['id'];
        $currentTeamName = (string)$row['name'];

        // predĺžime platnosť cookies (ak chceš)
        auth_set_login_cookies((int)$row['id'], $row['name'], $cpass);
      } else {
        // cookies neplatné → zmaž len login cookies
        auth_clear_login_cookies();
      }
    } else {
      auth_clear_login_cookies();
    }
  }
}
