<?php
function render_spomienka(string $nazov, string $obr, string $text, ?string $zadanie = null, $id = null, ?int $t = null): void {
$base = new DateTime('1989-11-01 00:00:00', new DateTimeZone('UTC'));
$t = $t ?? 0;
$days = intdiv((int)$t, 300); // 5 min = 1 deň
if ($days !== 0) { $base->modify(($days > 0 ? '+' : '') . $days . ' day'); }
$datum = $base->format('j.n.Y');


$showTask = isset($id) && $id !== '' && $id !== false;
?>
<!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($nazov, ENT_QUOTES, 'UTF-8'); ?></title>
<!-- Bootstrap CSS -->
<link rel="stylesheet" href="styl.css">
</head>
<body>
<div class="container spomienka">
<h1><?php echo htmlspecialchars($nazov, ENT_QUOTES, 'UTF-8'); ?></h1>
<hr>
<div class="date"><?php echo htmlspecialchars($datum, ENT_QUOTES, 'UTF-8'); ?></div>


<figure>
<img src="<?php echo htmlspecialchars($obr, ENT_QUOTES, 'UTF-8'); ?>" alt="">
</figure>


<div class="spomienka-text"><?php echo nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')); ?></div>



<?php if ($showTask): ?>
  <h2 class="section-title">Úloha</h2>
  <div class="zadanie"><?php echo nl2br(htmlspecialchars($zadanie, ENT_QUOTES, 'UTF-8')); ?></div>

  <?php
    // link na modul úloh – id = číslo úlohy; pridáme aj návratovú URL
    $taskUrl = 'tasks.php?task=' . urlencode((string)$id) . '&return=' . urlencode($_SERVER['REQUEST_URI']);
  ?>
  <p>
    <a href="<?php echo $taskUrl; ?>" class="btn btn-dark">Spustiť úlohu <?php echo htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8'); ?></a>
  </p>
<?php endif; ?>



<footer style="text-align:center; margin-top:2rem; font-size:0.95rem; color:#555;">
  &copy; <?php echo date('Y'); ?> 70. Zbor Bizón Víťazí
  <br>
  <a href="main.php" class="btn-main">⬅️ Vrátiť sa na hlavnú stránku</a>
</footer>

</div>



</body>
</html>
<?php }


$mysqli = @new mysqli('localhost', 'root', '', '');
if ($mysqli->connect_errno) { die("Chyba pri spojení: " . $mysqli->connect_error); }
$mysqli->query("CREATE DATABASE IF NOT EXISTS mestska_hra CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
$mysqli->select_db('mestska_hra');

/* --- tabuľka spomienky --- */
$mysqli->query("
CREATE TABLE IF NOT EXISTS spomienky (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nazov   VARCHAR(200) NOT NULL,
  obr     TEXT NOT NULL,
  text    MEDIUMTEXT NOT NULL,
  zadanie MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");


/* --- seed (len ak je prázdna) --- */
$cnt = (int)$mysqli->query("SELECT COUNT(*) c FROM spomienky")->fetch_assoc()['c'];
if ($cnt === 0) {
  $mysqli->query("
    INSERT INTO spomienky (nazov, obr, text) VALUES
    ('SPOMIENKA', 'https://cdn.britannica.com/70/234870-050-D4D024BB/Orange-colored-cat-yawns-displaying-teeth.jpg',
     'Tu je hlavná spomienka.\nMôže mať viac riadkov a je centrovaná.\n…'),
    ('Druhá spomienka', 'https://via.placeholder.com/1200x600',
     'Obsah druhej spomienky…')
  ");
}

/* --- vezmi ?id=... z linku --- */
$spomId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$spomId) {
  http_response_code(400);
  echo "Chýbajúce alebo zlé id."; exit;
}

/* --- načítaj z DB --- */
$st = $mysqli->prepare("SELECT id, nazov, obr, text, zadanie FROM spomienky WHERE id=?");
$st->bind_param("i", $spomId);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
  http_response_code(404);
  echo "Spomienka s id ".htmlspecialchars((string)$spomId)." neexistuje."; exit;
}

/* --- vypočítaj t (ak chceš podľa „herný čas“), inak nechaj 0 --- */
$GAME_REAL_START = strtotime('2025-11-14 09:00:00 Europe/Bratislava'); // prispôsob
$t = max(0, time() - $GAME_REAL_START); // koľko beží hra v sekundách
// 5 min = 1 deň – to už rieši priamo render_spomienka vo vnútri

/* --- zavolaj tvoju šablónu --- */
render_spomienka(
  nazov:  $row['nazov'],
  obr:    $row['obr'],
  text:   $row['text'],     // môže byť NULL
  id:     $row['id'],          // dôležité pre zobrazenie úlohy
  t:      $t
);
?>