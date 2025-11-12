<?php
  echo '<!doctype html><html lang="sk"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<link rel="stylesheet" href="styl.css">';
  
session_start();
require_once 'configdb.php';
header('Content-Type: text/html; charset=utf-8');

/* === DB INIT (auto-create DB + tables) === */

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
/* NEW: per-task stav (štart/finish) */
$mysqli->query("
CREATE TABLE IF NOT EXISTS task_state (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  task_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_user_task_state (user_id, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

require_once __DIR__ . '/auth.php';




/* SCORE helpers */
function get_score(mysqli $db, int $userId, int $taskId): ?int {
  $st = $db->prepare("SELECT points FROM score WHERE user_id=? AND task_id=?");
  $st->bind_param("ii", $userId, $taskId);
  $st->execute();
  $res = $st->get_result()->fetch_assoc();
  $st->close();
  return $res ? (int)$res['points'] : null;
}
function save_score(mysqli $db, int $userId, int $taskId, int $points): void {
  $st = $db->prepare("
    INSERT INTO score (user_id, task_id, points)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE points = VALUES(points)
  ");
  $st->bind_param("iii", $userId, $taskId, $points);
  $st->execute();
  $st->close();
}

/* TASK STATE helpers */
function ensure_task_started(mysqli $db, int $userId, int $taskId): array {
  // vráti ['started_at' => 'YYYY-mm-dd HH:ii:ss', 'completed_at' => null|datetime]
  $st = $db->prepare("SELECT started_at, completed_at FROM task_state WHERE user_id=? AND task_id=?");
  $st->bind_param("ii", $userId, $taskId);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if ($row) return $row;

  $st2 = $db->prepare("INSERT INTO task_state (user_id, task_id, started_at) VALUES (?, ?, NOW())");
  $st2->bind_param("ii", $userId, $taskId);
  $st2->execute(); $st2->close();

  return ['started_at' => date('Y-m-d H:i:s'), 'completed_at' => null];
}
function get_task_state(mysqli $db, int $userId, int $taskId): ?array {
  $st = $db->prepare("SELECT started_at, completed_at FROM task_state WHERE user_id=? AND task_id=?");
  $st->bind_param("ii", $userId, $taskId);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row ?: null;
}
function complete_task(mysqli $db, int $userId, int $taskId): void {
  $st = $db->prepare("UPDATE task_state SET completed_at=NOW() WHERE user_id=? AND task_id=? AND completed_at IS NULL");
  $st->bind_param("ii", $userId, $taskId);
  $st->execute(); $st->close();
}



/* ======================== Úlohy ======================== */

/** Úloha 1 – Dotazník */
function task_1(mysqli $db, string $returnUrl): void {
    $userId = $_SESSION['user_id'] ?? 1;
    $taskId = 1;

    // Kontrolu „už splnené“ robíme LEN ak to nie je POST
    $isPost   = ($_SERVER['REQUEST_METHOD'] === 'POST');
    $existing = $isPost ? null : get_score($db, $userId, $taskId);

    // Postavy a pravdepodobnosti [ +2 , 0 , -3 ] v %
    $people = [
    'jozef_novotny'     => ['name' => 'Jozef Novotný',     'p' => [25, 5, 70]],
    'zuzana_dolezalova' => ['name' => 'Zuzana Doležalová', 'p' => [70, 20, 10]],
    'eva_hricova'       => ['name' => 'Eva Hricová',       'p' => [80, 20,  0]],
	'peter_blaho'     => ['name' => 'Peter Blaho',     'p' => [40, 20, 30]],
    'jan_varga' => ['name' => 'Nadporučík Ján Varga', 'p' => [0, 5, 95]],
    'milan_sebo'       => ['name' => 'Milan Šebo',       'p' => [90, 10,  0]],
	'peter_farkas'     => ['name' => 'Peter Farkaš',     'p' => [0, 70, 30]],
    'jozef_cibula' => ['name' => 'Jozef Cibuľa', 'p' => [30, 40, 30]],
    'darina_kovacova'       => ['name' => 'Darina Kováčová',       'p' => [50, 0,  50]],
	'ondrej_krtko'       => ['name' => 'Ondrej Krtko',       'p' => [10, 30,  60]],
  ];

    $selected = $_POST['osoby'] ?? [];
    $results  = [];
    $points   = 0;
    $justFinishedPoints = null; // body získané práve teraz (v rámci tohto POSTu)

    if ($isPost && is_array($selected) && $selected) {
    // 1️⃣ Ak existujú cookies s uloženými výsledkami tejto úlohy, z nich načítaj
    if (isset($_COOKIE['task1_results'])) {
        $results = json_decode($_COOKIE['task1_results'], true) ?: [];
        $points = array_sum(array_column($results, 'outcome'));
    } else {
        // 2️⃣ Inak vygeneruj nové výsledky
        foreach ($selected as $key) {
            if (!isset($people[$key])) continue;
            [$p2, $p0, $pm3] = $people[$key]['p'];

            $r = random_int(1, 100);
            if     ($r <= $p2)          { $outcome =  2; $desc = 'sa pridal/a ku združeniu'; }
            elseif ($r <= $p2 + $p0)    { $outcome =  0; $desc = 'vás ignoroval/a'; }
            else                        { $outcome = -3; $desc = 'vás nahlásil/a Verejnej Bezpečnosti'; }

            $points += $outcome;
            $results[$key] = [
                'name'    => $people[$key]['name'],
                'outcome' => $outcome,
                'desc'    => $desc
            ];
        }
        // Uloženie do cookies (5 hodín)
        setcookie('task1_results', json_encode($results), time() + 3600*5, '/');
    }

    save_score($db, $userId, $taskId, $points);
    $justFinishedPoints = $points;
}


    // Render
   
    echo '<title>Úloha 1 – Podpora</title></head><body>';
    echo '<main class="container spomienka"><h1>Úloha 1 – Podpora</h1><hr>';

    // Zadanie – vždy viditeľné
    echo '<section class="zadanie" style="text-align:left">';
?>
  <p><em>V meste sa pohybuje veľa ľudí. Niektorí tajne nenávidia režim, iní zas potajme kolaborujú a donášajú na svojich priateľov. Bez ľudí sa však nič nezmení — ak chceme mať väčšiu šancu uspieť pri zmene režimu, musíme získať ich podporu.</em></p>

  <h2>Úloha: Získaj spojencov, vyhni sa donášačom</h2>

  <p>V okolí nájdete miniživotopisy na malých kartičkách. Vašou úlohou je určiť, <strong>ktoré osoby by ste oslovili</strong> so zámerom pridať ich do vášho združenia.</p>

  <h3>Postup</h3>
  <ol>
    <li>Nájdite v okolí kartičky s miniživotopismi.</li>
	<li>Nechajte ich na mieste a prečítajte si ich</li>
	<li>Rozhodnite sa, ktoré z osôb chcete osloviť</li>
    <li>Označte <strong>ľubovoľný počet osôb</strong>, ktoré chcete osloviť.</li>
    <li>Odošlite svoj výber</li>
  </ol>

  <h3>Hodnotenie</h3>
  <ul>
    <li>Za každú osobu, ktorá sa <strong>pridá k vášmu združeniu</strong>, získavate <strong>Vplyv</strong>.</li>
    <li><strong>Pozor!</strong> Niektoré osoby môžu <strong>nahlásiť</strong> vaše aktivity <strong>Verejnej bezpečnosti (VB)</strong>. V takom prípade <strong>Vplyv stratíte na úkor komunistov</strong>.</li>
  </ul>

  <p><strong>Tip:</strong> Čítajte medzi riadkami. Nie všetko je na kartičkách povedané priamo.</p>

<?php
    echo '</section>';
	global $isLoggedIn;
	if (!$isLoggedIn){ echo '<p style="text-align:center;margin-top:1rem;"><strong>Pre plnenie úlohy sa prihláste.</strong></p>';
    echo '<p style="text-align:center;"><a href="index.php" class="btn btn-dark">⬅️ Vrátiť sa na hlavnú stránku</a></p>';
    echo '</main></body></html>';
    return;}
		
    // Ak práve prebehol POST a máme výsledky → zobraz ich (aj keď už existuje záznam v DB, POST má prioritu v rámci tohto requestu)
    if ($isPost && $results) {
        echo '<section class="zadanie" style="text-align:left">';
        echo '<h2 class="section-title">Výsledky</h2><ul>';
        foreach ($results as $r) {
            $out = ($r['outcome'] > 0 ? '+' : '') . $r['outcome'];
            echo '<li><strong>' . h($r['name']) . '</strong> — ' . h($r['desc']) . " ({$out} b)</li>";
        }
        echo '</ul><p><strong>Spolu Vplyv za úlohu:</strong> ' . ($points >= 0 ? '+' : '') . $points . '</p>';
        if ($returnUrl) echo '<p><a class="btn btn-dark" href="' . h($returnUrl) . '">Späť na spomienku</a></p>';
        echo '</section>';
    }
    // Inak (nie je POST) – ak už má v DB záznam, zobraz informáciu a body
    elseif (!$isPost && $existing !== null) {
        echo '<section class="zadanie" style="text-align:left">';
        echo '<h2 class="section-title">Úlohu ste už splnili</h2>';
        echo '<p>Za túto úlohu ste získali <strong>' . ($existing >= 0 ? '+' : '') . $existing . ' bodov</strong>.</p>';
        if ($returnUrl) echo '<p><a class="btn btn-dark" href="' . h($returnUrl) . '">Späť na spomienku</a></p>';
        echo '</section>';
    }

    // Formulár zobraz iba vtedy, keď úloha ešte NIE JE splnená (a zároveň to nie je POST s výsledkami)
    if (!$isPost && $existing === null) {
        echo '<section><form method="post" class="task-form">';
        echo '<fieldset><legend class="form-label">Vyber osoby</legend>';
        foreach ($people as $key => $p) {
            echo '<div><label><input type="checkbox" name="osoby[]" value="' . h($key) . '"> ' . h($p['name']) . '</label></div>';
        }
        echo '</fieldset><button type="submit" class="btn btn-dark btn-submit" style="margin-top:1rem">Hodnotiť výber</button></form></section>';
    }

    ?> <footer style="text-align:center; margin-top:2rem; font-size:0.95rem; color:#555;">
  &copy; <?php echo date('Y'); ?> 70. Zbor Bizón Víťazí
  <br>
  <a href="index.php" class="btn-main">⬅️ Vrátiť sa na hlavnú stránku</a>
</footer>
</main></body></html>
<?php
}
function task_2(mysqli $db, string $returnUrl): void {
  $userId = $_SESSION['user_id'] ?? 1;
  $taskId = 2;

  // ak akcia=complete (AJAX z klienta)
  if (isset($_GET['action']) && $_GET['action'] === 'complete') {
    // z DB: štart
    $state = get_task_state($db, $userId, $taskId);
    if (!$state) { http_response_code(400); echo 'Not started'; exit; }

    $startedAt = strtotime($state['started_at']);
    $elapsedMin = floor((time() - $startedAt) / 60);
    // výpočet bodov
    if ($elapsedMin >= 100) {
      $points = -20; // fail
    } else {
      $points = 10 - intdiv($elapsedMin, 5);
    }
    // uložiť a dokončiť
    save_score($db, $userId, $taskId, $points);
    complete_task($db, $userId, $taskId);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'points' => $points, 'elapsed_min' => $elapsedMin]);
    exit;
  }

  // Priprav začiatok (prvé otvorenie štartuje čas)
  $state = ensure_task_started($db, $userId, $taskId);
  $startedAt = strtotime($state['started_at']);
  $completed = $state['completed_at'] !== null;
  $existingPoints = get_score($db, $userId, $taskId);

  // pre klient: epoch ms štartu
  $startedMs = $startedAt * 1000;

  // cieľ – N, E
  $TARGET_LAT = 48.1473000;
  $TARGET_LNG = 17.1005356;

  // HTML

  echo '<title>Úloha 2 – Dojdi na miesto</title></head><body>';
  echo '<main class="container spomienka">';
  echo '<h1>Úloha 2 – Dojdi na miesto</h1><hr>';

  // Zadanie (vždy)
  ?>
  <section>
  <p><em>V meste sú agenti Verejnej Bezpečnosti, ktorí odpočúvajú a pátrajú. Jeden z členov vášho združenia bol zadržaný a odvlečený na neznáme miesto. Čas hrá proti vám — čím neskôr ho nájdete, tým viac informácií z neho získajú a tým väčšiu stratu Vplyvu utrpíte.</em></p>

  <h2>Úloha: Zachráňte svojho člena</h2>

  <p> Agentom VB sa podarilo uniesť člena vášho združenia a zadržať ho na neznámom mieste. Vy neviete presné miesto — viete len, ako ďaleko ste od neho. Musíte sa čo najrýchlejšie dostať na miesto a tým ho vyslobodiť.</p>

  <h3>Postup</h3>
  <ol>
    <li>V tejto úlohe vidíte každých 15 sekúnd vašu aktuálnu vzdilenosť od miesta zadržania</li>
    <li>Nájdite pomocou vašej aktuálnej vzdilenosti toto miesto</li>
    <li>Keď nájdete toto miesto (s presnosťou 100m), podľa času sa vypočíta zmena Vplyvu.</li>
  </ol>

  <h3>Hodnotenie (Vplyv)</h3>
  <p><strong>Základný princíp:</strong></p>
  <pre>
Vplyv  = 10 − čas_v_minútach / 5
  </pre>

  <p><strong>Špeciálne pravidlo:</strong> Ak tím dorazí až po <strong>100 minútach</strong> alebo nedorazí, zadržiavaného úplne zlomia a tím <strong>stratí 20 Vplyvu</strong> (t.j. výsledok = −20, bez ďalších výpočtov).</p>

  <h4>Príklady</h4>
  <ul>
    <li>Ak miesto nájdete po <strong>15 minútach</strong>: Vplyv = 10 − 15 / ) = 10 − 3 = <strong>+7 Vplyvu</strong>.</li>
    <li>Ak miesto nájdete po <strong>63 minútach</strong>: Vplyv = 10 − 63 / 5 = 10 − 12 = <strong>−2 Vplyvu</strong> </li>
    <li>Ak miesto nájdete po <strong>100 minútach alebo nenájdete</strong>: <strong>−20 Vplyvu</strong> .</li>
  </ul>


  <p><strong>Tip pre hráčov:</strong> Snažte sa zistiť smer ktorým máte ísť aby ste znižovali vzdialenosť</p>
</section>

  <?php

  // Stav – ak už je dokončené
  if ($completed && $existingPoints !== null) {
    echo '<section class="zadanie" style="text-align:left">';
    echo '<h2 class="section-title">Úlohu ste už splnili</h2>';
    echo '<p>Za túto úlohu ste získali <strong>' . ($existingPoints >= 0 ? '+' : '') . $existingPoints . ' bodov</strong>.</p>';
    if ($returnUrl) echo '<p><a class="btn btn-dark" href="'.h($returnUrl).'">Späť na spomienku</a></p>';
    echo '</section>';
    echo '</main></body></html>';
    return;
  }
  global $isLoggedIn;
	if (!$isLoggedIn){ echo '<p style="text-align:center;margin-top:1rem;"><strong>Pre plnenie úlohy sa prihláste.</strong></p>';
    echo '<p style="text-align:center;"><a href="index.php" class="btn btn-dark">⬅️ Vrátiť sa na hlavnú stránku</a></p>';
    echo '</main></body></html>';
    return;}

  // Živá vzdialenosť + skóre
  echo '<section class="zadanie" style="text-align:left">';
  echo '<h2 class="section-title">Živý stav</h2>';
  echo '<p><strong>Vzdialenosť od cieľa:</strong> <span id="dist">–</span></p>';
  echo '<p><strong>Ubehnutý čas:</strong> <span id="elapsed">–</span></p>';
  echo '<p><strong>Aktuálne body (ak by ste splnili teraz):</strong> <span id="points">–</span></p>';
  echo '<p id="status" class="muted"></p>';
  if ($returnUrl) echo '<p><a class="btn btn-dark" href="'.h($returnUrl).'">Späť na spomienku</a></p>';
  echo '</section>';

  // JS: geolokácia + výpočet
  echo "<script>
(function(){
  const TARGET = { lat: $TARGET_LAT, lng: $TARGET_LNG };
  const startedMs = $startedMs;
  const distEl = document.getElementById('dist');
  const elapEl = document.getElementById('elapsed');
  const ptsEl  = document.getElementById('points');
  const statusEl = document.getElementById('status');

  function haversine(lat1, lon1, lat2, lon2) {
    const R = 6371000; // m
    const toRad = x => x * Math.PI/180;
    const dLat = toRad(lat2-lat1);
    const dLon = toRad(lon2-lon1);
    const a = Math.sin(dLat/2)**2 +
              Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)**2;
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c; // meters
  }
  function fmtDist(m){
    if (m < 1000) return Math.round(m) + ' m';
    return (m/1000).toFixed(3) + ' km';
  }
  function computePoints(elapsedMin){
    if (elapsedMin >= 100) return -20;
    const lost = Math.floor(elapsedMin / 5);
    return 10 - lost;
  }
  function fmtElapsed(ms){
    const s = Math.floor(ms/1000);
    const m = Math.floor(s/60);
    const rems = s % 60;
    return m + ' min ' + rems + ' s';
  }
  async function tryComplete() {
    try {
      const resp = await fetch(location.pathname + '?task=2&action=complete' + (location.search.includes('return=') ? '&' + location.search.split('?')[1].split('&').filter(x=>x.startsWith('return='))[0] : ''), { method: 'POST' });
      if (!resp.ok) return;
      const data = await resp.json();
      statusEl.textContent = 'Úloha splnená. Body: ' + (data.points >= 0 ? '+' : '') + data.points + '.';
      // Zamrazíme hodnoty po splnení
      clearInterval(timer);
    } catch(e){ /* ignore */ }
  }

  function update(lat, lng){
    const now = Date.now();
    const elapsedMs = now - startedMs;
    const elapsedMin = Math.floor(elapsedMs/60000);
    elapEl.textContent = fmtElapsed(elapsedMs);
    ptsEl.textContent  = computePoints(elapsedMin);

    const d = haversine(lat, lng, TARGET.lat, TARGET.lng);
    distEl.textContent = fmtDist(d);

    if (elapsedMin >= 100) {
      statusEl.textContent = 'Čas vypršal: úloha nesplnená (−20 bodov).';
      // môžeme automaticky dokončiť pre záporné body, ale radšej necháme manuálne – server aj tak dá −20 pri dokončení
    } else if (d <= 100) {
      statusEl.textContent = 'Ste do 100 m od cieľa – odosielam splnenie...';
      tryComplete();
    } 
  }

  if (!('geolocation' in navigator)) {
    statusEl.textContent = 'Tento prehliadač nepodporuje geolokáciu.';
    return;
  }

  // vysoká presnosť – môže žiadať GPS
  const opts = { enableHighAccuracy: true, maximumAge: 0, timeout: 10000 };
  let lastPos = null;

  navigator.geolocation.watchPosition(function(pos){
    lastPos = pos;
    update(pos.coords.latitude, pos.coords.longitude);
  }, function(err){
    statusEl.textContent = 'Geolokácia zlyhala: ' + err.message + ' (povoľ polohu).';
  }, opts);

  // doplnkové prepočty každých ~15 s (ak by neprišiel nový GPS update)
  const timer = setInterval(function(){
    if (lastPos) update(lastPos.coords.latitude, lastPos.coords.longitude);
    else {
      const elapsedMs = Date.now() - startedMs;
      elapEl.textContent = fmtElapsed(elapsedMs);
      ptsEl.textContent  = computePoints(Math.floor(elapsedMs/60000));
    }
  }, 15000);
})();
</script>";

  echo '</main></body></html>';
}
function task_3(mysqli $db, string $returnUrl): void {
    // Identita a ID úlohy
    $userId = $_SESSION['user_id'] ?? 1;
    $taskId = 3;

    // Je POST?
    $isPost   = ($_SERVER['REQUEST_METHOD'] === 'POST');
    // Ak to NIE JE POST, skús nacitat stav (už splnené?)
    $existing = $isPost ? null : get_score($db, $userId, $taskId);

    // Premenné pre POST spracovanie
    $answerText = '';
    $fileUrl    = null;
    $error      = null;

    // Spracovanie odovzdávky
    if ($isPost && ($_POST['action'] ?? '') === 'submit_tvorba' && (int)($_POST['task_id'] ?? 0) === $taskId) {
        // 1) Text
        $answerText = trim($_POST['answer_text'] ?? '');
        $lineCount  = 0;
        if ($answerText !== '') {
            foreach (preg_split("/\r\n|\n|\r/", $answerText) as $ln) {
                if (trim($ln) !== '') $lineCount++;
            }
        }

        // 2) Obrázok (voliteľný)
        $hasImg = isset($_FILES['answer_image']) && is_uploaded_file($_FILES['answer_image']['tmp_name']);

        // 3) Validácia: musí byť aspoň 16 veršov ALEBO nahratý obrázok
        if (!$hasImg && $lineCount < 16) {
            $error = 'Napíš aspoň 16 veršov, alebo nahraj fotku ručne písanej básne.';
        }

        // 4) Upload obrázka (ak je a zatiaľ bez chyby)
        if (!$error && $hasImg) {
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $mime = mime_content_type($_FILES['answer_image']['tmp_name']) ?: '';
            if (!isset($allowed[$mime])) {
                $error = 'Podporované formáty: JPG, PNG, WEBP.';
            } elseif ($_FILES['answer_image']['size'] > 5*1024*1024) {
                $error = 'Maximálna veľkosť je 5 MB.';
            } else {
                // cieľový priečinok
                $uploadDirDisk = __DIR__ . '/uploads/submissions';
                if (!is_dir($uploadDirDisk)) { @mkdir($uploadDirDisk, 0775, true); }
                $ext    = $allowed[$mime];
                $fname  = 't'.$taskId.'_u'.$userId.'_'.time().'.'.$ext;
                $disk   = $uploadDirDisk . '/' . $fname;
                if (!move_uploaded_file($_FILES['answer_image']['tmp_name'], $disk)) {
                    $error = 'Nepodarilo sa uložiť obrázok.';
                } else {
                    // relatívna URL pre web
                    $fileUrl = 'uploads/submissions/'.$fname;
                }
            }
        }

        // 5) Ak ok, zapíš do submissions a označ úlohu ako splnenú s 0 bodmi
        if (!$error) {
            // INSERT do submissions
            $st = $db->prepare("INSERT INTO submissions (user_id, task_id, answer_text, file_url) VALUES (?, ?, ?, ?)");
            $st->bind_param("iiss", $userId, $taskId, $answerText, $fileUrl);
            $st->execute();
            $st->close();

            // Označ splnené (0 bodov) – kompatibilné s tvojím get_score/save_score
            save_score($db, $userId, $taskId, 0);

            // PRG: vyhneme sa re-POST-u
            header("Location: tasks.php?id=".$taskId."&ok=1");
            exit;
        } else {
            header("Location: tasks.php?id=".$taskId."&err=".urlencode($error));
            exit;
        }
    }

    // ---------- RENDER ----------
    echo '<title>Úloha 3 – Tvorba do šuflíka</title></head><body>';
    echo '<main class="container spomienka"><h1>Úloha 3 – Tvorba do šuflíka</h1><hr>';

    // Zadanie – vždy viditeľné
    echo '<section class="zadanie" style="text-align:left">';
?>
  <p><em>Nie všetko sa dá povedať nahlas. Niekedy musí myšlienka prejsť skrytými dvierkami — veršami.</em></p>

  <h2>Úloha: Báseň so skrytým významom</h2>
  <p>Napíš <strong>16-veršovú báseň</strong> so <em>skrytým významom</em>, ktorý odporuje režimu alebo povzbudzuje človeka pochybovať o režime. 
     Môžeš ju napísať priamo sem, alebo priložiť <strong>fotografiu/scan</strong>, ak ju vytvoríš na papier.</p>

  <h3>Podmienky odovzdania</h3>
  <ul>
    <li><strong>Text:</strong> aspoň 16 <em>neprázdnych</em> veršov (riadkov), <em>alebo</em></li>
    <li><strong>Obrázok:</strong> fotka/scan básne (JPG/PNG/WEBP, max 5&nbsp;MB).</li>
    <li><strong>Hodnotenie:</strong> 0 bodov (úloha sa len <em>eviduje</em> v odovzdaných prácach).</li>
  </ul>
<?php
    echo '</section>';

    // Ak nemáš login guard tu, môžeš použiť rovnaký pattern ako pri 1:
    global $isLoggedIn;
    if (!$isLoggedIn){
        echo '<p style="text-align:center;margin-top:1rem;"><strong>Pre plnenie úlohy sa prihláste.</strong></p>';
        echo '<p style="text-align:center;"><a href="index.php" class="btn btn-dark">⬅️ Vrátiť sa na hlavnú stránku</a></p>';
        echo '</main></body></html>';
        return;
    }

    // Info po odovzdaní alebo ak už splnené
    $err = $_GET['err'] ?? '';
    $ok  = isset($_GET['ok']);
	$existingPoints = $isPost ? null : get_score($db, $userId, $taskId);
	$completed      = ($existingPoints !== null);

    if ($ok) {
        echo '<section class="zadanie" style="text-align:left">';
        echo '<div class="alert alert-success">Odovzdávka uložená. Úloha je označená ako splnená (0 bodov).</div>';
        if ($returnUrl) echo '<p><a class="btn btn-dark" href="' . h($returnUrl) . '">Späť na spomienku</a></p>';
        echo '</section>';
    }
    elseif ($completed && $existingPoints !== null) {
    echo '<section class="zadanie" style="text-align:left">';
    echo '<h2 class="section-title">Úlohu ste už splnili</h2>';
    echo '<p>Za túto úlohu ste získali <strong>' . ($existingPoints >= 0 ? '+' : '') . $existingPoints . ' bodov</strong>.</p>';
    if ($returnUrl) echo '<p><a class="btn btn-dark" href="'.h($returnUrl).'">Späť na spomienku</a></p>';
    echo '</section>';
    echo '</main></body></html>';
    return;
  }

    // Formulár zobraz iba vtedy, keď ešte NIE JE splnená (a zároveň nie je práve PRG „ok=1“)
    if (!$ok && !$isPost && $existing === null) {
        if ($err) {
            echo '<div class="alert alert-danger" style="max-width:720px;">' . h($err) . '</div>';
        }

        echo '<section><form method="post" action="tasks.php?id='. (int)$taskId .'" enctype="multipart/form-data" class="task-form">';
        echo '<input type="hidden" name="action" value="submit_tvorba">';
        echo '<input type="hidden" name="task_id" value="'. (int)$taskId .'">';

        echo '<label for="answer_text" class="form-label"><strong>Tvoja báseň (text)</strong></label>';
        echo '<textarea id="answer_text" name="answer_text" rows="18" placeholder="Sem napíš báseň..." style="width:100%;max-width:720px;"></textarea>';

        echo '<div style="margin-top:.75rem">';
        echo '  <label for="answer_image" class="form-label"><strong>Príloha (voliteľné)</strong> – fotka/scan (JPG/PNG/WEBP, max 5 MB)</label><br>';
        echo '  <input id="answer_image" type="file" name="answer_image" accept="image/jpeg,image/png,image/webp">';
        echo '</div>';

        echo '<button type="submit" class="btn btn-dark btn-submit" style="margin-top:1rem">Odovzdať</button>';
        echo '</form></section>';
    }

    // Pätička (ponechal som tvoj štýl)
    ?>
    <footer style="text-align:center; margin-top:2rem; font-size:0.95rem; color:#555;">
      &copy; <?php echo date('Y'); ?> 70. Zbor Bizón Víťazí
      <br>
      <a href="index.php" class="btn-main">⬅️ Vrátiť sa na hlavnú stránku</a>
    </footer>
    </main></body></html>
    <?php
}



/* ===================== ROUTER ===================== */
$task   = $_GET['task'] ?? '';
$return = $_GET['return'] ?? '';
$task = (int)($_GET['id'] ?? ($_GET['task'] ?? 0));


switch ((string)$task) {
    case '1': task_1($mysqli, $return); break;
	case '2': task_2($mysqli, $return); break;
	case '3': task_3($mysqli, $return); break;
    default:
        echo '<h1>Úlohy</h1><p>Neznáma alebo chýbajúca úloha.</p>';
        if ($return) echo '<p><a href="' . h($return) . '">Späť</a></p>';
}
?>
