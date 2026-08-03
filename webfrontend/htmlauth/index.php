<?php
/**
 * Wolf ISM8 - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 *
 * Loest die alte Perl-CGI-Oberflaeche (index.cgi, ajax.cgi, HTML::Template)
 * ab. Konfigurationsdatei und alle Skripte unter bin/ bleiben unveraendert,
 * damit der Server ohne Anpassung weiterlaeuft.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

require_once __DIR__ . '/wi_lib.php';

$wi_p = wi_paths();
if ($wi_p['home']) {
    $wi_sdk = $wi_p['home'] . '/libs/phplib/loxberry_system.php';
    if (file_exists($wi_sdk)) {
        require_once $wi_sdk;
        require_once $wi_p['home'] . '/libs/phplib/loxberry_web.php';
    }
}

$wi_saved = false;
$wi_error = '';
$wi_hinweis = '';
$wi_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? $_POST['activetab'] : 'tab-settings';

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $art = (string) $_POST['download'];
    $geraete = isset($_POST['geraete']) && is_array($_POST['geraete']) ? $_POST['geraete'] : array();
    if (!$geraete) {
        $wi_error = 'Es war kein Ger&auml;t ausgew&auml;hlt &mdash; ohne Auswahl w&auml;re die Vorlage leer.';
        $wi_tab = 'tab-loxone';
    } else {
        list($name, $inhalt) = wi_vorlage($art, wi_config_read(), $geraete);
        if ($name === '') {
            $wi_error = 'Unbekannte Vorlagenart.';
            $wi_tab = 'tab-loxone';
        } else {
            header('Content-Type: application/x-download');
            header('Content-Disposition: attachment; filename=' . $name);
            header('Content-Length: ' . strlen($inhalt));
            echo $inhalt;
            exit;
        }
    }
}

/* ============ Test-Aktionen ============ */
$wi_test_titel = '';
$wi_test_text = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    require_once __DIR__ . '/wi_test.php';
    list($wi_test_titel, $wi_test_text) = wi_test_ausfuehren((string) $_POST['test']);
    $wi_tab = 'tab-test';
}

/* ============ Speichern ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $neu = wi_config_read();

    $port = function ($wert, $vorgabe) {
        $n = (int) $wert;
        return ($n > 0 && $n <= 65535) ? (string) $n : (string) $vorgabe;
    };

    $neu['enable']      = isset($_POST['enable']) ? '1' : '0';
    $neu['ism8i_port']  = $port($_POST['ism8i_port'] ?? '', 12004);
    $neu['input_port']  = $port($_POST['input_port'] ?? '', 12005);
    $neu['multicast_port'] = $port($_POST['multicast_port'] ?? '', 35353);
    $neu['dp_log']      = isset($_POST['dp_log']) ? '1' : '0';
    $neu['mqtt']        = isset($_POST['mqtt']) ? '1' : '0';
    $neu['output']      = isset($_POST['tcp_udp']) ? 'data' : 'none';
    $neu['pull_on_write'] = isset($_POST['pull_on_write']) ? '1' : '0';

    $fw = (string) ($_POST['fw_version'] ?? '1.8');
    $neu['fw_version'] = in_array($fw, array('1.4', '1.5', '1.7', '1.8', '1.9'), true) ? $fw : '1.8';

    $to = (string) ($_POST['online_timeout'] ?? '-1');
    $neu['online_timeout'] = preg_match('/^(-1|[0-9]+)$/', trim($to)) ? trim($to) : '-1';

    // Miniserver-IP aus der Auswahl; leere Auswahl laesst den alten Wert stehen.
    $ms = (string) ($_POST['ms'] ?? '');
    $mslist = wi_miniservers();
    if ($ms !== '' && isset($mslist[$ms]) && $mslist[$ms]['ip'] !== '') {
        $neu['multicast_ip'] = $mslist[$ms]['ip'];
    }

    if (wi_config_write($neu)) {
        $wi_saved = true;
        $wi_hinweis = ($neu['enable'] === '1')
            ? wi_server('restart')
            : wi_server('stop');
    } else {
        $wi_error = 'Die Konfigurationsdatei konnte nicht geschrieben werden: ' . wi_e($wi_p['config']);
    }
}

$wi_cfg = wi_config_read();
$wi_fw = wi_cfg($wi_cfg, 'fw_version', '1.8');
$wi_dps = wi_datenpunkte($wi_fw);
$wi_geraete = wi_geraete($wi_dps);
$wi_ms = wi_miniservers();
$wi_ms_aktiv = '';
foreach ($wi_ms as $nr => $m) {
    if ($m['ip'] === wi_cfg($wi_cfg, 'multicast_ip', '')) {
        $wi_ms_aktiv = (string) $nr;
    }
}
$wi_pid = wi_server_pid();
$wi_ip = wi_localip();
$wi_udpin = wi_mqtt_udpinport();
$wi_log = wi_log_file('server');
$wi_zeilen = wi_log_tail($wi_log);

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
.wi-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.wi-wrap, .wi-wrap * { text-shadow: none !important; }
.wi-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.wi-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.wi-wrap input[type=text], .wi-wrap input[type=number], .wi-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.wi-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.wi-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.wi-row { display: flex; gap: 12px; flex-wrap: wrap; }
.wi-row > div { flex: 1; min-width: 200px; }
.wi-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.wi-wrap .wi-btn, .wi-wrap a.wi-btn, .wi-wrap button { box-shadow: none !important; }
.wi-wrap a.wi-btn, .wi-wrap a.wi-btn:visited, .wi-wrap a.wi-btn:hover { color: #fff !important; text-decoration: none; }
.wi-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.wi-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.wi-err { background: #ffebee; border: 1px solid #ef9a9a; }
.wi-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.wi-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.wi-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.wi-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.wi-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.wi-tab.wi-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.wi-pane { display: none; padding-top: 4px; }
.wi-pane.wi-active { display: block; }
.wi-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.wi-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.wi-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.wi-tbl th, .wi-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; vertical-align: top; }
.wi-tbl th { background: #f0f0f0; }
.wi-geraete { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 4px 14px; margin: 8px 0 4px; }
.wi-geraete label { font-weight: 400; font-size: 0.9em; color: #333; margin: 2px 0; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.wi-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.wi-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.wi-knopfreihe form { margin: 0; display: flex; }
.wi-knopfreihe .wi-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; margin-top: 0; }
.wi-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.wi-legende span { display: inline-flex; align-items: center; gap: 6px; }
.wi-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.wi-btn.wi-b-lesen   { background: #6dac20; }
.wi-btn.wi-b-technik { background: #546e7a; }
.wi-btn.wi-b-aktion  { background: #e0620d; }
.wi-punkt.wi-b-lesen   { background: #6dac20; }
.wi-punkt.wi-b-technik { background: #546e7a; }
.wi-punkt.wi-b-aktion  { background: #e0620d; }
</style>
<div class="wi-wrap">

<?php if ($wi_saved) { ?>
<div class="wi-alert wi-ok"><b>Gespeichert.</b>
<?= $wi_hinweis !== '' ? ' ' . wi_e(trim($wi_hinweis)) : '' ?></div>
<?php } ?>
<?php if ($wi_error !== '') { ?><div class="wi-alert wi-err"><b>Fehler:</b> <?= $wi_error ?></div><?php } ?>

<div class="wi-alert wi-info">
Server: <b><?= $wi_pid ? 'l&auml;uft' : 'l&auml;uft nicht' ?></b><?= $wi_pid ? ' (PID ' . $wi_pid . ') ' : ' ' ?>
&middot; Firmware <b><?= wi_e($wi_fw) ?></b> mit <b><?= count($wi_dps) ?></b> Datenpunkten
&middot; Weg zu Loxone: <b><?= wi_cfg($wi_cfg, 'mqtt', '0') === '1' ? 'MQTT' : '&ndash;' ?><?= wi_cfg($wi_cfg, 'output', 'none') === 'data' ? ' + TCP/UDP' : '' ?></b>
&middot; LoxBerry: <span class="wi-mono"><?= wi_e($wi_ip) ?></span>
</div>

<div class="wi-tabs">
    <div class="wi-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="wi-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="wi-tab" data-pane="tab-test">Test</div>
    <div class="wi-tab" data-pane="tab-log">Logdateien</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="wi-pane" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Server</h2>
<label class="wi-check"><input data-role="none" type="checkbox" name="enable" value="1"<?= wi_cfg($wi_cfg, 'enable', '0') === '1' ? ' checked' : '' ?>> Server einschalten</label>
<div class="wi-small">Startet den Auswertungsdienst beim Speichern und bei jedem Systemstart.</div>

<div class="wi-row">
<div>
<label>Firmware des ISM8</label>
<select data-role="none" name="fw_version">
<?php foreach (array('1.4', '1.5', '1.7', '1.8', '1.9') as $v) { ?>
<option value="<?= $v ?>"<?= $wi_fw === $v ? ' selected' : '' ?>><?= $v ?></option>
<?php } ?>
</select>
<div class="wi-small">Steht in der Weboberfl&auml;che des ISM8. Bestimmt, welche Datenpunkttabelle gilt.</div>
</div>
<div>
<label>ISM8 TCP-Port</label>
<input data-role="none" type="number" name="ism8i_port" min="1" max="65535" value="<?= wi_e(wi_cfg($wi_cfg, 'ism8i_port', '12004')) ?>">
<div class="wi-small">Der Port, an den das ISM8 seine Daten schickt. Im ISM8 als Ziel eintragen: <span class="wi-mono"><?= wi_e($wi_ip) ?></span></div>
</div>
<div>
<label>Eingangs-TCP-Port</label>
<input data-role="none" type="number" name="input_port" min="1" max="65535" value="<?= wi_e(wi_cfg($wi_cfg, 'input_port', '12005')) ?>">
<div class="wi-small">Hier nimmt das Plugin Schreibbefehle des Miniservers entgegen.</div>
</div>
</div>

<h2>Weg zum Miniserver</h2>
<label class="wi-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= wi_cfg($wi_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <b>MQTT</b> &mdash; empfohlen</label>
<div class="wi-small">Werte gehen an den MQTT-Broker des Gateways. Themen sind benannt, Werte kommen retained an, und der MQTT Finder zeigt, was tats&auml;chlich ankommt.</div>

<label class="wi-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="tcp_udp" value="1"<?= wi_cfg($wi_cfg, 'output', 'none') === 'data' ? ' checked' : '' ?>> TCP/UDP-Direktausgabe</label>
<div class="wi-small">Der alte Weg: Werte gehen als UDP-Datagramme an die Multicast-Gruppe. Nur einschalten, wenn eine bestehende Loxone-Konfiguration daran h&auml;ngt.</div>

<div class="wi-row" style="margin-top:12px;">
<div>
<label>Miniserver</label>
<select data-role="none" name="ms">
<option value="">&ndash; unver&auml;ndert &ndash;</option>
<?php foreach ($wi_ms as $nr => $m) { ?>
<option value="<?= wi_e($nr) ?>"<?= (string) $nr === $wi_ms_aktiv ? ' selected' : '' ?>><?= wi_e($m['name']) ?> (<?= wi_e($m['ip']) ?>)</option>
<?php } ?>
</select>
<div class="wi-small">Ziel der UDP-Direktausgabe. Aktuell: <span class="wi-mono"><?= wi_e(wi_cfg($wi_cfg, 'multicast_ip', '239.7.7.77')) ?></span></div>
</div>
<div>
<label>UDP-Port am Miniserver</label>
<input data-role="none" type="number" name="multicast_port" min="1" max="65535" value="<?= wi_e(wi_cfg($wi_cfg, 'multicast_port', '35353')) ?>">
</div>
</div>

<h2>Weitere Einstellungen</h2>
<label class="wi-check"><input data-role="none" type="checkbox" name="pull_on_write" value="1"<?= wi_cfg($wi_cfg, 'pull_on_write', '0') === '1' ? ' checked' : '' ?>> Nach jedem Schreibbefehl aktiv nachfragen</label>
<div class="wi-small">Das ISM8 meldet ge&auml;nderte Werte nicht immer von selbst zur&uuml;ck. Mit dieser Option fragt das Plugin nach jedem Schreiben nach.</div>

<label class="wi-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="dp_log" value="1"<?= wi_cfg($wi_cfg, 'dp_log', '0') === '1' ? ' checked' : '' ?>> Alle empfangenen Werte protokollieren</label>
<div class="wi-small">Nur zur Fehlersuche &mdash; die Datei w&auml;chst schnell. Das Protokoll liegt auf der RAM-Platte.</div>

<label style="margin-top:12px;">Zeitgrenze &bdquo;online&ldquo; in Sekunden</label>
<input data-role="none" type="text" name="online_timeout" value="<?= wi_e(wi_cfg($wi_cfg, 'online_timeout', '-1')) ?>" style="max-width:220px;">
<div class="wi-small">Kommt in dieser Zeit kein Wert vom ISM8, gilt es als offline. <span class="wi-mono">-1</span> schaltet die Pr&uuml;fung ab.</div>

<button data-role="none" class="wi-btn" type="submit" name="save" value="1">Speichern</button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="wi-pane" id="tab-loxone">

<h2>So kommen die Werte in den Miniserver</h2>

<div class="wi-step"><b>1. ISM8 einstellen.</b> In der Weboberfl&auml;che des ISM8 als Ziel
<span class="wi-mono"><?= wi_e($wi_ip) ?></span> und Port
<span class="wi-mono"><?= wi_e(wi_cfg($wi_cfg, 'ism8i_port', '12004')) ?></span> eintragen. Danach beginnt das ISM8 zu senden.</div>

<div class="wi-step"><b>2. Server einschalten</b> im Reiter Einstellungen und speichern.</div>

<div class="wi-step"><b>3. Ger&auml;te ausw&auml;hlen</b> und die passende Vorlage herunterladen (unten).
Es werden nur Datenpunkte der angehakten Ger&auml;te aufgenommen &mdash; die vollst&auml;ndige Tabelle
h&auml;tte <?= count($wi_dps) ?> Eintr&auml;ge und w&auml;re in Loxone Config unhandlich.</div>

<div class="wi-step"><b>4. In Loxone Config einlesen:</b> Rechtsklick auf den Miniserver &rarr;
<i>Vorlage einf&uuml;gen</i> &rarr; heruntergeladene Datei w&auml;hlen.</div>

<h2>MQTT &mdash; der empfohlene Weg</h2>
<div class="wi-small" style="margin-bottom:8px;">
Die Themen folgen dem Muster <span class="wi-mono">wolfism8/&lt;Ger&auml;t&gt;/&lt;Datenpunkt&gt;</span>.
Zus&auml;tzlich meldet <span class="wi-mono">wolfism8/online</span> den Zustand der Verbindung.
Broker: <span class="wi-mono"><?= $wi_ms_broker = wi_mqtt_broker(); echo $wi_ms_broker !== '' ? wi_e($wi_ms_broker) : 'MQTT-Gateway nicht gefunden' ?></span><?php
if ($wi_udpin) { ?> &middot; UDP-Relay des Gateways: <span class="wi-mono">Port <?= (int) $wi_udpin ?></span><?php } ?>
</div>

<?php if (wi_cfg($wi_cfg, 'mqtt', '0') !== '1') { ?>
<div class="wi-alert wi-err">MQTT ist im Reiter Einstellungen ausgeschaltet &mdash; die MQTT-Vorlagen liefern dann keine Werte.</div>
<?php } ?>
<?php if (!$wi_udpin) { ?>
<div class="wi-alert wi-info">F&uuml;r die <b>MQTT-Ausg&auml;nge</b> wird der UDP-Eingangsport des MQTT-Gateways gebraucht.
Er ist derzeit nicht auffindbar; die Vorlage tr&auml;gt dann Port 0 ein. Im Gateway unter <i>UDP In</i> einen Port setzen und die Vorlage neu erzeugen.</div>
<?php } ?>

<h2>Ger&auml;te ausw&auml;hlen</h2>
<div class="wi-small">Datenpunkttabelle der Firmware <b><?= wi_e($wi_fw) ?></b>:
<?= count($wi_dps) ?> Datenpunkte, davon <?= $wi_anz_out ?> lesbar und <?= $wi_anz_in ?> beschreibbar.</div>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="wi-geraete">
<?php foreach ($wi_geraete as $i => $g) { ?>
<label><input data-role="none" type="checkbox" name="geraete[]" value="<?= wi_e($g) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= wi_e($g) ?></label>
<?php } ?>
</div>
<div class="wi-small">Ohne Auswahl w&auml;re die Vorlage leer &mdash; mindestens ein Ger&auml;t anhaken.</div>

<div class="wi-legende">
<span><i class="wi-punkt wi-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="wi-punkt wi-b-aktion"></i> L&ouml;st etwas aus &mdash; erzeugt eine Datei</span>
</div>

<h3 class="wi-h3">MQTT (empfohlen)</h3>
<div class="wi-knopfreihe">
<button data-role="none" class="wi-btn wi-b-aktion" type="submit" name="download" value="mqtt_in">Vorlage: MQTT-Eing&auml;nge</button>
<button data-role="none" class="wi-btn wi-b-aktion" type="submit" name="download" value="mqtt_out">Vorlage: MQTT-Ausg&auml;nge</button>
</div>
<div class="wi-small">Eing&auml;nge = Werte vom Wolf-Ger&auml;t zum Miniserver. Ausg&auml;nge = Befehle vom Miniserver zum Wolf-Ger&auml;t.</div>

<h3 class="wi-h3">TCP/UDP (alter Weg)</h3>
<div class="wi-knopfreihe">
<button data-role="none" class="wi-btn wi-b-aktion" type="submit" name="download" value="udp_in">Vorlage: UDP-Eing&auml;nge</button>
<button data-role="none" class="wi-btn wi-b-aktion" type="submit" name="download" value="tcp_out">Vorlage: TCP-Ausg&auml;nge</button>
</div>
<div class="wi-small">Nur n&ouml;tig, wenn die TCP/UDP-Direktausgabe eingeschaltet ist.</div>
</form>

<h2>Datenpunkte dieser Firmware</h2>
<div class="wi-small">Die ersten 40 Zeilen zur Orientierung. Die Spalte Richtung sagt, ob ein Wert nur gelesen (<span class="wi-mono">Out</span>) oder auch geschrieben werden kann (<span class="wi-mono">In</span>).</div>
<table class="wi-tbl">
<tr><th style="width:52px;">ID</th><th>Ger&auml;t</th><th>Datenpunkt</th><th style="width:80px;">Richtung</th><th>MQTT-Thema</th></tr>
<?php foreach (array_slice($wi_dps, 0, 40) as $d) { ?>
<tr><td><?= sprintf('%03d', $d['id']) ?></td><td><?= wi_e($d['geraet']) ?></td><td><?= wi_e($d['name']) ?><?= $d['einheit'] !== '-' ? ' <span class="wi-small">(' . wi_e($d['einheit']) . ')</span>' : '' ?></td><td><?= wi_e($d['io']) ?></td><td><span class="wi-mono" style="font-size:0.85em;"><?= wi_e(wi_topic($d)) ?></span></td></tr>
<?php } ?>
</table>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="wi-pane" id="tab-test">

<div class="wi-legende">
<span><i class="wi-punkt wi-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="wi-punkt wi-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="wi-punkt wi-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert</span>
</div>

<h3 class="wi-h3">Ansehen</h3>
<div class="wi-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-lesen" type="submit" name="test" value="status">Serverzustand</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-lesen" type="submit" name="test" value="werte">Zuletzt empfangene Werte</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-lesen" type="submit" name="test" value="themen">MQTT-Themen dieser Firmware</button></form>
</div>

<h3 class="wi-h3">Technische Auskunft</h3>
<div class="wi-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-technik" type="submit" name="test" value="konfig">Konfiguration anzeigen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-technik" type="submit" name="test" value="ports">Netzwerkports pr&uuml;fen</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-technik" type="submit" name="test" value="umgebung">Umgebung und Perl-Module</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-technik" type="submit" name="test" value="mqttinfo">MQTT-Gateway</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-technik" type="submit" name="test" value="comtest">Multicast 20 s mitlesen</button></form>
</div>

<h3 class="wi-h3">L&ouml;st etwas aus</h3>
<div class="wi-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-aktion" type="submit" name="test" value="restart">Server neu starten</button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="wi-btn wi-b-aktion" type="submit" name="test" value="stop">Server anhalten</button></form>
</div>

<?php if ($wi_test_titel !== '') { ?>
<h2><?= wi_e($wi_test_titel) ?></h2>
<div class="wi-log"><?= wi_e($wi_test_text) ?></div>
<?php } else { ?>
<div class="wi-alert wi-info" style="margin-top:18px;">Noch nichts abgefragt. Die Ausgabe erscheint hier.</div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="wi-pane" id="tab-log">
<h2>Protokoll des Servers</h2>
<div class="wi-small">
<?php if ($wi_log !== '') { ?>
Datei: <span class="wi-mono"><?= wi_e($wi_log) ?></span> &middot; neueste Zeile zuerst
<?php } else { ?>
Noch keine Protokolldatei vorhanden. Sie entsteht, sobald der Server das erste Mal l&auml;uft.
<?php } ?>
</div>
<?php if ($wi_zeilen) { ?>
<div class="wi-log"><?php foreach ($wi_zeilen as $z) { echo wi_e($z) . "\n"; } ?></div>
<?php } ?>

<?php
$wi_dplog = wi_log_file('datapoints');
if ($wi_dplog === '') { $wi_dplog = wi_log_file('wolf'); }
if ($wi_dplog !== '' && $wi_dplog !== $wi_log) {
    $wi_dpz = wi_log_tail($wi_dplog, 200);
?>
<h2>Datenpunkt-Protokoll</h2>
<div class="wi-small">Datei: <span class="wi-mono"><?= wi_e($wi_dplog) ?></span></div>
<div class="wi-log"><?php foreach ($wi_dpz as $z) { echo wi_e($z) . "\n"; } ?></div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.wi-tab');
    var start = <?= json_encode($wi_tab) ?>;
    function zeige(id) {
        var i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('wi-active', tabs[i].getAttribute('data-pane') === id);
        }
        var panes = document.querySelectorAll('.wi-pane');
        for (i = 0; i < panes.length; i++) {
            panes[i].classList.toggle('wi-active', panes[i].id === id);
        }
    }
    for (var i = 0; i < tabs.length; i++) {
        (function (t) {
            t.addEventListener('click', function () { zeige(t.getAttribute('data-pane')); });
        })(tabs[i]);
    }
    zeige(start);
})();
</script>
<?php
if ($wi_frame) {
    LBWeb::lbfooter();
}
