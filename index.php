<?php
// main.php
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once 'configdb.php';

/* ======== DB INIT ======== */

/* Seed/aktualizácia ADMIN účtu */
$adminName = 'admin';
$adminPass = 'Ty,ned0stane5_BoDy';
$adminHash = password_hash($adminPass, PASSWORD_DEFAULT);
$gameON = true;


$st = $mysqli->prepare("
  INSERT INTO timy (name, pass_hash)
  VALUES (?, ?)
  ON DUPLICATE KEY UPDATE pass_hash = VALUES(pass_hash)
");
$st->bind_param("ss", $adminName, $adminHash);
$st->execute();
$st->close();
$mysqli->query("
CREATE TABLE IF NOT EXISTS submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  task_id INT NOT NULL,
  answer_text TEXT NULL,
  file_url VARCHAR(512) NULL,       -- napr. cesta k fotke
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_task (user_id, task_id)
);
");

$mysqli->query("
CREATE TABLE IF NOT EXISTS timy (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$mysqli->query("
CREATE TABLE IF NOT EXISTS score (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  task_id INT NOT NULL,
  points  INT NOT NULL,
  completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_task (user_id, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Seed default tím, ak chýba */
$teamExists = $mysqli->query("SELECT 1 FROM timy WHERE name='test' LIMIT 1")->fetch_row();
if (!$teamExists) {
  $hash = password_hash('1234', PASSWORD_DEFAULT);
  $st = $mysqli->prepare("INSERT INTO timy (name, pass_hash) VALUES ('test', ?)");
  $st->bind_param("s", $hash);
  $st->execute();
  $st->close();
}

/* ======== Helpery ======== */
function flash_set(string $msg): void { $_SESSION['flash_msg'] = $msg; }
function flash_get(): string {
  $m = $_SESSION['flash_msg'] ?? '';
  unset($_SESSION['flash_msg']);
  return $m;
}

// z authu už máš $isLoggedIn, $currentUserId, $currentTeamName

function is_admin(mysqli $db, int $uid): bool {
  $st = $db->prepare("SELECT is_admin FROM timy WHERE id=?");
  $st->bind_param("i", $uid);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return !empty($row) && (int)$row['is_admin'] === 1;
}

// Získanie odovzdaní s prípadným skóre (filter podľa task_id ak je zadaný)
function fetch_submissions_with_scores(mysqli $db, ?int $taskFilter = null): array {
  $sql = "
    SELECT sub.user_id, t.name AS team_name, sub.task_id, sub.answer_text, sub.file_url, sub.created_at,
           sc.points
    FROM submissions sub
    JOIN timy t ON t.id = sub.user_id
    LEFT JOIN score sc ON sc.user_id = sub.user_id AND sc.task_id = sub.task_id
  ";
  $params = [];
  $types  = '';
  if ($taskFilter !== null) {
    $sql .= " WHERE sub.task_id = ? ";
    $types .= 'i';
    $params[] = $taskFilter;
  }
  $sql .= " ORDER BY sub.task_id ASC, sub.created_at DESC";

  $st = $db->prepare($sql);
  if ($types !== '') { $st->bind_param($types, ...$params); }
  $st->execute();
  $res = $st->get_result();
  $rows = $res->fetch_all(MYSQLI_ASSOC);
  $st->close();
  return $rows;
}

// Zápis/úprava bodov (ručné hodnotenie)


// ==== HELPERY ====
// ==== AUTO-LOGIN z cookies (iba ak nie je session) ====
if (!isset($_SESSION['user_id']) && isset($_COOKIE['team_id'], $_COOKIE['team_pass'])) {
  $cid  = (int)$_COOKIE['team_id'];
  $cpass = (string)$_COOKIE['team_pass'];
  if ($cid > 0 && $cpass !== '') {
    $row = fetch_team_by_id($mysqli, $cid);
    if ($row && password_verify($cpass, $row['pass_hash'])) {
      $_SESSION['user_id']   = (int)$row['id'];
      $_SESSION['team_name'] = $row['name'];
      // voliteľné: obnov cookies (predĺženie)
      set_login_cookies((int)$row['id'], $row['name'], $cpass);
    } else {
      clear_login_cookies();
    }
  } else {
    clear_login_cookies();
  }
}

// ==== FLAGY PRE ŠABLÓNU ====
$isLoggedIn = isset($_SESSION['user_id'])
           || (($_COOKIE['logged_in'] ?? '') === '1' && isset($_COOKIE['team_id'], $_COOKIE['team_pass']));

$currentUserId = isset($_SESSION['user_id'])
  ? (int)$_SESSION['user_id']
  : ((($_COOKIE['logged_in'] ?? '') === '1' && isset($_COOKIE['team_id']) && ctype_digit((string)$_COOKIE['team_id']))
      ? (int)$_COOKIE['team_id']
      : 0);

$loggedName = isset($_SESSION['team_name'])
  ? (string)$_SESSION['team_name']
  : (string)($_COOKIE['team_name'] ?? '');
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function set_login_cookies(int $teamId, string $teamName, string $plainPass): void {
  $exp = time() + 3600*24*30; // 30 dní
  setcookie('team_id',   (string)$teamId, $exp, '/', '', false, true);
  setcookie('team_name', $teamName,        $exp, '/', '', false, true);
  setcookie('team_pass', $plainPass,       $exp, '/', '', false, true);
  setcookie('logged_in', '1',              $exp, '/', '', false, true);   // ← PRIDANÉ
}

function clear_login_cookies(): void {
  foreach (['team_id','team_name','team_pass','logged_in'] as $c) {       // ← PRIDANÉ logged_in
    setcookie($c, '', time()-3600, '/');
  }
}


function fetch_team_by_id(mysqli $db, int $id): ?array {
  $st = $db->prepare("SELECT id, name, pass_hash FROM timy WHERE id=?");
  $st->bind_param("i", $id);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row ?: null;
}

function fetch_team_by_name(mysqli $db, string $name): ?array {
  $st = $db->prepare("SELECT id, name, pass_hash FROM timy WHERE name=?");
  $st->bind_param("s", $name);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row ?: null;
}

$loginMsg = $_SESSION['flash_login_msg'] ?? '';
unset($_SESSION['flash_login_msg']);

// ==== SPRACOVANIE POST (login / logout) + PRG redirect ====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'logout') {
    unset($_SESSION['user_id'], $_SESSION['team_name']);
    clear_login_cookies();
    $_SESSION['flash_login_msg'] = 'Úspešne odhlásený.';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
  }

  if ($action === 'login') {
    $name = trim($_POST['team_name'] ?? '');
    $pass = (string)($_POST['team_pass'] ?? '');
    if ($name === '' || $pass === '') {
      $_SESSION['flash_login_msg'] = 'Vyplň meno aj heslo.';
    } else {
      $row = fetch_team_by_name($mysqli, $name);
      if ($row && password_verify($pass, $row['pass_hash'])) {
        $_SESSION['user_id']   = (int)$row['id'];
        $_SESSION['team_name'] = $row['name'];
        set_login_cookies((int)$row['id'], $row['name'], $pass);
        $_SESSION['flash_login_msg'] = 'Prihlásenie úspešné.';
      } else {
        clear_login_cookies();
        $_SESSION['flash_login_msg'] = 'Nesprávny tím alebo heslo.';
      }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
  }
}
if (($_POST['action'] ?? '') === 'admin_set_points') {
  if (!$isLoggedIn || !is_admin($mysqli, (int)$currentUserId)) {
    flash_set('Nemáš oprávnenie.');
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
  }

  $uid    = (int)($_POST['user_id'] ?? 0);
  $tid    = (int)($_POST['task_id'] ?? 0);
  $points = (int)($_POST['points']  ?? 0);

  if ($uid >= 0 && $tid >= 0) {
    // 1) UPDATE
    $mysqli->query("UPDATE score SET points=$points, completed_at=NOW() WHERE user_id=$uid AND task_id=$tid");
    if ($mysqli->affected_rows === 0) {
      // 2) INSERT (ak ešte neexistuje)
      $mysqli->query("INSERT INTO score (user_id, task_id, points, completed_at) VALUES ($uid, $tid, $points, NOW())");
    }
    flash_set("Body nastavené: tím #$uid, úloha #$tid → $points b.");
  } else {
    flash_set('Chýbajú údaje pre hodnotenie.');
  }
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}





// Peniaze – záznamy kreditu (kladné aj záporné)
$mysqli->query("
CREATE TABLE IF NOT EXISTS coin (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  amount  INT NOT NULL,               -- +príjem / -výdaj
  note    VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Produkty v obchode
$mysqli->query("
CREATE TABLE IF NOT EXISTS produkty (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(100) NOT NULL,
  img   VARCHAR(255) DEFAULT NULL,
  price INT NOT NULL                 -- cena v kreditoch (integer!)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// seed produktov (iba ak je prázdna)
$hasProducts = $mysqli->query("SELECT 1 FROM produkty LIMIT 1")->fetch_row();
if (!$hasProducts) {
  $mysqli->query("INSERT INTO produkty (name,img,price) VALUES
    ('Fotka z archívu', 'https://via.placeholder.com/300x180?text=Fotka', 30),
    ('Tip od informátora', 'https://via.placeholder.com/300x180?text=Tip', 55),
    ('Mapa mesta', 'https://via.placeholder.com/300x180?text=Mapa', 80),
    ('Jednorazový odposlúch', 'https://via.placeholder.com/300x180?text=Odposluch', 120)
  ");
}

// voliteľne: každému tímu štartovný kredit (iba raz)
$startCredit = 100;  // ← daj si do konštanty podľa potreby
// pridáme len ak nemá žiadny záznam v coin
$teams = $mysqli->query("SELECT id FROM timy");
while ($t = $teams->fetch_assoc()) {
  $tid = (int)$t['id'];
  $exists = $mysqli->query("SELECT 1 FROM coin WHERE user_id=$tid LIMIT 1")->fetch_row();
  if (!$exists) {
    $st = $mysqli->prepare("INSERT INTO coin (user_id, amount, note) VALUES (?,?,?)");
    $note = 'Štartovný kredit';
    $st->bind_param("iis", $tid, $startCredit, $note);
    $st->execute();
    $st->close();
  }
}
$teams->close();


$shopMsg = '';
if (($_POST['action'] ?? '') === 'buy') {
  if (!isset($_SESSION['user_id'])) {
    $shopMsg = 'Najprv sa prihlás.';
  } else {
    $uid = (int)$_SESSION['user_id'];
    $pid = (int)($_POST['product_id'] ?? 0);

    // načítaj produkt
    $st = $mysqli->prepare("SELECT id, name, price FROM produkty WHERE id=?");
    $st->bind_param("i", $pid);
    $st->execute();
    $prod = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$prod) {
      $shopMsg = 'Produkt neexistuje.';
    } else {
      // aktuálny zostatok
      $row = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS bal FROM coin WHERE user_id=$uid")->fetch_assoc();
      $balance = (int)$row['bal'];

      if ($balance < (int)$prod['price']) {
        $shopMsg = 'Nemáš dosť kreditov.';
      } else {
        // zapíš výdaj (negatívna suma)
        $st = $mysqli->prepare("INSERT INTO coin (user_id, amount, note) VALUES (?,?,?)");
        $neg = -1 * (int)$prod['price'];
        $note = 'Kúpa: '.$prod['name'];
        $st->bind_param("iis", $uid, $neg, $note);
        $st->execute();
        $st->close();

        // po úspechu redirect, aby si neodosielal formulár 2× a aby sa zostatok hneď obnovil
        header('Location: index.php#shop');
        exit;
      }
    }
  }
}


function admin_set_points(mysqli $db, int $userId, int $taskId, int $points): bool {
  // 1) Skús UPDATE
  $st = $db->prepare("UPDATE score SET points=?, completed_at=NOW() WHERE user_id=? AND task_id=?");
  $st->bind_param("iii", $points, $userId, $taskId);
  $st->execute();
  $affected = $st->affected_rows;
  $st->close();

  if ($affected > 0) return true;

  // 2) Ak nič nebol update-nuté → vlož nový riadok
  $st = $db->prepare("INSERT INTO score (user_id, task_id, points, completed_at) VALUES (?, ?, ?, NOW())");
  $st->bind_param("iii", $userId, $taskId, $points);
  $ok = $st->execute();
  $st->close();
  return $ok;
}



/* ======== Herný dátum (každých 5 min = +1 deň od 2.11.1989) ======== */
$GAME_REAL_START = strtotime('2025-11-14 09:00:00 Europe/Bratislava'); // uprav podľa potreby
$elapsed = max(0, time() - $GAME_REAL_START);
$days = intdiv($elapsed, 300); // 300 s = 5 min
$base = new DateTime('1989-11-02 00:00:00', new DateTimeZone('UTC'));
if ($days !== 0) { $base->modify(($days > 0 ? '+' : '') . $days . ' day'); }
$hernyDatum = $base->format('j.n.Y');

/* ======== VPLYV z DB: tím1, tím2, tím3 + komunisti ako zvyšok do 1000 ======== */
$team2 = $team3 = $team5 = 0;
$name2 = 'Tím 2'; $name3 = 'Tím 3'; $name5 = 'Tím 5';

$q = $mysqli->query("
  SELECT t.id, t.name, COALESCE(SUM(s.points),0) AS pts
  FROM timy t
  LEFT JOIN score s ON s.user_id = t.id
  GROUP BY t.id, t.name
");
while ($row = $q->fetch_assoc()) {
  $id  = (int)$row['id'];
  $pts = (int)$row['pts'];
  if ($id === 1) { $team2 = max(0, $pts); $name2 = $row['name']; }
  if ($id === 2) { $team3 = max(0, $pts); $name3 = $row['name']; }
  if ($id === 3) { $team5 = max(0, $pts); $name5 = $row['name']; }
}
$q->close();

$teamsTotal = $team2 + $team3 + $team5;
$communists = max(0, 1000 - $teamsTotal);

$chartLabels = ['Komunisti', $name2, $name3, $name5];
$chartValues = [$communists, $team2, $team3, $team5];
$Vyhra = ($communists < (1/3)*1000);

$isLoggedIn = isset($_SESSION['user_id']) && ($_COOKIE['logged_in'] ?? '') === '1';
$loggedTeamName = $_SESSION['team_name'] ?? ($_COOKIE['team_name'] ?? '');

?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Main – Mestská hra</title>
  <link rel="stylesheet" href="main.css">
  <script defer src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <header class="site-header">
    <button id="hamburger" aria-label="Menu" aria-expanded="false" aria-controls="side-menu">
      <span></span><span></span><span></span>
    </button>
    <h1 class="title">MESTSKÁ HRA 2025 &nbsp;•&nbsp; 14. 11.</h1>
  </header>

  <aside id="side-menu" class="side-menu">
    <nav>
      <button class="menu-item" data-open="home">Home</button>
      <button class="menu-item" data-open="rules">Pravidlá</button>
	  <?php if ($gameON){ ?>
      <button class="menu-item" data-open="game">Hra</button>
      <button class="menu-item" data-open="login">Prihlásenie</button>
	  <button class="menu-item" data-open="Tuzex">Obchod</button>
	  <?php if ($isLoggedIn && is_admin($mysqli, (int)$currentUserId)) { ?>
	  <button class="menu-item" data-open="admin-panel">admin-panel</button>
	  <?php } ?>
	  <button class="menu-item" data-open="radio">Rádio</button>
	  <?php } ?>

	  
    </nav>
  </aside>

  <main class="container">
  <?php if ($__f = flash_get()): ?>
  <div class="flash" style="margin:10px auto;max-width:800px;padding:10px 14px;border:1px solid #c9b58e;background:#fff8e6;border-radius:10px;">
    <?= h($__f) ?>
  </div>
<?php endif; ?>

    <!-- HOME -->
    <section id="home" class="panel">
      <h3>Vitaj na Mestskej hre</h3>
      <p>
        Pozývame ťa na tematickú mestskú hru pripomínajúcu udalosti <strong>17. novembra</strong>.
        Cez príbehy a úlohy sa posunieš časom späť do roku 1989 a budeš zbierať <em>vplyv</em> pre svoj tím.
        Premýšľaj, rozhoduj sa a objavuj súvislosti, ktoré formovali slobodu, ktorú dnes berieme ako samozrejmosť.
      </p>
      <p>
        Hru spúšťame <strong>14. 11. 2025</strong>. V našej „hernej časovej osi“ sa každý <strong>deň</strong> posunie
        každých <strong>5 minút</strong>. Sleduj mapu, úlohy a priebežný stav.
      </p>
    </section>

    <!-- HRA -->
    <section id="game" class="panel" hidden>
      <h3>Aktuálny stav vplyvu</h3>

      <div class="chart-wrap">
        <canvas id="influenceChart" width="320" height="320"></canvas>
      </div>

      <div class="game-meta">
        <div><strong>Herný dátum:</strong> <span id="herny-datum"><?= h($hernyDatum) ?></span></div>
        <div class="muted">Čas hry: od 2.11.1989, každých 5 min = +1 deň</div>
      </div>

      <?php if ($Vyhra): ?>
        <p class="flash">🎉 Dosiahli ste viac ako 2/3 vplyvu. <strong>Vyhrali ste hru. 🎉</strong></p>
      <?php endif; ?>

      <div class="legend-tools" style="text-align:center;margin:.5rem 0 1rem;">
        <button id="cb-toggle" class="btn" type="button">Farboslepý režim</button>
      </div>
      <ul id="cb-legend" style="list-style:none; padding:0; margin:0 0 1rem 0; text-align:center;"></ul>

      <hr>

      <div class="spomienky-grid">
        <?php for ($i=1; $i<=20; $i++): ?>
          <a class="spomienka-btn" href="spomienka.php?id=<?= $i ?>">Spomienka <?= $i ?></a>
        <?php endfor; ?>
      </div>
    </section>

    <!-- PRAVIDLÁ -->
    <section id="rules" class="panel" hidden>
      <h3>Pravidlá</h3>
      <ul>
        <li>Začiatok: <strong>14. 11. 2025</strong>, miesto štartu podľa inštrukcií organizátorov.</li>
        <li>Herný čas: každých 5 min v reále = 1 deň v roku 1989.</li>
        <li>Úlohy prinášajú body (vplyv); niektoré sa míňajú časom.</li>
        <li>Dodržuj bezpečnosť a pokyny organizátorov. Hra prebieha v reálnom meste.</li>
        <li>Po dokončení úlohy uvidíš výsledné body a úlohu už nepôjde hrať znovu.</li>
      </ul>
    </section>

    <!-- PRIHLÁSENIE -->
    <section id="login" class="panel" hidden>
<?php if ($loginMsg !== ''): ?>
  <div class="alert"><?=$loginMsg?></div>
<?php endif; ?>

<?php if ($isLoggedIn): ?>
  <div class="login-state">
    Si prihlásený: <strong><?=h($loggedName)?></strong>
    <form method="post" style="display:inline-block;margin-left:1rem;">
      <input type="hidden" name="action" value="logout">
      <button type="submit">Odhlásiť</button>
    </form>
  </div>
<?php else: ?>
  <form method="post" class="login-form">
    <input type="hidden" name="action" value="login">
    <label>Meno tímu
      <input type="text" name="team_name" value="<?=h($_COOKIE['team_name'] ?? '')?>" required>
    </label>
    <label>Heslo
      <input type="password" name="team_pass" required>
    </label>
    <button type="submit">Prihlásiť</button>
  </form>
<?php endif; ?>

</section>
<section id="Tuzex" class="panel" hidden>
  <h3>Obchod</h3>

  <?php
  // zostatok prihláseného tímu
  $balance = 0;
  if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $row = $mysqli->query("SELECT COALESCE(SUM(amount),0) AS bal FROM coin WHERE user_id=$uid")->fetch_assoc();
    $balance = (int)$row['bal'];
  }
  ?>

  <?php if ($shopMsg): ?>
    <p class="flash"><?= h($shopMsg) ?></p>
  <?php endif; ?>

  <?php if (!isset($_SESSION['user_id'])): ?>
    <p>Na nákup sa prosím <strong>prihlás</strong> v sekcii „Prihlásenie“.</p>
  <?php else: ?>
    <p><strong>Tvoj zostatok:</strong> <?= $balance ?> kreditov</p>

    <div class="products-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;">
      <?php
      $res = $mysqli->query("SELECT id, name, img, price FROM produkty ORDER BY price ASC");
      while ($p = $res->fetch_assoc()):
        $afford = ($balance >= (int)$p['price']);
      ?>
        <div class="product-card" style="background:#fff;border:1px solid #a08d6d;border-radius:10px;overflow:hidden;">
          <?php if ($p['img']): ?>
            <img src="<?= h($p['img']) ?>" alt="" style="display:block;width:100%;height:140px;object-fit:cover;">
          <?php endif; ?>
          <div style="padding:.75rem">
            <div style="font-weight:700;margin-bottom:.25rem;"><?= h($p['name']) ?></div>
            <div style="margin-bottom:.5rem;">Cena: <strong><?= (int)$p['price'] ?></strong></div>

            <form method="post">
              <input type="hidden" name="action" value="buy">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <button type="submit"
                class="btn"
                <?= $afford ? '' : 'disabled' ?>
                style="width:100%;<?= $afford ? '' : 'background:#a33' ?>;">
                <?= $afford ? 'Kúpiť' : 'Nedostatok kreditov' ?>
              </button>
            </form>
          </div>
        </div>
      <?php endwhile; $res->close(); ?>
    </div>
  <?php endif; ?>
</section>
<?php if ($isLoggedIn && is_admin($mysqli, (int)$currentUserId)): ?>
<section id="admin-panel" class="panel" hidden>
  <h2>Kontrola odovzdaní (Admin)</h2>

    <form method="get" style="margin-bottom:1rem; display:flex; gap:.5rem; align-items:center;">
      <input type="hidden" name="page" value="<?= htmlspecialchars($_GET['page'] ?? '', ENT_QUOTES) ?>">
      <label>Filtrovať úlohu:
        <input type="number" name="task_filter" min="1" value="<?= isset($_GET['task_filter']) ? (int)$_GET['task_filter'] : '' ?>">
      </label>
      <button type="submit">Filtrovať</button>
      <a href="<?= strtok($_SERVER['REQUEST_URI'], '?') ?>" style="margin-left:.5rem;">Zrušiť filter</a>
    </form>

    <?php
      $taskFilter = isset($_GET['task_filter']) && $_GET['task_filter'] !== '' ? max(1, (int)$_GET['task_filter']) : null;
      $rows = fetch_submissions_with_scores($mysqli, $taskFilter);
      if (!$rows) {
        echo '<p>Žiadne odovzdania.</p>';
      } else {
        echo '<div class="table" style="overflow:auto;">';
        echo '<table style="width:100%; border-collapse:collapse;">';
        echo '<thead><tr>';
        echo '<th style="text-align:left; border-bottom:1px solid #ddd;">Tím</th>';
        echo '<th style="text-align:left; border-bottom:1px solid #ddd;">Úloha</th>';
        echo '<th style="text-align:left; border-bottom:1px solid #ddd;">Odpoveď</th>';
        echo '<th style="text-align:left; border-bottom:1px solid #ddd;">Súbor</th>';
        echo '<th style="text-align:left; border-bottom:1px solid #ddd;">Odovzdané</th>';
        echo '<th style="text-align:left; border-bottom:1px solid #ddd;">Body</th>';
        echo '<th style="text-align:left; border-bottom:1px solid #ddd;">Hodnotenie</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
		  $r = str_replace("\r", "<br>", $r);
          $ansShort = $r['answer_text'] !== null ? mb_strimwidth($r['answer_text'], 0, 600, '…', 'UTF-8') : '';
		  $ansSafe = htmlspecialchars($ansShort, ENT_QUOTES);
		  $ansSafe = str_replace('&lt;br&gt;', '<br>', $ansSafe);
          $fileHtml = $r['file_url'] ? '<a target="_blank" href="'.htmlspecialchars($r['file_url'], ENT_QUOTES).'">otvoriť</a>' : '';
          echo '<tr>';
          echo '<td>'.htmlspecialchars($r['team_name'], ENT_QUOTES).'</td>';
          echo '<td>#'.(int)$r['task_id'].'</td>';
          echo '<td style="max-width:420px;">'.$ansSafe.'</td>';
          echo '<td>'.$fileHtml.'</td>';
          echo '<td>'.htmlspecialchars($r['created_at'], ENT_QUOTES).'</td>';
          echo '<td>'.($r['points'] !== null ? (int)$r['points'] : '—').'</td>';
          echo '<td>';
          echo '<form method="post" style="display:flex; gap:.5rem; align-items:center;">';
          echo '  <input type="hidden" name="action" value="admin_set_points">';
          echo '  <input type="hidden" name="user_id" value="'.(int)$r['user_id'].'">';
          echo '  <input type="hidden" name="task_id" value="'.(int)$r['task_id'].'">';
          echo '  <input type="number" name="points" value="'.($r['points'] !== null ? (int)$r['points'] : 0).'" style="width:6rem;">';
          echo '  <button type="submit">Uložiť</button>';
          echo '</form>';
          echo '</td>';
          echo '</tr>';
        }

        echo '</tbody></table></div>';
      }
    ?>
</section>
<?php endif; ?>

  </main>

 
  <script>
// --- hamburger ---

 

const btn  = document.getElementById('hamburger');
const menu = document.getElementById('side-menu');

btn.addEventListener('click', () => {
  const isOpen = menu.classList.toggle('open');
  btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
});
document.addEventListener('click', (e) => {
  if (!menu.classList.contains('open')) return;
  const clickedHamburger = e.target === btn || btn.contains(e.target);
  const clickedMenu = menu.contains(e.target);
  if (!clickedHamburger && !clickedMenu) {
    menu.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }
});

// --- prepínač panelov ---
function showPanel(id){
  document.querySelectorAll('.panel').forEach(p => p.setAttribute('hidden',''));
  const target = document.getElementById(id);
  if (target) target.removeAttribute('hidden');

  if (id === 'game') {
    requestAnimationFrame(() => {
      initPieIfNeeded();
      renderLegend();
    });
  }
  menu.classList.remove('open');
  btn.setAttribute('aria-expanded', 'false');
}

document.querySelectorAll('.menu-item').forEach(el => {
  document.querySelectorAll('.menu-item').forEach(el => {
  el.addEventListener('click', () => {
    const id = el.dataset.open;

    if (id === 'radio') {
      window.location.href = 'radio.html'; // 🔹 otvorí novú stránku
      return;
    }

    showPanel(id);
  });
});
});

// --- cookies helpery ---
function setCookie(name, value, days=365) {
  const d = new Date(); d.setTime(d.getTime() + (days*24*60*60*1000));
  document.cookie = `${name}=${encodeURIComponent(value)}; expires=${d.toUTCString()}; path=/`;
}
function getCookie(name) {
  const m = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return m ? decodeURIComponent(m[2]) : null;
}

// --- dáta pre graf z PHP ---
const chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
const chartValues = <?= json_encode($chartValues) ?>;

// farby: Komunisti, Tím 2, Tím 3, Tím 5
const colors = ['#d62728', '#2ca02c', '#7fbfff', '#1f77b4'];

// symboly do výsekov (indexy súhlasí s poradím dát)
const symbols = ['', '▲', '■', '●']; // Komunisti bez symbolu

let pie = null;

// plugin: nakreslí veľký symbol do stredu výseku len vo farboslepom režime
const bigSymbolsPlugin = {
  id: 'bigSymbols',
  afterDatasetsDraw(chart) {
    // zobrazuj len ak je režim zapnutý
    const cbMode = getCookie('colorblind') === '1';
    if (!cbMode) return;

    const { ctx } = chart;
    const meta = chart.getDatasetMeta(0);
    ctx.save();
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#111';
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 4;

    meta.data.forEach((arc, i) => {
      const sym = symbols[i] || '';
      if (!sym) return;

      const mid = (arc.startAngle + arc.endAngle) / 2;
      const r = arc.innerRadius + (arc.outerRadius - arc.innerRadius) * 0.6;
      const x = arc.x + Math.cos(mid) * r;
      const y = arc.y + Math.sin(mid) * r;

      const baseSize = arc.outerRadius * 0.22;
      const angle = arc.endAngle - arc.startAngle;
      const angleFactor = Math.min(1, angle / 0.9);
      const fontSize = Math.max(14, Math.min(44, baseSize * angleFactor));

      ctx.font = `bold ${fontSize}px system-ui, Arial, sans-serif`;
      ctx.strokeText(sym, x, y);
      ctx.fillText(sym, x, y);
    });

    ctx.restore();
  }
};


function initPieIfNeeded() {
  if (pie) { pie.resize(); return; }
  const canvas = document.getElementById('influenceChart');
  if (!canvas || !window.Chart) return;

  pie = new Chart(canvas, {
    type: 'pie',
    data: {
      labels: chartLabels,
      datasets: [{
        data: chartValues,
        backgroundColor: colors
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } }
    },
    plugins: [bigSymbolsPlugin]
  });
}


// --- herný dátum (refresh každú minútu) ---
(function(){
  const startReal = <?= (int)$GAME_REAL_START ?> * 1000; // ms
  const dateEl = document.getElementById('herny-datum');
  function update() {
    if (!dateEl) return;
    const elapsed = Math.max(0, Date.now() - startReal);
    const days = Math.floor(elapsed / (5*60*1000));
    const base = new Date(Date.UTC(1989, 10, 2)); // 2.11.1989
    base.setUTCDate(base.getUTCDate() + days);
    const d = base.getUTCDate();
    const m = base.getUTCMonth() + 1;
    const y = base.getUTCFullYear();
    dateEl.textContent = `${d}.${m}.${y}`;
  }
  update();
  setInterval(update, 60000);
})();

// --- legenda + farboslepý režim ---
const legendEl = document.getElementById('cb-legend');
const cbBtn = document.getElementById('cb-toggle');

function renderLegend() {
  if (!legendEl) return;
  const cb = getCookie('colorblind') === '1';
  const items = [
    {label: chartLabels[0], color: colors[0], shape: ''},
    {label: chartLabels[1], color: colors[1], shape: '▲'},
    {label: chartLabels[2], color: colors[2], shape: '■'},
    {label: chartLabels[3], color: colors[3], shape: '●'},
  ];
  legendEl.innerHTML = items.map(it => {
    const mark = cb && it.shape ? ` <span style="font-weight:700;">${it.shape}</span>` : '';
    return `<li style="display:inline-flex;align-items:center;gap:.4rem;margin:.25rem .6rem;">
      <span style="display:inline-block;width:14px;height:14px;background:${it.color};border-radius:3px;border:1px solid #333;"></span>
      <span>${it.label}${mark}</span>
    </li>`;
  }).join('');
}

if (cbBtn) {
  cbBtn.addEventListener('click', () => {
    const current = getCookie('colorblind') === '1';
    setCookie('colorblind', current ? '0' : '1');
    renderLegend();
    if (pie) pie.update(); // <— pridaj toto, aby sa graf prekreslil
  });
}

renderLegend();
</script>

</body>
</html>
