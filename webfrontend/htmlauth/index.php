<?php
/**
 * Wolf ISM8 - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Der Reiter MQTT ist seit 3.0.8 eigenstaendig: der Hausstandard verlangt,
 * dass ALLE MQTT-Belange dort wohnen - Haken, Praefix, Gateway-Zustand, das
 * einzutragende Abo und die Themenliste - und dass das Einstellungsformular
 * die MQTT-Werte nicht mehr anfasst.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
// Meldungen ins Protokoll, nicht in den Browser: bis 3.0.7 standen Pfade
// und Dateinamen auf der Seite. Damit ein fataler Fehler trotzdem keine
// leere Seite ergibt - die Hausregel verlangt beides -, faengt ein
// Abschlussbehandler ihn ab und schreibt einen lesbaren Satz.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR,
                                         E_COMPILE_ERROR), true)) {
        echo '<div style="padding:12px;border:1px solid #ef9a9a;background:#ffebee;'
           . 'border-radius:8px;font-family:sans-serif">'
           . '<b>Die Seite konnte nicht vollstaendig aufgebaut werden.</b><br>'
           . 'Der Grund steht im Protokoll des Webservers. Reiter Logdateien '
           . 'und das Systemprotokoll des LoxBerry nennen ihn im Klartext.'
           . '</div>';
    }
});

require_once __DIR__ . '/wi_lib.php';
require_once __DIR__ . '/wi_test.php';
// SG-Ready und Paragraph 14a (V24, neu in 3.1.0). Eigene Datei, weil das
// Modul fuer sich stehen soll: wer es nicht braucht, laesst sg_ein auf 0,
// und dann laeuft von hier nichts ausser dem Lesen der Vorgabewerte.
require_once __DIR__ . '/wi_sg.php';

$wi_p = wi_paths();
if ($wi_p['home']) {
    $wi_sdk = $wi_p['home'] . '/libs/phplib/loxberry_system.php';
    if (file_exists($wi_sdk)) {
        require_once $wi_sdk;
        require_once $wi_p['home'] . '/libs/phplib/loxberry_web.php';
        $wi_p = wi_paths();   // nach dem Einbinden neu holen
    }
}

/* ============ Laeuft gerade eine Aktualisierung? ============
 *
 * Zwischen preupgrade.sh und postupgrade.sh ist config/plugins/<ordner>/
 * geloescht (purge_installation). Was diese Seite in dieser Zeit anzeigen
 * wuerde, sind die VORGABEWERTE - anderer ISM8-Port, anderes Themenpraefix,
 * Haken aus.
 *
 * Gesperrt wird, weil es hier einen gemessenen Schaden gibt, nicht aus
 * Vorsicht. In WSL gemessen am 18.09.2026, Pruefstand
 * Pruefung-WOLF-ISM-NG-3.1.3:
 *   Fall B4  die Seite bot ism8i_port 12004 an - eingestellt war 12777;
 *   Fall B5  ein Speichern in dieser Zeit meldete Erfolg und war hinterher
 *            fort: postupgrade.sh legt die gesicherte Datei darueber;
 *   Fall B3  der Knopf "Dienst neu starten" im Reiter Test startete den
 *            Dienst mitten in der Aktualisierung (1 Prozess statt 0).
 * Intercom 2.2.11 sperrt aus demselben Grund; Sprachsteuerung 0.11.7 sperrt
 * nicht, weil dort in der Luecke nichts verlorenging (Regeln/06).
 *
 * Die Pruefung steht VOR dem Wachposten: der ruft wi_formkey(), und das
 * SCHREIBT eine Datei, wenn noch kein Merkmal da ist. In der Luecke ist
 * genau das der Fall.
 */
if (wi_upgrade_laeuft()) {
    $wi_frame = class_exists('LBWeb', false);
    if ($wi_frame) {
        LBWeb::lbheader('Wolf ISM8 Server', 'https://wiki.loxberry.de/', 'help.html');
    }
    echo '<div style="max-width:980px;margin:0 auto;'
       . 'font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#333">' . "\n"
       . '<h2 style="color:#6dac20">WOLF ISM NG</h2>' . "\n"
       . '<div style="border-radius:8px;padding:10px 14px;margin:12px 0;'
       . 'background:#fdf3e3;border:1px solid #e0620d"><b>'
       . wi_e(wi_t('UPGRADE.TITEL')) . '</b> ' . wi_e(wi_t('UPGRADE.TEXT'));
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        echo '<br>' . wi_e(wi_t('UPGRADE.NICHT_GESPEICHERT'));
    }
    echo '</div>' . "\n" . '</div>' . "\n";
    if ($wi_frame) {
        LBWeb::lbfooter();
    }
    exit;
}

$wi_saved = false;
$wi_error = '';
$wi_hinweis = '';
$wi_beanstandungen = array();
/* Durchgang 02.10.2026: die dritte Art (Regeln/04) - eine Lage, die der
 * Bediener lesen soll, die aber weder Erfolg noch Beanstandung ist (gelb). */
$wi_hinweise = array();
$wi_test_titel = '';
$wi_test_text = '';
$wi_test_tab = '';
$wi_sg_probe = null;
/* X-2 (Regeln/04, Bauliste O3): nach einer Beanstandung reisen die Eingaben
 * des EINEN Formulars mit der Einmalmeldung zurueck.
 * array('formular' => Name, 'werte' => Feld => Wert, 'falsch' => Felder) */
$wi_eingaben = array();
$wi_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

/* ============ Einmalmeldung (PRG, Bauliste O1) ============
 *
 * Bis 3.1.5 antwortete jeder POST mit 200 und der fertigen Seite (Bericht
 * oberflaeche, O1: "grep -c Location index.php" ergab 0). F5 schickte den
 * letzten POST noch einmal - auch "an die Heizung schreiben", "Server neu
 * starten", "Retained-Themen wirklich loeschen" und "Sicherung
 * zurueckspielen". Jetzt endet jeder POST mit 303 auf index.php?tab=<reiter>;
 * das Ergebnis reist in data/plugins/<ordner>/einmalmeldung.json (0600,
 * hoechstens 120 s alt, NUR beim GET gelesen und dabei geloescht). Die
 * Downloads (Vorlage, Sicherung) liefern weiter unmittelbar ihre Datei. */
$wi_flash_datei = wi_einmal_datei();
$wi_flash_tab = '';
if (!$wi_post && is_file($wi_flash_datei)) {
    $wi_flash = json_decode((string) @file_get_contents($wi_flash_datei), true);
    @unlink($wi_flash_datei);
    $wi_flash_alter = (is_array($wi_flash) && isset($wi_flash['ts'])) ? time() - (int) $wi_flash['ts'] : 9999;
    if (is_array($wi_flash) && $wi_flash_alter >= -5 && $wi_flash_alter <= 120) {
        $wi_saved = !empty($wi_flash['saved']);
        foreach (array('error', 'hinweis', 'test_titel', 'test_text', 'test_tab') as $wi_fk) {
            if (isset($wi_flash[$wi_fk]) && is_string($wi_flash[$wi_fk])) {
                ${'wi_' . $wi_fk} = $wi_flash[$wi_fk];
            }
        }
        foreach (array('beanstandungen', 'hinweise') as $wi_fk) {
            if (isset($wi_flash[$wi_fk]) && is_array($wi_flash[$wi_fk])) {
                ${'wi_' . $wi_fk} = array_values(array_filter($wi_flash[$wi_fk], 'is_string'));
            }
        }
        if (isset($wi_flash['sg_probe']) && is_array($wi_flash['sg_probe'])) {
            $wi_sg_probe = $wi_flash['sg_probe'];
        }
        if (isset($wi_flash['eingaben']) && is_array($wi_flash['eingaben'])) {
            $wi_eingaben = $wi_flash['eingaben'];
        }
        if (isset($wi_flash['tab']) && is_string($wi_flash['tab'])) {
            $wi_flash_tab = $wi_flash['tab'];
        }
    }
}

/* ---- X-2: Werte und Markierung nach einer Beanstandung (Regeln/04) ----
 * Bauform tb_fa/tb_fw/tb_fh/tb_fm (Spotpreis Tibber, Durchgang 01.10.2026). */
/** Ist dieses Formular das beanstandete? */
function wi_fa($formular)
{
    global $wi_eingaben;
    return is_array($wi_eingaben) && isset($wi_eingaben['formular'])
        && $wi_eingaben['formular'] === $formular;
}
/** Wert eines Feldes: nach einer Beanstandung die Eingabe, sonst der gespeicherte. */
function wi_fw($formular, $feld, $gespeichert)
{
    global $wi_eingaben;
    if (wi_fa($formular) && isset($wi_eingaben['werte'][$feld])
        && is_string($wi_eingaben['werte'][$feld])) {
        return $wi_eingaben['werte'][$feld];
    }
    return is_scalar($gespeichert) ? (string) $gespeichert : '';
}
/** Haken: nach einer Beanstandung so, wie er abgeschickt wurde. */
function wi_fh($formular, $feld, $gespeichert)
{
    global $wi_eingaben;
    if (!wi_fa($formular)) {
        return (string) $gespeichert === '1';
    }
    return isset($wi_eingaben['werte'][$feld]) && $wi_eingaben['werte'][$feld] === '1';
}
/** Markierung eines beanstandeten Feldes (Attribute, schon maskiert). */
function wi_fm($feld)
{
    global $wi_eingaben;
    return (is_array($wi_eingaben) && isset($wi_eingaben['falsch']) && is_array($wi_eingaben['falsch'])
            && in_array($feld, $wi_eingaben['falsch'], true))
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
/** Die abgeschickten Felder eines Formulars als Zeichenketten (fuer X-2). */
function wi_eingaben_von($felder, $haken)
{
    $w = array();
    foreach ($felder as $f) {
        $w[$f] = (isset($_POST[$f]) && is_string($_POST[$f])) ? $_POST[$f] : '';
    }
    foreach ($haken as $f) {
        $w[$f] = isset($_POST[$f]) ? '1' : '0';
    }
    return $w;
}

/* ============ EIN Wachposten vor allen Handlern ============
 *
 * Nicht acht Abfragen in acht Zweigen: ein Formular ohne gueltiges
 * Merkmal wird hier entwertet, danach kann kein Handler mehr greifen.
 * Verglichen wird mit hash_equals - ein einfaches == liesse sich ueber
 * die Antwortzeit Zeichen fuer Zeichen erraten.
 */
if ($wi_post) {
    $wi_fmt_ein = isset($_POST['fmt']) && is_string($_POST['fmt']) ? $_POST['fmt'] : '';
    if (!hash_equals(wi_formkey(), $wi_fmt_ein)) {
        $wi_error = wi_t('MELDUNG.FREMDES_FORMULAR');
        // Den aktiven Reiter behalten - der Anwender soll die Meldung dort
        // sehen, wo er war. Der Regelfall ist keine fremde Seite, sondern
        // eine lange offen gelegene eigene.
        $wi_behalten = isset($_POST['activetab']) && is_string($_POST['activetab'])
            ? $_POST['activetab'] : null;
        $_POST = array();
        $_FILES = array();
        if ($wi_behalten !== null) {
            $_POST['activetab'] = $wi_behalten;
        }
    }
}

// Der Reiter kommt aus einem abgesendeten Formular (activetab), aus der
// Einmalmeldung oder aus der Adresse (?tab=...).
$wi_wunsch = isset($_POST['activetab']) && is_string($_POST['activetab']) ? (string) $_POST['activetab']
    : ($wi_flash_tab !== '' ? $wi_flash_tab
    : (isset($_GET['tab']) && is_string($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : ''));
$wi_tab = preg_match('/^tab-(settings|mqtt|sg|loxone|test|log)$/', $wi_wunsch)
    ? $wi_wunsch : 'tab-settings';

/* ============ Loxone-Vorlage herunterladen ============ */
if ($wi_post && isset($_POST['download'])) {
    $art = is_string($_POST['download']) ? (string) $_POST['download'] : '';
    $geraete = isset($_POST['geraete']) && is_array($_POST['geraete'])
        ? array_values(array_filter($_POST['geraete'], 'is_string')) : array();
    $nurgesehen = isset($_POST['nurgesehen']) ? wi_gesehen() : null;
    $wi_tab = 'tab-loxone';
    if (!$geraete) {
        $wi_error = wi_t('MELDUNG.KEIN_GERAET');
    } else {
        list($name, $inhalt, $anzahl) = wi_vorlage($art, wi_config_read(), $geraete, $nurgesehen);
        if ($name === '') {
            $wi_error = wi_t('MELDUNG.UNBEKANNTE_ART');
        } elseif ($art === 'mqtt_out' && !wi_mqtt_udpinport()) {
            // Ohne UDP-Eingangsport des Gateways entstuende die Adresse
            // /dev/udp/<ip>/0. Ein virtueller Ausgang auf Port 0 sendet
            // nichts, und Loxone meldet dazu nichts.
            $wi_error = wi_t('MELDUNG.KEIN_UDPIN_VORLAGE');
        } elseif ($anzahl < 1) {
            $wi_error = wi_t('MELDUNG.LEERE_VORLAGE');
        } else {
            header('Content-Type: application/x-download');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($inhalt));
            echo $inhalt;
            exit;
        }
    }
}

/* ============ Einstellungen sichern (V17, X-3) ============
 *
 * X-3 (Durchgang 02.10.2026, Bauliste O6): die Sicherung geht immer
 * vollstaendig hinaus. Wuerde das Zurueckspielen sie abweisen, steht in der
 * Datei eine Kommentarzeile "# _warnung: ..." mit den SCHLUESSELNAMEN (nie
 * den Werten), und am Knopf steht eine gelbe Warnung (Bauform EVCC Nr. 13).
 * Bis 3.1.5 merkte man erst auf dem neuen LoxBerry, dass sie wertlos war. */
if ($wi_post && isset($_POST['sichern'])) {
    $wi_scfg = wi_config_read();
    $txt = wi_konfig_text($wi_scfg);
    $wi_swarn = wi_sicherung_maengel($wi_scfg);
    if ($wi_swarn) {
        $txt = '# _warnung: Diese Einstellungen wuerden beim Zurueckspielen abgewiesen: '
             . implode(', ', $wi_swarn) . "\n" . $txt;
    }
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="wolf_ism8i_einstellungen.txt"');
    header('Content-Length: ' . strlen($txt));
    echo $txt;
    exit;
}

/* ============ Einstellungen zurueckspielen (V17) ============ */
if ($wi_post && isset($_POST['laden'])) {
    $wi_tab = 'tab-settings';
    /* O8 (Durchgang 02.10.2026): tmp_name muss eine Zeichenkette sein. Ein
     * Feld "sicherung[]" ergab bis 3.1.5 unter PHP 8 einen TypeError in
     * is_uploaded_file() und HTTP 500 (Bericht oberflaeche, O8). */
    $wi_up = isset($_FILES['sicherung']) && is_array($_FILES['sicherung']) ? $_FILES['sicherung'] : null;
    if ($wi_up === null || !isset($wi_up['tmp_name']) || !is_string($wi_up['tmp_name'])
        || is_array($wi_up['tmp_name']) || !isset($wi_up['size']) || !is_scalar($wi_up['size'])
        || !@is_uploaded_file($wi_up['tmp_name'])) {
        $wi_error = wi_t('MELDUNG.SICH_KEINE_DATEI');
    } elseif ((int) $wi_up['size'] > 65536) {
        $wi_error = wi_t('MELDUNG.SICH_ZU_GROSS');
    } else {
        $roh = (string) @file_get_contents($wi_up['tmp_name']);
        list($neu, $mangel, $wi_ans_s) = wi_konfig_einlesen($roh);
        if ($neu === null) {
            // Eine halb gueltige Datei ueberschreibt NICHTS.
            $wi_beanstandungen = $mangel;
            $wi_error = wi_t('MELDUNG.SICH_ABGELEHNT');
        } elseif (wi_config_write($neu)) {
            $wi_saved = true;
            $wi_hinweis = wi_t('MELDUNG.SICH_UEBERNOMMEN') . ' ' . wi_dienst_uebernehmen($neu, null);
            /* Nr. 36 b (seit 3.1.8): die Sprachausgabe aus der Sicherung. Fehlt sie (Sicherung
             * vor 3.1.8), gelten ihre Vorgaben - wie fuer jeden anderen fehlenden Schluessel. Die
             * Sprechtoken traegt keine Sicherung: die geltenden bleiben. */
            $wi_ajetzt = wi_ansage_lesen();
            $wi_aneu = wi_ansage_vorgaben();
            foreach ((array) $wi_ans_s as $wi_ak => $wi_aw) {
                $wi_aneu[$wi_ak] = $wi_aw;
            }
            $wi_aneu['tts'] = ansage_sicherung_tokens_behalten($wi_aneu['tts'], $wi_ajetzt['tts']);
            if (!wi_ansage_schreiben($wi_aneu)) {
                $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e(wi_ansage_datei()));
            }
        } else {
            $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e($wi_p['config']));
        }
    }
}

/* ============ Speichern: SG-Ready - eigener Handler (V24) ============
 *
 * Wie beim MQTT-Reiter ein EIGENES Formular mit eigenem Handler. Jeder Wert
 * laeuft durch wi_wert_taugt() - dieselbe Positivliste, die auch eine
 * zurueckgespielte Sicherung prueft. Beanstandet wird gesammelt.
 *
 * O2/O4 (Durchgang 02.10.2026, Entscheidung 16): bei EINER Beanstandung
 * wird NICHTS gespeichert - auch nicht bei den beiden Querpruefungen. Bis
 * 3.1.5 stand dann "Nicht uebernommen", und gespeichert war alles (Bericht
 * oberflaeche, O4).
 */
if ($wi_post && isset($_POST['save_sg'])) {
    $wi_tab = 'tab-sg';
    $wi_vorher_sg = wi_config_read();
    $neu = $wi_vorher_sg;
    $wi_falsch = array();
    $wi_sg_felder = array('sg_quelle', 'sg_awattar_ordner', 'sg_stunden', 'sg_block',
                          'sg_horizont', 'sg_kreis', 'sg_ww_normal', 'sg_ww_laden',
                          'sg_korrektur', 'sg_laden_max', 'sg_14a_modus', 'sg_14a_alter');

    $neu['sg_ein']    = isset($_POST['sg_ein']) ? '1' : '0';
    $neu['sg_senden'] = isset($_POST['sg_senden']) ? '1' : '0';
    $neu['sg_14a']    = isset($_POST['sg_14a']) ? '1' : '0';

    foreach ($wi_sg_felder as $wi_k) {
        if (!isset($_POST[$wi_k])) {
            continue;
        }
        $wi_v = trim(is_string($_POST[$wi_k]) ? $_POST[$wi_k] : '');
        $wi_alt = wi_cfg($neu, $wi_k, wi_defaults()[$wi_k]);
        if ($wi_v === '') {
            continue;   // Leeres Feld loescht nichts.
        }
        if (wi_wert_taugt($wi_k, $wi_v)) {
            $neu[$wi_k] = $wi_v;
        } else {
            $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.SG_WERT'),
                wi_e($wi_k), wi_e($wi_v), wi_e((string) $wi_alt));
            $wi_falsch[] = $wi_k;
        }
    }

    /* Ausschalten nimmt das Schreiben mit (sichere Richtung, Bauliste
     * "Festlegungen"): wer nur den ersten Haken herausnimmt, schaltet beides
     * aus, und die Meldung sagt das. Abgewiesen wird nur das EINSCHALTEN des
     * Schreibens ohne das Modul. */
    if ($neu['sg_senden'] === '1' && $neu['sg_ein'] !== '1'
        && wi_cfg($wi_vorher_sg, 'sg_senden', '0') === '1') {
        $neu['sg_senden'] = '0';
        $wi_hinweise[] = wi_t('MELDUNG.SG_SENDEN_MIT_AUS');
    }
    if (!$wi_falsch) {
        foreach (wi_sg_querpruefung($neu) as $wi_q) {
            $wi_beanstandungen[] = $wi_q[1];
            $wi_falsch[] = $wi_q[0];
        }
    }

    if ($wi_beanstandungen) {
        $wi_eingaben = array('formular' => 'sg',
            'werte' => wi_eingaben_von($wi_sg_felder, array('sg_ein', 'sg_senden', 'sg_14a')),
            'falsch' => array_values(array_unique($wi_falsch)));
    } elseif (wi_config_write($neu)) {
        $wi_saved = true;
        /* Der Dienst braucht dafuer KEINEN Neustart: er liest diese
         * Schluessel gar nicht - bin/wolf_sg.php tut es bei jedem Lauf neu. */
        $wi_hinweis = wi_t('MELDUNG.SG_GESPEICHERT');
        /* C2 (Durchgang 02.10.2026): wird das Schreiben ausgeschaltet, nimmt
         * die Oberflaeche einen gesendeten Zwang SOFORT zurueck - nicht erst
         * der naechste Waechterlauf. Gelingt es nicht, versucht es der. */
        $wi_war_scharf = wi_cfg($wi_vorher_sg, 'sg_ein', '0') === '1'
                      && wi_cfg($wi_vorher_sg, 'sg_senden', '0') === '1';
        $wi_ist_scharf = $neu['sg_ein'] === '1' && $neu['sg_senden'] === '1';
        if ($wi_war_scharf && !$wi_ist_scharf) {
            list($wi_zrc, $wi_zm) = wi_sg_zuruecknehmen($neu);
            $wi_zl = end($wi_zm);
            $wi_hinweise[] = vsprintf(wi_t($wi_zl[0]), $wi_zl[1]);
        }
        if (wi_cfg($wi_vorher_sg, 'sg_ein', '0') === '1' && $neu['sg_ein'] !== '1') {
            $wi_mn = wi_sg_mqtt_aus($neu, false);
            if ($wi_mn) {
                $wi_hinweise[] = wi_t('MELDUNG.SG_MQTT_AUS');
            }
        }
    } else {
        $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e($wi_p['config']));
    }
}

/* ============ Trockenlauf des SG-Moduls ============ */
if ($wi_post && isset($_POST['sg_probe'])) {
    $wi_tab = 'tab-sg';
    $wi_sg_probe = wi_sg_stellen(wi_config_read(), false);
}

/* ============ Test-Aktionen ============ */
if ($wi_post && isset($_POST['test']) && is_string($_POST['test'])) {
    $wi_was = (string) $_POST['test'];
    list($wi_test_titel, $wi_test_text) = wi_test_ausfuehren($wi_was);
    if (strpos($wi_was, 'aufraeumen') === 0) {
        $wi_tab = 'tab-mqtt';
    } elseif ($wi_was === 'dplog_leeren') {
        $wi_tab = 'tab-log';
    } else {
        $wi_tab = 'tab-test';
    }
    $wi_test_tab = $wi_tab;
    if ($wi_was === 'schreibprobe' || $wi_was === 'schreibernst') {
        // Die Auswahl bleibt nach der Umleitung stehen.
        $wi_eingaben = array('formular' => 'schreibprobe',
            'werte' => wi_eingaben_von(array('sp_id', 'sp_wert'), array()), 'falsch' => array());
    }
}

/* ============ Speichern: Einstellungen - OHNE MQTT ============
 *
 * O2 (Durchgang 02.10.2026, Entscheidung 16): bei einer Beanstandung wird
 * NICHTS gespeichert, die Eingaben kommen markiert zurueck (X-2). Bis 3.1.5
 * standen "Gespeichert." und "Nicht uebernommen" zugleich da, und die
 * gueltigen Felder waren gespeichert (Bericht oberflaeche, O2/O3). */
if ($wi_post && isset($_POST['save'])) {
    $wi_tab = 'tab-settings';
    $wi_vorher = wi_config_read();
    $neu = $wi_vorher;
    $wi_falsch = array();

    // Fehlt ein Feld, gilt der BESTEHENDE Wert - nicht die Vorgabe.
    // Und ein unzulaessiger Wert wird BEANSTANDET statt zurechtgebogen.
    $port = function ($schluessel, $alt, $feld) use (&$wi_beanstandungen, &$wi_falsch) {
        if (!isset($_POST[$schluessel]) || !is_string($_POST[$schluessel])) {
            return (string) $alt;
        }
        $t = trim((string) $_POST[$schluessel]);
        if ($t === '') {
            return (string) $alt;
        }
        if (!ctype_digit($t) || (int) $t < 1 || (int) $t > 65535) {
            $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.PORT_UNGUELTIG'),
                wi_e($feld), wi_e($t), wi_e((string) $alt));
            $wi_falsch[] = $schluessel;
            return (string) $alt;
        }
        return (string) (int) $t;
    };
    $zahl = function ($schluessel, $alt, $feld, $min, $max) use (&$wi_beanstandungen, &$wi_falsch) {
        if (!isset($_POST[$schluessel]) || !is_string($_POST[$schluessel])) {
            return (string) $alt;
        }
        $t = trim((string) $_POST[$schluessel]);
        if ($t === '') {
            return (string) $alt;
        }
        if (!ctype_digit($t) || (int) $t < $min || (int) $t > $max) {
            $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.ZAHL_UNGUELTIG'),
                wi_e($feld), wi_e($t), (int) $min, (int) $max, wi_e((string) $alt));
            $wi_falsch[] = $schluessel;
            return (string) $alt;
        }
        return (string) (int) $t;
    };

    $neu['enable']      = isset($_POST['enable']) ? '1' : '0';
    $neu['ism8i_port']  = $port('ism8i_port', wi_cfg($neu, 'ism8i_port', '12004'),
                                wi_t('EINST.ISM8_PORT'));
    $neu['input_port']  = $port('input_port', wi_cfg($neu, 'input_port', '12005'),
                                wi_t('EINST.INPUT_PORT'));
    $neu['multicast_port'] = $port('multicast_port',
                                wi_cfg($neu, 'multicast_port', '35353'),
                                wi_t('EINST.UDP_PORT'));
    $neu['dp_log']      = isset($_POST['dp_log']) ? '1' : '0';
    $neu['pull_on_write'] = isset($_POST['pull_on_write']) ? '1' : '0';

    // V18: das Ausgabeformat ist ein Auswahlfeld.
    $wi_alt_out = wi_cfg($neu, 'output', 'none');
    $out = isset($_POST['output']) && is_string($_POST['output']) ? (string) $_POST['output'] : $wi_alt_out;
    if (in_array($out, array('none', 'data', 'csv', 'fhem'), true)) {
        $neu['output'] = $out;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.OUT_UNGUELTIG'), wi_e($out), wi_e($wi_alt_out));
        $wi_falsch[] = 'output';
    }

    $wi_alt_fw = wi_cfg($neu, 'fw_version', '1.8');
    $fw = isset($_POST['fw_version']) && is_string($_POST['fw_version']) ? (string) $_POST['fw_version'] : $wi_alt_fw;
    if (in_array($fw, array('1.4', '1.5', '1.7', '1.8', '1.9'), true)) {
        $neu['fw_version'] = $fw;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.FW_UNGUELTIG'), wi_e($fw), wi_e($wi_alt_fw));
        $wi_falsch[] = 'fw_version';
    }

    $wi_alt_to = wi_cfg($neu, 'online_timeout', '-1');
    $to = trim((string) (isset($_POST['online_timeout']) && is_string($_POST['online_timeout'])
        ? $_POST['online_timeout'] : $wi_alt_to));
    if (preg_match('/^(-1|[0-9]+)$/', $to)) {
        $neu['online_timeout'] = $to;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.TIMEOUT_UNGUELTIG'), wi_e($to), wi_e($wi_alt_to));
        $wi_falsch[] = 'online_timeout';
    }

    $neu['herzschlag'] = $zahl('herzschlag', wi_cfg($neu, 'herzschlag', '60'),
                               wi_t('EINST.HERZSCHLAG'), 0, 86400);
    $neu['abgleich_takt'] = $zahl('abgleich_takt', wi_cfg($neu, 'abgleich_takt', '0'),
                               wi_t('EINST.ABGLEICH'), 0, 86400);

    // V23/3.0.9: welche Stoercodetabelle gilt. Leer ist ein gueltiger Wert.
    $wi_alt_sc = wi_cfg($neu, 'stoercodes', '');
    $sc = isset($_POST['stoercodes']) && is_string($_POST['stoercodes'])
        ? trim((string) $_POST['stoercodes']) : $wi_alt_sc;
    if ($sc === '' || isset(wi_stoercode_tabellen()[$sc])) {
        $neu['stoercodes'] = $sc;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.SC_UNGUELTIG'),
                                       wi_e($sc), wi_e($wi_alt_sc));
        $wi_falsch[] = 'stoercodes';
    }

    /* V19: die Multicast-Gruppe ist waehlbar.
     * O5/O7 (Durchgang 02.10.2026): ein Miniserver mit Rechnernamen wird
     * aufgeloest (und das gesagt) oder beanstandet; einer mit LEERER Adresse
     * wird beanstandet. Bis 3.1.5 ging der Rechnername ungeprueft in die
     * Datei, und der Dienst sendete still an die Multicast-Gruppe (O5); die
     * leere Adresse wurde still verworfen und "Gespeichert." gemeldet (O7).
     * Der Wert laeuft jetzt durch wi_wert_taugt(), wie jeder andere. */
    $ms = isset($_POST['ms']) && is_string($_POST['ms']) ? (string) $_POST['ms'] : '';
    if ($ms === 'gruppe') {
        $neu['multicast_ip'] = '239.7.7.77';
    } elseif ($ms !== '') {
        $mslist = wi_miniservers();
        if (!isset($mslist[$ms])) {
            $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.MS_UNBEKANNT'), wi_e($ms));
            $wi_falsch[] = 'ms';
        } elseif (trim((string) $mslist[$ms]['ip']) === '') {
            $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.MS_OHNE_ADRESSE'), wi_e($mslist[$ms]['name']));
            $wi_falsch[] = 'ms';
        } else {
            $wi_msip = trim((string) $mslist[$ms]['ip']);
            if (wi_wert_taugt('multicast_ip', $wi_msip)) {
                $neu['multicast_ip'] = $wi_msip;
            } else {
                $wi_aufg = wi_ipv4_aufloesen($wi_msip);
                if ($wi_aufg !== '' && wi_wert_taugt('multicast_ip', $wi_aufg)) {
                    $neu['multicast_ip'] = $wi_aufg;
                    $wi_hinweise[] = sprintf(wi_t('MELDUNG.MS_AUFGELOEST'), wi_e($mslist[$ms]['name']),
                                             wi_e($wi_msip), wi_e($wi_aufg));
                } else {
                    $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.MS_NAME'), wi_e($mslist[$ms]['name']),
                                                   wi_e($wi_msip));
                    $wi_falsch[] = 'ms';
                }
            }
        }
    }

    if ($wi_beanstandungen) {
        $wi_eingaben = array('formular' => 'einst',
            'werte' => wi_eingaben_von(array('fw_version', 'stoercodes', 'ism8i_port', 'input_port',
                                             'output', 'ms', 'multicast_port', 'herzschlag',
                                             'online_timeout', 'abgleich_takt'),
                                       array('enable', 'pull_on_write', 'dp_log')),
            'falsch' => array_values(array_unique($wi_falsch)));
    } elseif (wi_config_write($neu)) {
        $wi_saved = true;
        $wi_hinweis = wi_dienst_uebernehmen($neu, $wi_vorher);
    } else {
        $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e($wi_p['config']));
    }
}

/* ============ Speichern: MQTT - eigener Handler (V12) ============
 *
 * Der Einstellungs-Handler fasst die MQTT-Werte nicht an.
 * O2 (Durchgang 02.10.2026): bei einer Beanstandung nichts speichern; bis
 * 3.1.5 war mit "MQTT aus" und einem unzulaessigen Praefix "mqtt 0"
 * gespeichert (Bericht oberflaeche, O2).
 * M3/M4 (Durchgang 02.10.2026): das alte Praefix wird vorgemerkt, damit
 * Aufraeumen und Deinstallation es leeren; MQTT aus leert danach am Broker.
 */
if ($wi_post && isset($_POST['save_mqtt'])) {
    $wi_tab = 'tab-mqtt';
    $wi_vorher = wi_config_read();
    $neu = $wi_vorher;
    $neu['mqtt'] = isset($_POST['mqtt']) ? '1' : '0';

    $alt_pre = wi_cfg($neu, 'praefix', 'wolf_ng');
    // Kleinschreiben und Leerraum am Rand abschneiden bleiben still
    // (Entscheidung 19, benannte Ausnahmen).
    $pre = strtolower(trim((string) (isset($_POST['praefix']) && is_string($_POST['praefix'])
        ? $_POST['praefix'] : $alt_pre)));
    if ($pre === '') {
        $pre = $alt_pre;
    }
    $wechsel = '';
    if (preg_match('/^[a-z0-9_-]{1,32}$/', $pre)) {
        if ($pre !== $alt_pre) {
            $wechsel = sprintf(wi_t('MQTT.PRAEFIX_GEWECHSELT'), $alt_pre, $pre);
        }
        $neu['praefix'] = $pre;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.PRAEFIX_UNGUELTIG'), wi_e($pre), wi_e($alt_pre));
    }

    if ($wi_beanstandungen) {
        $wi_eingaben = array('formular' => 'mqtt',
            'werte' => wi_eingaben_von(array('praefix'), array('mqtt')),
            'falsch' => array('praefix'));
    } elseif (wi_config_write($neu)) {
        $wi_saved = true;
        if ($wechsel !== '') {
            wi_praefix_vormerken($alt_pre);
        }
        $wi_hinweis = trim($wechsel . ' ' . wi_dienst_uebernehmen($neu, $wi_vorher));
        if (wi_cfg($wi_vorher, 'mqtt', '1') === '1' && $neu['mqtt'] !== '1') {
            // Der Dienst trennt nach SIGHUP sauber; erst danach leeren, sonst
            // schriebe er "online" gleich wieder.
            sleep(2);
            list($wi_lrc, $wi_lz) = wi_mqtt_leeren($neu);
            foreach ($wi_lz as $wi_l) {
                $wi_hinweise[] = wi_e(preg_replace('/^<[A-Z]+> /', '', $wi_l));
            }
        }
    } else {
        $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e($wi_p['config']));
    }
}

/* ============ Speichern: Sprachausgabe - eigener Handler (Nr. 36 b, seit 3.1.8) ============
 *
 * Eigenes Formular wie MQTT und SG-Ready. Der Dienst liest diese Einstellungen
 * nicht (sie liegen in wolf_ansage.json, nicht in wolf_ism8i.conf) - es gibt
 * keinen Neustart und kein SIGHUP. Jede Beanstandung verhindert das Speichern
 * (Nr. 16); kein Sprechtoken steht in einer Meldung oder reist zurueck (X-2):
 * ein leeres Tokenfeld heisst "behalten", der Haken loescht, beides zugleich
 * ist ein Widerspruch (ansage_formular_lesen()). */
if ($wi_post && isset($_POST['save_ansage'])) {
    $wi_tab = 'tab-settings';
    $wi_av = wi_ansage_lesen();
    $wi_tmangel = array();
    $wi_tbean = array();
    $wi_an = $wi_av;
    $wi_an['tts'] = ansage_formular_lesen($_POST, $wi_av['tts'], $wi_tmangel, $wi_tbean,
                                          wi_ansage_opt(), wi_ansage_k());
    foreach (wi_ansage_anlaesse() as $wi_n) {
        $wi_an[$wi_n] = isset($_POST['ansage_' . $wi_n]) ? '1' : '0';
    }
    foreach ($wi_tmangel as $wi_tm) {
        $wi_beanstandungen[] = wi_e($wi_tm['text']);
    }
    if ($wi_beanstandungen) {
        $wi_x2w = array();
        $wi_x2h = array('ansage_stoerung', 'ansage_ausfall');
        foreach (ansage_x2_felder(wi_ansage_opt()) as $wi_f) {
            if (substr($wi_f, -9) === '_loeschen') {
                $wi_x2h[] = $wi_f;
            } else {
                $wi_x2w[] = $wi_f;
            }
        }
        $wi_eingaben = array('formular' => 'ansage', 'werte' => wi_eingaben_von($wi_x2w, $wi_x2h),
                             'falsch' => array_values(array_unique($wi_tbean)));
    } elseif (wi_ansage_schreiben($wi_an)) {
        $wi_saved = true;
        $wi_hinweis = wi_t('SPRACHAUSGABE.GESPEICHERT');
    } else {
        $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e(wi_ansage_datei()));
    }
}

/* ============ Umleitung (PRG, Bauliste O1) ============
 *
 * JEDER POST endet hier mit 303 - auch einer, den der Wachposten abgewiesen
 * hat. Laesst sich die Einmalmeldung nicht ablegen, wird die Seite wie bis
 * 3.1.5 unmittelbar gezeigt: lieber ohne Umleitung als ohne Ergebnis. */
if ($wi_post) {
    $wi_flash = array('ts' => time(), 'saved' => $wi_saved ? 1 : 0, 'error' => $wi_error,
                      'hinweis' => (string) $wi_hinweis, 'beanstandungen' => $wi_beanstandungen,
                      'hinweise' => $wi_hinweise, 'test_titel' => $wi_test_titel,
                      'test_text' => $wi_test_text, 'test_tab' => $wi_test_tab,
                      'sg_probe' => $wi_sg_probe, 'eingaben' => $wi_eingaben, 'tab' => $wi_tab);
    if (wi_json_schreiben($wi_flash_datei, $wi_flash, 0600)) {
        header('Location: index.php?tab=' . substr($wi_tab, 4), true, 303);
        exit;
    }
}

/* ============ Daten fuer die Anzeige ============ */
$wi_cfg = wi_config_read();
$wi_fw = wi_cfg($wi_cfg, 'fw_version', '1.8');
$wi_sc_wahl = wi_cfg($wi_cfg, 'stoercodes', '');
$wi_pre = wi_cfg($wi_cfg, 'praefix', 'wolf_ng');
$wi_dps = wi_datenpunkte($wi_fw);
$wi_geraete = wi_geraete($wi_dps);
$wi_ms = wi_miniservers();
$wi_mcip = wi_cfg($wi_cfg, 'multicast_ip', '239.7.7.77');
$wi_ms_aktiv = '';
foreach ($wi_ms as $nr => $m) {
    if ($m['ip'] === $wi_mcip) {
        $wi_ms_aktiv = (string) $nr;
    }
}
if ($wi_ms_aktiv === '' && $wi_mcip === '239.7.7.77') {
    $wi_ms_aktiv = 'gruppe';
}
$wi_pid = wi_server_pid();
$wi_ip = wi_localip();
$wi_udpin = wi_mqtt_udpinport();
$wi_gw = wi_gateway_info();
$wi_log = wi_log_file('server');
$wi_zeilen = wi_log_tail($wi_log);
$wi_zustand = wi_zustand();
$wi_gesehen = wi_gesehen();

$wi_anz_out = 0;
$wi_anz_in = 0;
foreach ($wi_dps as $d) {
    if (strpos($d['io'], 'Out') !== false) { $wi_anz_out++; }
    if (strpos($d['io'], 'In') !== false)  { $wi_anz_in++; }
}

// WICHTIG: LBWeb::lbheader() setzt SDK-Globale - deshalb ueberall wi_-Praefix.
$wi_frame = class_exists('LBWeb', false);
if ($wi_frame) {
    LBWeb::lbheader('Wolf ISM8 Server', 'https://wiki.loxberry.de/', 'help.html');
}
/* O11 (Durchgang 02.10.2026): der eigene Teil der Seite geht durch einen
 * Puffer. Die Pruefzeilen "Tragen alle Formulare das Merkmal?" und "Setzt
 * der Server sm-active?" messen danach das GERENDERTE HTML, nicht den
 * Quelltext (wi_pruef_gerendert() in wi_test.php). */
ob_start();
$wi_sicherung_mangel = wi_sicherung_maengel($wi_cfg);
?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap input[type=file] {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
/* Ein Auswahlfeld bringt seinen Pfeil selbst mit: die Rahmen-CSS des LoxBerry
   nimmt ihn weg, und dann sieht das Feld aus wie ein Textfeld. Diese
   Fehlerklasse steht in den Hausregeln zweimal. */
.sm-wrap select { -webkit-appearance: none; -moz-appearance: none; appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23555'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 8px center; background-size: 18px; padding-right: 30px; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 200px; }
.sm-btn { background: #6dac20 !important; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; vertical-align: top; }
.sm-tbl th { background: #f0f0f0; }
.sm-geraete { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 4px 14px; margin: 8px 0 4px; }
.sm-geraete label { font-weight: 400; font-size: 0.9em; color: #333; margin: 2px 0; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
/* Ohne !important gewinnt jQuery Mobile mit eigenem Hintergrund UND eigenen
   Hover-Regeln; zusammen mit color:#fff !important stuende dann weiss auf
   weiss. Jede Gruppe braucht deshalb eine eigene :hover- und :focus-Farbe. */
.sm-btn.sm-b-lesen,   .sm-btn.sm-b-lesen:focus   { background: #6dac20 !important; color: #fff !important; }
.sm-btn.sm-b-technik, .sm-btn.sm-b-technik:focus { background: #546e7a !important; color: #fff !important; }
.sm-btn.sm-b-aktion,  .sm-btn.sm-b-aktion:focus  { background: #e0620d !important; color: #fff !important; }
.sm-btn.sm-b-lesen:hover   { background: #5c9219 !important; color: #fff !important; }
.sm-btn.sm-b-technik:hover { background: #445a63 !important; color: #fff !important; }
.sm-btn.sm-b-aktion:hover  { background: #c1540b !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }

.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }

/* Aliase auf die Namen der Hausstandard-Vorlage. Dieses Plugin fuehrt ein
   eigenes Klassensystem (sm-pane statt sm-seite, sm-small statt sm-hilfe,
   sm-alert/-ok/-err/-info/-warn statt sm-hinweis/sm-warnung). Wer den
   Vorlagenblock spaeter neu kopiert, bekommt sonst eine Seite ohne
   sichtbare Reiterinhalte - .sm-seite traegt das display:none. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hilfe { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-hinweis { border-radius: 8px; padding: 10px 14px; margin: 12px 0; background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-warnung { border-radius: 8px; padding: 10px 14px; margin: 12px 0; background: #fdf3e3; border: 1px solid #e0620d; }
/* Eine Tabelle, die breiter ist als das Fenster, braucht ihre EIGENE
   Bildlaufleiste - sonst zieht sie die ganze Seite breit. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; max-width: 100%; }
/* Ohne min-width wird die Tabelle im Behaelter GESTAUCHT statt gerollt -
   .sm-tbl steht auf width:100%. Die Bildlaufleiste entstand deshalb nie. */
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }

/* Selbstpruefung (V5) und Kacheln (V1) */
.sm-pruef { list-style: none; padding: 0; margin: 8px 0; }
.sm-pruef li { padding: 5px 0 5px 26px; position: relative; font-size: 0.92em; border-bottom: 1px solid #f0f0f0; }
.sm-pruef li b { display: block; font-weight: 600; }
.sm-pruef .sm-ja::before   { content: "\2713"; color: #6dac20; position: absolute; left: 2px; font-weight: 700; }
.sm-pruef .sm-nein::before { content: "\2717"; color: #c62828; position: absolute; left: 2px; font-weight: 700; }
.sm-pruef .sm-grau::before { content: "\2013"; color: #888;    position: absolute; left: 2px; font-weight: 700; }
.sm-kacheln { display: flex; flex-wrap: wrap; margin: 8px 0; }
.sm-kachel { background: #fafafa; border: 1px solid #e0e0e0; border-radius: 8px; padding: 8px 14px; margin: 4px 6px 4px 0; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.15em; color: #4f7d17; }
/* Nur die Breite. KEIN eigener Pfeil: LoxBerry 4.0.0.15 zeichnet ihn
   selbst, und eine eigene appearance/background-image-Regel loescht den
   vorhandenen, ohne einen zu setzen - so war das Auswahlfeld im
   Dashboard-Designer ganz ohne Pfeil. Dieselbe eine Zeile steht in
   BYD-Autos. Ergaenzt 07.09.2026: die Klasse stand seit der
   Pfeil-Korrektur im HTML, ohne dass es sie noch gab. */
.sm-wrap .sm-auswahl { max-width: 520px; }
/* Ein beanstandetes Feld (X-2, Regeln/04): rot umrandet, die Eingabe steht darin. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-wrap input[type=checkbox].sm-beanstandet { outline: 2px solid #c62828; outline-offset: 2px; }
/* Nr. 36 b (seit 3.1.8): Vorlagenklasse fuer den Formular-Baustein der gemeinsamen Sprachausgabe
   (VORLAGE_hausstandard.css.html); sm-hilfe und sm-hinweis fuehrt diese Linie schon (Aliase oben). */
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
</style>
<div class="sm-wrap">

<?php if ($wi_saved) { ?>
<div class="sm-alert sm-ok"><b><?= wi_t('MELDUNG.GESPEICHERT') ?></b>
<?= $wi_hinweis !== '' ? ' ' . wi_e(trim($wi_hinweis)) : '' ?></div>
<?php } ?>
<?php if ($wi_error !== '') { ?><div class="sm-alert sm-err"><b><?= wi_t('MELDUNG.FEHLER') ?></b> <?= $wi_error ?></div><?php } ?>
<?php if ($wi_beanstandungen) { ?>
<div class="sm-alert sm-warn"><b><?= wi_t('MELDUNG.BEANSTANDET') ?></b>
<ul style="margin:6px 0 0 18px;">
<?php foreach ($wi_beanstandungen as $wi_b) { ?><li><?= $wi_b ?></li><?php } ?>
</ul></div>
<?php } ?>
<?php foreach ($wi_hinweise as $wi_h) { ?>
<div class="sm-warnung"><?= $wi_h ?></div>
<?php } ?>

<?php /* Kopf (Entscheidung Nr. 43, seit 3.1.9): Statusuebersicht ueber den
   Reitern, immer sichtbar. Bis 3.1.8 stand dasselbe als Fliesszeile in einem
   Meldungskasten. Nur Werte, die oben schon gelesen sind. */ ?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?= wi_t('KOPF.EIGENSCHAFT') ?></th><th><?= wi_t('KOPF.WERT') ?></th></tr>
<tr><td><?= wi_t('KOPF.T_SERVER') ?></td>
    <td><b><?= $wi_pid ? wi_t('KOPF.LAEUFT') : wi_t('KOPF.LAEUFT_NICHT') ?></b><?= $wi_pid ? ' (PID ' . (int) $wi_pid . ')' : '' ?></td></tr>
<tr><td><?= wi_t('KOPF.T_ISM8') ?></td>
    <td><?= sprintf(wi_t('KOPF.FIRMWARE'), wi_e($wi_fw), count($wi_dps)) ?></td></tr>
<tr><td><?= wi_t('KOPF.T_WEG') ?></td>
    <td><b><?= wi_cfg($wi_cfg, 'mqtt', '0') === '1' ? 'MQTT' : '&ndash;' ?><?= wi_cfg($wi_cfg, 'output', 'none') !== 'none' ? ' + ' . wi_e(strtoupper(wi_cfg($wi_cfg, 'output', 'none'))) : '' ?></b></td></tr>
<tr><td><?= wi_t('KOPF.T_LOXBERRY') ?></td>
    <td><span class="sm-mono"><?= wi_e($wi_ip) ?></span></td></tr>
<tr><td><?= wi_t('KOPF.T_ABBILD') ?></td>
    <td><?= is_array($wi_zustand) && isset($wi_zustand['werte']) ? sprintf(wi_t('KOPF.WERTE'), count($wi_zustand['werte']), wi_e(wi_alter_text(wi_zustand_alter()))) : wi_e(wi_alter_text(-1)) ?></td></tr>
</table>

<?php
/*
 * Die Reiter sind echte Verweise, keine <div>. Vorher stand hier
 * <div class="sm-tab" data-pane="..."> - und weil alle Flaechen bis zum Lauf
 * des JavaScripts auf display:none stehen, war die Seite ohne JavaScript
 * vollstaendig leer. Jetzt setzt der Server die Klasse sm-active an Reiter
 * UND Flaeche; das JavaScript spart nur noch den Seitenaufbau.
 *
 * Die Leiste ist AUSGESCHRIEBEN, nicht erzeugt - fuenf Zeilen statt einer
 * foreach-Schleife. Bis 3.0.10 stand hier eine Schleife mit
 * data-pane="tab-<?php echo …; ?>", und der Kommentar an dieser Stelle
 * behauptete, das ausgeschriebene tab- loese das Problem. Es loeste es
 * nicht: der Rest des Attributs ist PHP, ein LITERALES data-pane="tab-…"
 * kam in der Datei kein einziges Mal vor. Folgen, beide gemessen am
 * 04.09.2026: hausstandard_pruefen.py verglich nur Positivliste gegen
 * Bereiche und die Leiste gar nicht, und die eigene Pruefzeile im Reiter
 * Test meldete auf JEDER Installation "Leiste 0, Bereiche 5, Liste 5" -
 * ein Fehlalarm bei jedem Lauf ist eine abgeschaltete Pruefung.
 */
?>
<div class="sm-tabs">
    <a class="sm-tab<?php echo $wi_tab === 'tab-settings' ? ' sm-active' : ''; ?>"
       data-pane="tab-settings"
       href="index.php?tab=settings"><?= wi_t('REITER.EINSTELLUNGEN') ?></a>
    <a class="sm-tab<?php echo $wi_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>"
       data-pane="tab-mqtt"
       href="index.php?tab=mqtt"><?= wi_t('REITER.MQTT') ?></a>
    <a class="sm-tab<?php echo $wi_tab === 'tab-sg' ? ' sm-active' : ''; ?>"
       data-pane="tab-sg"
       href="index.php?tab=sg"><?= wi_t('REITER.SG') ?></a>
    <a class="sm-tab<?php echo $wi_tab === 'tab-loxone' ? ' sm-active' : ''; ?>"
       data-pane="tab-loxone"
       href="index.php?tab=loxone"><?= wi_t('REITER.LOXONE') ?></a>
    <a class="sm-tab<?php echo $wi_tab === 'tab-test' ? ' sm-active' : ''; ?>"
       data-pane="tab-test"
       href="index.php?tab=test"><?= wi_t('REITER.TEST') ?></a>
    <a class="sm-tab<?php echo $wi_tab === 'tab-log' ? ' sm-active' : ''; ?>"
       data-pane="tab-log"
       href="index.php?tab=log"><?= wi_t('REITER.LOG') ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<div class="sm-hinweis" style="background:#f2f8ea;border-color:#cfe3b0"><?= wi_t('KOPF.WAS_IST_DAS') ?></div>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings"><?= wi_fmt() ?>

<h2><?= wi_t('EINST.H_SERVER') ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="enable" value="1"<?= wi_fh('einst', 'enable', wi_cfg($wi_cfg, 'enable', '0')) ? ' checked' : '' ?>> <?= wi_t('EINST.EINSCHALTEN') ?></label>
<div class="sm-small"><?= wi_t('EINST.EINSCHALTEN_HINT') ?></div>

<div class="sm-row">
<div>
<label><?= wi_t('EINST.FIRMWARE') ?></label>
<select data-role="none" name="fw_version"<?= wi_fm('fw_version') ?>>
<?php foreach (array('1.4', '1.5', '1.7', '1.8', '1.9') as $v) { ?>
<option value="<?= $v ?>"<?= wi_fw('einst', 'fw_version', $wi_fw) === $v ? ' selected' : '' ?>><?= $v ?></option>
<?php } ?>
</select>
<div class="sm-small"><?= wi_t('EINST.FIRMWARE_HINT') ?></div>
</div>
<div>
<label><?= wi_t('EINST.STOERTAB') ?></label>
<select data-role="none" name="stoercodes"<?= wi_fm('stoercodes') ?>>
<option value=""<?= wi_fw('einst', 'stoercodes', $wi_sc_wahl) === '' ? ' selected' : '' ?>><?= wi_t('EINST.STOERTAB_KEINE') ?></option>
<?php foreach (wi_stoercode_tabellen() as $wi_k => $wi_v) { ?>
<option value="<?= wi_e($wi_k) ?>"<?= wi_fw('einst', 'stoercodes', $wi_sc_wahl) === (string) $wi_k ? ' selected' : '' ?>><?= wi_e($wi_v[0]) ?> (<?= (int) $wi_v[2] ?>)</option>
<?php } ?>
</select>
<div class="sm-small"><?= wi_t('EINST.STOERTAB_HINT') ?></div>
</div>
<div>
<label><?= wi_t('EINST.ISM8_PORT') ?></label>
<input data-role="none" type="number" name="ism8i_port" min="1" max="65535" value="<?= wi_e(wi_fw('einst', 'ism8i_port', wi_cfg($wi_cfg, 'ism8i_port', '12004'))) ?>"<?= wi_fm('ism8i_port') ?>>
<div class="sm-small"><?= sprintf(wi_t('EINST.ISM8_PORT_HINT'), '<span class="sm-mono">' . wi_e($wi_ip) . '</span>') ?></div>
</div>
<div>
<label><?= wi_t('EINST.INPUT_PORT') ?></label>
<input data-role="none" type="number" name="input_port" min="1" max="65535" value="<?= wi_e(wi_fw('einst', 'input_port', wi_cfg($wi_cfg, 'input_port', '12005'))) ?>"<?= wi_fm('input_port') ?>>
<div class="sm-small"><?= wi_t('EINST.INPUT_PORT_HINT') ?></div>
</div>
</div>

<h2><?= wi_t('EINST.H_WEG') ?></h2>
<div class="sm-small"><?= wi_t('EINST.WEG_HINT') ?></div>
<div class="sm-row" style="margin-top:12px;">
<div>
<label><?= wi_t('EINST.OUTPUT') ?></label>
<select data-role="none" name="output"<?= wi_fm('output') ?>>
<?php foreach (array('none', 'data', 'csv', 'fhem') as $v) { ?>
<option value="<?= $v ?>"<?= wi_fw('einst', 'output', wi_cfg($wi_cfg, 'output', 'none')) === $v ? ' selected' : '' ?>><?= wi_t('EINST.OUT_' . strtoupper($v)) ?></option>
<?php } ?>
</select>
<div class="sm-small"><?= wi_t('EINST.OUTPUT_HINT') ?></div>
</div>
<div>
<label><?= wi_t('EINST.MINISERVER') ?></label>
<?php $wi_ms_wahl = wi_fw('einst', 'ms', $wi_ms_aktiv); ?>
<select data-role="none" name="ms"<?= wi_fm('ms') ?>>
<option value=""<?= $wi_ms_wahl === '' ? ' selected' : '' ?>><?= wi_t('EINST.UNVERAENDERT') ?></option>
<option value="gruppe"<?= $wi_ms_wahl === 'gruppe' ? ' selected' : '' ?>><?= wi_t('EINST.GRUPPE') ?></option>
<?php foreach ($wi_ms as $nr => $m) {
    /* O5/O7: was die Auswahl beim Speichern tun wird, steht schon daran. */
    $wi_msi = trim((string) $m['ip']);
    $wi_msz = $wi_msi === '' ? wi_t('EINST.MS_OHNE_ADRESSE')
        : (wi_wert_taugt('multicast_ip', $wi_msi) ? $wi_msi : sprintf(wi_t('EINST.MS_RECHNERNAME'), $wi_msi)); ?>
<option value="<?= wi_e($nr) ?>"<?= (string) $nr === $wi_ms_wahl ? ' selected' : '' ?>><?= wi_e($m['name']) ?> (<?= wi_e($wi_msz) ?>)</option>
<?php } ?>
</select>
<div class="sm-small"><?= sprintf(wi_t('EINST.MINISERVER_HINT'), '<span class="sm-mono">' . wi_e($wi_mcip) . '</span>') ?></div>
</div>
<div>
<label><?= wi_t('EINST.UDP_PORT') ?></label>
<input data-role="none" type="number" name="multicast_port" min="1" max="65535" value="<?= wi_e(wi_fw('einst', 'multicast_port', wi_cfg($wi_cfg, 'multicast_port', '35353'))) ?>"<?= wi_fm('multicast_port') ?>>
</div>
</div>

<h2><?= wi_t('EINST.H_UEBERWACHUNG') ?></h2>
<div class="sm-row">
<div>
<label><?= wi_t('EINST.HERZSCHLAG') ?></label>
<input data-role="none" type="number" name="herzschlag" min="0" max="86400" value="<?= wi_e(wi_fw('einst', 'herzschlag', wi_cfg($wi_cfg, 'herzschlag', '60'))) ?>"<?= wi_fm('herzschlag') ?>>
<div class="sm-small"><?= wi_t('EINST.HERZSCHLAG_HINT') ?></div>
</div>
<div>
<label><?= wi_t('EINST.TIMEOUT') ?></label>
<input data-role="none" type="text" name="online_timeout" value="<?= wi_e(wi_fw('einst', 'online_timeout', wi_cfg($wi_cfg, 'online_timeout', '-1'))) ?>"<?= wi_fm('online_timeout') ?>>
<div class="sm-small"><?= sprintf(wi_t('EINST.TIMEOUT_HINT'), '<span class="sm-mono">-1</span>') ?></div>
</div>
<div>
<label><?= wi_t('EINST.ABGLEICH') ?></label>
<input data-role="none" type="number" name="abgleich_takt" min="0" max="86400" value="<?= wi_e(wi_fw('einst', 'abgleich_takt', wi_cfg($wi_cfg, 'abgleich_takt', '0'))) ?>"<?= wi_fm('abgleich_takt') ?>>
<div class="sm-small"><?= wi_t('EINST.ABGLEICH_HINT') ?></div>
</div>
</div>

<h2><?= wi_t('EINST.H_WEITERE') ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="pull_on_write" value="1"<?= wi_fh('einst', 'pull_on_write', wi_cfg($wi_cfg, 'pull_on_write', '0')) ? ' checked' : '' ?>> <?= wi_t('EINST.PULL') ?></label>
<div class="sm-small"><?= wi_t('EINST.PULL_HINT') ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="dp_log" value="1"<?= wi_fh('einst', 'dp_log', wi_cfg($wi_cfg, 'dp_log', '0')) ? ' checked' : '' ?>> <?= wi_t('EINST.DPLOG') ?></label>
<div class="sm-small"><?= wi_t('EINST.DPLOG_HINT') ?></div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save" value="1"><?= wi_t('EINST.SPEICHERN') ?></button>
</form>

<?php /* Nr. 36 b (Stufe 2, seit 3.1.8): die Sprachausgabe - eigenes Formular mit
       * eigenem Handler (save_ansage), der Formular-Baustein des gemeinsamen Moduls. */
$wi_ans = wi_ansage_lesen(); ?>
<h2><?= wi_e(wi_t('SPRACHAUSGABE.H')) ?></h2>
<div class="sm-small"><?= wi_e(wi_t('SPRACHAUSGABE.EINLEITUNG')) ?></div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings"><?= wi_fmt() ?>
<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="ansage_stoerung" value="1"<?= wi_fh('ansage', 'ansage_stoerung', $wi_ans['stoerung']) ? ' checked' : '' ?>> <?= wi_e(wi_t('SPRACHAUSGABE.L_STOERUNG')) ?></label>
<div class="sm-small"><?= wi_e(wi_t('SPRACHAUSGABE.L_STOERUNG_HINT')) ?></div>
<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="ansage_ausfall" value="1"<?= wi_fh('ansage', 'ansage_ausfall', $wi_ans['ausfall']) ? ' checked' : '' ?>> <?= wi_e(wi_t('SPRACHAUSGABE.L_AUSFALL')) ?></label>
<div class="sm-small"><?= wi_e(sprintf(wi_t('SPRACHAUSGABE.L_AUSFALL_HINT'), (int) (wi_ansage_ausfall_grenze() / 60))) ?></div>
<?= ansage_formular_html($wi_ans['tts'], array(
    'w' => function ($n, $g) { return wi_fw('ansage', $n, $g); },
    'm' => function ($n) { return wi_fm($n); },
    'c' => function ($n, $g) { return wi_fh('ansage', $n, $g); },
    'modi' => wi_ansage_modi()), wi_ansage_k()) ?>
<div class="sm-small"><?= wi_e(wi_t('SPRACHAUSGABE.TEST_HINWEIS')) ?></div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_ansage" value="1"><?= wi_e(wi_t('SPRACHAUSGABE.SPEICHERN')) ?></button>
</form>

<h2><?= wi_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-small"><?= wi_t('EINST.SICHERUNG_HINT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= wi_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= wi_t('LEGENDE.AKTION') ?></span>
</div>
<?php if ($wi_sicherung_mangel) { ?>
<div class="sm-warnung"><?= sprintf(wi_t('EINST.SICHERN_WARNUNG'), wi_e(implode(', ', $wi_sicherung_mangel))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-settings"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="sichern" value="1"><?= wi_t('EINST.SICHERN') ?></button></form>
</div>
<form method="post" action="index.php" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="activetab" value="tab-settings"><?= wi_fmt() ?>
<label><?= wi_t('EINST.LADEN_DATEI') ?></label>
<input data-role="none" type="file" name="sicherung" accept=".txt,.conf,text/plain">
<div class="sm-small"><?= wi_t('EINST.LADEN_HINT') ?></div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="laden" value="1"><?= wi_t('EINST.LADEN') ?></button>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">
<h2><?= wi_t('MQTT.H_EINSTELLUNG') ?></h2>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt"><?= wi_fmt() ?>
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= wi_fh('mqtt', 'mqtt', wi_cfg($wi_cfg, 'mqtt', '1')) ? ' checked' : '' ?>> <?= wi_t('MQTT.EIN') ?></label>
<div class="sm-small"><?= wi_t('MQTT.EIN_HINT') ?></div>
<label style="margin-top:12px;"><?= wi_t('MQTT.PRAEFIX') ?></label>
<input data-role="none" type="text" name="praefix" value="<?= wi_e(wi_fw('mqtt', 'praefix', $wi_pre)) ?>" style="max-width:280px;"<?= wi_fm('praefix') ?>>
<div class="sm-alert sm-warn"><?= wi_t('MQTT.PRAEFIX_HINT') ?></div>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_mqtt" value="1"><?= wi_t('MQTT.SPEICHERN') ?></button>
</form>

<h2><?= wi_t('MQTT.H_GATEWAY') ?></h2>
<?php if ($wi_gw === null) { ?>
<div class="sm-alert sm-warn"><?= wi_t('MQTT.GW_UNBEKANNT') ?></div>
<?php } else { ?>
<div class="sm-small">
<?= sprintf(wi_t('MQTT.GW_AUTOSTART'), $wi_gw['autostart'] ? wi_t('MQTT.JA') : wi_t('MQTT.NEIN')) ?><br>
<?= sprintf(wi_t('MQTT.GW_FASSUNG'), $wi_gw['fassung'] > 0 ? (int) $wi_gw['fassung'] : wi_t('MQTT.UNBEKANNT')) ?><br>
<?php $wi_broker = wi_mqtt_broker(); ?>
<?= sprintf(wi_t('LOXONE.BROKER'), '<span class="sm-mono">' . ($wi_broker !== '' ? wi_e($wi_broker) : wi_t('LOXONE.KEIN_BROKER')) . '</span>') ?>
<?php if ($wi_udpin) { ?> &middot; <?= sprintf(wi_t('LOXONE.UDPRELAY'), '<span class="sm-mono">' . (int) $wi_udpin . '</span>') ?><?php } ?>
</div>
<?php if (!$wi_gw['autostart']) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?= wi_t('LOXONE.W_AUTOSTART') ?></div><?php } ?>
<?php } ?>

<h2><?= wi_t('MQTT.H_ABO') ?></h2>
<?php
/* Der Pflichtsatz haengt an der Gateway-Fassung. Unter V2 ist NICHTS
 * einzutragen; der Kern schaltet dort die Knoepfe der Abonnement-Seite ab.
 * Ist die Fassung nicht lesbar, stehen BEIDE Saetze da - einen von beiden zu
 * behaupten waere fuer die Haelfte der Anlagen falsch. */
$wi_gwf = ($wi_gw === null) ? 0 : (int) $wi_gw['fassung'];
?>
<div class="sm-step"><?= sprintf(wi_t('MQTT.ABO_EINLEITUNG'),
    '<span class="sm-mono">' . wi_e($wi_pre) . '/#</span>') ?></div>
<?php if ($wi_gwf >= 2) { ?>
<div class="sm-hinweis">
<b><?= wi_t('MQTT.ABO_V2_H') ?></b><br>
<?= wi_t('MQTT.ABO_V2') ?>
</div>
<?php } elseif ($wi_gwf === 1) { ?>
<div class="sm-warnung">
<b><?= wi_t('MQTT.ABO_V1_H') ?></b><br>
<?= wi_t('MQTT.ABO_V1') ?>
</div>
<?php } else { ?>
<div class="sm-warnung">
<b><?= wi_t('MQTT.ABO_V1_H') ?></b><br>
<?= wi_t('MQTT.ABO_V1') ?>
</div>
<div class="sm-hinweis">
<b><?= wi_t('MQTT.ABO_V2_H') ?></b><br>
<?= wi_t('MQTT.ABO_V2') ?>
</div>
<div class="sm-small"><?= wi_t('MQTT.ABO_UNKLAR') ?></div>
<?php } ?>

<h2><?= wi_t('MQTT.H_THEMEN') ?></h2>
<?php if (wi_cfg($wi_cfg, 'mqtt', '0') !== '1') { ?>
<div class="sm-alert sm-err"><?= wi_t('LOXONE.MQTT_AUS') ?></div>
<?php } ?>
<div class="sm-small"><?= sprintf(wi_t('MQTT.THEMEN_HINT'),
    '<span class="sm-mono">' . wi_e($wi_pre) . '/&lt;' . wi_t('LOXONE.TH_GERAET') . '&gt;/&lt;' . wi_t('LOXONE.TH_DP') . '&gt;</span>') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= wi_t('LOXONE.TH_THEMA') ?></th><th><?= wi_t('MQTT.TH_BEDEUTUNG') ?></th><th style="width:90px;"><?= wi_t('MQTT.TH_RETAIN') ?></th></tr>
<?php
/* O11 (Durchgang 02.10.2026): die festen Themen aus EINER Liste
 * (wi_mqtt_feste_themen()); der Reiter Test zaehlt sie gegen den Sendecode. */
foreach (wi_mqtt_feste_themen() as $wi_ft => $wi_fa) { ?>
<tr><td><span class="sm-mono"><?= wi_e($wi_pre) ?>/<?= wi_e($wi_ft) ?></span></td><td><?= wi_t($wi_fa[1]) ?></td><td><?= $wi_fa[0] ? wi_t('MQTT.RET_JA') : wi_t('MQTT.RET_NEIN') ?></td></tr>
<?php } ?>
<?php
$wi_zeit_typen = array('DPT_TimeOfDay', 'DPT_Date');
$wi_gezeigt = 0;
foreach ($wi_dps as $d) {
    if (strpos($d['io'], 'Out') === false || in_array($d['dpt'], $wi_zeit_typen, true)) { continue; }
    $wi_gezeigt++;
?>
<tr><td><span class="sm-mono" style="font-size:0.85em;"><?= wi_e(wi_topic($d)) ?></span></td><td><?= wi_e($d['geraet']) ?> &mdash; <?= wi_e($d['name']) ?><?= $d['einheit'] !== '-' ? ' (' . wi_e($d['einheit']) . ')' : '' ?></td><td><?= wi_ist_zustand($d['dpt'], $d['io']) ? wi_t('MQTT.RET_JA') : wi_t('MQTT.RET_NEIN') ?></td></tr>
<?php } ?>
<?php
/* M9 (Durchgang 02.10.2026): die Themen des SG-Moduls stehen mit in der
 * Liste. Bis 3.1.5 fehlten sie hier, obwohl wi_sg_mqtt_themen() sie fuehrt
 * (Bericht mqtt, Befund 9). Sie gehen nur mit eingeschaltetem SG-Modul
 * hinaus, alle fluechtig. */
foreach (wi_sg_mqtt_themen() as $wi_st) { ?>
<tr><td><span class="sm-mono" style="font-size:0.85em;"><?= wi_e($wi_pre) ?>/<?= wi_e($wi_st) ?></span></td><td><?= wi_t('MQTT.B_SG_' . strtoupper(substr($wi_st, 3))) ?></td><td><?= wi_t('MQTT.RET_NEIN') ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= sprintf(wi_t('MQTT.THEMEN_ZAHL'), $wi_gezeigt + count(wi_mqtt_feste_themen()), count($wi_dps) - $wi_gezeigt) ?>
<?= sprintf(wi_t('MQTT.THEMEN_SG'), count(wi_sg_mqtt_themen())) ?></div>

<h2><?= wi_t('MQTT.H_AUFRAEUMEN') ?></h2>
<div class="sm-small"><?= wi_t('MQTT.AUFRAEUMEN_HINT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= wi_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= wi_t('LEGENDE.AKTION') ?></span>
</div>
<?php /* O13 (Durchgang 02.10.2026, Regeln/04): der Trockenlauf ist grau, der
       * scharfe Knopf steht unter "Schalten" in einer eigenen Reihe. */ ?>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-mqtt"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="aufraeumen_probe"><?= wi_t('MQTT.AUFRAEUMEN_PROBE') ?></button></form>
</div>
<h3 class="sm-h3"><?= wi_t('TEST.H_SCHALTEN') ?></h3>
<div class="sm-small"><?= wi_t('MQTT.AUFRAEUMEN_SCHALTEN') ?></div>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-mqtt"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="aufraeumen"><?= wi_t('MQTT.AUFRAEUMEN') ?></button></form>
</div>
<?php if ($wi_test_titel !== '' && $wi_tab === 'tab-mqtt') { ?>
<h3 class="sm-h3"><?= wi_e($wi_test_titel) ?></h3>
<div class="sm-log"><?= wi_e($wi_test_text) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: SG-Ready ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-sg' ? ' sm-active' : ''; ?>" id="tab-sg">

<h2><?= wi_t('SG.H') ?></h2>
<div class="sm-warnung"><b><?= wi_t('SG.WARN_H') ?></b><br><?= wi_t('SG.WARN') ?></div>
<div class="sm-hinweis"><?= wi_t('SG.EINLEITUNG') ?></div>

<?php
$wi_sgl = wi_sg_lage($wi_cfg);
$wi_sgm = wi_sg_merker();
?>

<h3 class="sm-h3"><?= wi_t('SG.H_LAGE') ?></h3>
<div class="sm-kacheln">
<div class="sm-kachel"><b><?= wi_e(wi_t('SG.LAGE_' . strtoupper($wi_sgl['lage']))) ?></b><?= wi_t('SG.K_LAGE') ?></div>
<div class="sm-kachel"><b><?= (int) count($wi_sgl['fenster']) ?></b><?= wi_t('SG.K_FENSTER') ?></div>
<div class="sm-kachel"><b><?= (int) count($wi_sgl['preise']) ?></b><?= wi_t('SG.K_PREISE') ?></div>
<div class="sm-kachel"><b><?= $wi_sgl['ein'] ? ($wi_sgl['senden'] ? wi_t('SG.K_SCHARF') : wi_t('SG.K_TROCKEN')) : wi_t('SG.K_AUS') ?></b><?= wi_t('SG.K_BETRIEB') ?></div>
</div>
<div class="sm-small"><?= wi_e($wi_sgl['qhinweis']) ?></div>
<?php if ($wi_sgm !== null) { ?>
<div class="sm-small"><?= sprintf(wi_t('SG.ZULETZT'),
    wi_e(wi_t('SG.LAGE_' . strtoupper((string) $wi_sgm['lage']))),
    wi_e(wi_alter_text(max(0, time() - (int) $wi_sgm['ts'])))) ?></div>
<?php } ?>
<?php if ($wi_sgl['gekappt']) { ?>
<div class="sm-warnung"><?= sprintf(wi_t('SG.GEKAPPT'), (int) $wi_sgl['laden_max'],
    wi_e(date('d.m. H:i', (int) $wi_sgl['laden_seit']))) ?></div>
<?php } elseif ($wi_sgl['lage'] === 'laden' && (int) $wi_sgl['laden_seit'] > 0) { ?>
<div class="sm-small"><?= sprintf(wi_t('SG.LADEN_BIS'),
    wi_e(date('d.m. H:i', (int) $wi_sgl['laden_seit'] + 3600 * (int) $wi_sgl['laden_max'])),
    (int) $wi_sgl['laden_max']) ?></div>
<?php } ?>

<?php if ($wi_sgl['fehlt']) { ?>
<div class="sm-alert sm-err"><?= sprintf(wi_t('SG.FEHLT'), wi_e(implode(', ', $wi_sgl['fehlt']))) ?></div>
<?php } ?>
<?php if ($wi_sgl['planhinweis'] !== '') { ?>
<div class="sm-alert sm-warn"><?= wi_e(wi_t($wi_sgl['planhinweis'])) ?></div>
<?php } ?>

<?php if ($wi_sgl['fenster']) { ?>
<h3 class="sm-h3"><?= wi_t('SG.H_PLAN') ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= wi_t('SG.TH_VON') ?></th><th><?= wi_t('SG.TH_BIS') ?></th><th><?= wi_t('SG.TH_PREIS') ?></th><th><?= wi_t('SG.TH_JETZT') ?></th></tr>
<?php foreach ($wi_sgl['fenster'] as $wi_f) { ?>
<tr><td><span class="sm-mono"><?= wi_e(date('d.m. H:i', $wi_f['von'])) ?></span></td>
<td><span class="sm-mono"><?= wi_e(date('d.m. H:i', $wi_f['bis'])) ?></span></td>
<td><?= wi_e(number_format($wi_f['schnitt'], 2, ',', '.')) ?> ct</td>
<td><?= (time() >= $wi_f['von'] && time() < $wi_f['bis']) ? wi_t('SG.JETZT_JA') : '&ndash;' ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= wi_t('SG.PLAN_HINT') ?></div>
<?php } ?>

<h3 class="sm-h3"><?= wi_t('SG.H_BEFEHLE') ?></h3>
<?php if (!$wi_sgl['befehle']) { ?>
<div class="sm-small"><?= wi_t('SG.KEINE_BEFEHLE') ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:60px;"><?= wi_t('LOXONE.TH_ID') ?></th><th><?= wi_t('SG.TH_WERT') ?></th><th><?= wi_t('SG.TH_WARUM') ?></th></tr>
<?php foreach ($wi_sgl['befehle'] as $wi_b) { ?>
<tr><td><span class="sm-mono"><?= sprintf('%03d', (int) $wi_b['id']) ?></span></td>
<td><span class="sm-mono"><?= wi_e($wi_b['wert']) ?></span></td>
<td><?= wi_e(wi_t($wi_b['warum'])) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= wi_t('SG.BEFEHLE_HINT') ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= wi_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= wi_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-sg"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="sg_probe" value="1"><?= wi_t('SG.PROBE') ?></button></form>
</div>
<?php if (is_array($wi_sg_probe) && isset($wi_sg_probe[2]) && is_array($wi_sg_probe[2])) { ?>
<div class="sm-alert sm-info"><b><?= wi_t('SG.PROBE_H') ?></b><br>
<?php foreach ($wi_sg_probe[2] as $wi_m) { ?>
<span class="sm-mono"><?= wi_e(vsprintf(wi_t((string) $wi_m[0]), (array) $wi_m[1])) ?></span><br>
<?php } ?>
</div>
<?php } ?>

<h3 class="sm-h3"><?= wi_t('SG.H_EINST') ?></h3>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-sg"><?= wi_fmt() ?>
<input data-role="none" type="hidden" name="formular" value="sg">

<label class="sm-check"><input data-role="none" type="checkbox" name="sg_ein" value="1"<?= wi_fh('sg', 'sg_ein', wi_cfg($wi_cfg, 'sg_ein', '0')) ? ' checked' : '' ?><?= wi_fm('sg_ein') ?>> <?= wi_t('SG.F_EIN') ?></label>
<div class="sm-hilfe"><?= wi_t('SG.F_EIN_HINT') ?></div>

<label class="sm-check"><input data-role="none" type="checkbox" name="sg_senden" value="1"<?= wi_fh('sg', 'sg_senden', wi_cfg($wi_cfg, 'sg_senden', '0')) ? ' checked' : '' ?><?= wi_fm('sg_senden') ?>> <?= wi_t('SG.F_SENDEN') ?></label>
<div class="sm-hilfe"><?= wi_t('SG.F_SENDEN_HINT') ?></div>

<label for="sg_quelle"><?= wi_t('SG.F_QUELLE') ?></label>
<select data-role="none" class="sm-auswahl" id="sg_quelle" name="sg_quelle"<?= wi_fm('sg_quelle') ?>>
<?php foreach (array('aus', 'datei', 'awattar') as $wi_q) { ?>
<option value="<?= $wi_q ?>"<?= wi_fw('sg', 'sg_quelle', wi_cfg($wi_cfg, 'sg_quelle', 'aus')) === $wi_q ? ' selected' : '' ?>><?= wi_t('SG.Q_' . strtoupper($wi_q)) ?></option>
<?php } ?>
</select>
<div class="sm-hilfe"><?= wi_t('SG.F_QUELLE_HINT') ?></div>

<label for="sg_awattar_ordner"><?= wi_t('SG.F_ORDNER') ?></label>
<input data-role="none" type="text" id="sg_awattar_ordner" name="sg_awattar_ordner" value="<?= wi_e(wi_fw('sg', 'sg_awattar_ordner', wi_cfg($wi_cfg, 'sg_awattar_ordner', 'spotpreis'))) ?>"<?= wi_fm('sg_awattar_ordner') ?>>
<div class="sm-hilfe"><?= wi_t('SG.F_ORDNER_HINT') ?></div>

<label for="sg_kreis"><?= wi_t('SG.F_KREIS') ?></label>
<select data-role="none" class="sm-auswahl" id="sg_kreis" name="sg_kreis"<?= wi_fm('sg_kreis') ?>>
<?php foreach (wi_sg_kreise() as $wi_kk => $wi_kn) { ?>
<option value="<?= $wi_kk ?>"<?= wi_fw('sg', 'sg_kreis', wi_cfg($wi_cfg, 'sg_kreis', 'direkt')) === $wi_kk ? ' selected' : '' ?>><?= wi_e($wi_kn) ?></option>
<?php } ?>
</select>
<div class="sm-hilfe"><?= wi_t('SG.F_KREIS_HINT') ?></div>

<label for="sg_stunden"><?= wi_t('SG.F_STUNDEN') ?></label>
<input data-role="none" type="number" min="0" max="24" step="1" id="sg_stunden" name="sg_stunden" value="<?= wi_e(wi_fw('sg', 'sg_stunden', wi_cfg($wi_cfg, 'sg_stunden', '4'))) ?>"<?= wi_fm('sg_stunden') ?>>

<label for="sg_block"><?= wi_t('SG.F_BLOCK') ?></label>
<input data-role="none" type="number" min="1" max="12" step="1" id="sg_block" name="sg_block" value="<?= wi_e(wi_fw('sg', 'sg_block', wi_cfg($wi_cfg, 'sg_block', '2'))) ?>"<?= wi_fm('sg_block') ?>>
<div class="sm-hilfe"><?= wi_t('SG.F_BLOCK_HINT') ?></div>

<label for="sg_horizont"><?= wi_t('SG.F_HORIZONT') ?></label>
<input data-role="none" type="number" min="1" max="48" step="1" id="sg_horizont" name="sg_horizont" value="<?= wi_e(wi_fw('sg', 'sg_horizont', wi_cfg($wi_cfg, 'sg_horizont', '24'))) ?>"<?= wi_fm('sg_horizont') ?>>

<label for="sg_ww_normal"><?= wi_t('SG.F_WW_NORMAL') ?></label>
<input data-role="none" type="text" id="sg_ww_normal" name="sg_ww_normal" value="<?= wi_e(wi_fw('sg', 'sg_ww_normal', wi_cfg($wi_cfg, 'sg_ww_normal', '48'))) ?>"<?= wi_fm('sg_ww_normal') ?>>

<label for="sg_ww_laden"><?= wi_t('SG.F_WW_LADEN') ?></label>
<input data-role="none" type="text" id="sg_ww_laden" name="sg_ww_laden" value="<?= wi_e(wi_fw('sg', 'sg_ww_laden', wi_cfg($wi_cfg, 'sg_ww_laden', '55'))) ?>"<?= wi_fm('sg_ww_laden') ?>>
<div class="sm-hilfe"><?= wi_t('SG.F_WW_HINT') ?></div>

<label for="sg_korrektur"><?= wi_t('SG.F_KORREKTUR') ?></label>
<input data-role="none" type="text" id="sg_korrektur" name="sg_korrektur" value="<?= wi_e(wi_fw('sg', 'sg_korrektur', wi_cfg($wi_cfg, 'sg_korrektur', '2'))) ?>"<?= wi_fm('sg_korrektur') ?>>
<div class="sm-hilfe"><?= wi_t('SG.F_KORREKTUR_HINT') ?></div>

<label for="sg_laden_max"><?= wi_t('SG.F_LADEN_MAX') ?></label>
<input data-role="none" type="number" min="1" max="24" step="1" id="sg_laden_max" name="sg_laden_max" value="<?= wi_e(wi_fw('sg', 'sg_laden_max', wi_cfg($wi_cfg, 'sg_laden_max', '6'))) ?>"<?= wi_fm('sg_laden_max') ?>>
<div class="sm-hilfe"><?= wi_t('SG.F_LADEN_MAX_HINT') ?></div>

<h3 class="sm-h3"><?= wi_t('SG.H_14A') ?></h3>
<div class="sm-hinweis"><?= wi_t('SG.E14_EINLEITUNG') ?></div>

<label class="sm-check"><input data-role="none" type="checkbox" name="sg_14a" value="1"<?= wi_fh('sg', 'sg_14a', wi_cfg($wi_cfg, 'sg_14a', '0')) ? ' checked' : '' ?>> <?= wi_t('SG.F_14A') ?></label>
<div class="sm-hilfe"><?= sprintf(wi_t('SG.F_14A_HINT'),
    '<span class="sm-mono">' . wi_e(wi_paths()['home'] !== '' ? wi_paths()['home'] . '/data/plugins/' . wi_paths()['plugin'] . '/sg_14a.json' : 'data/plugins/&lt;ordner&gt;/sg_14a.json') . '</span>') ?></div>

<label for="sg_14a_modus"><?= wi_t('SG.F_14A_MODUS') ?></label>
<select data-role="none" class="sm-auswahl" id="sg_14a_modus" name="sg_14a_modus"<?= wi_fm('sg_14a_modus') ?>>
<?php foreach (array('spar', 'standby') as $wi_mm) { ?>
<option value="<?= $wi_mm ?>"<?= wi_fw('sg', 'sg_14a_modus', wi_cfg($wi_cfg, 'sg_14a_modus', 'spar')) === $wi_mm ? ' selected' : '' ?>><?= wi_t('SG.M14_' . strtoupper($wi_mm)) ?></option>
<?php } ?>
</select>
<div class="sm-hilfe"><?= wi_t('SG.F_14A_MODUS_HINT') ?></div>

<label for="sg_14a_alter"><?= wi_t('SG.F_14A_ALTER') ?></label>
<input data-role="none" type="number" min="0" max="86400" step="1" id="sg_14a_alter" name="sg_14a_alter" value="<?= wi_e(wi_fw('sg', 'sg_14a_alter', wi_cfg($wi_cfg, 'sg_14a_alter', '900'))) ?>"<?= wi_fm('sg_14a_alter') ?>>
<div class="sm-hilfe"><?= wi_t('SG.F_14A_ALTER_HINT') ?></div>

<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_sg" value="1"><?= wi_t('SG.SPEICHERN') ?></button>
</form>

</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?= wi_t('LOXONE.H_WEG') ?></h2>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S1'),
    '<span class="sm-mono">' . wi_e($wi_ip) . '</span>',
    '<span class="sm-mono">' . wi_e(wi_cfg($wi_cfg, 'ism8i_port', '12004')) . '</span>') ?></div>

<div class="sm-step"><?= wi_t('LOXONE.S2') ?></div>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S3'), (int) count($wi_dps)) ?></div>

<div class="sm-step"><?= wi_t('LOXONE.S4') ?></div>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S5'),
    '<span class="sm-mono">tcp://' . wi_e($wi_ip) . ':' . wi_e(wi_cfg($wi_cfg, 'input_port', '12005')) . '</span>') ?></div>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S6'),
    '<span class="sm-mono">' . wi_e($wi_pre) . '/zaehler</span>',
    '<span class="sm-mono">' . wi_e($wi_pre) . '/online</span>') ?></div>

<div class="sm-step"><?= wi_t('LOXONE.S7') ?></div>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S8'), '<span class="sm-mono">' . wi_e($wi_pre) . '/#</span>') ?></div>

<div class="sm-alert sm-warn"><?= wi_t('LOXONE.DOPPELT') ?></div>

<h2><?= wi_t('LOXONE.H_GERAETE') ?></h2>
<div class="sm-small"><?= sprintf(wi_t('LOXONE.TABELLE'), wi_e($wi_fw), count($wi_dps), $wi_anz_out, $wi_anz_in) ?></div>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone"><?= wi_fmt() ?>
<div class="sm-geraete">
<?php foreach ($wi_geraete as $i => $g) {
    $wi_n = 0;
    foreach ($wi_dps as $d) { if ($d['geraet'] === $g && isset($wi_gesehen[$d['id']])) { $wi_n++; } }
?>
<label><input data-role="none" type="checkbox" name="geraete[]" value="<?= wi_e($g) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= wi_e($g) ?><?= $wi_n ? ' <span class="sm-mono">' . sprintf(wi_t('LOXONE.GESEHEN_N'), $wi_n) . '</span>' : '' ?></label>
<?php } ?>
</div>
<div class="sm-small"><?= wi_t('LOXONE.OHNE_AUSWAHL') ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="nurgesehen" value="1"<?= $wi_gesehen ? '' : ' disabled' ?>> <?= wi_t('LOXONE.NURGESEHEN') ?></label>
<div class="sm-small"><?= $wi_gesehen ? sprintf(wi_t('LOXONE.NURGESEHEN_HINT'), count($wi_gesehen)) : wi_t('LOXONE.NURGESEHEN_LEER') ?></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= wi_t('LEGENDE.TECHNIK') ?></span>
</div>

<h3 class="sm-h3"><?= wi_t('LOXONE.H_MQTT_VORLAGEN') ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="mqtt_in"><?= wi_t('LOXONE.V_MQTT_IN') ?></button>
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="mqtt_out"><?= wi_t('LOXONE.V_MQTT_OUT') ?></button>
</div>
<div class="sm-small"><?= wi_t('LOXONE.V_MQTT_HINT') ?></div>

<h3 class="sm-h3"><?= wi_t('LOXONE.H_UDP_VORLAGEN') ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="udp_in"><?= wi_t('LOXONE.V_UDP_IN') ?></button>
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="tcp_out"><?= wi_t('LOXONE.V_TCP_OUT') ?></button>
</div>
<div class="sm-small"><?= wi_t('LOXONE.V_UDP_HINT') ?></div>
</form>

<?php /* X-8 (G4, 02.10.2026, Entscheidung 36): die Liste nennt die vier Vorlagen und
         deren Eingaenge mit den Titeln, die wi_vorlage() vergibt - aus deren Ausgabe
         gelesen, nicht abgeschrieben -, und Beispielnamen der Datenpunkte aus der
         Datenpunkttabelle der eingestellten Firmware (MQTT-Name wie wi_vorlage:
         wi_topic(), "/" -> "_"; UDP/TCP-Titel "<Geraet> <Name>"). Regel A4: die
         Sammelstoerung ist eine ODER-Kaskade mit je zwei Eingaengen.
         Zeile: array(Kennung, Typ, Name, Parameter, Argumente, Verbindung, Argumente);
         Name als array('t', Schluessel) oder array('w', fertiger Wert). {Bn} -> #n. */
$wi_bv = array();
foreach (array('mqtt_in', 'mqtt_out', 'udp_in', 'tcp_out') as $wi_a) {
    $wi_x = wi_vorlage($wi_a, $wi_cfg, array());
    preg_match_all('/ Title="([^"]*)"/', (string) $wi_x[1], $wi_m);
    $wi_bv[$wi_a] = array();
    foreach ($wi_m[1] as $wi_t1) { $wi_bv[$wi_a][] = html_entity_decode($wi_t1, ENT_QUOTES, 'UTF-8'); }
    $wi_bv[$wi_a] += array('', '', '', '');
}
$wi_bdp = array();
foreach ($wi_dps as $wi_d) { $wi_bdp[(int) $wi_d['id']] = $wi_d; }
$wi_bm = function ($t) { return '<span class="sm-mono">' . wi_e($t) . '</span>'; };
$wi_bpunkt = function ($id, $aus) use ($wi_bdp, $wi_bm) {
    if (!isset($wi_bdp[$id])) { return wi_t('BAUSTEIN.DP_FEHLT'); }
    $d = $wi_bdp[$id];
    $mq = str_replace('/', '_', wi_topic($d));
    $ud = $d['geraet'] . ' ' . $d['name'];
    return $aus ? sprintf(wi_t('BAUSTEIN.DP_AUS'), $wi_bm($mq . '_setzen'), $wi_bm($ud . ' setzen'))
                : sprintf(wi_t('BAUSTEIN.DP_EIN'), $wi_bm($mq), $wi_bm($ud));
};
/* X-10 (09.10.2026): fuer die Wegzellen "MQTT-Weg: ... UDP-Weg: ..." (Grammatik von
 * leitungen_setzen.py) beide Namen einzeln: array(MQTT-Name, UDP-Titel). */
$wi_bpaar = function ($id) use ($wi_bdp, $wi_bm) {
    if (!isset($wi_bdp[$id])) { $f = wi_t('BAUSTEIN.DP_FEHLT_NAME'); return array($f, $f); }
    $d = $wi_bdp[$id];
    return array($wi_bm(str_replace('/', '_', wi_topic($d))), $wi_bm($d['geraet'] . ' ' . $d['name']));
};
$wi_bs = array(
    array('B1', 'B1_TYP', array('w', $wi_bv['mqtt_in'][0]), 'B1_PARAM', array(wi_e(wi_t('LOXONE.V_MQTT_IN')), $wi_bm($wi_pre . '_…'), $wi_bm($wi_bv['mqtt_in'][1]), $wi_bm($wi_bv['mqtt_in'][2]), $wi_bm($wi_bv['mqtt_in'][3])), 'B1_EIN', array()),
    array('B2', 'B2_TYP', array('w', $wi_bv['mqtt_out'][0]), 'B2_PARAM', array(wi_e(wi_t('LOXONE.V_MQTT_OUT')), $wi_bm('…_setzen')), 'B2_EIN', array()),
    array('B3', 'B3_TYP', array('w', $wi_bv['udp_in'][0]), 'B3_PARAM', array(wi_e(wi_t('LOXONE.V_UDP_IN')), $wi_bm(wi_cfg($wi_cfg, 'multicast_port', '35353')), $wi_bm($wi_bv['udp_in'][1]), $wi_bm($wi_bv['udp_in'][2]), $wi_bm($wi_bv['udp_in'][3]), $wi_bm('<Kennung>;\\v')), 'B3_EIN', array()),
    array('B4', 'B4_TYP', array('w', $wi_bv['tcp_out'][0]), 'B4_PARAM', array(wi_e(wi_t('LOXONE.V_TCP_OUT')), $wi_bm('tcp://' . $wi_ip . ':' . wi_cfg($wi_cfg, 'input_port', '12005')), $wi_bm('<Kennung>;<v>')), 'B4_EIN', array()),
    array('B5', 'B5_TYP', array('t', 'B5_NAME'), 'B5_PARAM', array(), 'B5_EIN', $wi_bpaar(2)),
    array('B6', 'B6_TYP', array('t', 'B6_NAME'), 'B6_PARAM', array(), 'B6_EIN', array($wi_bm($wi_bv['mqtt_in'][2]), $wi_bm($wi_bv['udp_in'][2]))),
    array('B8', 'T_NICHT', array('t', 'B8_NAME'), 'P_KEINE', array(), 'B8_EIN', array($wi_bm($wi_bv['mqtt_in'][1]), $wi_bm($wi_bv['udp_in'][1]))),
    array('B9', 'T_ODER', array('t', 'B9_NAME'), 'P_KEINE', array(), 'B9_EIN', array()),
    array('B10', 'T_ODER', array('t', 'B10_NAME'), 'B10_PARAM', array($wi_bpunkt(53, false)), 'B10_EIN', $wi_bpaar(1)),
    array('B11', 'B11_TYP', array('t', 'B11_NAME'), 'B11_PARAM', array(), 'B11_EIN', array()),
    array('B12', 'B12_TYP', array('t', 'B12_NAME'), 'P_KEINE', array(), 'B12_EIN', $wi_bpaar(4)),
    array('B13', 'B13_TYP', array('t', 'B13_NAME'), 'B13_PARAM', array(), 'B13_EIN', array($wi_bpunkt(199, true))),
    array('B14', 'B14_TYP', array('t', 'B14_NAME'), 'B14_PARAM', array(), 'B14_EIN', array($wi_bpunkt(58, true))),
    array('B15', 'B15_TYP', array('t', 'B15_NAME'), 'B15_PARAM', array(), 'B15_EIN', $wi_bpaar(195)),
);
$wi_bnr = array();
foreach ($wi_bs as $wi_i => $wi_z) { $wi_bnr[$wi_z[0]] = $wi_i + 1; }
$wi_bt = function ($schluessel, $arg = array()) use ($wi_bnr) {
    $t = (string) wi_t('BAUSTEIN.' . $schluessel);
    $t = $arg ? vsprintf($t, $arg) : $t;
    return preg_replace_callback('/\{(B\d+)\}/', function ($m) use ($wi_bnr) {
        return isset($wi_bnr[$m[1]]) ? '#' . $wi_bnr[$m[1]] : $m[0];
    }, $t);
}; ?>
<h2><?= wi_t('BAUSTEIN.H') ?></h2>
<div class="sm-small"><?= wi_t('BAUSTEIN.EINLEITUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:32px;">#</th><th><?= wi_t('BAUSTEIN.TH_TYP') ?></th><th><?= wi_t('BAUSTEIN.TH_NAME') ?></th><th><?= wi_t('BAUSTEIN.TH_PARAM') ?></th><th><?= wi_t('BAUSTEIN.TH_EINGANG') ?></th></tr>
<?php foreach ($wi_bs as $wi_i => $wi_z) { ?>
<tr><td><?= $wi_i + 1 ?></td><td><?= $wi_bt($wi_z[1]) ?></td><td><span class="sm-mono"><?= $wi_z[2][0] === 'w' ? wi_e($wi_z[2][1]) : $wi_bt($wi_z[2][1]) ?></span></td><td><?= $wi_bt($wi_z[3], $wi_z[4]) ?></td><td><?= $wi_bt($wi_z[5], $wi_z[6]) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small" style="margin-top:6px;"><?= $wi_bt('ERLAEUTERUNG') ?></div>
<div class="sm-small" style="margin-top:6px;"><?= $wi_bt('ANSAGE') ?></div>

<h2><?= wi_t('ARTEN.H') ?></h2>
<div class="sm-small"><?= wi_t('ARTEN.HINT') ?></div>
<?php
$wi_arten = wi_betriebsarten($wi_fw);
foreach ($wi_arten as $wi_dpt => $wi_gruppen) {
    foreach ($wi_gruppen as $wi_muster => $wi_werte) { ?>
<h3 class="sm-h3"><?= wi_e($wi_dpt) ?> &mdash; <?= wi_e(str_replace('|', ', ', $wi_muster)) ?></h3>
<div class="sm-breit"><table class="sm-tbl">
<tr><th style="width:60px;"><?= wi_t('ARTEN.TH_WERT') ?></th><th><?= wi_t('ARTEN.TH_TEXT') ?></th></tr>
<?php foreach ($wi_werte as $wi_z => $wi_txt) { if ($wi_txt === '-') { continue; } ?>
<tr><td><?= (int) $wi_z ?></td><td><?= wi_e($wi_txt) ?></td></tr>
<?php } ?>
</table></div>
<div class="sm-small"><?= wi_t('ARTEN.STATUSTEXT') ?> <span class="sm-mono"><?php
$wi_st = array();
foreach ($wi_werte as $wi_z => $wi_txt) { if ($wi_txt !== '-') { $wi_st[] = '&lt;v&gt;=' . (int) $wi_z . ':' . wi_e($wi_txt); } }
echo implode(' | ', $wi_st);
?></span></div>
<?php }
} ?>

<h2><?= wi_t('STOER.H') ?></h2>
<?php $wi_sc = wi_stoercodes(); ?>
<?php if (!$wi_sc) { ?>
<div class="sm-alert sm-warn"><?= wi_t('STOER.LEER') ?></div>
<div class="sm-small"><?= sprintf(wi_t('STOER.WOHIN'),
    '<span class="sm-mono">' . wi_e(dirname($wi_p['config']) . '/wolf_stoercodes.csv') . '</span>') ?></div>
<?php } else { ?>
<div class="sm-small"><?= sprintf(wi_t('STOER.ANZAHL'), count($wi_sc)) ?></div>
<div class="sm-breit"><table class="sm-tbl">
<tr><th style="width:60px;"><?= wi_t('ARTEN.TH_WERT') ?></th><th><?= wi_t('ARTEN.TH_TEXT') ?></th></tr>
<?php foreach ($wi_sc as $wi_nr => $wi_txt) { ?>
<tr><td><?= (int) $wi_nr ?></td><td><?= wi_e($wi_txt) ?></td></tr>
<?php } ?>
</table></div>
<?php } ?>

<h2><?= wi_t('LOXONE.H_DP') ?></h2>
<div class="sm-small"><?= sprintf(wi_t('LOXONE.DP_HINT'), '<span class="sm-mono">Out</span>', '<span class="sm-mono">In</span>') ?></div>
<div class="sm-row">
<div><label><?= wi_t('LOXONE.FILTER_GERAET') ?></label>
<select data-role="none" id="wi-f-geraet"><option value=""><?= wi_t('LOXONE.FILTER_ALLE') ?></option>
<?php foreach ($wi_geraete as $g) { ?><option value="<?= wi_e($g) ?>"><?= wi_e($g) ?></option><?php } ?>
</select></div>
<div><label><?= wi_t('LOXONE.FILTER_SUCHE') ?></label>
<input data-role="none" type="text" id="wi-f-suche"></div>
<div><label><?= wi_t('LOXONE.FILTER_RICHTUNG') ?></label>
<select data-role="none" id="wi-f-richtung">
<option value=""><?= wi_t('LOXONE.FILTER_ALLE') ?></option>
<option value="Out"><?= wi_t('DP.IO_LESEN') ?></option>
<option value="In"><?= wi_t('DP.IO_SCHREIBEN') ?></option>
</select></div>
</div>
<div class="sm-small" id="wi-f-zahl"><?= sprintf(wi_t('LOXONE.DP_ZAHL'), count($wi_dps), count($wi_dps)) ?></div>
<div class="sm-breit">
<table class="sm-tbl" id="wi-dp-tabelle">
<tr><th style="width:52px;"><?= wi_t('LOXONE.TH_ID') ?></th><th><?= wi_t('LOXONE.TH_GERAET') ?></th><th><?= wi_t('LOXONE.TH_DP') ?></th><th style="width:80px;"><?= wi_t('LOXONE.TH_RICHTUNG') ?></th><th><?= wi_t('LOXONE.TH_THEMA') ?></th></tr>
<?php foreach ($wi_dps as $d) { ?>
<tr data-g="<?= wi_e($d['geraet']) ?>" data-io="<?= wi_e($d['io']) ?>" data-s="<?= wi_e(wi_klein($d['id'] . ' ' . $d['geraet'] . ' ' . $d['name'] . ' ' . wi_topic($d))) ?>"><td><?= sprintf('%03d', $d['id']) ?></td><td><?= wi_e($d['geraet']) ?></td><td><?= wi_e($d['name']) ?><?= $d['einheit'] !== '-' ? ' (' . wi_e($d['einheit']) . ')' : '' ?></td><td><?= wi_e($d['io']) ?> (<?= wi_e(wi_io_text($d['io'])) ?>)</td><td><span class="sm-mono" style="font-size:0.85em;"><?= wi_e(wi_topic($d)) ?></span></td></tr>
<?php } ?>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<h2><?= wi_t('TEST.H_SELBST') ?></h2>
<?php
/* Die Selbstpruefung laeuft NUR, wenn dieser Reiter serverseitig der offene
 * ist. Bis 3.0.10 stand der Aufruf hier unbedingt - und weil alle fuenf
 * Reiter immer mitgerendert werden, lief er bei JEDEM Seitenaufruf, auch
 * beim blossen Oeffnen der Logdateien. Gemessen am 04.09.2026 auf dem
 * Baurechner: 1229, 1448 und 1413 ms je Aufruf, darunter sechs perl-Starts
 * fuer die Modulprobe, ein ps-Aufruf und die Erzeugung aller vier
 * Loxone-Vorlagen ueber 228 Datenpunkte. Das Laden der Datenpunkte selbst
 * kostet 1 bis 2 ms. */
$wi_pz = $wi_tab === 'tab-test' ? wi_pruefzeilen($wi_cfg) : array();
$wi_ja = 0; $wi_nein = 0; $wi_grau = 0;
foreach ($wi_pz as $z) {
    if ($z[0] === 1) { $wi_ja++; } elseif ($z[0] === 0) { $wi_nein++; } else { $wi_grau++; }
}
?>
<?php if (!$wi_pz) { ?>
<div class="sm-hinweis"><?= wi_t('TEST.SELBST_RUHT') ?></div>
<?php } else { ?>
<div class="sm-small"><!--WI_PRUEF_BILANZ--></div>
<?php } ?>
<ul class="sm-pruef">
<?php foreach ($wi_pz as $z) {
    $wi_k = $z[0] === 1 ? 'sm-ja' : ($z[0] === 0 ? 'sm-nein' : 'sm-grau'); ?>
<li class="<?= $wi_k ?>"><b><?= wi_e($z[1]) ?></b><?= wi_e($z[2]) ?></li>
<?php } ?>
<?php if ($wi_pz) { ?><!--WI_PRUEF_GERENDERT--><?php } ?>
</ul>

<?php if (is_array($wi_zustand) && isset($wi_zustand['zaehler'])) { ?>
<h2><?= wi_t('TEST.H_ZAEHLER') ?></h2>
<div class="sm-kacheln">
<?php foreach ($wi_zustand['zaehler'] as $wi_k2 => $wi_v2) { ?>
<div class="sm-kachel"><b><?= (int) $wi_v2 ?></b><?= wi_e(wi_t('ZAEHLER.' . strtoupper($wi_k2))) ?></div>
<?php } ?>
</div>
<?php } ?>

<?php if (is_array($wi_zustand) && isset($wi_zustand['werte']) && $wi_zustand['werte']) { ?>
<h2><?= wi_t('WERT.H') ?></h2>
<div class="sm-small"><?= sprintf(wi_t('WERT.HINT'), wi_e(wi_alter_text(wi_zustand_alter()))) ?></div>
<label><?= wi_t('WERT.FILTER') ?></label>
<input data-role="none" type="text" id="wi-w-suche" style="max-width:340px;">
<div class="sm-small" id="wi-w-zahl"><?= sprintf(wi_t('WERT.ZAHL'), count($wi_zustand['werte']), count($wi_zustand['werte'])) ?></div>
<div class="sm-breit">
<table class="sm-tbl" id="wi-wert-tabelle">
<tr><th style="width:52px;"><?= wi_t('LOXONE.TH_ID') ?></th><th><?= wi_t('LOXONE.TH_GERAET') ?></th><th><?= wi_t('LOXONE.TH_DP') ?></th><th style="width:110px;"><?= wi_t('WERT.TH_WERT') ?></th><th><?= wi_t('WERT.TH_KLARTEXT') ?></th><th style="width:90px;"><?= wi_t('WERT.TH_ALTER') ?></th></tr>
<?php
// Der Datenpunkt aus der Tabelle, damit Typ und Geraet fuer die Klartexte
// bekannt sind. Das Abbild traegt Geraet und Name mit, den KNX-Typ nicht.
$wi_nach_id = array();
foreach ($wi_dps as $d) { $wi_nach_id[$d['id']] = $d; }
$wi_ids = array_map('intval', array_keys($wi_zustand['werte']));
sort($wi_ids);
foreach ($wi_ids as $wi_id2) {
    $w = $wi_zustand['werte'][(string) $wi_id2];
    $klar = '';
    if (isset($wi_nach_id[$wi_id2])) {
        $dp = $wi_nach_id[$wi_id2];
        // Betriebsarten: die nackte Zahl in Klartext.
        $tab = wi_art_tabelle($dp, $wi_fw);
        if ($tab !== null && ctype_digit(trim((string) $w['w']))) {
            $n = (int) $w['w'];
            $klar = isset($tab[$n]) && $tab[$n] !== '-' ? $tab[$n] : wi_t('WERT.UNBEKANNT');
        }
        // Stoercode: nur, wenn eine Tabelle hinterlegt ist (V23).
        if ($klar === '' && $dp['dpt'] === 'DPT_Value_1_Ucount'
            && strpos($dp['name'], 'törcode') !== false
            && ctype_digit(trim((string) $w['w']))) {
            $s = wi_stoercode((int) $w['w']);
            $klar = $s !== '' ? $s : wi_t('WERT.KEIN_STOERTEXT');
        }
    }
    $alter = max(0, time() - (int) $w['t']);
?>
<tr data-s="<?= wi_e(wi_klein($wi_id2 . ' ' . $w['g'] . ' ' . $w['n'] . ' ' . $w['w'] . ' ' . $klar)) ?>"><td><?= sprintf('%03d', $wi_id2) ?></td><td><?= wi_e($w['g']) ?></td><td><?= wi_e($w['n']) ?></td><td><b><?= wi_e($w['w']) ?></b><?= $w['e'] !== '-' ? ' ' . wi_e($w['e']) : '' ?></td><td><?= wi_e($klar) ?></td><td><?= wi_e(wi_alter_text($alter)) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<h2><?= wi_t('WERT.H') ?></h2>
<div class="sm-alert sm-info"><?= wi_t('WERT.LEER') ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= wi_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= wi_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= wi_t('LEGENDE.AKTION') ?></span>
</div>

<h3 class="sm-h3"><?= wi_t('TEST.H_ANSEHEN') ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?= wi_t('TEST.STATUS') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="werte"><?= wi_t('TEST.WERTE') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="themen"><?= wi_t('TEST.THEMEN') ?></button></form>
</div>

<h3 class="sm-h3"><?= wi_t('TEST.H_TECHNIK') ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?= wi_t('TEST.KONFIG') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="ports"><?= wi_t('TEST.PORTS') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?= wi_t('TEST.UMGEBUNG') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="mqttinfo"><?= wi_t('TEST.MQTTINFO') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="comtest"><?= wi_t('TEST.COMTEST') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="vorlagenprobe"><?= wi_t('TEST.VORLAGENPROBE') ?></button></form>
</div>

<h3 class="sm-h3"><?= wi_t('TEST.H_SCHREIBEN') ?></h3>
<div class="sm-small"><?= wi_t('TEST.SCHREIBEN_HINT') ?></div>
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?>
<div class="sm-row">
<div><label><?= wi_t('TEST.SP_DP') ?></label>
<select data-role="none" name="sp_id">
<?php foreach ($wi_dps as $d) { if (strpos($d['io'], 'In') === false) { continue; } ?>
<option value="<?= (int) $d['id'] ?>"<?= wi_fw('schreibprobe', 'sp_id', '') === (string) $d['id'] ? ' selected' : '' ?>><?= sprintf('%03d', $d['id']) ?> &mdash; <?= wi_e($d['geraet']) ?> &mdash; <?= wi_e($d['name']) ?> (<?= wi_e($d['dpt']) ?>)</option>
<?php } ?>
</select></div>
<div><label><?= wi_t('TEST.SP_WERT') ?></label>
<input data-role="none" type="text" name="sp_wert" value="<?= wi_e(wi_fw('schreibprobe', 'sp_wert', '')) ?>"></div>
</div>
<?php /* O13 (Durchgang 02.10.2026, Regeln/04): der Trockenlauf ist grau; der
       * Knopf, der die Heizung beschreibt, steht unter "Schalten" in einer
       * eigenen Reihe - bis 3.1.5 einen Fingerbreit neben der Probe. */ ?>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="schreibprobe"><?= wi_t('TEST.SP_TROCKEN') ?></button>
</div>
<h3 class="sm-h3"><?= wi_t('TEST.H_SCHALTEN') ?></h3>
<div class="sm-small"><?= wi_t('TEST.SCHALTEN_HINT') ?></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="schreibernst"><?= wi_t('TEST.SP_ERNST') ?></button>
</div>
</form>

<h3 class="sm-h3"><?= wi_t('TEST.H_AKTION') ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?= wi_t('TEST.RESTART') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?= wi_t('TEST.STOP') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="ansage_test"><?= wi_e(wi_t('SPRACHAUSGABE.K_TEST')) ?></button></form>
</div>

<?php if ($wi_test_titel !== '' && $wi_tab === 'tab-test') { ?>
<h2><?= wi_e($wi_test_titel) ?></h2>
<div class="sm-log"><?= wi_e($wi_test_text) ?></div>
<?php } elseif ($wi_test_titel === '') { ?>
<div class="sm-alert sm-info" style="margin-top:18px;"><?= wi_t('TEST.NICHTS') ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<?php if ($wi_frame && method_exists('LBWeb', 'loglist_html')) { ?>
<h2><?= wi_t('LOG.H_VERWALTUNG') ?></h2>
<div class="sm-small"><?= wi_t('LOG.VERWALTUNG_HINT') ?></div>
<?php echo LBWeb::loglist_html(array('PACKAGE' => $wi_p['plugin'], 'NAME' => 'server')); ?>
<?php } ?>

<h2><?= wi_t('LOG.H_SERVER') ?></h2>
<div class="sm-small">
<?php if ($wi_log !== '') { ?>
<?= sprintf(wi_t('LOG.DATEI'), '<span class="sm-mono">' . wi_e($wi_log) . '</span>') ?>
<?php } else { ?>
<?= wi_t('LOG.KEINE') ?>
<?php } ?>
</div>
<?php if ($wi_zeilen) { ?>
<div class="sm-log"><?php foreach ($wi_zeilen as $z) { echo wi_e($z) . "\n"; } ?></div>
<?php } ?>

<?php
$wi_dplog = wi_log_file('datapoints');
if ($wi_dplog === '') { $wi_dplog = wi_log_file('wolf'); }
if ($wi_dplog !== '' && $wi_dplog !== $wi_log) {
    $wi_dpz = wi_log_tail($wi_dplog, 200);
    $wi_dpgr = (int) @filesize($wi_dplog);
?>
<h2><?= wi_t('LOG.H_DP') ?></h2>
<div class="sm-small"><?= sprintf(wi_t('LOG.DATEI_KURZ'), '<span class="sm-mono">' . wi_e($wi_dplog) . '</span>') ?>
&middot; <?= sprintf(wi_t('LOG.GROESSE'), wi_e(number_format($wi_dpgr / 1024, 1, ',', '.'))) ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= wi_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-log"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="dplog_leeren"><?= wi_t('LOG.LEEREN') ?></button></form>
</div>
<?php if ($wi_test_titel !== '' && $wi_tab === 'tab-log') { ?>
<div class="sm-log"><?= wi_e($wi_test_text) ?></div>
<?php } ?>
<div class="sm-log"><?php foreach ($wi_dpz as $z) { echo wi_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    var start = <?= json_encode($wi_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('sm-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.sm-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('sm-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function (e) {
                // Ohne preventDefault folgt der Browser dem Verweis und baut
                // die Seite neu auf - Eingaben in anderen Reitern sind dann
                // fort. Der Verweis bleibt trotzdem stehen: ohne JavaScript
                // ist er der einzige Weg zwischen den Reitern.
                e.preventDefault();
                zeige(t.getAttribute('data-pane'));
            });
        })(tabs[i]);
    }
    zeige(start);

    // Filter der Datenpunkttabelle (V15). Ohne JavaScript bleibt die volle
    // Tabelle stehen - sie ist dann laenger, aber vollstaendig.
    var tab = document.getElementById('wi-dp-tabelle');
    if (tab) {
        var fg = document.getElementById('wi-f-geraet'),
            fs = document.getElementById('wi-f-suche'),
            fr = document.getElementById('wi-f-richtung'),
            fz = document.getElementById('wi-f-zahl'),
            vorlage = fz ? fz.textContent : '';
        var filtern = function () {
            var zeilen = tab.querySelectorAll('tr[data-g]'), n = 0;
            for (var i = 0; i < zeilen.length; i++) {
                var z = zeilen[i], ok = true;
                if (fg.value && z.getAttribute('data-g') !== fg.value) { ok = false; }
                if (ok && fr.value && z.getAttribute('data-io').indexOf(fr.value) < 0) { ok = false; }
                if (ok && fs.value) {
                    if (z.getAttribute('data-s').indexOf(fs.value.toLowerCase()) < 0) { ok = false; }
                }
                z.style.display = ok ? '' : 'none';
                if (ok) { n++; }
            }
            if (fz) { fz.textContent = vorlage.replace(/^\d+/, String(n)); }
        };
        fg.addEventListener('change', filtern);
        fr.addEventListener('change', filtern);
        fs.addEventListener('input', filtern);
    }

    // Derselbe Filter fuer die Wertetabelle (V1).
    var wtab = document.getElementById('wi-wert-tabelle');
    var wsuche = document.getElementById('wi-w-suche');
    var wzahl = document.getElementById('wi-w-zahl');
    if (wtab && wsuche) {
        var wvorlage = wzahl ? wzahl.textContent : '';
        wsuche.addEventListener('input', function () {
            var zeilen = wtab.querySelectorAll('tr[data-s]'), n = 0;
            var s = wsuche.value.toLowerCase();
            for (var i = 0; i < zeilen.length; i++) {
                var ok = !s || zeilen[i].getAttribute('data-s').indexOf(s) >= 0;
                zeilen[i].style.display = ok ? '' : 'none';
                if (ok) { n++; }
            }
            if (wzahl) { wzahl.textContent = wvorlage.replace(/^\d+/, String(n)); }
        });
    }
})();
</script>
<?php
/* O11: die gerenderten Pruefzeilen einsetzen, dann ausgeben. */
$wi_html = (string) ob_get_clean();
if ($wi_pz) {
    list($wi_gz, $wi_gn) = wi_pruef_gerendert($wi_html, $wi_tab);
    foreach ($wi_gn as $wi_gs) {
        if ($wi_gs === 1) { $wi_ja++; } elseif ($wi_gs === 0) { $wi_nein++; } else { $wi_grau++; }
    }
    $wi_html = str_replace('<!--WI_PRUEF_GERENDERT-->', $wi_gz, $wi_html);
    $wi_html = str_replace('<!--WI_PRUEF_BILANZ-->',
        sprintf(wi_t('TEST.SELBST_BILANZ'), $wi_ja, count($wi_pz) + count($wi_gn), $wi_nein, $wi_grau), $wi_html);
}
echo $wi_html;
if ($wi_frame) {
    LBWeb::lbfooter();
}
