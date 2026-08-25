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

$wi_p = wi_paths();
if ($wi_p['home']) {
    $wi_sdk = $wi_p['home'] . '/libs/phplib/loxberry_system.php';
    if (file_exists($wi_sdk)) {
        require_once $wi_sdk;
        require_once $wi_p['home'] . '/libs/phplib/loxberry_web.php';
        $wi_p = wi_paths();   // nach dem Einbinden neu holen
    }
}

$wi_saved = false;
$wi_error = '';
$wi_hinweis = '';
$wi_beanstandungen = array();

/* ============ EIN Wachposten vor allen Handlern ============
 *
 * Nicht acht Abfragen in acht Zweigen: ein Formular ohne gueltiges
 * Merkmal wird hier entwertet, danach kann kein Handler mehr greifen.
 * Verglichen wird mit hash_equals - ein einfaches == liesse sich ueber
 * die Antwortzeit Zeichen fuer Zeichen erraten.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wi_fmt_ein = isset($_POST['fmt']) && is_string($_POST['fmt']) ? $_POST['fmt'] : '';
    if (!hash_equals(wi_formkey(), $wi_fmt_ein)) {
        $wi_error = wi_t('MELDUNG.FREMDES_FORMULAR');
        $_POST = array();
        $_FILES = array();
    }
}

// Der Reiter kommt aus einem abgesendeten Formular (activetab) oder aus der
// Adresse (?tab=...). Letzteres brauchen die Reiter, seit sie echte Verweise
// sind - siehe die Reiterleiste weiter unten.
$wi_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : '');
$wi_tab = preg_match('/^tab-(settings|mqtt|loxone|test|log)$/', $wi_wunsch)
    ? $wi_wunsch : 'tab-settings';

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $art = (string) $_POST['download'];
    $geraete = isset($_POST['geraete']) && is_array($_POST['geraete']) ? $_POST['geraete'] : array();
    $nurgesehen = isset($_POST['nurgesehen']) ? wi_gesehen() : null;
    if (!$geraete) {
        $wi_error = wi_t('MELDUNG.KEIN_GERAET');
        $wi_tab = 'tab-loxone';
    } else {
        list($name, $inhalt, $anzahl) = wi_vorlage($art, wi_config_read(), $geraete, $nurgesehen);
        if ($name === '') {
            $wi_error = wi_t('MELDUNG.UNBEKANNTE_ART');
            $wi_tab = 'tab-loxone';
        } elseif ($art === 'mqtt_out' && !wi_mqtt_udpinport()) {
            // Ohne UDP-Eingangsport des Gateways entstuende die Adresse
            // /dev/udp/<ip>/0. Ein virtueller Ausgang auf Port 0 sendet
            // nichts, und Loxone meldet dazu nichts.
            $wi_error = wi_t('MELDUNG.KEIN_UDPIN_VORLAGE');
            $wi_tab = 'tab-loxone';
        } elseif ($anzahl < 1) {
            $wi_error = wi_t('MELDUNG.LEERE_VORLAGE');
            $wi_tab = 'tab-loxone';
        } else {
            header('Content-Type: application/x-download');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . strlen($inhalt));
            echo $inhalt;
            exit;
        }
    }
}

/* ============ Einstellungen sichern (V17) ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sichern'])) {
    $txt = wi_konfig_text(wi_config_read());
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="wolf_ism8i_einstellungen.txt"');
    header('Content-Length: ' . strlen($txt));
    echo $txt;
    exit;
}

/* ============ Einstellungen zurueckspielen (V17) ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['laden'])) {
    $wi_tab = 'tab-settings';
    if (!isset($_FILES['sicherung']) || !is_array($_FILES['sicherung'])
        || !isset($_FILES['sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['sicherung']['tmp_name'])) {
        $wi_error = wi_t('MELDUNG.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['sicherung']['size'] > 65536) {
        $wi_error = wi_t('MELDUNG.SICH_ZU_GROSS');
    } else {
        $roh = (string) @file_get_contents($_FILES['sicherung']['tmp_name']);
        list($neu, $mangel) = wi_konfig_einlesen($roh);
        if ($neu === null) {
            // Eine halb gueltige Datei ueberschreibt NICHTS.
            $wi_beanstandungen = $mangel;
            $wi_error = wi_t('MELDUNG.SICH_ABGELEHNT');
        } elseif (wi_config_write($neu)) {
            $wi_saved = true;
            $wi_hinweis = wi_t('MELDUNG.SICH_UEBERNOMMEN') . ' ' . wi_dienst_uebernehmen($neu, null);
        } else {
            $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e($wi_p['config']));
        }
    }
}

/* ============ Test-Aktionen ============ */
$wi_test_titel = '';
$wi_test_text = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    $wi_was = (string) $_POST['test'];
    list($wi_test_titel, $wi_test_text) = wi_test_ausfuehren($wi_was);
    if (strpos($wi_was, 'aufraeumen') === 0) {
        $wi_tab = 'tab-mqtt';
    } elseif ($wi_was === 'dplog_leeren') {
        $wi_tab = 'tab-log';
    } else {
        $wi_tab = 'tab-test';
    }
}

/* ============ Speichern: Einstellungen - OHNE MQTT ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $wi_vorher = wi_config_read();
    $neu = $wi_vorher;

    // Fehlt ein Feld, gilt der BESTEHENDE Wert - nicht die Vorgabe.
    // Und ein unzulaessiger Wert wird BEANSTANDET statt zurechtgebogen.
    $port = function ($schluessel, $alt, $feld) use (&$wi_beanstandungen) {
        if (!isset($_POST[$schluessel])) {
            return (string) $alt;
        }
        $t = trim((string) $_POST[$schluessel]);
        if ($t === '') {
            return (string) $alt;
        }
        if (!ctype_digit($t) || (int) $t < 1 || (int) $t > 65535) {
            $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.PORT_UNGUELTIG'),
                wi_e($feld), wi_e($t), wi_e((string) $alt));
            return (string) $alt;
        }
        return (string) (int) $t;
    };
    $zahl = function ($schluessel, $alt, $feld, $min, $max) use (&$wi_beanstandungen) {
        if (!isset($_POST[$schluessel])) {
            return (string) $alt;
        }
        $t = trim((string) $_POST[$schluessel]);
        if ($t === '') {
            return (string) $alt;
        }
        if (!ctype_digit($t) || (int) $t < $min || (int) $t > $max) {
            $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.ZAHL_UNGUELTIG'),
                wi_e($feld), wi_e($t), (int) $min, (int) $max, wi_e((string) $alt));
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

    // V18: das Ausgabeformat ist ein Auswahlfeld. Bis 3.0.7 war es ein Haken,
    // der nur zwischen 'data' und 'none' umschalten konnte - wer 'fhem' oder
    // 'csv' in der Datei stehen hatte, verlor es beim ERSTEN Speichern, ohne
    // dass die Oberflaeche es vorher auch nur angezeigt haette.
    $wi_alt_out = wi_cfg($neu, 'output', 'none');
    $out = isset($_POST['output']) ? (string) $_POST['output'] : $wi_alt_out;
    if (in_array($out, array('none', 'data', 'csv', 'fhem'), true)) {
        $neu['output'] = $out;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.OUT_UNGUELTIG'), wi_e($out), wi_e($wi_alt_out));
        $neu['output'] = $wi_alt_out;
    }

    $wi_alt_fw = wi_cfg($neu, 'fw_version', '1.8');
    $fw = isset($_POST['fw_version']) ? (string) $_POST['fw_version'] : $wi_alt_fw;
    if (in_array($fw, array('1.4', '1.5', '1.7', '1.8', '1.9'), true)) {
        $neu['fw_version'] = $fw;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.FW_UNGUELTIG'), wi_e($fw), wi_e($wi_alt_fw));
        $neu['fw_version'] = $wi_alt_fw;
    }

    $wi_alt_to = wi_cfg($neu, 'online_timeout', '-1');
    $to = trim((string) (isset($_POST['online_timeout']) ? $_POST['online_timeout'] : $wi_alt_to));
    if (preg_match('/^(-1|[0-9]+)$/', $to)) {
        $neu['online_timeout'] = $to;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.TIMEOUT_UNGUELTIG'), wi_e($to), wi_e($wi_alt_to));
        $neu['online_timeout'] = $wi_alt_to;
    }

    $neu['herzschlag'] = $zahl('herzschlag', wi_cfg($neu, 'herzschlag', '60'),
                               wi_t('EINST.HERZSCHLAG'), 0, 86400);
    $neu['abgleich_takt'] = $zahl('abgleich_takt', wi_cfg($neu, 'abgleich_takt', '0'),
                               wi_t('EINST.ABGLEICH'), 0, 86400);

    // V23/3.0.9: welche Stoercodetabelle gilt. Leer ist ein gueltiger Wert
    // und heisst "keine" - der Datenpunkt 372 bedeutet je nach Waermeerzeuger
    // Verschiedenes, und eine falsche Tabelle ist schlimmer als keine.
    $wi_alt_sc = wi_cfg($neu, 'stoercodes', '');
    $sc = isset($_POST['stoercodes']) ? trim((string) $_POST['stoercodes']) : $wi_alt_sc;
    if ($sc === '' || isset(wi_stoercode_tabellen()[$sc])) {
        $neu['stoercodes'] = $sc;
    } else {
        $wi_beanstandungen[] = sprintf(wi_t('MELDUNG.SC_UNGUELTIG'),
                                       wi_e($sc), wi_e($wi_alt_sc));
        $neu['stoercodes'] = $wi_alt_sc;
    }

    // V19: die Multicast-Gruppe ist wieder waehlbar. Bis 3.0.7 uebernahm die
    // Oberflaeche die Adresse AUSSCHLIESSLICH aus der Miniserver-Auswahl - wer
    // einmal einen Miniserver gewaehlt hatte, kam nie wieder zum Gruppenbetrieb
    // zurueck.
    $ms = isset($_POST['ms']) ? (string) $_POST['ms'] : '';
    if ($ms === 'gruppe') {
        $neu['multicast_ip'] = '239.7.7.77';
    } elseif ($ms !== '') {
        $mslist = wi_miniservers();
        if (isset($mslist[$ms]) && $mslist[$ms]['ip'] !== '') {
            $neu['multicast_ip'] = $mslist[$ms]['ip'];
        }
    }

    if (wi_config_write($neu)) {
        $wi_saved = true;
        $wi_hinweis = wi_dienst_uebernehmen($neu, $wi_vorher);
    } else {
        $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e($wi_p['config']));
    }
}

/* ============ Speichern: MQTT - eigener Handler (V12) ============
 *
 * Der Einstellungs-Handler fasst die MQTT-Werte nicht mehr an. Er laedt
 * ohnehin den Bestand, die Werte ueberleben also unveraendert. Stuende dort
 * weiter isset($_POST['mqtt']), schaltete jedes Speichern der Einstellungen
 * MQTT stillschweigend ab.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mqtt'])) {
    $wi_tab = 'tab-mqtt';
    $wi_vorher = wi_config_read();
    $neu = $wi_vorher;
    $neu['mqtt'] = isset($_POST['mqtt']) ? '1' : '0';

    $alt_pre = wi_cfg($neu, 'praefix', 'wolf_ng');
    $pre = strtolower(trim((string) (isset($_POST['praefix']) ? $_POST['praefix'] : $alt_pre)));
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
        $neu['praefix'] = $alt_pre;
    }

    if (wi_config_write($neu)) {
        $wi_saved = true;
        $wi_hinweis = trim($wechsel . ' ' . wi_dienst_uebernehmen($neu, $wi_vorher));
    } else {
        $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e($wi_p['config']));
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
.sm-breit { overflow-x: auto; max-width: 100%; }

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

<div class="sm-alert sm-info">
<?= wi_t('KOPF.SERVER') ?> <b><?= $wi_pid ? wi_t('KOPF.LAEUFT') : wi_t('KOPF.LAEUFT_NICHT') ?></b><?= $wi_pid ? ' (PID ' . $wi_pid . ') ' : ' ' ?>
&middot; <?= sprintf(wi_t('KOPF.FIRMWARE'), wi_e($wi_fw), count($wi_dps)) ?>
&middot; <?= wi_t('KOPF.WEG') ?> <b><?= wi_cfg($wi_cfg, 'mqtt', '0') === '1' ? 'MQTT' : '&ndash;' ?><?= wi_cfg($wi_cfg, 'output', 'none') !== 'none' ? ' + ' . wi_e(strtoupper(wi_cfg($wi_cfg, 'output', 'none'))) : '' ?></b>
&middot; <?= wi_t('KOPF.LOXBERRY') ?> <span class="sm-mono"><?= wi_e($wi_ip) ?></span>
<?php if (is_array($wi_zustand) && isset($wi_zustand['werte'])) { ?>
&middot; <?= sprintf(wi_t('KOPF.WERTE'), count($wi_zustand['werte']), wi_e(wi_alter_text(wi_zustand_alter()))) ?>
<?php } ?>
</div>

<?php
/*
 * Die Reiter sind echte Verweise, keine <div>. Vorher stand hier
 * <div class="sm-tab" data-pane="..."> - und weil alle Flaechen bis zum Lauf
 * des JavaScripts auf display:none stehen, war die Seite ohne JavaScript
 * vollstaendig leer. Jetzt setzt der Server die Klasse sm-active an Reiter
 * UND Flaeche; das JavaScript spart nur noch den Seitenaufbau.
 *
 * Das data-pane-Attribut traegt den Vorsatz tab- ausgeschrieben. Ohne ihn
 * fand hausstandard_pruefen.py weder einen Reiter noch die Form 'erzeugte
 * Leiste' und setzte die Spalte auf einen Strich - der liest sich beim
 * Ueberfliegen wie ein Haken und heisst 'nichts gemessen'.
 */
$wi_reiter = array(
    'tab-settings' => wi_t('REITER.EINSTELLUNGEN'),
    'tab-mqtt'     => wi_t('REITER.MQTT'),
    'tab-loxone'   => wi_t('REITER.LOXONE'),
    'tab-test'     => wi_t('REITER.TEST'),
    'tab-log'      => wi_t('REITER.LOG'),
);
?>
<div class="sm-tabs">
<?php foreach ($wi_reiter as $wi_id => $wi_bez) { ?>
    <a class="sm-tab<?php echo $wi_tab === $wi_id ? ' sm-active' : ''; ?>"
       data-pane="tab-<?php echo wi_e(substr($wi_id, 4)); ?>"
       href="index.php?tab=<?php echo wi_e(substr($wi_id, 4)); ?>"><?php echo $wi_bez; ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings"><?= wi_fmt() ?>

<h2><?= wi_t('EINST.H_SERVER') ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="enable" value="1"<?= wi_cfg($wi_cfg, 'enable', '0') === '1' ? ' checked' : '' ?>> <?= wi_t('EINST.EINSCHALTEN') ?></label>
<div class="sm-small"><?= wi_t('EINST.EINSCHALTEN_HINT') ?></div>

<div class="sm-row">
<div>
<label><?= wi_t('EINST.FIRMWARE') ?></label>
<select data-role="none" name="fw_version">
<?php foreach (array('1.4', '1.5', '1.7', '1.8', '1.9') as $v) { ?>
<option value="<?= $v ?>"<?= $wi_fw === $v ? ' selected' : '' ?>><?= $v ?></option>
<?php } ?>
</select>
<div class="sm-small"><?= wi_t('EINST.FIRMWARE_HINT') ?></div>
</div>
<div>
<label><?= wi_t('EINST.STOERTAB') ?></label>
<select data-role="none" name="stoercodes">
<option value=""<?= $wi_sc_wahl === '' ? ' selected' : '' ?>><?= wi_t('EINST.STOERTAB_KEINE') ?></option>
<?php foreach (wi_stoercode_tabellen() as $wi_k => $wi_v) { ?>
<option value="<?= wi_e($wi_k) ?>"<?= $wi_sc_wahl === $wi_k ? ' selected' : '' ?>><?= wi_e($wi_v[0]) ?> (<?= (int) $wi_v[2] ?>)</option>
<?php } ?>
</select>
<div class="sm-small"><?= wi_t('EINST.STOERTAB_HINT') ?></div>
</div>
<div>
<label><?= wi_t('EINST.ISM8_PORT') ?></label>
<input data-role="none" type="number" name="ism8i_port" min="1" max="65535" value="<?= wi_e(wi_cfg($wi_cfg, 'ism8i_port', '12004')) ?>">
<div class="sm-small"><?= sprintf(wi_t('EINST.ISM8_PORT_HINT'), '<span class="sm-mono">' . wi_e($wi_ip) . '</span>') ?></div>
</div>
<div>
<label><?= wi_t('EINST.INPUT_PORT') ?></label>
<input data-role="none" type="number" name="input_port" min="1" max="65535" value="<?= wi_e(wi_cfg($wi_cfg, 'input_port', '12005')) ?>">
<div class="sm-small"><?= wi_t('EINST.INPUT_PORT_HINT') ?></div>
</div>
</div>

<h2><?= wi_t('EINST.H_WEG') ?></h2>
<div class="sm-small"><?= wi_t('EINST.WEG_HINT') ?></div>
<div class="sm-row" style="margin-top:12px;">
<div>
<label><?= wi_t('EINST.OUTPUT') ?></label>
<select data-role="none" name="output">
<?php foreach (array('none', 'data', 'csv', 'fhem') as $v) { ?>
<option value="<?= $v ?>"<?= wi_cfg($wi_cfg, 'output', 'none') === $v ? ' selected' : '' ?>><?= wi_t('EINST.OUT_' . strtoupper($v)) ?></option>
<?php } ?>
</select>
<div class="sm-small"><?= wi_t('EINST.OUTPUT_HINT') ?></div>
</div>
<div>
<label><?= wi_t('EINST.MINISERVER') ?></label>
<select data-role="none" name="ms">
<option value=""><?= wi_t('EINST.UNVERAENDERT') ?></option>
<option value="gruppe"<?= $wi_ms_aktiv === 'gruppe' ? ' selected' : '' ?>><?= wi_t('EINST.GRUPPE') ?></option>
<?php foreach ($wi_ms as $nr => $m) { ?>
<option value="<?= wi_e($nr) ?>"<?= (string) $nr === $wi_ms_aktiv ? ' selected' : '' ?>><?= wi_e($m['name']) ?> (<?= wi_e($m['ip']) ?>)</option>
<?php } ?>
</select>
<div class="sm-small"><?= sprintf(wi_t('EINST.MINISERVER_HINT'), '<span class="sm-mono">' . wi_e($wi_mcip) . '</span>') ?></div>
</div>
<div>
<label><?= wi_t('EINST.UDP_PORT') ?></label>
<input data-role="none" type="number" name="multicast_port" min="1" max="65535" value="<?= wi_e(wi_cfg($wi_cfg, 'multicast_port', '35353')) ?>">
</div>
</div>

<h2><?= wi_t('EINST.H_UEBERWACHUNG') ?></h2>
<div class="sm-row">
<div>
<label><?= wi_t('EINST.HERZSCHLAG') ?></label>
<input data-role="none" type="number" name="herzschlag" min="0" max="86400" value="<?= wi_e(wi_cfg($wi_cfg, 'herzschlag', '60')) ?>">
<div class="sm-small"><?= wi_t('EINST.HERZSCHLAG_HINT') ?></div>
</div>
<div>
<label><?= wi_t('EINST.TIMEOUT') ?></label>
<input data-role="none" type="text" name="online_timeout" value="<?= wi_e(wi_cfg($wi_cfg, 'online_timeout', '-1')) ?>">
<div class="sm-small"><?= sprintf(wi_t('EINST.TIMEOUT_HINT'), '<span class="sm-mono">-1</span>') ?></div>
</div>
<div>
<label><?= wi_t('EINST.ABGLEICH') ?></label>
<input data-role="none" type="number" name="abgleich_takt" min="0" max="86400" value="<?= wi_e(wi_cfg($wi_cfg, 'abgleich_takt', '0')) ?>">
<div class="sm-small"><?= wi_t('EINST.ABGLEICH_HINT') ?></div>
</div>
</div>

<h2><?= wi_t('EINST.H_WEITERE') ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="pull_on_write" value="1"<?= wi_cfg($wi_cfg, 'pull_on_write', '0') === '1' ? ' checked' : '' ?>> <?= wi_t('EINST.PULL') ?></label>
<div class="sm-small"><?= wi_t('EINST.PULL_HINT') ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="dp_log" value="1"<?= wi_cfg($wi_cfg, 'dp_log', '0') === '1' ? ' checked' : '' ?>> <?= wi_t('EINST.DPLOG') ?></label>
<div class="sm-small"><?= wi_t('EINST.DPLOG_HINT') ?></div>

<button data-role="none" class="sm-btn" type="submit" name="save" value="1"><?= wi_t('EINST.SPEICHERN') ?></button>
</form>

<h2><?= wi_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-small"><?= wi_t('EINST.SICHERUNG_HINT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= wi_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= wi_t('LEGENDE.AKTION') ?></span>
</div>
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
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= wi_cfg($wi_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <?= wi_t('MQTT.EIN') ?></label>
<div class="sm-small"><?= wi_t('MQTT.EIN_HINT') ?></div>
<label style="margin-top:12px;"><?= wi_t('MQTT.PRAEFIX') ?></label>
<input data-role="none" type="text" name="praefix" value="<?= wi_e($wi_pre) ?>" style="max-width:280px;">
<div class="sm-alert sm-warn"><?= wi_t('MQTT.PRAEFIX_HINT') ?></div>
<button data-role="none" class="sm-btn" type="submit" name="save_mqtt" value="1"><?= wi_t('MQTT.SPEICHERN') ?></button>
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
<?php if ($wi_gwf === 1 || $wi_gwf === 0) { ?>
<div class="sm-step">
<b><?= wi_t('MQTT.ABO_V1_H') ?></b><br>
<?= sprintf(wi_t('MQTT.ABO_V1'), '<span class="sm-mono">' . wi_e($wi_pre) . '/#</span>') ?>
</div>
<?php } ?>
<?php if ($wi_gwf === 2 || $wi_gwf === 0) { ?>
<div class="sm-step">
<b><?= wi_t('MQTT.ABO_V2_H') ?></b><br>
<?= wi_t('MQTT.ABO_V2') ?>
</div>
<?php } ?>
<?php if ($wi_gwf === 0) { ?>
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
<tr><th><?= wi_t('LOXONE.TH_THEMA') ?></th><th><?= wi_t('MQTT.TH_BEDEUTUNG') ?></th></tr>
<tr><td><span class="sm-mono"><?= wi_e($wi_pre) ?>/online</span></td><td><?= wi_t('MQTT.B_ONLINE') ?></td></tr>
<tr><td><span class="sm-mono"><?= wi_e($wi_pre) ?>/zeitstempel</span></td><td><?= wi_t('MQTT.B_ZEIT') ?></td></tr>
<tr><td><span class="sm-mono"><?= wi_e($wi_pre) ?>/zaehler</span></td><td><?= wi_t('MQTT.B_ZAEHLER') ?></td></tr>
<?php
$wi_zeit_typen = array('DPT_TimeOfDay', 'DPT_Date');
$wi_gezeigt = 0;
foreach ($wi_dps as $d) {
    if (strpos($d['io'], 'Out') === false || in_array($d['dpt'], $wi_zeit_typen, true)) { continue; }
    $wi_gezeigt++;
?>
<tr><td><span class="sm-mono" style="font-size:0.85em;"><?= wi_e(wi_topic($d)) ?></span></td><td><?= wi_e($d['geraet']) ?> &mdash; <?= wi_e($d['name']) ?><?= $d['einheit'] !== '-' ? ' (' . wi_e($d['einheit']) . ')' : '' ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-small"><?= sprintf(wi_t('MQTT.THEMEN_ZAHL'), $wi_gezeigt, count($wi_dps) - $wi_gezeigt) ?></div>

<h2><?= wi_t('MQTT.H_AUFRAEUMEN') ?></h2>
<div class="sm-small"><?= wi_t('MQTT.AUFRAEUMEN_HINT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= wi_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= wi_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-mqtt"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="aufraeumen_probe"><?= wi_t('MQTT.AUFRAEUMEN_PROBE') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-mqtt"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="aufraeumen"><?= wi_t('MQTT.AUFRAEUMEN') ?></button></form>
</div>
<?php if ($wi_test_titel !== '' && $wi_tab === 'tab-mqtt') { ?>
<h3 class="sm-h3"><?= wi_e($wi_test_titel) ?></h3>
<div class="sm-log"><?= wi_e($wi_test_text) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?= wi_t('LOXONE.H_WEG') ?></h2>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S1'),
    '<span class="sm-mono">' . wi_e($wi_ip) . '</span>',
    '<span class="sm-mono">' . wi_e(wi_cfg($wi_cfg, 'ism8i_port', '12004')) . '</span>') ?></div>

<div class="sm-step"><?= wi_t('LOXONE.S2') ?></div>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S3'), '<span class="sm-mono">' . wi_e($wi_pre) . '/#</span>') ?></div>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S4'), count($wi_dps)) ?></div>

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
<span><i class="sm-punkt sm-b-aktion"></i><?= wi_t('LEGENDE.AKTION_DATEI') ?></span>
</div>

<h3 class="sm-h3"><?= wi_t('LOXONE.H_MQTT_VORLAGEN') ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="mqtt_in"><?= wi_t('LOXONE.V_MQTT_IN') ?></button>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="mqtt_out"><?= wi_t('LOXONE.V_MQTT_OUT') ?></button>
</div>
<div class="sm-small"><?= wi_t('LOXONE.V_MQTT_HINT') ?></div>

<h3 class="sm-h3"><?= wi_t('LOXONE.H_UDP_VORLAGEN') ?></h3>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="udp_in"><?= wi_t('LOXONE.V_UDP_IN') ?></button>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="download" value="tcp_out"><?= wi_t('LOXONE.V_TCP_OUT') ?></button>
</div>
<div class="sm-small"><?= wi_t('LOXONE.V_UDP_HINT') ?></div>
</form>

<h2><?= wi_t('BAUSTEIN.H') ?></h2>
<div class="sm-small"><?= wi_t('BAUSTEIN.EINLEITUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:32px;">#</th><th><?= wi_t('BAUSTEIN.TH_TYP') ?></th><th><?= wi_t('BAUSTEIN.TH_NAME') ?></th><th><?= wi_t('BAUSTEIN.TH_PARAM') ?></th><th><?= wi_t('BAUSTEIN.TH_EINGANG') ?></th></tr>
<?php for ($wi_i = 1; $wi_i <= 8; $wi_i++) { ?>
<tr><td><?= $wi_i ?></td><td><?= wi_t('BAUSTEIN.B' . $wi_i . '_TYP') ?></td><td><?= wi_t('BAUSTEIN.B' . $wi_i . '_NAME') ?></td><td><?= wi_t('BAUSTEIN.B' . $wi_i . '_PARAM') ?></td><td><?= wi_t('BAUSTEIN.B' . $wi_i . '_EIN') ?></td></tr>
<?php } ?>
</table>
</div>
<?php for ($wi_i = 1; $wi_i <= 4; $wi_i++) { ?>
<div class="sm-small" style="margin-top:6px;"><?= wi_t('BAUSTEIN.ZU' . $wi_i) ?></div>
<?php } ?>

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
<tr data-g="<?= wi_e($d['geraet']) ?>" data-io="<?= wi_e($d['io']) ?>" data-s="<?= wi_e(strtolower($d['id'] . ' ' . $d['geraet'] . ' ' . $d['name'] . ' ' . wi_topic($d))) ?>"><td><?= sprintf('%03d', $d['id']) ?></td><td><?= wi_e($d['geraet']) ?></td><td><?= wi_e($d['name']) ?><?= $d['einheit'] !== '-' ? ' (' . wi_e($d['einheit']) . ')' : '' ?></td><td><?= wi_e($d['io']) ?> (<?= wi_e(wi_io_text($d['io'])) ?>)</td><td><span class="sm-mono" style="font-size:0.85em;"><?= wi_e(wi_topic($d)) ?></span></td></tr>
<?php } ?>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<h2><?= wi_t('TEST.H_SELBST') ?></h2>
<?php
$wi_pz = wi_pruefzeilen($wi_cfg);
$wi_ja = 0; $wi_nein = 0; $wi_grau = 0;
foreach ($wi_pz as $z) {
    if ($z[0] === 1) { $wi_ja++; } elseif ($z[0] === 0) { $wi_nein++; } else { $wi_grau++; }
}
?>
<div class="sm-small"><?= sprintf(wi_t('TEST.SELBST_BILANZ'), $wi_ja, count($wi_pz), $wi_nein, $wi_grau) ?></div>
<ul class="sm-pruef">
<?php foreach ($wi_pz as $z) {
    $wi_k = $z[0] === 1 ? 'sm-ja' : ($z[0] === 0 ? 'sm-nein' : 'sm-grau'); ?>
<li class="<?= $wi_k ?>"><b><?= wi_e($z[1]) ?></b><?= wi_e($z[2]) ?></li>
<?php } ?>
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
<tr data-s="<?= wi_e(strtolower($wi_id2 . ' ' . $w['g'] . ' ' . $w['n'] . ' ' . $w['w'] . ' ' . $klar)) ?>"><td><?= sprintf('%03d', $wi_id2) ?></td><td><?= wi_e($w['g']) ?></td><td><?= wi_e($w['n']) ?></td><td><b><?= wi_e($w['w']) ?></b><?= $w['e'] !== '-' ? ' ' . wi_e($w['e']) : '' ?></td><td><?= wi_e($klar) ?></td><td><?= wi_e(wi_alter_text($alter)) ?></td></tr>
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
<option value="<?= (int) $d['id'] ?>"<?= (isset($_POST['sp_id']) && (int) $_POST['sp_id'] === $d['id']) ? ' selected' : '' ?>><?= sprintf('%03d', $d['id']) ?> &mdash; <?= wi_e($d['geraet']) ?> &mdash; <?= wi_e($d['name']) ?> (<?= wi_e($d['dpt']) ?>)</option>
<?php } ?>
</select></div>
<div><label><?= wi_t('TEST.SP_WERT') ?></label>
<input data-role="none" type="text" name="sp_wert" value="<?= wi_e(isset($_POST['sp_wert']) ? (string) $_POST['sp_wert'] : '') ?>"></div>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="schreibprobe"><?= wi_t('TEST.SP_TROCKEN') ?></button>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="schreibernst"><?= wi_t('TEST.SP_ERNST') ?></button>
</div>
</form>

<h3 class="sm-h3"><?= wi_t('TEST.H_AKTION') ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?= wi_t('TEST.RESTART') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><?= wi_fmt() ?><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?= wi_t('TEST.STOP') ?></button></form>
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
if ($wi_frame) {
    LBWeb::lbfooter();
}
