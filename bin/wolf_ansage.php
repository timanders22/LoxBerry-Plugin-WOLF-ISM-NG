<?php
/**
 * Wolf ISM8 - Ansage bei Stoerung und Ausfall (Nr. 36 b, Stufe 2, seit 3.1.8)
 *
 * Wird aus cron/cron.05min gerufen, neben wolf_sg.php. Liest das
 * Zustandsabbild des Dienstes (data/plugins/<ordner>/zustand.json), erkennt
 * die Flanken und spricht ueber die gemeinsame Sprachausgabe
 * (wi_ansage_takt() in wi_lib.php). Ab Werk ist die Ausgabe aus; dann wird
 * nur die Flanke fortgeschrieben, gesprochen wird nichts.
 *
 * Warum ein Takt in PHP und keine Bruecke aus dem Perl-Dienst: ein Ausfall
 * des Dienstes ist nur von aussen zu erkennen, und eine Ansage dauert bis
 * zu 10 s - der Dienst nimmt in dieser Zeit die Telegramme des ISM8 an.
 *
 * Schalter:
 *   --einmal   ein Takt (der Cron-Weg)
 * Ein UNBEKANNTER Schalter beendet das Programm mit einer Antwort, statt
 * stillschweigend einen Takt zu laufen.
 *
 * Rueckgabe: 0 in Ordnung (auch: nichts zu sagen), 1 eine Ansage scheiterte
 * oder der Takt brach ab, 2 nicht ausfuehrbar.
 */

/* Die Bibliothek ueber eine Kandidatenliste - nie ueber eine feste Zahl
 * ".."; Archiv- und Installationslage unterscheiden sich (wie wolf_sg.php). */
$wi_kandidaten = array();
$wi_home = getenv('LBHOMEDIR');
$wi_pdir = getenv('LBPPLUGINDIR');
if ($wi_home && $wi_pdir) {
    $wi_kandidaten[] = $wi_home . '/webfrontend/htmlauth/plugins/' . $wi_pdir . '/wi_lib.php';
}
$wi_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/wi_lib.php';
$wi_kandidaten[] = dirname(__DIR__) . '/webfrontend/htmlauth/wi_lib.php';

$wi_geladen = '';
foreach ($wi_kandidaten as $wi_k) {
    if (is_file($wi_k)) {
        require_once $wi_k;
        $wi_geladen = $wi_k;
        break;
    }
}
if ($wi_geladen === '') {
    fwrite(STDERR, "wi_lib.php nicht gefunden. Gesucht in:\n  "
                 . implode("\n  ", $wi_kandidaten) . "\n");
    exit(2);
}

$wi_erlaubt = array('--einmal');
foreach ($argv as $wi_i => $wi_a) {
    if ($wi_i === 0 || strncmp((string) $wi_a, '--', 2) !== 0) {
        continue;
    }
    if (!in_array($wi_a, $wi_erlaubt, true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $wi_a . "\n"
                     . 'Erlaubt: ' . implode(' ', $wi_erlaubt) . "\n");
        exit(2);
    }
}

/* Nur in der Anlage (Muster 3 der Nachlese, wie wolf_sg.php): aus einem
 * ausgepackten Archiv wird nichts gelesen, gesprochen oder geschrieben. */
if (wi_paths()['home'] === '') {
    fwrite(STDERR, "wolf_ansage.php liegt nicht in einer LoxBerry-Installation - es wurde nichts "
                 . "gelesen, gesprochen oder geschrieben.\n");
    exit(2);
}
/* Waehrend einer Aktualisierung nichts tun (der Cron prueft die Marke auch). */
if (wi_upgrade_laeuft()) {
    exit(0);
}

/* Die Sprache der Ansage: LBLANG hat Vorrang, sonst Base.Lang aus der
 * general.json (Regeln/03) - im Cron ist LBLANG nicht gesetzt, und ohne
 * diese Zeile spraeche der Takt immer deutsch. */
if ((string) getenv('LBLANG') === '') {
    $wi_gj = wi_general_json();
    $wi_g = $wi_gj !== '' ? json_decode((string) @file_get_contents($wi_gj), true) : null;
    if (is_array($wi_g) && isset($wi_g['Base']['Lang']) && is_string($wi_g['Base']['Lang'])
        && preg_match('/^[a-z]{2}/i', $wi_g['Base']['Lang'])) {
        putenv('LBLANG=' . strtolower(substr($wi_g['Base']['Lang'], 0, 2)));
    }
}

try {
    $wi_zeilen = wi_ansage_takt();
} catch (Throwable $wi_fehler) {
    wi_ansage_log('ERROR', 'Takt abgebrochen: ' . get_class($wi_fehler) . ' in '
                  . basename($wi_fehler->getFile()) . ':' . $wi_fehler->getLine());
    exit(1);
}
$wi_rc = 0;
foreach ($wi_zeilen as $wi_z) {
    wi_ansage_log($wi_z[0], $wi_z[1]);
    if ($wi_z[0] === 'WARNING') {
        $wi_rc = 1;
    }
}
exit($wi_rc);
