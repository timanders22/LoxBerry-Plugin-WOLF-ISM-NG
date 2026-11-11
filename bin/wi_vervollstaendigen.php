<?php
/**
 * Wolf ISM8 - Konfiguration vervollstaendigen, Kommandozeile (seit 3.1.20).
 *
 * Aufgerufen von cron/cron.05min nach der Frage nach der Marke einer
 * Aktualisierung und vor dem ersten Lesen der Konfiguration (Regeln/05,
 * Bauform Docker NG 1.3.7). Geschrieben wird ausschliesslich ueber
 * wi_config_vervollstaendigen() in wi_lib.php; der Perl-Dienst schreibt die
 * Datei nur, wenn sie fehlt.
 *
 * Aufruf: php wi_vervollstaendigen.php   (ohne Argument)
 * Ausgabe genau eine Zeile:
 *   VERVOLLSTAENDIGEN;STAND=<ohne_wurzel|upgrade|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<AUFRUF|WURZEL|MODUL_FEHLT>
 * Rueckgabe 1 bei fehler und bei STAND=0, sonst 0. Die Zeile nennt nur
 * Schluesselnamen, nie einen Wert.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
if (!isset($argv) || count($argv) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
/* Installiert liegt diese Datei unter <Wurzel>/bin/plugins/<ordner>/. Die
 * Wurzel gilt nur mit config/plugins, data/plugins und
 * config/system/general.json darunter. */
$wi_vv_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$wi_vv_o = basename(__DIR__);
$wi_vv_h = rtrim((string) getenv('LBHOMEDIR'), '/');
if (!$wi_vv_wurzel($wi_vv_h)) {
    $wi_vv_h = dirname(dirname(dirname(__DIR__)));
    if (!$wi_vv_wurzel($wi_vv_h)) {
        echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$wi_vv_lib = $wi_vv_h . '/webfrontend/htmlauth/plugins/' . $wi_vv_o . '/wi_lib.php';
if (preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $wi_vv_o) !== 1 || !is_file($wi_vv_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $wi_vv_h);
putenv('LBPPLUGINDIR=' . $wi_vv_o);
require_once $wi_vv_lib;
$wi_vv_e = wi_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $wi_vv_e['stand'] . ';SCHLUESSEL=' . implode(',', $wi_vv_e['schluessel']) . "\n";
exit($wi_vv_e['stand'] === 'fehler' ? 1 : 0);
