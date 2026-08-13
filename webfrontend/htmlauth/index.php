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
// Der Reiter kommt aus einem abgesendeten Formular (activetab) oder aus der
// Adresse (?tab=...). Letzteres brauchen die Reiter, seit sie echte Verweise
// sind - siehe die Reiterleiste weiter unten.
$wi_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['tab']) ? 'tab-' . (string) $_GET['tab'] : '');
$wi_tab = preg_match('/^tab-(settings|loxone|test|log)$/', $wi_wunsch)
    ? $wi_wunsch : 'tab-settings';

/* ============ Loxone-Vorlage herunterladen ============ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $art = (string) $_POST['download'];
    $geraete = isset($_POST['geraete']) && is_array($_POST['geraete']) ? $_POST['geraete'] : array();
    if (!$geraete) {
        $wi_error = wi_t('MELDUNG.KEIN_GERAET');
        $wi_tab = 'tab-loxone';
    } else {
        list($name, $inhalt) = wi_vorlage($art, wi_config_read(), $geraete);
        if ($name === '') {
            $wi_error = wi_t('MELDUNG.UNBEKANNTE_ART');
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
        $wi_error = sprintf(wi_t('MELDUNG.SCHREIBFEHLER'), wi_e($wi_p['config']));
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
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-check { font-weight: 400 !important; font-size: 0.95em !important; color: #333 !important; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 200px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
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
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($wi_saved) { ?>
<div class="sm-alert sm-ok"><b><?= wi_t('MELDUNG.GESPEICHERT') ?></b>
<?= $wi_hinweis !== '' ? ' ' . wi_e(trim($wi_hinweis)) : '' ?></div>
<?php } ?>
<?php if ($wi_error !== '') { ?><div class="sm-alert sm-err"><b><?= wi_t('MELDUNG.FEHLER') ?></b> <?= $wi_error ?></div><?php } ?>

<div class="sm-alert sm-info">
<?= wi_t('KOPF.SERVER') ?> <b><?= $wi_pid ? wi_t('KOPF.LAEUFT') : wi_t('KOPF.LAEUFT_NICHT') ?></b><?= $wi_pid ? ' (PID ' . $wi_pid . ') ' : ' ' ?>
&middot; <?= sprintf(wi_t('KOPF.FIRMWARE'), wi_e($wi_fw), count($wi_dps)) ?>
&middot; <?= wi_t('KOPF.WEG') ?> <b><?= wi_cfg($wi_cfg, 'mqtt', '0') === '1' ? 'MQTT' : '&ndash;' ?><?= wi_cfg($wi_cfg, 'output', 'none') === 'data' ? ' + TCP/UDP' : '' ?></b>
&middot; <?= wi_t('KOPF.LOXBERRY') ?> <span class="sm-mono"><?= wi_e($wi_ip) ?></span>
</div>

<?php
/*
 * Die Reiter sind echte Verweise, keine <div>. Vorher stand hier
 * <div class="sm-tab" data-pane="..."> - und weil alle Flaechen bis zum Lauf
 * des JavaScripts auf display:none stehen, war die Seite ohne JavaScript
 * vollstaendig leer. Jetzt setzt der Server die Klasse sm-active an Reiter
 * UND Flaeche; das JavaScript spart nur noch den Seitenaufbau.
 */
$wi_reiter = array(
    'tab-settings' => wi_t('REITER.EINSTELLUNGEN'),
    'tab-loxone'   => wi_t('REITER.LOXONE'),
    'tab-test'     => wi_t('REITER.TEST'),
    'tab-log'      => wi_t('REITER.LOG'),
);
?>
<div class="sm-tabs">
<?php foreach ($wi_reiter as $wi_id => $wi_bez) { ?>
    <a class="sm-tab<?php echo $wi_tab === $wi_id ? ' sm-active' : ''; ?>"
       data-pane="<?php echo wi_e($wi_id); ?>"
       href="index.php?tab=<?php echo wi_e(substr($wi_id, 4)); ?>"><?php echo $wi_bez; ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

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
<label class="sm-check"><input data-role="none" type="checkbox" name="mqtt" value="1"<?= wi_cfg($wi_cfg, 'mqtt', '1') === '1' ? ' checked' : '' ?>> <?= wi_t('EINST.MQTT') ?></label>
<div class="sm-small"><?= wi_t('EINST.MQTT_HINT') ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="tcp_udp" value="1"<?= wi_cfg($wi_cfg, 'output', 'none') === 'data' ? ' checked' : '' ?>> <?= wi_t('EINST.TCPUDP') ?></label>
<div class="sm-small"><?= wi_t('EINST.TCPUDP_HINT') ?></div>

<div class="sm-row" style="margin-top:12px;">
<div>
<label><?= wi_t('EINST.MINISERVER') ?></label>
<select data-role="none" name="ms">
<option value=""><?= wi_t('EINST.UNVERAENDERT') ?></option>
<?php foreach ($wi_ms as $nr => $m) { ?>
<option value="<?= wi_e($nr) ?>"<?= (string) $nr === $wi_ms_aktiv ? ' selected' : '' ?>><?= wi_e($m['name']) ?> (<?= wi_e($m['ip']) ?>)</option>
<?php } ?>
</select>
<div class="sm-small"><?= sprintf(wi_t('EINST.MINISERVER_HINT'), '<span class="sm-mono">' . wi_e(wi_cfg($wi_cfg, 'multicast_ip', '239.7.7.77')) . '</span>') ?></div>
</div>
<div>
<label><?= wi_t('EINST.UDP_PORT') ?></label>
<input data-role="none" type="number" name="multicast_port" min="1" max="65535" value="<?= wi_e(wi_cfg($wi_cfg, 'multicast_port', '35353')) ?>">
</div>
</div>

<h2><?= wi_t('EINST.H_WEITERE') ?></h2>
<label class="sm-check"><input data-role="none" type="checkbox" name="pull_on_write" value="1"<?= wi_cfg($wi_cfg, 'pull_on_write', '0') === '1' ? ' checked' : '' ?>> <?= wi_t('EINST.PULL') ?></label>
<div class="sm-small"><?= wi_t('EINST.PULL_HINT') ?></div>

<label class="sm-check" style="margin-top:10px;"><input data-role="none" type="checkbox" name="dp_log" value="1"<?= wi_cfg($wi_cfg, 'dp_log', '0') === '1' ? ' checked' : '' ?>> <?= wi_t('EINST.DPLOG') ?></label>
<div class="sm-small"><?= wi_t('EINST.DPLOG_HINT') ?></div>

<label style="margin-top:12px;"><?= wi_t('EINST.TIMEOUT') ?></label>
<input data-role="none" type="text" name="online_timeout" value="<?= wi_e(wi_cfg($wi_cfg, 'online_timeout', '-1')) ?>" style="max-width:220px;">
<div class="sm-small"><?= sprintf(wi_t('EINST.TIMEOUT_HINT'), '<span class="sm-mono">-1</span>') ?></div>

<button data-role="none" class="sm-btn" type="submit" name="save" value="1"><?= wi_t('EINST.SPEICHERN') ?></button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?= wi_t('LOXONE.H_WEG') ?></h2>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S1'),
    '<span class="sm-mono">' . wi_e($wi_ip) . '</span>',
    '<span class="sm-mono">' . wi_e(wi_cfg($wi_cfg, 'ism8i_port', '12004')) . '</span>') ?></div>

<div class="sm-step"><?= wi_t('LOXONE.S2') ?></div>

<div class="sm-step"><?= sprintf(wi_t('LOXONE.S3'), count($wi_dps)) ?></div>

<div class="sm-step"><?= wi_t('LOXONE.S4') ?></div>

<h2><?= wi_t('LOXONE.H_MQTT') ?></h2>
<?php if (!function_exists('wi_hs_autostart')) { function wi_hs_autostart() { $h = getenv('LBHOMEDIR') ?: '/opt/loxberry'; $g = $h . '/config/system/general.json'; if (!is_file($g)) { return null; } $j = json_decode((string) @file_get_contents($g), true); if (!is_array($j) || !isset($j['Mqtt'])) { return null; } return !empty($j['Mqtt']['Gatewayautostart']); } } if (wi_hs_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo wi_t('LOXONE.W_AUTOSTART'); ?></div><?php } ?>
<div class="sm-small" style="margin-bottom:8px;">
<?= sprintf(wi_t('LOXONE.MQTT_THEMEN'), '<span class="sm-mono">wolf_ng/&lt;' . wi_t('LOXONE.TH_GERAET') . '&gt;/&lt;' . wi_t('LOXONE.TH_DP') . '&gt;</span>') ?>
<?= sprintf(wi_t('LOXONE.MQTT_ONLINE'), '<span class="sm-mono">wolf_ng/online</span>') ?>
<?php $wi_ms_broker = wi_mqtt_broker(); ?>
<?= sprintf(wi_t('LOXONE.BROKER'), '<span class="sm-mono">'
    . ($wi_ms_broker !== '' ? wi_e($wi_ms_broker) : wi_t('LOXONE.KEIN_BROKER')) . '</span>') ?><?php
if ($wi_udpin) { ?> &middot; <?= sprintf(wi_t('LOXONE.UDPRELAY'), '<span class="sm-mono">' . (int) $wi_udpin . '</span>') ?><?php } ?>
</div>

<?php if (wi_cfg($wi_cfg, 'mqtt', '0') !== '1') { ?>
<div class="sm-alert sm-err"><?= wi_t('LOXONE.MQTT_AUS') ?></div>
<?php } ?>
<?php if (!$wi_udpin) { ?>
<div class="sm-alert sm-info"><?= wi_t('LOXONE.KEIN_UDPIN') ?></div>
<?php } ?>

<h2><?= wi_t('LOXONE.H_GERAETE') ?></h2>
<div class="sm-small"><?= sprintf(wi_t('LOXONE.TABELLE'), wi_e($wi_fw), count($wi_dps), $wi_anz_out, $wi_anz_in) ?></div>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-geraete">
<?php foreach ($wi_geraete as $i => $g) { ?>
<label><input data-role="none" type="checkbox" name="geraete[]" value="<?= wi_e($g) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= wi_e($g) ?></label>
<?php } ?>
</div>
<div class="sm-small"><?= wi_t('LOXONE.OHNE_AUSWAHL') ?></div>

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

<h2><?= wi_t('LOXONE.H_DP') ?></h2>
<div class="sm-small"><?= sprintf(wi_t('LOXONE.DP_HINT'), '<span class="sm-mono">Out</span>', '<span class="sm-mono">In</span>') ?></div>
<table class="sm-tbl">
<tr><th style="width:52px;"><?= wi_t('LOXONE.TH_ID') ?></th><th><?= wi_t('LOXONE.TH_GERAET') ?></th><th><?= wi_t('LOXONE.TH_DP') ?></th><th style="width:80px;"><?= wi_t('LOXONE.TH_RICHTUNG') ?></th><th><?= wi_t('LOXONE.TH_THEMA') ?></th></tr>
<?php foreach (array_slice($wi_dps, 0, 40) as $d) { ?>
<tr><td><?= sprintf('%03d', $d['id']) ?></td><td><?= wi_e($d['geraet']) ?></td><td><?= wi_e($d['name']) ?><?= $d['einheit'] !== '-' ? ' <span class="sm-small">(' . wi_e($d['einheit']) . ')</span>' : '' ?></td><td><?= wi_e($d['io']) ?> <span class="sm-small">(<?= wi_e(wi_io_text($d['io'])) ?>)</span></td><td><span class="sm-mono" style="font-size:0.85em;"><?= wi_e(wi_topic($d)) ?></span></td></tr>
<?php } ?>
</table>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= wi_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= wi_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= wi_t('LEGENDE.AKTION') ?></span>
</div>

<h3 class="sm-h3"><?= wi_t('TEST.H_ANSEHEN') ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?= wi_t('TEST.STATUS') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="werte"><?= wi_t('TEST.WERTE') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="themen"><?= wi_t('TEST.THEMEN') ?></button></form>
</div>

<h3 class="sm-h3"><?= wi_t('TEST.H_TECHNIK') ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konfig"><?= wi_t('TEST.KONFIG') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="ports"><?= wi_t('TEST.PORTS') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="umgebung"><?= wi_t('TEST.UMGEBUNG') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="mqttinfo"><?= wi_t('TEST.MQTTINFO') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="comtest"><?= wi_t('TEST.COMTEST') ?></button></form>
</div>

<h3 class="sm-h3"><?= wi_t('TEST.H_AKTION') ?></h3>
<div class="sm-knopfreihe">
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="restart"><?= wi_t('TEST.RESTART') ?></button></form>
<form method="post" action="index.php"><input data-role="none" type="hidden" name="activetab" value="tab-test"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="stop"><?= wi_t('TEST.STOP') ?></button></form>
</div>

<?php if ($wi_test_titel !== '') { ?>
<h2><?= wi_e($wi_test_titel) ?></h2>
<div class="sm-log"><?= wi_e($wi_test_text) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info" style="margin-top:18px;"><?= wi_t('TEST.NICHTS') ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-pane<?php echo $wi_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
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
?>
<h2><?= wi_t('LOG.H_DP') ?></h2>
<div class="sm-small"><?= sprintf(wi_t('LOG.DATEI_KURZ'), '<span class="sm-mono">' . wi_e($wi_dplog) . '</span>') ?></div>
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
