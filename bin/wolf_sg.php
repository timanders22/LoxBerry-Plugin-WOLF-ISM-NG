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

/* Schalter pruefen, bevor irgendetwas laeuft.
 *
 * --mqtt-leeren (seit 3.1.4) gehoert nicht zum SG-Modul, steht aber hier,
 * weil dies das eine PHP-Programm unter bin/ ist: uninstall/uninstall leert
 * damit die zurueckbehaltenen Themen der Linie am Broker (wi_mqtt_leeren()).
 * Rueckgabe dort: 0 bestaetigt leer, 1 nicht bestaetigt, 2 nicht ausfuehrbar. */
/* --zuruecknehmen (seit dem Durchgang 02.10.2026, C2): uninstall/uninstall
 * nimmt damit einen gesendeten Zwang zurueck, BEVOR es den Dienst anhaelt -
 * die Befehle gehen ueber dessen Befehls-Port. */
$wi_erlaubt = array('--einmal', '--trocken', '--selbsttest', '--mqtt-leeren', '--zuruecknehmen');
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

    /* --- Durchgang 02.10.2026 ----------------------------------------- */
    // C4: Paragraph 14a. Zeitpunkt fest, Grenze 900 s.
    $j = $ab + 7200;
    $pruefe('14a: frisches Signal dimmt',
        wi_sg_14a_auswerten(array('dimmen' => 1, 'ts' => $j - 60), 0, $j, 900), array(true, 60, 'SG.E14_AKTIV'));
    $pruefe('14a: Zeitstempel in Millisekunden ist kaputt',
        wi_sg_14a_auswerten(array('dimmen' => 1, 'ts' => ($j - 7200) * 1000), 0, $j, 900), array(null, -1, 'SG.E14_KAPUTT_TS'));
    $pruefe('14a: Zeitstempel 1 h in der Zukunft ist kaputt',
        wi_sg_14a_auswerten(array('dimmen' => 1, 'ts' => $j + 3600), 0, $j, 900), array(null, -1, 'SG.E14_KAPUTT_TS'));
    $pruefe('14a: 200 s Zukunft gilt noch (Uhrversatz)',
        wi_sg_14a_auswerten(array('dimmen' => '1', 'ts' => $j + 200), 0, $j, 900), array(true, 0, 'SG.E14_AKTIV'));
    $pruefe('14a: dimmen 2 ist kaputt, nicht "ruhe"',
        wi_sg_14a_auswerten(array('dimmen' => 2, 'ts' => $j), 0, $j, 900), array(null, -1, 'SG.E14_KAPUTT_WERT'));
    $pruefe('14a: dimmen "false" heisst ruhe',
        wi_sg_14a_auswerten(array('dimmen' => 'false', 'ts' => $j), 0, $j, 900), array(false, 0, 'SG.E14_RUHE'));
    $pruefe('14a: veraltet liefert null (sg/dimmen -1)',
        wi_sg_14a_auswerten(array('dimmen' => 1, 'ts' => $j - 7200), 0, $j, 900), array(null, 7200, 'SG.E14_VERALTET'));
    $pruefe('14a: ohne ts gilt das Dateidatum',
        wi_sg_14a_auswerten(array('dimmen' => 0), $j - 30, $j, 900), array(false, 30, 'SG.E14_RUHE'));

    // C1: Hoechstdauer der Anhebung (6 h).
    $pruefe('Hoechstdauer: Ladung beginnt (kein Merker)',
        wi_sg_kappen(true, null, 6, $j), array(true, false, 0));
    $pruefe('Hoechstdauer: 5 h 59 min laden, noch nicht gekappt',
        wi_sg_kappen(true, array('lage' => 'laden', 'laden_seit' => $j - 21540), 6, $j), array(true, false, $j - 21540));
    $pruefe('Hoechstdauer: nach 6 h gekappt, normal',
        wi_sg_kappen(true, array('lage' => 'laden', 'laden_seit' => $j - 21600), 6, $j), array(false, true, $j - 21600));
    $pruefe('Hoechstdauer: Plan ohne Ladung loest die Kappung',
        wi_sg_kappen(false, array('lage' => 'normal', 'laden_seit' => $j - 30000, 'gekappt' => 1), 6, $j),
        array(false, false, 0));

    // C3: Merkervergleich mit Kreis und Befehlszeilen.
    $mk = array('lage' => 'laden', 'kreis' => 'direkt', 'zeilen' => array('57;0', '58;0', '56;55', '65;2'));
    $pruefe('Merker: gleiche Lage, Kreis und Zeilen sind gleich',
        wi_sg_gleich($mk, 'laden', 'direkt', array('57;0', '58;0', '56;55', '65;2')), true);
    $pruefe('Merker: Kreiswechsel ist ein Wechsel',
        wi_sg_gleich($mk, 'laden', 'mischer1', array('57;0', '58;0', '56;55', '65;2')), false);
    $pruefe('Merker: neuer Ladesollwert ist ein Wechsel',
        wi_sg_gleich($mk, 'laden', 'direkt', array('57;0', '58;0', '56;58', '65;2')), false);
    $pruefe('Merker aus 3.1.5 (ohne Kreis) gilt als verschieden',
        wi_sg_gleich(array('lage' => 'laden'), 'laden', 'direkt', array('57;0')), false);
    // C2/C3: die Normalbefehle des ALTEN Kreises (Tabelle 1.9, direkter Kreis).
    $nb = wi_sg_normal_fuer(array('fw_version' => '1.9', 'sg_ww_normal' => '48'), 'direkt');
    $pruefe('Ruecknahme: Normalbefehle des direkten Kreises (Firmware 1.9)',
        wi_sg_zeilen($nb['befehle']), array('57;0', '58;0', '56;48', '65;0'));

    printf("\nRechenkern SG-Ready: %d Faelle geprueft, %d Fehlschlaege.\n", $faelle, $fehl);
    exit($fehl ? 1 : 0);
}

/* ==================================================================
 * Nur in der Anlage (Muster 3 der Nachlese)
 *
 * Aus einem ausgepackten Archiv unter einer echten Wurzel - am Geraet steht
 * LBHOMEDIR in /etc/environment - nahm dieses Programm bis 3.1.4 deren
 * Konfiguration, UDP-Eingang und Befehls-Port (Fall S5,
 * Pruefung-WOLF-ISM-NG-3.1.4). wi_paths() liefert dann keine Wurzel; hier
 * wird ausgestiegen, bevor etwas gelesen, gesendet oder geschrieben wird.
 * ================================================================== */
if (wi_paths()['home'] === '') {
    $wi_arch = wi_paths()['archiv'];
    fwrite(STDERR, ($wi_arch !== ''
        ? 'wolf_sg.php liegt nicht in der Installation unter ' . $wi_arch
          . " (ausgepacktes Archiv oder Pruefordner).\n"
        : "Es wurde kein LoxBerry-Wurzelverzeichnis gefunden.\n")
        . "Es wurde nichts gelesen, gesendet oder geschrieben. Abhilfe: das Programm aus\n"
        . "<Wurzel>/bin/plugins/<ordner> aufrufen oder LBHOMEDIR und LBPPLUGINDIR setzen.\n");
    exit(2);
}

if ($wi_modus === '--mqtt-leeren') {
    list($wi_rc, $wi_zeilen) = wi_mqtt_leeren(wi_config_read());
    echo implode("\n", $wi_zeilen) . "\n";
    exit($wi_rc);
}

if ($wi_modus === '--zuruecknehmen') {
    // C2: Deinstallation. Ausgabe im Format des Installers.
    $wi_cfg0 = wi_config_read();
    list($wi_rc, $wi_meld) = wi_sg_zuruecknehmen($wi_cfg0);
    foreach ($wi_meld as $wi_m) {
        wi_log_sg($wi_m[0], $wi_m[1]);
    }
    $wi_letzte = end($wi_meld);
    echo ($wi_rc === 0 ? '<OK> SG-Ready: ' : '<WARNING> SG-Ready: ')
        . wi_sg_text($wi_letzte[0], $wi_letzte[1]) . "\n";
    $wi_n = wi_sg_mqtt_aus($wi_cfg0, true);
    if ($wi_n) {
        echo '<INFO> SG-Ready: ' . $wi_n . " MQTT-Zeilen (laden 0, dimmen 0, lage aus) an das Gateway.\n";
    }
    exit($wi_rc);
}

/* ==================================================================
 * Regelbetrieb
 * ================================================================== */
$cfg = wi_config_read();
$ernst = ($wi_modus === '--einmal');

/* C2 (Durchgang 02.10.2026): ist das Schreiben aus (sg_ein oder sg_senden
 * auf 0) und traegt der Merker noch einen Zwang, wird er EINMAL
 * zurueckgenommen. Schlaegt das fehl (Dienst oder ISM8 fehlt), versucht es
 * der naechste Lauf wieder. Nur im Ernstlauf - der Trockenlauf sendet nie. */
if ($ernst && (wi_cfg($cfg, 'sg_ein', '0') !== '1' || wi_cfg($cfg, 'sg_senden', '0') !== '1')) {
    $wi_m0 = wi_sg_merker();
    if ($wi_m0 !== null && in_array((string) $wi_m0['lage'], array('laden', 'dimmen'), true)) {
        list($wi_rc0, $wi_meld0) = wi_sg_zuruecknehmen($cfg);
        foreach ($wi_meld0 as $wi_m) {
            wi_log_sg($wi_m[0], $wi_m[1]);
        }
    }
}

if (wi_cfg($cfg, 'sg_ein', '0') !== '1') {
    // Ausgeschaltet heisst: nichts rechnen, nichts senden, nichts sagen.
    // Ein Cron, der alle fuenf Minuten eine Zeile schreibt, erzeugt 288
    // Zeilen am Tag auf einer Ramdisk.
    // C2: nur EINMAL nach dem Ausschalten laden 0, dimmen 0, lage aus.
    if ($ernst) {
        $wi_n = wi_sg_mqtt_aus($cfg, false);
        if ($wi_n) {
            wi_log_sg('MQTT_AUS', array($wi_n));
        }
    }
    exit(0);
}

/* --- Stellen ---------------------------------------------------------
 * Seit dem Durchgang 02.10.2026 VOR dem Veroeffentlichen: sg/lage meldet
 * den Merker, also das, was an der Heizung gilt (C2, Entscheidung 31) - und
 * der steht erst nach dem Stellen fest. */
list($n, $uebersprungen, $meld) = wi_sg_stellen($cfg, $ernst);

$lage = wi_sg_lage($cfg);

/* --- Veroeffentlichen ------------------------------------------------
 *
 * Ueber den UDP-Eingang des MQTT-Gateways, wie der Aufraeumer es auch tut.
 *
 * Seit 3.1.4 geht ALLES fluechtig hinaus (Muster 6 der Nachlese, Regeln/07
 * Abschnitt 3 mit dem Nachtrag vom 24.09.2026: Werte, die allein durch die
 * Uhr falsch werden, sind nie retained). lage, laden, dimmen, fenster und
 * naechster_* gelten fuer JETZT bzw. fuer das naechste Fenster; stirbt der
 * Takt, blieb bis 3.1.4 z. B. "laden 1" fuer immer im Broker stehen, und
 * nach einem Neustart von Broker oder Gateway las Loxone ein Ladefenster,
 * das laengst vorbei war. Der Preis: nach einem solchen Neustart fehlen die
 * Werte bis zum naechsten Takt (hoechstens fuenf Minuten).
 *
 * Die Altwerte der Vorfassungen raeumt wi_sg_altlast() ab (siehe dort). */
$port = wi_mqtt_udpinport();
$pre = wi_praefix();
$gesendet_mqtt = 0;
if ($port && wi_cfg($cfg, 'mqtt', '0') === '1') {
    // C2: sg/lage aus dem Merker. Ohne Merker wurde nie etwas gestellt -
    // an der Heizung gilt dann, was dort eingestellt ist: normal.
    $wi_gilt = (is_array($lage['merker']) && isset($lage['merker']['lage']))
        ? (string) $lage['merker']['lage'] : 'normal';
    $werte = array(
        'sg/lage'    => $wi_gilt,
        'sg/laden'   => $lage['laden'] ? '1' : '0',
        'sg/dimmen'  => $lage['dimmen'] === null ? '-1' : ($lage['dimmen'] ? '1' : '0'),
        'sg/fenster' => (string) count($lage['fenster']),
        'sg/ts'      => (string) time(),
    );
    if ($lage['fenster']) {
        $naechstes = $lage['fenster'][0];
        foreach ($lage['fenster'] as $f) {
            if ($f['bis'] > time()) { $naechstes = $f; break; }
        }
        $werte['sg/naechster_start'] = (string) $naechstes['von'];
        $werte['sg/naechster_preis'] = (string) $naechstes['schnitt'];
    } else {
        /* Nachtrag 02.10.2026: ohne Plan 0 und -1 statt nichts - sonst blieb
         * in Loxone das letzte Fenster stehen (Bericht mqtt, Befund 8, Teil 2;
         * Regeln/07: eine Empfehlung, die verstummt, sendet 0, nicht nichts). */
        $werte['sg/naechster_start'] = '0';
        $werte['sg/naechster_preis'] = '-1';
    }
    list($direkt, $ueber_udp_leeren) = wi_sg_altlast($pre, $werte);
    set_error_handler(function () { return true; });
    $sock = fsockopen('udp://127.0.0.1', (int) $port, $nr, $txt, 3);
    restore_error_handler();
    if ($sock) {
        foreach ($werte as $t => $w) {
            if (isset($direkt[$t])) {
                continue;   // ging mit dem Abraeumen schon am Broker hinaus
            }
            // Broker nicht zu fragen: der Altwert geht in JEDEM Lauf mit
            // leerer Nutzlast unmittelbar vor dem gueltigen Wert hinaus -
            // ohne Merker, denn fwrite() meldet auch fuer ein verworfenes
            // Datagramm Erfolg (Regeln/07, "Ein Absender merkt nichts davon").
            if (isset($ueber_udp_leeren[$t])) {
                @fwrite($sock, 'retain ' . $pre . '/' . $t . " \n");
                usleep(2000);
            }
            // Thema und Wert enthalten nach Bau nie Leerraum. Erfolg heisst:
            // die ganze Zeile ist geschrieben (Kette, Bauart B).
            $wi_z = 'publish ' . $pre . '/' . $t . ' ' . $w . "\n";
            if (@fwrite($sock, $wi_z) === strlen($wi_z)) {
                $gesendet_mqtt++;
            }
            usleep(2000);
        }
        fclose($sock);
        // C2: dieser Lauf hat SG-Themen gesendet - beim Ausschalten folgt
        // einmal laden 0, dimmen 0, lage aus.
        $wi_mm = wi_sg_mqtt_merker_datei();
        if ($ernst && $gesendet_mqtt && $wi_mm !== '' && !is_file($wi_mm)) {
            if (!is_dir(dirname($wi_mm))) {
                @mkdir(dirname($wi_mm), 0775, true);
            }
            @file_put_contents($wi_mm, (string) time() . "\n");
        }
    }
}

foreach ($meld as $m) {
    // Im Protokoll steht Klartext, keine Sprachschluessel - das Protokoll
    // ist ein technisches Nachschlagewerk und bleibt einsprachig.
    wi_log_sg($m[0], $m[1]);
}
if ($gesendet_mqtt) {
    wi_log_sg('MQTT', array($gesendet_mqtt));
}
exit(0);

/**
 * Die zurueckbehaltenen SG-Altwerte der Fassungen 3.1.0 bis 3.1.3 abraeumen.
 *
 * Rueckgabe array(direkt, ueber_udp_leeren), je array(thema => true):
 *  - Merker liegt (Kennung "leer-bestaetigt <praefix>: <liste>"): nichts.
 *  - Broker gefragt, nichts belegt: Merker schreiben, nichts.
 *  - Broker gefragt, einige belegt: GENAU diese am Broker leeren, den
 *    gueltigen Wert unmittelbar dahinter fluechtig in derselben Verbindung,
 *    dann nachlesen; Merker erst, wenn der Broker nichts mehr liefert
 *    (Vorbild VolkswagenID 0.9.24, Beschattungswaechter 0.9.21).
 *  - Broker nicht zu fragen (CONNACK/SUBACK, Muster 11): KEIN Merker; alle
 *    Themen gehen ueber UDP mit leerer Nutzlast vor dem Wert hinaus.
 * Der Merker nennt Praefix und Themenliste; ein anderes Praefix oder eine
 * andere Liste gilt nicht. purge_installation raeumt ihn bei jedem Update
 * mit ab - dann wird einmal nachgefragt.
 */
function wi_sg_altlast($pre, array $werte)
{
    $liste = array('sg/lage', 'sg/laden', 'sg/dimmen', 'sg/fenster',
                   'sg/naechster_start', 'sg/naechster_preis');
    $p = wi_paths();
    $merker = $p['home'] . '/data/plugins/' . $p['plugin'] . '/sg_retain_geraeumt';
    $kennung = 'leer-bestaetigt ' . $pre . ': ' . implode(' ', $liste);
    if (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung) {
        return array(array(), array());
    }
    $filter = array($pre . '/sg/#');
    $belegt = function ($f) use ($pre, $liste) {
        $t = array();
        foreach ($liste as $x) {
            if (isset($f['belegt'][$pre . '/' . $x])) { $t[] = $x; }
        }
        return $t;
    };
    $f = wi_mqtt_sitzung($filter);
    if ($f['lage'] !== 'ok') {
        wi_log_sg('SG.M_ALT_UNBEKANNT', array($pre));
        return array(array(), array_fill_keys($liste, true));
    }
    $direkt = array();
    $alt = $belegt($f);
    if ($alt) {
        $senden = array();
        foreach ($alt as $x) {
            $senden[] = array($pre . '/' . $x, '', true);
            if (isset($werte[$x])) {
                $senden[] = array($pre . '/' . $x, $werte[$x], false);
                $direkt[$x] = true;
            }
        }
        $f = wi_mqtt_sitzung($filter, $senden);
        wi_log_sg('SG.M_ALT_GELEERT', array(implode(', ', $alt)));
        if ($f['lage'] !== 'ok' || $belegt($f)) {
            return array($direkt, array());
        }
    }
    if (!is_dir(dirname($merker))) {
        @mkdir(dirname($merker), 0775, true);
    }
    @file_put_contents($merker, $kennung . "\n");
    return array($direkt, array());
}

/** Klartext einer Meldung (Protokoll und Installer, einsprachig). */
function wi_sg_text($schluessel, $args)
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
        'MQTT_AUS'          => 'SG-Ready ausgeschaltet: %d MQTT-Zeilen (laden 0, dimmen 0, lage aus) an das Gateway.',
        'SG.M_ALT_GELEERT'  => 'Zurueckbehaltene Altwerte am Broker geleert: %s.',
        'SG.M_ALT_UNBEKANNT' => 'Der Broker liess sich nicht befragen - die frueher zurueckbehaltenen SG-Themen unter %s/sg/ gehen in jedem Lauf mit leerer Nutzlast vor dem Wert hinaus.',
        'SG.M_GEKAPPT'      => 'Hoechstdauer der Anhebung (%d h) erreicht, Ladung lief seit %s - es gilt normal bis zum Ende des Ladefensters.',
        'SG.M_KREIS_ZURUECK' => 'Kreiswechsel waehrend eines Zwangs: Kreis %s auf normal zurueckgestellt, jetzt gilt %s.',
        'SG.M_KREIS_ALT_FEHLT' => 'Der fruehere Kreis %s ist in dieser Firmware nicht schreibbar - er wurde nicht zurueckgestellt.',
        'SG.M_ZURUECK_NICHTS' => 'Kein Zwang gestellt - nichts zurueckzunehmen.',
        'SG.M_ZURUECK_OK'   => 'Zwang (%s) zurueckgenommen: Kreis %s steht wieder auf normal.',
        'SG.M_ZURUECK_TEIL' => 'Zuruecknehmen: NUR %d von %d Befehlen angekommen - der naechste Lauf versucht es wieder.',
    );
    return isset($texte[$schluessel]) ? vsprintf($texte[$schluessel], $args) : $schluessel;
}

/** Eine Protokollzeile, gebremst: gleiche Meldung hoechstens stuendlich. */
function wi_log_sg($schluessel, $args)
{
    $text = wi_sg_text($schluessel, $args);
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
