<?php
// config.php

$ONLINE_MODE = false;  // 🔁 prepínač (false = lokálna DB, true = online DB)

if ($ONLINE_MODE) {
    // 🌍 ONLINE režim
    $DB_HOST = "localhost";
    $DB_USER = "bsoskauting_odyseus";
    $DB_PASS = "Itaka#12Sekier";
    $DB_NAME = "bsoskauting_mestka_hra";
} else {
    // 💻 LOKÁLNY režim
    $DB_HOST = "localhost";
    $DB_USER = "root";
    $DB_PASS = "";
    $DB_NAME = "mestska_hra";
}

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}
?>
