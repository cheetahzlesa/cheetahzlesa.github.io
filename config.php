<?php
// Nastavenie DB
const DB_DSN  = 'mysql:host=localhost;dbname=radio;charset=utf8mb4';
const DB_USER = 'radio_user';
const DB_PASS = 'radio_pass';

// Časová zóna – Bratislava
date_default_timezone_set('Europe/Bratislava');

// “Kotva” pre reportáže (kedy sa počíta prvé 30-min okno).
// Od tejto kotvy idú reportáže po poradi každých 30 min.
// Nastav si napr. na polnoc dňa, keď to spúšťaš:
const REPORT_ANCHOR_UNIX = 1730227200; // 2024-10-29 00:00:00 Europe/Bratislava (príklad)

// Dĺžka okna pre reporty (30 min)
const SLOT_SECONDS = 1800;

// Bezpečnostné: povolené hosty pre CORS (ak potrebuješ)
const ALLOW_CORS = false;
