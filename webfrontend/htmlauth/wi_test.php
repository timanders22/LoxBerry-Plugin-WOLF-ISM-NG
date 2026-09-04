<?php
/**
 * Wolf ISM8 - Aktionen des Reiters Test
 *
 * Jede Funktion liefert array(Ueberschrift, Text). Der Text wird von der
 * Oberflaeche mit wi_e() maskiert ausgegeben, hier also bewusst als Klartext
 * erzeugt - eine HTML-Entitaet stuende dort woertlich da. Deshalb stehen die
 * Texte im Abschnitt [PRUEF] der Sprachdateien umschrieben (ae, oe, ue) und
 * ohne Auszeichnung.
 *
 * Mehrzeilige Ausgaben setzen sich aus einem Schluessel je Zeile zusammen
 * (..._1, ..._2, ...). Grund: INI-Werte ueber mehrere Zeilen sind mit
 * INI_SCANNER_RAW eine Wette auf das Verhalten des Parsers, und kein anderes
 * Plugin des Hauses macht das.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

require_once __DIR__ . '/wi_lib.php';
// Die Pruefzeilen des SG-Moduls brauchen dessen Funktionen. require_once,
// nicht require: index.php laedt die Datei ebenfalls.
require_once __DIR__ . '/wi_sg.php';

/** Shell-Befehl ausfuehren und Ausgabe einsammeln. */
function wi_sh($cmd)
{
    $out = array();
    @exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}

/**
 * Mehrere Sprachschluessel zu einem Absatz verbinden.
 * wi_zeilen('PRUEF.W_LEER_', 1, 6) ergibt W_LEER_1 bis W_LEER_6, je Zeile.
 */
function wi_zeilen($praefix, $von, $bis)
{
    $z = array();
    for ($i = $von; $i <= $bis; $i++) {
        $z[] = wi_t($praefix . $i);
    }
    return implode("\n", $z);
}

/* ==================================================================
 * Die Selbstpruefung (V5)
 *
 * Je Zeile eine Frage mit Haken, Kreuz oder Strich. Drei Ausgaenge, nicht
 * zwei: der Strich heisst "hier war nichts zu messen" und zaehlt in KEINER
 * Zusammenfassung als bestanden.
 *
 * Und jede Zeile, die ueber eine Menge urteilt, prueft zuerst, ob die Menge
 * leer ist. "Alle 0 von 0 sind in Ordnung" ist kein Haken - das ist die
 * Fehlerklasse, die in den Hausregeln als stille Falschaussage gefuehrt wird.
 *
 * Rueckgabe: Liste aus array(1|0|-1, Frage, Antwort)
 * ================================================================== */
function wi_pruefzeilen($cfg)
{
    $z = array();
    $p = wi_paths();
    $zu = wi_zustand();
    $fw = wi_cfg($cfg, 'fw_version', '1.8');
    $dps = wi_datenpunkte($fw);

    /* --- Schreibt das Speichern alles zurueck, was es gelesen hat? ------
     *
     * wi_config_write() schreibt AUSSCHLIESSLICH die Schluessel aus
     * wi_defaults(). Fehlt dort einer, den die Oberflaeche anbietet, dann
     * loescht ihn jeder Speichervorgang - lautlos. In 3.0.8 traf das
     * praefix, herzschlag und abgleich_takt; mit dem Praefix wanderten
     * saemtliche MQTT-Themen zurueck auf die Vorgabe.
     *
     * Geprueft wird gegen die Konfiguration, wie sie GESCHRIEBEN wird: was
     * nach einem Schreibvorgang in der Datei stuende, muss alles enthalten,
     * was jetzt darin steht. */
    $wi_vorgaben = wi_defaults();
    $wi_verloren = array();
    foreach (array_keys($cfg) as $wi_k) {
        if (!array_key_exists($wi_k, $wi_vorgaben)) {
            $wi_verloren[] = $wi_k;
        }
    }
    $z[] = array($wi_verloren ? 0 : 1, wi_t('PZ.SCHLUESSEL'),
                 $wi_verloren
                     ? sprintf(wi_t('PZ.SCHLUESSEL_NEIN'), implode(', ', $wi_verloren))
                     : sprintf(wi_t('PZ.SCHLUESSEL_JA'), count($wi_vorgaben)));

    /* --- Welche Stoercodetabelle gilt? ---------------------------------
     *
     * Drei Ausgaenge, nicht zwei: "keine gewaehlt" ist kein Fehler, sondern
     * der Auslieferungszustand - und es ist auch kein Erfolg. Der Strich. */
    list($wi_sh, $wi_sn, $wi_sz) = wi_stoercode_herkunft();
    if ($wi_sh === 'eigen') {
        $z[] = array(1, wi_t('TEST.SC_HERKUNFT'),
                     sprintf(wi_t('TEST.SC_EIGEN'), $wi_sn, $wi_sz));
    } elseif ($wi_sh === 'mitgeliefert') {
        $z[] = array(1, wi_t('TEST.SC_HERKUNFT'),
                     sprintf(wi_t('TEST.SC_MIT'), $wi_sn, $wi_sz));
    } else {
        $z[] = array(-1, wi_t('TEST.SC_HERKUNFT'), wi_t('TEST.SC_KEINE'));
    }

    // --- Die Ursache steht VOR der Wirkung -------------------------------
    $an = wi_cfg($cfg, 'enable', '0') === '1';
    $z[] = array($an ? 1 : 0, wi_t('PZ.EIN'),
                 $an ? wi_t('PZ.EIN_JA') : wi_t('PZ.EIN_NEIN'));

    $wd = wi_server_pid();
    $z[] = array($wd ? 1 : 0, wi_t('PZ.WATCHDOG'),
                 $wd ? sprintf(wi_t('PZ.PID'), $wd) : wi_t('PZ.LAEUFT_NICHT'));

    $sv = wi_ism8i_pid();
    $z[] = array($sv ? 1 : 0, wi_t('PZ.MODUL'),
                 $sv ? sprintf(wi_t('PZ.PID'), $sv) : wi_t('PZ.LAEUFT_NICHT'));

    // --- Perl-Module ------------------------------------------------------
    $module = array('List::MoreUtils', 'IO::Socket::Multicast', 'Math::Round',
                    'Net::MQTT::Simple', 'HTML::Entities', 'IO::Select');
    $fehlt = array();
    foreach ($module as $m) {
        if (wi_sh('perl -M' . escapeshellarg($m) . ' -e 1 2>&1') !== '') {
            $fehlt[] = $m;
        }
    }
    $z[] = array($fehlt ? 0 : 1, wi_t('PZ.MODULE'),
                 $fehlt ? sprintf(wi_t('PZ.MODULE_FEHLT'), implode(', ', $fehlt))
                        : sprintf(wi_t('PZ.MODULE_OK'), count($module)));

    // --- Datenpunkttabelle ------------------------------------------------
    $z[] = array($dps ? 1 : 0, wi_t('PZ.TABELLE'),
                 $dps ? sprintf(wi_t('PZ.TABELLE_OK'), count($dps), $fw)
                      : sprintf(wi_t('PZ.TABELLE_FEHLT'), $fw));

    // --- Konfiguration vollstaendig? --------------------------------------
    $roh = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
    $vorhanden = array();
    foreach (preg_split('/\R/', $roh) as $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '' || $zeile[0] === '#') { continue; }
        $f = preg_split('/\s+/', $zeile);
        // count($f) === 1 heisst "Schluessel da, Wert leer" - dieselbe Regel
        // wie in wi_config_read(). Sonst meldete diese Zeile den Werkszustand
        // von 'stoercodes' als Luecke.
        if (count($f) === 1 || count($f) === 2) { $vorhanden[strtolower($f[0])] = true; }
    }
    $soll = array_keys(wi_defaults());
    $lueck = array();
    foreach ($soll as $k) {
        if (!isset($vorhanden[$k])) { $lueck[] = $k; }
    }
    if ($roh === '') {
        $z[] = array(0, wi_t('PZ.KONFIG'), sprintf(wi_t('PZ.KONFIG_FEHLT'), $p['config']));
    } else {
        $z[] = array($lueck ? 0 : 1, wi_t('PZ.KONFIG'),
                     $lueck ? sprintf(wi_t('PZ.KONFIG_LUECKE'), implode(', ', $lueck))
                            : sprintf(wi_t('PZ.KONFIG_OK'), count($soll)));
    }

    // --- Das Zustandsabbild ----------------------------------------------
    if ($zu === null) {
        $z[] = array(-1, wi_t('PZ.ABBILD'), wi_t('PZ.ABBILD_KEINS'));
    } else {
        $alt = wi_zustand_alter();
        $frisch = ($alt >= 0 && $alt < 900);
        $z[] = array($frisch ? 1 : 0, wi_t('PZ.ABBILD'),
                     sprintf(wi_t('PZ.ABBILD_ALTER'), wi_alter_text($alt)));
    }

    // --- Hat das ISM8 je verbunden? ---------------------------------------
    if ($zu === null) {
        $z[] = array(-1, wi_t('PZ.VERBUNDEN'), wi_t('PZ.OHNE_ABBILD'));
    } else {
        $v = isset($zu['zaehler']['verbindungen']) ? (int) $zu['zaehler']['verbindungen'] : 0;
        $z[] = array($v > 0 ? 1 : 0, wi_t('PZ.VERBUNDEN'),
                     $v > 0 ? sprintf(wi_t('PZ.VERBUNDEN_JA'), $v, wi_e(isset($zu['ism8_ip']) ? $zu['ism8_ip'] : '?'))
                            : wi_t('PZ.VERBUNDEN_NIE'));
    }

    // --- Kommen Werte an? Erst pruefen, ob die Menge leer ist. ------------
    $anz = ($zu !== null && isset($zu['werte'])) ? count($zu['werte']) : 0;
    if ($zu === null) {
        $z[] = array(-1, wi_t('PZ.WERTE'), wi_t('PZ.OHNE_ABBILD'));
    } elseif ($anz === 0) {
        $z[] = array(0, wi_t('PZ.WERTE'), wi_t('PZ.WERTE_KEINE'));
    } else {
        $z[] = array(1, wi_t('PZ.WERTE'), sprintf(wi_t('PZ.WERTE_JA'), $anz, count($dps)));
    }

    // --- Firmware-Plausibilitaet (V4) -------------------------------------
    $fv = wi_fw_verdacht();
    if ($zu === null) {
        $z[] = array(-1, wi_t('PZ.FW'), wi_t('PZ.OHNE_ABBILD'));
    } elseif ($fv === null) {
        // Ein Haken hier heisst NICHT "die Firmware stimmt" - er heisst
        // "nichts spricht dagegen". Der umgekehrte Fall (Tabelle zu neu) ist
        // von hier aus nicht erkennbar, und das steht auch da.
        $z[] = array(1, wi_t('PZ.FW'), sprintf(wi_t('PZ.FW_OK'), $fw));
    } else {
        $z[] = array(0, wi_t('PZ.FW'),
            $fv['fw'] !== ''
                ? sprintf(wi_t('PZ.FW_VERDACHT'), $fv['anzahl'], $fv['min'], $fv['max'], $fv['fw'], $fw)
                : sprintf(wi_t('PZ.FW_UNBEKANNT'), $fv['anzahl'], $fv['min'], $fv['max']));
    }

    // --- Ausfallerkennung --------------------------------------------------
    $hz = (int) wi_cfg($cfg, 'herzschlag', '60');
    $to = wi_cfg($cfg, 'online_timeout', '-1');
    $z[] = array($hz > 0 ? 1 : 0, wi_t('PZ.HERZ'),
                 $hz > 0 ? sprintf(wi_t('PZ.HERZ_JA'), $hz) : wi_t('PZ.HERZ_AUS'));
    $z[] = array(((string) $to === '-1' || (int) $to <= 0) ? 0 : 1, wi_t('PZ.AUSFALL'),
                 ((string) $to === '-1' || (int) $to <= 0)
                    ? wi_t('PZ.AUSFALL_AUS') : sprintf(wi_t('PZ.AUSFALL_JA'), (int) $to));

    // --- MQTT --------------------------------------------------------------
    $mq = wi_cfg($cfg, 'mqtt', '0') === '1';
    $gw = wi_gateway_info();
    if (!$mq) {
        $z[] = array(-1, wi_t('PZ.MQTT'), wi_t('PZ.MQTT_AUS'));
    } elseif ($gw === null) {
        $z[] = array(0, wi_t('PZ.MQTT'), wi_t('PZ.MQTT_KEIN_GW'));
    } else {
        $z[] = array($gw['autostart'] ? 1 : 0, wi_t('PZ.MQTT'),
                     $gw['autostart'] ? sprintf(wi_t('PZ.MQTT_OK'), $gw['fassung'] > 0 ? $gw['fassung'] : '?')
                                      : wi_t('PZ.MQTT_KEIN_AUTOSTART'));
    }

    // --- Der eigene Cron-Eintrag (V10) -------------------------------------
    $cron = $p['home'] ? $p['home'] . '/system/cron/cron.05min/' . $p['plugin'] : '';
    if (!$p['home']) {
        $z[] = array(-1, wi_t('PZ.CRON'), wi_t('PZ.CRON_UNBEKANNT'));
    } else {
        $da = is_file($cron);
        $z[] = array($da ? 1 : 0, wi_t('PZ.CRON'),
                     $da ? sprintf(wi_t('PZ.CRON_JA'), $cron) : sprintf(wi_t('PZ.CRON_NEIN'), $cron));
    }

    // --- Die erzeugten Vorlagen -------------------------------------------
    list($ok, $txt) = wi_vorlagen_pruefen($cfg);
    $z[] = array($ok === -1 ? -1 : ($ok ? 1 : 0), wi_t('PZ.VORLAGEN'), $txt);

    // --- Reiterleiste, Bereiche und Positivliste gegeneinander -------------
    list($ok2, $txt2) = wi_reiter_pruefen();
    $z[] = array($ok2 === -1 ? -1 : ($ok2 ? 1 : 0), wi_t('PZ.REITER'), $txt2);

    // --- Steht zu JEDER Einstellung eine Wertpruefung bereit? --------------
    // wi_wert_taugt() ist die gemeinsame Positivliste von Formular und
    // Zurueckspielen. Waechst wi_defaults(), ohne dass sie mitwaechst, faellt
    // der neue Schluessel beim Zurueckspielen still durch - deshalb zaehlt
    // diese Zeile beide Mengen gegeneinander.
    $luecken = wi_wertpruefung_luecken();
    $z[] = array($luecken ? 0 : 1, wi_t('PZ.WERTPRUEFUNG'),
                 $luecken ? sprintf(wi_t('PZ.WERTPRUEFUNG_FEHLT'), implode(', ', $luecken))
                          : sprintf(wi_t('PZ.WERTPRUEFUNG_OK'), count(wi_defaults())));

    // --- Sichern und Zurueckspielen als Rundlauf --------------------------
    list($ok4, $txt4) = wi_sicherung_rundlauf($cfg);
    $z[] = array($ok4 ? 1 : 0, wi_t('PZ.RUNDLAUF'), $txt4);

    // --- Retain: Oberflaeche gegen den Dienst ------------------------------
    list($ok5, $txt5) = wi_zustandstypen_pruefen();
    $z[] = array($ok5 === null ? -1 : ($ok5 ? 1 : 0), wi_t('PZ.RETAIN'), $txt5);

    /* --- SG-Ready (V24) --------------------------------------------------
     *
     * Zwei Zeilen, und beide pruefen zuerst, ob es etwas zu pruefen gibt.
     * Ausgeschaltet ist der Auslieferungszustand und weder Erfolg noch
     * Fehler - also der Strich. */
    if (wi_cfg($cfg, 'sg_ein', '0') !== '1') {
        $z[] = array(-1, wi_t('PZ.SG'), wi_t('PZ.SG_AUS'));
    } else {
        $sgl = wi_sg_lage($cfg);
        if ($sgl['fehlt']) {
            $z[] = array(0, wi_t('PZ.SG'),
                         sprintf(wi_t('PZ.SG_FEHLT'), implode(', ', $sgl['fehlt'])));
        } elseif (!$sgl['preise']) {
            $z[] = array(0, wi_t('PZ.SG'), wi_t('PZ.SG_KEINE_PREISE'));
        } else {
            $z[] = array(1, wi_t('PZ.SG'),
                         sprintf(wi_t('PZ.SG_OK'), count($sgl['preise']),
                                 count($sgl['fenster']),
                                 wi_t('SG.LAGE_' . strtoupper($sgl['lage']))));
        }
        // Das Dimmsignal bekommt eine eigene Zeile: sein Ausfall ist etwas
        // anderes als ein fehlender Preis, und er darf nicht in derselben
        // Zeile untergehen.
        list($d14, $a14, $h14) = wi_sg_14a($cfg);
        /* Gruen NUR, wenn das Signal wirklich frisch ist. Der erste Anlauf
         * dieser Zeile fragte "$d14 === null" und war damit auch bei einem
         * VERALTETEN Signal gruen - denn veraltet liefert false, nicht null.
         * Ein Haken neben dem Satz "das Signal ist veraltet" ist genau der
         * Fehlalarm-Typ, den diese Fassung sonst ueberall beseitigt.
         * Ausgeschaltet ist der Strich, alles andere ausser frisch das Kreuz. */
        $frisch = in_array($h14, array('SG.E14_AKTIV', 'SG.E14_RUHE'), true);
        $z[] = array($h14 === 'SG.E14_AUS' ? -1 : ($frisch ? 1 : 0),
                     wi_t('PZ.SG14'),
                     wi_t($h14) . ($a14 >= 0 ? ' ' . sprintf(wi_t('PZ.SG14_ALTER'),
                                                             wi_alter_text($a14)) : ''));
    }

    // --- Betriebsart-Tabellen gegen das Perl ------------------------------
    list($ok3, $txt3) = wi_arten_pruefen($fw);
    $z[] = array($ok3 === null ? -1 : ($ok3 ? 1 : 0), wi_t('PZ.ARTEN'), $txt3);

    return $z;
}

/**
 * Sichern und Zurueckspielen als RUNDLAUF: die Datei, die der eine Knopf
 * ausliefert, dem anderen vorlegen.
 *
 * Bis 3.0.10 scheiterte genau das im Werkszustand. wi_konfig_text() schreibt
 * den leeren Vorgabewert von 'stoercodes' als Zeile mit nur einem Feld, und
 * wi_konfig_einlesen() zaehlte eine solche Zeile als Beanstandung - womit die
 * GANZE Datei abgelehnt wurde. Auf jeder frischen Anlage war der Umzug auf
 * einen zweiten LoxBerry damit unmoeglich, und die Meldung zeigte auf eine
 * Einstellung, die der Anwender nie angefasst hatte.
 *
 * Diese Zeile misst das Erzeugnis, nicht den Quelltext.
 */
function wi_sicherung_rundlauf($cfg)
{
    $txt = wi_konfig_text($cfg);
    list($neu, $mangel) = wi_konfig_einlesen($txt);
    if ($neu === null) {
        return array(false, sprintf(wi_t('PZ.RUNDLAUF_NEIN'),
                                    count($mangel), strip_tags((string) $mangel[0])));
    }
    $soll = count(wi_defaults());
    if (count($neu) !== $soll) {
        return array(false, sprintf(wi_t('PZ.RUNDLAUF_ZAHL'), count($neu), $soll));
    }
    return array(true, sprintf(wi_t('PZ.RUNDLAUF_JA'), $soll));
}

/**
 * Retain: die Typenliste der Oberflaeche gegen die des Dienstes.
 *
 * Beide Seiten muessen dieselben KNX-Typen fuer Zustaende halten, sonst sagt
 * die Namenstabelle etwas anderes, als der Dienst tut. Die Listen stehen
 * zwangslaeufig zweimal da - ueber die Sprachgrenze hinweg gibt es keine
 * gemeinsame Funktion -, also werden sie gezaehlt.
 */
function wi_zustandstypen_pruefen()
{
    $p = wi_paths();
    $kandidaten = array($p['bindir'] . '/wolf_ism8i.pl',
                        dirname(dirname(__DIR__)) . '/bin/wolf_ism8i.pl');
    $quelle = '';
    foreach ($kandidaten as $k) {
        if (is_file($k)) {
            $quelle = (string) @file_get_contents($k);
            break;
        }
    }
    if ($quelle === '') {
        return array(null, wi_t('PZ.RETAIN_UNLESBAR'));
    }
    if (!preg_match('/our\s+@ZUSTAND_TYPEN\s*=\s*\((.*?)\);/s', $quelle, $m)) {
        return array(null, wi_t('PZ.RETAIN_KEINE_LISTE'));
    }
    preg_match_all('/"([A-Za-z0-9_]+)"/', $m[1], $t);
    $dienst = $t[1];
    $ober = wi_zustandstypen();
    sort($dienst);
    sort($ober);
    if (!$dienst) {
        return array(null, wi_t('PZ.RETAIN_KEINE_LISTE'));
    }
    if ($dienst !== $ober) {
        $nur_o = array_diff($ober, $dienst);
        $nur_d = array_diff($dienst, $ober);
        return array(false, sprintf(wi_t('PZ.RETAIN_NEIN'),
            $nur_o ? implode(', ', $nur_o) : '-',
            $nur_d ? implode(', ', $nur_d) : '-'));
    }
    return array(true, sprintf(wi_t('PZ.RETAIN_JA'), count($ober)));
}

/** Alle vier Vorlagen erzeugen und durch simplexml_load_string schicken. */
function wi_vorlagen_pruefen($cfg)
{
    $dps = wi_datenpunkte(wi_cfg($cfg, 'fw_version', '1.8'));
    if (!$dps) {
        return array(-1, wi_t('PZ.VORLAGEN_KEINE_DP'));
    }
    $geraete = wi_geraete($dps);
    $eins = array($geraete[0]);
    $schlecht = array();
    $gut = 0;
    $versucht = 0;
    foreach (array('udp_in', 'tcp_out', 'mqtt_in', 'mqtt_out') as $art) {
        list($name, $inhalt, $anz) = wi_vorlage($art, $cfg, $eins, null);
        if ($inhalt === '') {
            continue;   // fuer dieses Geraet gibt es die Bauform nicht
        }
        $versucht++;
        $alt = libxml_use_internal_errors(true);
        $x = simplexml_load_string($inhalt);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        if ($x === false) { $schlecht[] = $art; } else { $gut++; }
    }
    if ($gut === 0) {
        return array(-1, wi_t('PZ.VORLAGEN_NICHTS'));
    }
    return array(!$schlecht,
        $schlecht ? sprintf(wi_t('PZ.VORLAGEN_KAPUTT'), implode(', ', $schlecht))
                  : sprintf(wi_t('PZ.VORLAGEN_OK'), $gut, $versucht));
}

/**
 * Reiterleiste, Bereiche und Positivliste gegeneinander zaehlen.
 *
 * Drei Stellen muessen zusammenpassen, und keine Prüfkette liest sie
 * gegeneinander - hausstandard_pruefen.py sieht nur, ob die Zahlen gleich
 * sind, nicht ob ein Reiter fehlt, den es geben muesste.
 */
function wi_reiter_pruefen()
{
    $q = (string) @file_get_contents(__DIR__ . '/index.php');
    if ($q === '') {
        return array(-1, wi_t('PZ.REITER_UNLESBAR'));
    }
    preg_match_all('/data-pane="(tab-[a-z0-9]+)"/', $q, $a);
    preg_match_all('/class="sm-pane[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $q, $b);
    preg_match('/\^tab-\(([a-z0-9|]+)\)/', $q, $c);
    $leiste = array_unique($a[1]);
    $bereiche = array_unique($b[1]);
    $liste = isset($c[1]) ? array_map(function ($x) { return 'tab-' . $x; },
                                      explode('|', $c[1])) : array();
    sort($leiste); sort($bereiche); sort($liste);
    $gleich = ($leiste === $bereiche && $leiste === $liste && count($leiste) > 0);
    return array($gleich, sprintf(wi_t('PZ.REITER_ZAHL'),
        count($leiste), count($bereiche), count($liste)));
}

/**
 * Die Betriebsart-Tabellen der Oberflaeche gegen die im Perl.
 *
 * Sie stehen zwangslaeufig zweimal da - ueber die Sprachgrenze hinweg gibt es
 * keine gemeinsame Funktion. Diese Zeile stellt sicher, dass sie nicht
 * auseinanderlaufen: gezaehlt werden die Klartexte im Perl.
 */
function wi_arten_pruefen($fw)
{
    $pl = wi_paths()['bindir'] . '/wolf_ism8i.pl';
    if (!is_file($pl)) {
        $pl = dirname(dirname(__DIR__)) . '/bin/wolf_ism8i.pl';
    }
    if (!is_file($pl)) {
        return array(null, wi_t('PZ.ARTEN_KEIN_PERL'));
    }
    $q = (string) @file_get_contents($pl);
    $eigene = array();
    foreach (wi_betriebsarten($fw) as $gruppen) {
        foreach ($gruppen as $werte) {
            foreach ($werte as $txt) {
                if ($txt !== '-') { $eigene[$txt] = true; }
            }
        }
    }
    $fehlt = array();
    foreach (array_keys($eigene) as $txt) {
        if (strpos($q, '"' . $txt . '"') === false) { $fehlt[] = $txt; }
    }
    return array(!$fehlt, $fehlt
        ? sprintf(wi_t('PZ.ARTEN_ABWEICHUNG'), count($fehlt), implode(', ', array_slice($fehlt, 0, 3)))
        : sprintf(wi_t('PZ.ARTEN_OK'), count($eigene)));
}

function wi_test_ausfuehren($was)
{
    $p = wi_paths();
    $cfg = wi_config_read();

    switch ($was) {

        case 'status':
            $wd = wi_server_pid();
            $sv = wi_ism8i_pid();
            $lauf = function ($pid) {
                return $pid ? sprintf(wi_t('PRUEF.LAEUFT_PID'), $pid) : wi_t('PRUEF.LAEUFT_NICHT');
            };
            $t  = sprintf(wi_t('PRUEF.S_WATCHDOG'), $lauf($wd)) . "\n";
            $t .= sprintf(wi_t('PRUEF.S_MODUL'), $lauf($sv)) . "\n";
            $t .= sprintf(wi_t('PRUEF.S_KONFIG'),
                wi_cfg($cfg, 'enable', '0') === '1' ? wi_t('PRUEF.EIN') : wi_t('PRUEF.AUS')) . "\n\n";
            if ($wd && !$sv) {
                $t .= wi_zeilen('PRUEF.S_LUECKE_', 1, 3) . "\n\n";
            }
            if (!$wd && wi_cfg($cfg, 'enable', '0') === '1') {
                $t .= wi_zeilen('PRUEF.S_TOT_', 1, 2) . "\n\n";
            }
            $t .= wi_sh('ps -o pid,etime,rss,args -C perl 2>/dev/null | grep -i -E "wolf|PID" ');
            return array(wi_t('PRUEF.T_STATUS'), $t !== '' ? $t : wi_t('PRUEF.KEINE_ANGABEN'));

        case 'werte':
            $datei = wi_log_file('server');
            if ($datei === '') {
                return array(wi_t('PRUEF.T_WERTE'),
                    wi_t('PRUEF.W_KEIN_LOG_1') . "\n\n" . wi_t('PRUEF.W_KEIN_LOG_2'));
            }
            $zeilen = wi_log_tail($datei, 400);
            $treffer = array();
            foreach ($zeilen as $z) {
                // Das Themen-Praefix ist einstellbar. Fest verdrahtet zaehlte diese
                // Zeile nach einem Wechsel nichts mehr - sie suchte einen
                // Namen, den der Dienst nicht mehr benutzt.
                $muster = '/\d{1,3}\s*;|'
                        . preg_quote(wi_praefix() . '/', '/') . '/u';
                if (preg_match($muster, $z)) {
                    $treffer[] = $z;
                }
                if (count($treffer) >= 60) {
                    break;
                }
            }
            if (!$treffer) {
                return array(wi_t('PRUEF.T_WERTE'),
                    wi_t('PRUEF.W_LEER_1') . "\n\n" . wi_zeilen('PRUEF.W_LEER_', 2, 6));
            }
            return array(wi_t('PRUEF.T_WERTE_NEU'), implode("\n", $treffer));

        case 'themen':
            $fw = wi_cfg($cfg, 'fw_version', '1.8');
            $dps = wi_datenpunkte($fw);
            if (!$dps) {
                return array(wi_t('PRUEF.T_THEMEN'), wi_t('PRUEF.TH_KEINE'));
            }
            $t  = sprintf(wi_t('PRUEF.TH_KOPF'), $fw, count($dps)) . "\n";
            $t .= sprintf(wi_t('PRUEF.TH_MQTT'),
                wi_cfg($cfg, 'mqtt', '0') === '1' ? wi_t('PRUEF.EIN') : wi_t('PRUEF.AUS_GROSS')) . "\n\n";
            // Das EINGESTELLTE Praefix, nicht der Vorgabename - und alle DREI
            // Lebenszeichen-Themen. Bis 3.0.10 nannte diese Liste 221
            // Themen, waehrend der Dienst 223 sendet und die Tabelle im
            // Reiter MQTT 223 zeigte: drei Auflistungen, drei Zahlen.
            $t .= wi_praefix() . "/online\n";
            $t .= wi_praefix() . "/zeitstempel\n";
            $t .= wi_praefix() . "/zaehler\n";
            // Uhrzeit- und Datumstypen bleiben weg: der Dienst
            // veroeffentlicht sie nicht (wolf_ism8i.pl, @ignored_types).
            // Bis 3.0.7 standen sie in dieser Liste - in Firmware 1.9 acht
            // Themen, die es nie gibt. Wer die Liste als Sollzustand nahm,
            // suchte acht Themen vergeblich. Die MQTT-Eingangsvorlage
            // filtert sie seit jeher richtig heraus.
            $zeit_typen = array('DPT_TimeOfDay', 'DPT_Date');
            foreach ($dps as $d) {
                if (strpos($d['io'], 'Out') === false) {
                    continue;
                }
                if (in_array($d['dpt'], $zeit_typen, true)) {
                    continue;
                }
                $t .= wi_topic($d) . "\n";
            }
            return array(wi_t('PRUEF.T_THEMEN_FW'), $t);

        case 'konfig':
            $t = sprintf(wi_t('PRUEF.K_DATEI'), $p['config']) . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= wi_t('PRUEF.K_FEHLT') . "\n\n";
                foreach (wi_defaults() as $k => $v) {
                    $t .= $k . ' ' . $v . "\n";
                }
            }
            return array(wi_t('PRUEF.T_KONFIG'), $t);

        case 'ports':
            $t  = sprintf(wi_t('PRUEF.P_ADRESSE'), wi_localip()) . "\n\n";
            $t .= wi_t('PRUEF.P_ERWARTET') . "\n";
            $t .= sprintf(wi_t('PRUEF.P_ISM8'), wi_cfg($cfg, 'ism8i_port', '12004')) . "\n";
            $t .= sprintf(wi_t('PRUEF.P_INPUT'), wi_cfg($cfg, 'input_port', '12005')) . "\n\n";
            $ss = wi_sh('ss -lntup 2>/dev/null || netstat -lntup 2>/dev/null');
            $gefiltert = array();
            foreach (preg_split('/\R/', $ss) as $z) {
                if (preg_match('/(State|:' . preg_quote(wi_cfg($cfg, 'ism8i_port', '12004'), '/') . '\b|:'
                    . preg_quote(wi_cfg($cfg, 'input_port', '12005'), '/') . '\b|wolf)/i', $z)) {
                    $gefiltert[] = $z;
                }
            }
            $t .= $gefiltert ? implode("\n", $gefiltert) : wi_t('PRUEF.P_KEINER');
            return array(wi_t('PRUEF.T_PORTS'), $t);

        case 'umgebung':
            $t  = sprintf(wi_t('PRUEF.U_PHP'), PHP_VERSION) . "\n";
            $t .= sprintf(wi_t('PRUEF.U_HOME'),
                $p['home'] !== '' ? $p['home'] : wi_t('PRUEF.U_HOME_LEER')) . "\n";
            $t .= sprintf(wi_t('PRUEF.U_PLUGIN'), $p['plugin']) . "\n";
            $t .= sprintf(wi_t('PRUEF.U_BIN'), $p['bindir']) . "\n";
            $t .= sprintf(wi_t('PRUEF.U_LOG'), $p['logdir']) . "\n\n";
            $t .= wi_sh('perl -v 2>/dev/null | grep -m1 version');
            $t .= "\n\n" . wi_t('PRUEF.U_MODULE') . "\n";
            /*
             * Geprueft wird genau das, was die Perl-Skripte mit "use" laden -
             * nachgezaehlt in bin/wolf_ism8i.pl und bin/ism8i_comtest.pl.
             *
             * Bis 2.5.0 stand hier Config::Simple. Das kommt im ganzen Plugin
             * kein einziges Mal vor; geprueft wurde also ein Modul, das
             * niemand braucht. List::MoreUtils dagegen fehlte in der Liste -
             * und das ist ausgerechnet jenes, dessen Fehlen den Server gar
             * nicht erst starten laesst (wolf_ism8i.pl, Zeile 14, "use" ohne
             * eval; first_index wird in Zeile 1315 wirklich gebraucht). Die
             * Diagnose haette "alles vorhanden" gemeldet, waehrend nichts lief.
             *
             * Die Kernmodule (IO::Socket::INET, Encode, File::Basename,
             * Data::Dumper) stehen bewusst nicht hier - die bringt jedes Perl
             * mit, ihr Fehlen waere kein denkbarer Fall.
             */
            $module = array(
                'List::MoreUtils',        // first_index - ohne dieses kein Start
                'IO::Socket::Multicast',  // UDP-Direktausgabe und comtest
                'Math::Round',            // Skalierung der Datenpunktwerte
                'Net::MQTT::Simple',      // MQTT-Weg, seit 2.5.0 die Vorgabe
                'HTML::Entities',
                'IO::Select',
            );
            foreach ($module as $m) {
                $r = wi_sh('perl -M' . escapeshellarg($m) . ' -e 1 2>&1');
                $t .= sprintf("  %-26s %s\n", $m,
                    $r === '' ? wi_t('PRUEF.U_VORHANDEN') : wi_t('PRUEF.U_FEHLT'));
            }
            $t .= "\n" . wi_t('PRUEF.U_TABELLEN') . "\n";
            foreach (array('14', '15', '17', '18', '19') as $fw) {
                $f = $p['bindir'] . '/wolf_datenpunkte_' . $fw . '.csv';
                $nr = substr($fw, 0, 1) . '.' . substr($fw, 1);
                $t .= sprintf("  %-6s %s\n", $nr, is_file($f)
                    ? sprintf(wi_t('PRUEF.U_DP_ANZAHL'), count(wi_datenpunkte($nr)))
                    : wi_t('PRUEF.U_DP_FEHLT'));
            }
            return array(wi_t('PRUEF.T_UMGEBUNG'), $t);

        case 'mqttinfo':
            $broker = wi_mqtt_broker();
            $udp = wi_mqtt_udpinport();
            $t  = sprintf(wi_t('PRUEF.M_BROKER'),
                $broker !== '' ? $broker : wi_t('PRUEF.M_NICHT_GEFUNDEN')) . "\n";
            $t .= sprintf(wi_t('PRUEF.M_RELAY'),
                $udp ? $udp : wi_t('PRUEF.M_NICHT_GESETZT')) . "\n";
            $t .= sprintf(wi_t('PRUEF.M_PLUGIN'),
                wi_cfg($cfg, 'mqtt', '0') === '1' ? wi_t('PRUEF.EIN') : wi_t('PRUEF.AUS')) . "\n\n";
            if ($broker === '') {
                $t .= wi_zeilen('PRUEF.M_OHNE_GW_', 1, 2) . "\n\n";
            }
            if (!$udp) {
                $t .= wi_zeilen('PRUEF.M_OHNE_UDP_', 1, 2) . "\n\n";
            }
            $t .= wi_zeilen('PRUEF.M_FINDER_', 1, 2);
            return array(wi_t('PRUEF.T_MQTT'), $t);

        case 'restart':
            $a = wi_server('restart');
            sleep(2);
            $t = ($a !== '' ? $a . "\n\n" : '');
            $wd = wi_server_pid();
            $t .= $wd ? sprintf(wi_t('PRUEF.R_LAEUFT'), $wd) : wi_t('PRUEF.R_LAEUFT_NICHT');
            return array(wi_t('PRUEF.T_RESTART'), $t);

        case 'stop':
            $a = wi_server('stop');
            sleep(1);
            $t = ($a !== '' ? $a . "\n\n" : '');
            $t .= wi_server_pid()
                ? wi_t('PRUEF.H_LAEUFT_NOCH')
                : wi_t('PRUEF.H_ANGEHALTEN') . "\n\n" . wi_zeilen('PRUEF.H_HINWEIS_', 1, 2);
            return array(wi_t('PRUEF.T_STOP'), $t);

        case 'vorlagenprobe':
            list($ok, $txt) = wi_vorlagen_pruefen($cfg);
            $t = $txt . "\n\n";
            $geraete = wi_geraete(wi_datenpunkte(wi_cfg($cfg, 'fw_version', '1.8')));
            if ($geraete) {
                list($n, $inhalt, $anz) = wi_vorlage('mqtt_in', $cfg, array($geraete[0]), null);
                $t .= sprintf(wi_t('PRUEF.VP_BEISPIEL'), $n, $anz) . "\n\n";
                $zeilen = preg_split('/\R/', $inhalt);
                $t .= implode("\n", array_slice($zeilen, 0, 6));
                if (count($zeilen) > 6) {
                    $t .= "\n" . sprintf(wi_t('PRUEF.VP_WEITERE'), count($zeilen) - 6);
                }
            }
            return array(wi_t('PRUEF.T_VORLAGEN'), $t);

        case 'schreibprobe':
        case 'schreibernst':
            $id = isset($_POST['sp_id']) ? (string) $_POST['sp_id'] : '';
            $wert = isset($_POST['sp_wert']) ? (string) $_POST['sp_wert'] : '';
            list($ok, $txt) = wi_schreibprobe($cfg, $id, $wert, $was === 'schreibernst');
            return array($was === 'schreibernst' ? wi_t('PRUEF.T_SP_ERNST')
                                                 : wi_t('PRUEF.T_SP_TROCKEN'), $txt);

        case 'aufraeumen_probe':
        case 'aufraeumen':
            return wi_aufraeumen($cfg, $was === 'aufraeumen');

        case 'dplog_leeren':
            $datei = wi_log_file('datapoints');
            if ($datei === '') { $datei = wi_log_file('wolf'); }
            if ($datei === '' || !is_file($datei)) {
                return array(wi_t('PRUEF.T_DPLOG'), wi_t('PRUEF.DL_KEINE'));
            }
            $gr = (int) @filesize($datei);
            if (@file_put_contents($datei, '') === false) {
                return array(wi_t('PRUEF.T_DPLOG'), sprintf(wi_t('PRUEF.DL_FEHLER'), $datei));
            }
            clearstatcache(true, $datei);
            return array(wi_t('PRUEF.T_DPLOG'),
                sprintf(wi_t('PRUEF.DL_OK'), $datei,
                        number_format($gr / 1024, 1, ',', '.'),
                        (int) @filesize($datei)));

        case 'comtest':
            $skript = $p['bindir'] . '/ism8i_comtest.pl';
            if (!is_file($skript)) {
                return array(wi_t('PRUEF.T_COMTEST'), sprintf(wi_t('PRUEF.C_FEHLT'), $skript));
            }
            $kopf = wi_zeilen('PRUEF.C_KOPF_', 1, 3) . "\n\n";
            if (wi_cfg($cfg, 'output', 'none') !== 'data') {
                return array(wi_t('PRUEF.T_COMTEST'), $kopf
                    . wi_zeilen('PRUEF.C_AUS_', 1, 2) . "\n\n"
                    . wi_zeilen('PRUEF.C_AUS_', 3, 4));
            }
            $ausgabe = wi_sh('timeout 20 perl ' . escapeshellarg($skript));
            if (trim($ausgabe) === '') {
                $ausgabe = wi_t('PRUEF.C_STILL_1') . "\n\n" . wi_zeilen('PRUEF.C_STILL_', 2, 5);
            }
            return array(wi_t('PRUEF.T_COMTEST'), $kopf . $ausgabe);
    }

    return array(wi_t('PRUEF.T_UNBEKANNT'), wi_t('PRUEF.UNBEKANNT'));
}

/**
 * Retained gebliebene Themen aufraeumen (V13).
 *
 * Geschickt wird ueber den UDP-Eingang des MQTT-Gateways: "retain <thema> "
 * mit LEERER Nutzlast loescht im MQTT-Protokoll einen retained-Wert.
 *
 * NICHT GEMESSEN und deshalb ausdruecklich gesagt: ob das Gateway eine leere
 * Nutzlast so weiterreicht, ist an einem Broker zu pruefen. Der Trockenlauf
 * schickt NICHTS und zeigt nur, was hinausginge - er ist die Stufe davor.
 */
function wi_aufraeumen($cfg, $ernst)
{
    $themen = wi_alle_themen($cfg);
    $port = wi_mqtt_udpinport();
    $kopf = sprintf(wi_t('PRUEF.AR_KOPF'), count($themen)) . "\n";

    if (!$ernst) {
        $kopf .= wi_t('PRUEF.AR_TROCKEN') . "\n\n";
        $zeig = array_slice($themen, 0, 40);
        $kopf .= implode("\n", $zeig);
        if (count($themen) > 40) {
            $kopf .= "\n" . sprintf(wi_t('PRUEF.AR_WEITERE'), count($themen) - 40);
        }
        return array(wi_t('PRUEF.T_AUFRAEUMEN'), $kopf);
    }

    if (!$port) {
        return array(wi_t('PRUEF.T_AUFRAEUMEN'), $kopf . wi_t('PRUEF.AR_KEIN_PORT'));
    }
    $sock = @fsockopen('udp://127.0.0.1', (int) $port, $nr, $txt, 3);
    if (!$sock) {
        return array(wi_t('PRUEF.T_AUFRAEUMEN'),
                     $kopf . sprintf(wi_t('PRUEF.AR_KEIN_SOCKET'), (int) $port, wi_e($txt)));
    }
    $n = 0;
    foreach ($themen as $th) {
        if (@fwrite($sock, 'retain ' . $th . " \n") !== false) {
            $n++;
        }
        usleep(2000);
    }
    fclose($sock);
    return array(wi_t('PRUEF.T_AUFRAEUMEN'),
        $kopf . sprintf(wi_t('PRUEF.AR_ERNST'), $n, (int) $port) . "\n\n"
              . wi_t('PRUEF.AR_VORBEHALT'));
}
