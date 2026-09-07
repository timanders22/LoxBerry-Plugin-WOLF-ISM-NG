<?php
/**
 * Wolf ISM8 - der periodische Lauf des SG-Ready-Moduls (V24, neu in 3.1.0)
 *
 * Wird aus cron/cron.05min gerufen. Er rechnet die Lage, veroeffentlicht sie
 * ueber MQTT und stellt - nur mit beiden Schaltern - die Heizung.
 *
 * Schalter:
 *   --einmal      rechnen, veroeffentlichen, stellen (der Cron-Weg)
 *   --trocken     alles ausser stellen
 *   --selbsttest  Rechenkern gegen feste Pruefwerte, ohne Anlage
 *
 * Ein UNBEKANNTER Schalter beendet das Werkzeug mit einer Antwort, statt
 * stillschweigend in den Regelbetrieb zu laufen.
 *
 * Rueckgabe: 0 in Ordnung, 1 Befund, 2 nicht ausfuehrbar.
 */

/* Die Bibliothek ueber eine Kandidatenliste - nie ueber eine feste Zahl
 * ".."; Archiv- und Installationslage unterscheiden sich. */
$wi_kandidaten = array();
$wi_home = getenv('LBHOMEDIR');
$wi_pdir = getenv('LBPPLUGINDIR');
if ($wi_home && $wi_pdir) {
    $wi_kandidaten[] = $wi_home . '/webfrontend/htmlauth/plugins/' . $wi_pdir . '/wi_sg.php';
}
$wi_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/wi_sg.php';
$wi_kandidaten[] = dirname(__DIR__) . '/webfrontend/htmlauth/wi_sg.php';

$wi_geladen = '';
foreach ($wi_kandidaten as $wi_k) {
    if (is_file($wi_k)) {
        require_once $wi_k;
        $wi_geladen = $wi_k;
        break;
    }
}
if ($wi_geladen === '') {
    fwrite(STDERR, "wi_sg.php nicht gefunden. Gesucht in:\n  "
                 . implode("\n  ", $wi_kandidaten) . "\n");
    exit(1);
}

/* Schalter pruefen, bevor irgendetwas laeuft. */
$wi_erlaubt = array('--einmal', '--trocken', '--selbsttest');
$wi_modus = '--einmal';
foreach ($argv as $wi_i => $wi_a) {
    if ($wi_i === 0 || strncmp((string) $wi_a, '--', 2) !== 0) {
        continue;
    }
    if (!in_array($wi_a, $wi_erlaubt, true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $wi_a . "\n"
                     . 'Erlaubt: ' . implode(' ', $wi_erlaubt) . "\n");
        exit(2);
    }
    $wi_modus = $wi_a;
}

/* ==================================================================
 * Selbsttest: der Rechenkern gegen feste Pruefwerte
 *
 * Ohne Anlage, ohne Preise aus dem Netz, ohne Heizung. Er misst genau das,
 * was hier messbar ist: die Auswahl der Fenster. Form 1 nach Hausstandard -
 * die Schlusszeile wird aus den ausgegebenen Zeilen GEZAEHLT.
 * ================================================================== */
if ($wi_modus === '--selbsttest') {
    $faelle = 0;
    $fehl = 0;
    $ab = 1757116800;   // fester Zeitpunkt, damit der Lauf reproduzierbar ist

    $pruefe = function ($name, $ist, $soll) use (&$faelle, &$fehl) {
        $faelle++;
        $gleich = (json_encode($ist) === json_encode($soll));
        if (!$gleich) {
            $fehl++;
        }
        printf("%-6s %-52s ist=%s soll=%s\n", $gleich ? '[OK]' : '[FEHL]', $name,
               json_encode($ist), json_encode($soll));
    };

    // Preise: 24 Stunden, die guenstigsten liegen bei 02:00 und 03:00.
    $p = array();
    $reihe = array(9, 8, 2, 3, 7, 9, 12, 15, 14, 11, 10, 9,
                   8, 7, 6, 7, 9, 14, 18, 17, 13, 11, 10, 9);
    foreach ($reihe as $i => $ct) {
        $p[$ab + $i * 3600] = (float) $ct;
    }

    list($f, $h) = wi_sg_fahrplan($p, 2, 2, $ab, 24);
    $pruefe('Zwei Stunden am Stueck: das billigste Paar', count($f) === 1 ? array(
        (int) (($f[0]['von'] - $ab) / 3600), (int) (($f[0]['bis'] - $ab) / 3600)) : $f,
        array(2, 4));

    list($f2, $h2) = wi_sg_fahrplan($p, 4, 2, $ab, 24);
    $pruefe('Vier Stunden: zwei Fenster, das zweite bei 14 Uhr',
        array_map(function ($x) use ($ab) { return (int) (($x['von'] - $ab) / 3600); }, $f2),
        array(2, 13));

    list($f3, $h3) = wi_sg_fahrplan($p, 0, 2, $ab, 24);
    $pruefe('Null Stunden ergibt kein Fenster', array(count($f3), $h3), array(0, 'SG.PLAN_NULL'));

    list($f4, $h4) = wi_sg_fahrplan(array(), 4, 2, $ab, 24);
    $pruefe('Ohne Preise wird nichts geplant', array(count($f4), $h4),
        array(0, 'SG.PLAN_KEINE_PREISE'));

    /* Eine Luecke im Preisbestand darf kein Fenster ueberbruecken.
     *
     * Der Pruefwert ist eigens dafuer gebaut: die beiden BILLIGSTEN Stunden
     * (2 und 4, je 1 ct) liegen so, dass nur ein Fenster ueber die fehlende
     * Stunde 3 hinweg sie beide faende. Alles andere kostet 20 ct, nur das
     * Paar 13/14 kostet 9 - das muss herauskommen.
     *
     * Der erste Anlauf dieses Falls war anders gebaut und ist DURCHGEFALLEN,
     * weil die Erwartung falsch gerechnet war (13 statt 1) - der Code hatte
     * recht. Nachgerechnet, dann den Pruefwert so gewaehlt, dass er die
     * Frage wirklich stellt. */
    $lue = array();
    foreach (range(0, 23) as $i) {
        $lue[$ab + $i * 3600] = 20.0;
    }
    $lue[$ab + 2 * 3600] = 1.0;
    $lue[$ab + 4 * 3600] = 1.0;
    $lue[$ab + 13 * 3600] = 9.0;
    $lue[$ab + 14 * 3600] = 9.0;
    unset($lue[$ab + 3 * 3600]);
    list($f5, $h5) = wi_sg_fahrplan($lue, 2, 2, $ab, 24);
    $pruefe('Eine Luecke wird nicht ueberbrueckt',
        count($f5) === 1 ? (int) (($f5[0]['von'] - $ab) / 3600) : -1, 13);

    // Gegenprobe: OHNE die Luecke gewinnt das billige Paar 2/3.
    $ohne = $lue;
    $ohne[$ab + 3 * 3600] = 1.0;
    list($f5b, $h5b) = wi_sg_fahrplan($ohne, 2, 2, $ab, 24);
    $pruefe('Gegenprobe: ohne Luecke gewinnt das billige Paar',
        count($f5b) === 1 ? (int) (($f5b[0]['von'] - $ab) / 3600) : -1, 2);

    // Horizont: nur die naechsten sechs Stunden.
    list($f6, $h6) = wi_sg_fahrplan($p, 2, 2, $ab, 6);
    $pruefe('Der Horizont begrenzt die Auswahl',
        count($f6) === 1 ? (int) (($f6[0]['von'] - $ab) / 3600) : -1, 2);

    $pruefe('Im Fenster', wi_sg_im_fenster($f, $ab + 2 * 3600 + 60), true);
    $pruefe('Vor dem Fenster', wi_sg_im_fenster($f, $ab + 3600), false);
    $pruefe('Genau am Ende ist ausserhalb', wi_sg_im_fenster($f, $ab + 4 * 3600), false);

    $pruefe('Zahlenform ohne Exponent und ohne Nullen', wi_sg_zahl(55.0), '55');
    $pruefe('Zahlenform mit halbem Grad', wi_sg_zahl(2.5), '2.5');
    $pruefe('Zahlenform negativ', wi_sg_zahl(-1.5), '-1.5');

    printf("\nRechenkern SG-Ready: %d Faelle geprueft, %d Fehlschlaege.\n", $faelle, $fehl);
    exit($fehl ? 1 : 0);
}

/* ==================================================================
 * Regelbetrieb
 * ================================================================== */
$cfg = wi_config_read();

if (wi_cfg($cfg, 'sg_ein', '0') !== '1') {
    // Ausgeschaltet heisst: nichts rechnen, nichts senden, nichts sagen.
    // Ein Cron, der alle fuenf Minuten eine Zeile schreibt, erzeugt 288
    // Zeilen am Tag auf einer Ramdisk.
    exit(0);
}

$lage = wi_sg_lage($cfg);

/* --- Veroeffentlichen ------------------------------------------------
 *
 * Ueber den UDP-Eingang des MQTT-Gateways, wie der Aufraeumer es auch tut.
 * Zustaende retained, das Lebenszeichen der Lage nicht - derselbe
 * Hausstandard wie im Auswertungsmodul.
 *
 * NICHT GEMESSEN: ob das Gateway diese Zeilen annimmt. Die Zeilenform
 * "retain <thema> <wert>" ist dokumentiert; ein Broker steht hier nicht. */
$port = wi_mqtt_udpinport();
$pre = wi_praefix();
$gesendet_mqtt = 0;
if ($port && wi_cfg($cfg, 'mqtt', '0') === '1') {
    $themen = array(
        array('retain', 'sg/lage', $lage['lage']),
        array('retain', 'sg/laden', $lage['laden'] ? '1' : '0'),
        array('retain', 'sg/dimmen', $lage['dimmen'] === null ? '-1' : ($lage['dimmen'] ? '1' : '0')),
        array('retain', 'sg/fenster', (string) count($lage['fenster'])),
        array('publish', 'sg/ts', (string) time()),
    );
    if ($lage['fenster']) {
        $naechstes = $lage['fenster'][0];
        foreach ($lage['fenster'] as $f) {
            if ($f['bis'] > time()) { $naechstes = $f; break; }
        }
        $themen[] = array('retain', 'sg/naechster_start', (string) $naechstes['von']);
        $themen[] = array('retain', 'sg/naechster_preis', (string) $naechstes['schnitt']);
    }
    set_error_handler(function () { return true; });
    $sock = fsockopen('udp://127.0.0.1', (int) $port, $nr, $txt, 3);
    restore_error_handler();
    if ($sock) {
        foreach ($themen as $t) {
            // Jedes Argument ohne Trennzeichenbasteleien: Thema und Wert
            // enthalten nach Bau nie Leerraum.
            if (@fwrite($sock, $t[0] . ' ' . $pre . '/' . $t[1] . ' ' . $t[2] . "\n") !== false) {
                $gesendet_mqtt++;
            }
            usleep(2000);
        }
        fclose($sock);
    }
}

/* --- Stellen ---------------------------------------------------------- */
$ernst = ($wi_modus === '--einmal');
list($n, $uebersprungen, $meld) = wi_sg_stellen($cfg, $ernst);

foreach ($meld as $m) {
    // Im Protokoll steht Klartext, keine Sprachschluessel - das Protokoll
    // ist ein technisches Nachschlagewerk und bleibt einsprachig.
    wi_log_sg($m[0], $m[1]);
}
if ($gesendet_mqtt) {
    wi_log_sg('MQTT', array($gesendet_mqtt));
}
exit(0);

/** Eine Protokollzeile, gebremst: gleiche Meldung hoechstens stuendlich. */
function wi_log_sg($schluessel, $args)
{
    $texte = array(
        'SG.M_AUS'          => 'SG-Ready ist ausgeschaltet.',
        'SG.M_FEHLT'        => 'Diese Datenpunkte fehlen in der Firmware: %s. Es wurde nichts gestellt.',
        'SG.M_NICHTS'       => 'Kein Befehl gebildet.',
        'SG.M_UNVERAENDERT' => 'Lage unveraendert (%s) - nichts gesendet.',
        'SG.M_TROCKEN'      => 'Trockenlauf: Lage %s, %d Befehle waeren gegangen.',
        'SG.M_GESENDET'     => 'Datenpunkt %s auf %s gesetzt. Antwort: %s',
        'SG.M_TEILWEISE'    => 'NUR %d von %d Befehlen angekommen - der Merker bleibt stehen.',
        'MQTT'              => '%d MQTT-Zeilen an das Gateway.',
    );
    $text = isset($texte[$schluessel]) ? vsprintf($texte[$schluessel], $args) : $schluessel;
    /* wi_log_file() sucht eine VORHANDENE Datei und liefert sonst nichts.
     * Beim ersten Lauf gibt es aber noch keine - und ein Modul, das seine
     * erste Meldung verschluckt, ist genau der Fall, den die Hausregel
     * "wer ein Protokoll anzeigt, muss es auch schreiben" meint. Also
     * legen wir sie an, mit demselben Namen, den der Reiter Logdateien
     * ohnehin findet. */
    $datei = wi_log_file('server');
    if ($datei === '') {
        $verz = wi_paths()['logdir'];
        if ($verz === '' || (!is_dir($verz) && !@mkdir($verz, 0775, true))) {
            return;
        }
        $datei = $verz . '/server.log';
    }
    // Bremse: dieselbe Meldung nicht oefter als stuendlich. Sonst schreibt
    // ein Cron alle fuenf Minuten 288 gleichlautende Zeilen am Tag.
    $merk = dirname($datei) . '/.sg_letzte';
    $jetzt = time();
    $alt = @json_decode((string) @file_get_contents($merk), true);
    if (is_array($alt) && isset($alt[$text]) && $jetzt - (int) $alt[$text] < 3600) {
        return;
    }
    if (!is_array($alt)) {
        $alt = array();
    }
    $alt[$text] = $jetzt;
    if (count($alt) > 50) {
        $alt = array_slice($alt, -50, null, true);
    }
    @file_put_contents($merk, json_encode($alt));
    clearstatcache(true, $datei);
    if (is_file($datei) && filesize($datei) > 512000) {
        $rest = array_slice(file($datei, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($datei, implode("\n", $rest) . "\n");
    }
    @file_put_contents($datei, date('Y-m-d H:i:s') . ' <INFO> SG: ' . $text . "\n", FILE_APPEND);
}
