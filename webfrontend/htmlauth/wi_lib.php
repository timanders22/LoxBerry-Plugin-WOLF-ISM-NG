<?php
/**
 * Wolf ISM8 - gemeinsame Hilfsfunktionen fuer Oberflaeche und Testseite
 *
 * Die Konfigurationsdatei config/wolf_ism8i.conf bleibt unveraendert im
 * Format "schluessel wert" (durch Leerzeichen getrennt, eine Zeile je
 * Eintrag), damit bin/wolf_ism8i.pl, daemon/daemon und postupgrade.sh ohne
 * Anpassung weiterlesen koennen.
 *
 * WICHTIG zum Dateiformat: wolf_ism8i.pl ueberspringt in loadConfig() jede
 * Zeile, die irgendwo ein "#" enthaelt - auch mitten in der Zeile. Kommentare
 * duerfen also nur auf eigenen Zeilen stehen, niemals hinter einem Wert.
 * Ausserdem wird jede Zeile vor dem Auswerten kleingeschrieben; Werte muessen
 * daher kleinschreibungsfest sein.
 *
 * Eigenes Praefix "wi_", weil LBWeb::lbheader() SDK-Globale setzt und sonst
 * Namen kollidieren.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('wi_e')) {
    function wi_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/** Basisverzeichnisse ermitteln - funktioniert installiert wie im Archiv. */
/* ==================================================================
 * Sprache
 *
 * Bis 2.5.0 gab es keine: die Oberflaeche schrieb ihre Texte unmittelbar
 * auf Deutsch ins HTML, und templates/lang/ war ein LEERER Ordner - das
 * Geruest fuer Sprachdateien stand da, gefuellt hat es niemand.
 *
 * Seit 2.5.1 geht jeder sichtbare Text durch wi_t(). Englisch ist die
 * Rueckfallebene: fehlt ein Schluessel in der gewaehlten Sprache, wird der
 * englische genommen; fehlt auch der, kommt der Schluesselname selbst
 * heraus - Absicht, denn eine leere Seite verschweigt den Fehler, ein
 * sichtbares "EINST.L_PORT" nicht.
 * ================================================================== */

function wi_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/** Text zu einem Schluessel 'ABSCHNITT.SCHLUESSEL'. */
function wi_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = wi_paths();
        $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . wi_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}

function wi_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home && is_dir('/opt/loxberry')) {
        $home = '/opt/loxberry';
    }
    /* LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und hat Vorrang.
     *
     * Die frueheren Rueckfaelle trafen beide daneben: Installiert liegt diese
     * Datei unter webfrontend/htmlauth/plugins/<ordner>/, also ergab
     * basename(dirname(dirname(__DIR__))) den Wert "htmlauth" und
     * basename(dirname(__DIR__)) den Wert "plugins" - nie einen Plugin-Ordner.
     * Uebrig blieb immer der feste Name; eine Zweitinstallation (wolf_ng_01)
     * haette damit die Konfiguration der ersten benutzt.
     *
     * Jetzt wird der Ordner aus dem eigenen Ablageort genommen; der feste
     * Name greift nur, wo der ermittelte nachweislich keiner sein kann. */
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) {
        $dir = basename(__DIR__);
    }
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'htmlauth' || $dir === 'plugins') {
        $dir = 'wolf_ng';
    }
    if ($home) {
        $p = array(
            'home'   => $home,
            'plugin' => $dir,
            'config' => $home . '/config/plugins/' . $dir . '/wolf_ism8i.conf',
            'bindir' => $home . '/bin/plugins/' . $dir,
            'logdir' => $home . '/log/plugins/' . $dir,
        );
    } else {
        $base = dirname(dirname(__DIR__));
        $p = array(
            'home'   => '',
            'plugin' => $dir,
            'config' => $base . '/config/wolf_ism8i.conf',
            'bindir' => $base . '/bin',
            'logdir' => sys_get_temp_dir(),
        );
    }
    return $p;
}

/** Voreinstellungen. MQTT ein, TCP/UDP-Direktausgabe aus (Hausstandard). */
function wi_defaults()
{
    return array(
        'enable'         => '0',
        'ism8i_port'     => '12004',
        'input_port'     => '12005',
        'fw_version'     => '1.8',
        'multicast_ip'   => '239.7.7.77',
        'multicast_port' => '35353',
        'dp_log'         => '0',
        'output'         => 'none',
        'mqtt'           => '1',
        'pull_on_write'  => '0',
        'online_timeout' => '-1',
    );
}

/** Konfiguration lesen. Format: "schluessel wert" je Zeile, # = Kommentarzeile. */
function wi_config_read()
{
    $out = wi_defaults();
    $file = wi_paths()['config'];
    if (!is_file($file)) {
        return $out;
    }
    foreach (preg_split('/\R/', (string) @file_get_contents($file)) as $line) {
        if (strpos($line, '#') !== false) {
            continue;   // genau wie loadConfig() in wolf_ism8i.pl
        }
        $t = trim($line);
        if ($t === '') {
            continue;
        }
        $f = preg_split('/\s+/', $t);
        if (count($f) !== 2) {
            continue;
        }
        $out[strtolower($f[0])] = $f[1];
    }
    return $out;
}

/** Wert lesen, mit Vorgabe. */
function wi_cfg($cfg, $key, $default = '')
{
    return isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default;
}

/**
 * Konfiguration schreiben. Erzeugt genau das Format, das wolf_ism8i.pl selbst
 * anlegt: Kommentarblock, dann je Zeile "schluessel wert".
 */
function wi_config_write($cfg)
{
    $file = wi_paths()['config'];
    @mkdir(dirname($file), 0775, true);

    $kopf = array(
        '######################################################################',
        '# Konfiguration des Wolf-ISM8-Servers.',
        '# Geschrieben von der Plugin-Oberflaeche. Je Zeile ein Eintrag,',
        '# Schluessel und Wert durch ein Leerzeichen getrennt.',
        '# Zeilen, die MIT einem Doppelkreuz beginnen, uebergeht der Server.',
        '# Seit 2.5.2 gilt das nur noch fuer den Zeilenanfang; vorher fiel',
        '# jede Zeile weg, in der irgendwo ein Doppelkreuz stand.',
        '######################################################################',
        '',
    );
    $txt = implode("\n", $kopf) . "\n";
    foreach (wi_defaults() as $k => $vorgabe) {
        $v = isset($cfg[$k]) && $cfg[$k] !== '' ? $cfg[$k] : $vorgabe;
        $txt .= $k . ' ' . $v . "\n";
    }
    /* Erst daneben schreiben, dann umbenennen.
     *
     * Ein einfaches file_put_contents kuerzt die Datei und fuellt sie neu.
     * In dieses Fenster kann der Serverprozess wolf_ism8i.pl fallen: er
     * liest dieselbe Datei beim Start und nach jeder Aenderung. Er bekaeme
     * eine halbe oder leere Konfiguration und faellt dann auf lauter
     * Vorgabewerte zurueck - andere Ports, andere Firmware-Fassung.
     * rename() ist im selben Dateisystem unteilbar. */
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $txt, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Die Spalte Out/In als lesbaren Text.
 *
 * In der CSV steht "Out/-", "-/In" oder "Out/In". Das ist die Schreibweise
 * des Herstellers und bleibt deshalb in der Tabelle stehen - daneben aber
 * ein Wort, das man ohne Handbuch versteht. Die Beanstandung, die
 * Schreibbarkeit sei nur farblich dargestellt, trifft nicht zu: sie stand
 * schon immer als Text da. Verstaendlich war der Text nur nicht.
 */
function wi_io_text($io)
{
    $io = (string) $io;
    $out = strpos($io, 'Out') !== false;
    $in  = strpos($io, 'In') !== false;
    if ($out && $in) { return wi_t('DP.IO_BEIDES'); }
    if ($in)         { return wi_t('DP.IO_SCHREIBEN'); }
    if ($out)        { return wi_t('DP.IO_LESEN'); }
    return wi_t('DP.IO_UNBEKANNT');
}

/**
 * Datenpunkte einer Firmware laden.
 * Spalten: ID; Geraet; Datenpunkt; KNX-Datenpunkttyp; Out/In; Einheit
 * Rueckgabe: Liste von Feldern, Zeilen mit abweichender Spaltenzahl entfallen.
 */
function wi_datenpunkte($fw)
{
    $datei = wi_paths()['bindir'] . '/wolf_datenpunkte_' . str_replace('.', '', (string) $fw) . '.csv';
    if (!is_file($datei)) {
        // im Archiv (nicht installiert) liegen die CSV neben dem Plugin
        $datei = dirname(dirname(__DIR__)) . '/bin/wolf_datenpunkte_' . str_replace('.', '', (string) $fw) . '.csv';
    }
    $out = array();
    if (!is_file($datei)) {
        return $out;
    }
    foreach (preg_split('/\R/', (string) @file_get_contents($datei)) as $line) {
        $line = rtrim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $f = explode(';', $line);
        if (count($f) !== 6) {
            continue;   // Schutz vor Luecken in der Tabelle
        }
        $id = (int) $f[0];
        if ($id <= 0) {
            continue;
        }
        $out[] = array(
            'id'     => $id,
            'geraet' => trim($f[1]),
            'name'   => trim($f[2]),
            'dpt'    => trim($f[3]),
            'io'     => trim($f[4]),
            'einheit' => trim($f[5]),
        );
    }
    return $out;
}

/** Geraeteliste einer Firmware, Reihenfolge wie in der CSV. */
function wi_geraete($dps)
{
    $out = array();
    foreach ($dps as $d) {
        if (!in_array($d['geraet'], $out, true)) {
            $out[] = $d['geraet'];
        }
    }
    return $out;
}

/**
 * Name in ein MQTT-taugliches Thema umformen.
 * Gleiche Ersetzung wie getMQTTFriendly() in wolf_ism8i.pl, damit die
 * erzeugten Loxone-Vorlagen zu den tatsaechlich gesendeten Themen passen.
 */
function wi_mqtt_friendly($s)
{
    $s = (string) $s;
    $s = preg_replace('/\(.*/u', '', $s);   // alles ab einer Klammer faellt weg
    $s = preg_replace('/\+.*/u', '', $s);   // alles ab einem Plus faellt weg
    $s = preg_replace('/\s+$/u', '', $s);
    $s = str_replace('/', '_', $s);
    $s = str_replace(':', '', $s);
    $s = str_replace(' ', '/', $s);
    return $s;
}

/** Vollstaendiges MQTT-Thema eines Datenpunkts. */
function wi_topic($d)
{
    return 'wolf_ng/' . wi_mqtt_friendly($d['geraet']) . '/' . wi_mqtt_friendly($d['name']);
}

/** Lokale IP des LoxBerry. */
function wi_localip()
{
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'get_localip')) {
        $ip = @LBSystem::get_localip();
        if ($ip) {
            return $ip;
        }
    }
    $out = array();
    @exec("hostname -I 2>/dev/null", $out);
    if ($out) {
        $teile = preg_split('/\s+/', trim($out[0]));
        if ($teile && filter_var($teile[0], FILTER_VALIDATE_IP)) {
            return $teile[0];
        }
    }
    return '127.0.0.1';
}

/** Miniserver aus general.json. Rueckgabe: nr => array(Name, IPAddress). */
function wi_miniservers()
{
    $out = array();
    $f = wi_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return $out;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j) || !isset($j['Miniserver']) || !is_array($j['Miniserver'])) {
        return $out;
    }
    foreach ($j['Miniserver'] as $nr => $ms) {
        $out[(string) $nr] = array(
            'name' => isset($ms['Name']) ? $ms['Name'] : ('Miniserver ' . $nr),
            'ip'   => isset($ms['Ipaddress']) ? $ms['Ipaddress']
                    : (isset($ms['IPAddress']) ? $ms['IPAddress'] : ''),
        );
    }
    return $out;
}

/** UDP-Eingangsport des MQTT-Gateways (Relay-Weg). Beide Schreibweisen pruefen. */
function wi_mqtt_udpinport()
{
    $f = wi_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return 0;
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $abschnitt) {
        if (!isset($j[$abschnitt]) || !is_array($j[$abschnitt])) {
            continue;
        }
        foreach (array('Udpinport', 'udpinport') as $schluessel) {
            if (!empty($j[$abschnitt][$schluessel])) {
                return (int) $j[$abschnitt][$schluessel];
            }
        }
    }
    return 0;
}

/** Adresse des MQTT-Brokers, nur zur Anzeige, ohne Kennwort. */
function wi_mqtt_broker()
{
    $f = wi_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return '';
    }
    $j = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($j)) {
        return '';
    }
    foreach (array('Mqtt', 'mqtt') as $a) {
        if (isset($j[$a]['Brokerhost'])) {
            $port = isset($j[$a]['Brokerport']) ? $j[$a]['Brokerport'] : 1883;
            return $j[$a]['Brokerhost'] . ':' . $port;
        }
        if (isset($j[$a]['brokerhost'])) {
            $port = isset($j[$a]['brokerport']) ? $j[$a]['brokerport'] : 1883;
            return $j[$a]['brokerhost'] . ':' . $port;
        }
    }
    return '';
}

/**
 * Gehoert die PID diesem Skript?
 *
 * /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Ein Treffer liegt
 * vor, wenn
 *   - das erste Argument der volle Skriptpfad ist (Start ueber den Shebang),
 *     oder
 *   - das erste Argument ein Interpreter ist UND der volle Pfad unter den
 *     Argumenten steht. Der Watchdog startet das Auswertungsmodul naemlich
 *     als "perl -X <pfad>", der Pfad steht dort erst an dritter Stelle.
 *
 * Die Einschraenkung auf Interpreter ist wichtig: sonst waere auch ein
 * "tail -f <pfad>" oder ein Editor mit offener Datei ein Treffer.
 */
function wi_ist_prozess($pid, $skript)
{
    $roh = @file_get_contents('/proc/' . (int) $pid . '/cmdline');
    if ($roh === false || $roh === '') {
        return false;
    }
    $args = explode("\0", $roh);
    if (isset($args[0]) && $args[0] === $skript) {
        return true;
    }
    $interpreter = array('perl', 'bash', 'sh', 'dash');
    return isset($args[0])
        && in_array(basename($args[0]), $interpreter, true)
        && in_array($skript, $args, true);
}

/** Erste PID, die zu diesem Skript gehoert - 0 wenn keine. */
function wi_pid_von($skript)
{
    foreach ((array) @scandir('/proc') as $e) {
        if (ctype_digit((string) $e) && wi_ist_prozess((int) $e, $skript)) {
            return (int) $e;
        }
    }
    return 0;
}

/**
 * Laeuft der Server? Rueckgabe: PID des Watchdogs oder 0.
 *
 * Bis 2.5.0 stand hier "pgrep -o -f wolf_watchdog". Das durchsucht die ganze
 * Befehlszeile jedes Prozesses und trifft damit auch einen Editor, in dem die
 * Datei offen ist, oder ein zweites Exemplar des Plugins. "ps -C" und
 * "killall" waeren keine Alternative: die vergleichen den comm-Namen, der bei
 * einem Skript mit Shebang "bash" bzw. "perl" lautet - die finden gar nichts.
 */
function wi_server_pid()
{
    return wi_pid_von(wi_paths()['bindir'] . '/wolf_watchdog.sh');
}

/** Laeuft das Auswertungsmodul selbst? */
function wi_ism8i_pid()
{
    return wi_pid_von(wi_paths()['bindir'] . '/wolf_ism8i.pl');
}

/** wolf_server start|stop|restart|status aufrufen. */
function wi_server($aktion)
{
    if (!in_array($aktion, array('start', 'stop', 'restart', 'status'), true)) {
        return '';
    }
    $skript = wi_paths()['bindir'] . '/wolf_server';
    if (!is_file($skript)) {
        return 'wolf_server nicht gefunden: ' . $skript;
    }
    $out = array();
    @exec(escapeshellarg($skript) . ' ' . $aktion . ' 2>&1', $out);
    return implode("\n", $out);
}

/** Logdatei-Kandidaten (LoxBerry legt je nach Version unterschiedlich ab). */
function wi_log_file($name = 'server')
{
    $c = glob(wi_paths()['logdir'] . '/' . $name . '*.log');
    if (!$c) {
        return '';
    }
    usort($c, function ($a, $b) { return filemtime($b) - filemtime($a); });
    return $c[0];
}

/** Die letzten N Zeilen einer Datei, neueste zuerst. */
/**
 * Die letzten $max Zeilen einer Datei, neueste zuerst.
 *
 * Bis 2.5.1 wurde die ganze Datei mit file_get_contents() eingelesen. Mit
 * eingeschalteter Datenpunkt-Protokollierung (dp_log = 1) waechst sie
 * schnell - der Hinweis auf den Speicher war berechtigt.
 *
 * Der vorgeschlagene Weg ueber exec("tail") ist aber der langsamste der
 * drei. An einer Datei an der Rotationsgrenze gemessen, PHP 7.4 und 8.1:
 *
 *   ganz einlesen            rund 0,3 ms   Spitze rund 1,4 MB
 *   exec("tail -n 300")      rund 1,9 ms   Spitze rund  75 kB
 *   rueckwaerts mit fseek    rund 0,05 ms  Spitze rund 125 kB
 *
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat - und er
 * braucht eine Shell, die man wieder absichern muesste.
 */
function wi_log_tail($file, $max = 300, $block = 8192)
{
    if ($file === '' || !is_file($file)) {
        return array();
    }
    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $lines = array();
    while ($pos > 0 && count($lines) <= $max) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $lines = preg_split('/\R/', $puffer);
    }
    fclose($fp);
    $lines = array_values(array_filter(array_map('rtrim', $lines),
        function ($l) { return trim($l) !== ''; }));
    return array_slice(array_reverse($lines), 0, $max);
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der drei Bausteine aus LoxBerry::LoxoneTemplateBuilder
 * (VirtualInUdp, VirtualInHttp, VirtualOut). Das Perl-Modul steht in PHP
 * nicht zur Verfuegung; erzeugt wird deshalb exakt dieselbe Zeichenfolge,
 * einschliesslich Zeilenende CRLF, Tabulator vor den Kindelementen und der
 * Reihenfolge der Attribute.
 *
 * Ein Unterschied ist beabsichtigt: Die Perl-Fassung ruft auf ihre eigene
 * Ausgabe noch einmal decode_entities() auf, macht die Maskierung also
 * wieder rueckgaengig. Enthielte ein Datenpunktname ein & oder ein
 * Anfuehrungszeichen, waere die Datei kaputt. Hier bleibt die Maskierung
 * stehen. In allen fuenf mitgelieferten Datenpunkttabellen kommt kein
 * solches Zeichen vor, die Ausgabe ist daher Byte fuer Byte gleich.
 * ================================================================== */

/** Attributwert maskieren. */
function wi_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Virtueller UDP-Eingang.
 * $cmds: Liste aus array('title' => ..., 'check' => ..., 'comment' => ...)
 */
function wi_xml_virtual_in_udp($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInUdp ';
    $o .= 'Title="' . wi_x($kopf['title']) . '" ';
    $o .= 'Comment="' . wi_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . wi_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'Port="' . wi_x(isset($kopf['port']) ? $kopf['port'] : '') . '" ';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInUdpCmd ';
        $o .= 'Title="' . wi_x($c['title']) . '" ';
        $o .= 'Comment="' . wi_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Address="" ';
        $o .= 'Check="' . wi_x(isset($c['check']) ? $c['check'] : '') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInUdp>' . $crlf;
    return $o;
}

/** Virtueller HTTP-Eingang (dient hier nur als Traeger der MQTT-Themennamen). */
function wi_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . wi_x($kopf['title']) . '" ';
    $o .= 'Comment="' . wi_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . wi_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . wi_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . wi_x($c['title']) . '" ';
        $o .= 'Comment="' . wi_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . wi_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/** Virtueller Ausgang. */
function wi_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'Title="' . wi_x($kopf['title']) . '" ';
    $o .= 'Comment="' . wi_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . wi_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep="" ';
    $o .= '>' . $crlf;
    $id = 0;
    foreach ($cmds as $c) {
        $id++;
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'ID="' . $id . '" ';
        $o .= 'Title="' . wi_x($c['title']) . '" ';
        $o .= 'Comment="' . wi_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="' . strtoupper(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOn="' . wi_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOff="" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'Analog="true" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Die vier Vorlagen erzeugen.
 * $art: udp_in | tcp_out | mqtt_in | mqtt_out
 * $geraete: Liste der ausgewaehlten Geraetenamen
 * Rueckgabe: array(dateiname, inhalt)
 */
function wi_vorlage($art, $cfg, $geraete)
{
    $dps = wi_datenpunkte(wi_cfg($cfg, 'fw_version', '1.8'));
    $ip = wi_localip();
    // Monat ist einstellig gezaehlt korrekt - die alte Perl-Fassung nahm
    // localtime()[4] ohne +1 und schrieb dadurch stets den Vormonat.
    $stempel = date('d.m.Y');
    $fuss = 'Erzeugt vom LoxBerry-Plugin Wolf ISM8 (' . $stempel . ')';

    $gewaehlt = function ($d) use ($geraete) {
        return in_array($d['geraet'], $geraete, true);
    };

    if ($art === 'udp_in') {
        $cmds = array(array('title' => 'Online', 'check' => 'online;\\v'));
        foreach ($dps as $d) {
            if (strpos($d['io'], 'Out') === false || !$gewaehlt($d)) {
                continue;
            }
            $cmds[] = array(
                'title' => $d['geraet'] . ' ' . $d['name'],
                'check' => sprintf('%03d', $d['id']) . ';\\v',
            );
        }
        return array('wolf_udp_input.xml', wi_xml_virtual_in_udp(array(
            'title'   => 'Wolf ISM8',
            'address' => $ip,
            'port'    => wi_cfg($cfg, 'multicast_port', '35353'),
            'comment' => $fuss,
        ), $cmds));
    }

    if ($art === 'tcp_out') {
        $cmds = array();
        foreach ($dps as $d) {
            if (strpos($d['io'], 'In') === false || !$gewaehlt($d)) {
                continue;
            }
            $cmds[] = array(
                'title'  => $d['geraet'] . ' ' . $d['name'],
                'method' => 'get',
                'on'     => sprintf('%03d', $d['id']) . ';\\v',
            );
        }
        return array('wolf_output.xml', wi_xml_virtual_out(array(
            'title'   => 'Wolf ISM8',
            'address' => 'tcp://' . $ip . ':' . wi_cfg($cfg, 'input_port', '12005'),
            'comment' => $fuss,
        ), $cmds));
    }

    if ($art === 'mqtt_in') {
        $cmds = array(array(
            'title'   => 'wolf_ng_online',
            'comment' => 'Zustand der ISM8-Verbindung',
            'check'   => ' ',
        ));
        $zeit_typen = array('DPT_TimeOfDay', 'DPT_Date');
        foreach ($dps as $d) {
            if (strpos($d['io'], 'Out') === false || !$gewaehlt($d)) {
                continue;
            }
            if (in_array($d['dpt'], $zeit_typen, true)) {
                continue;   // Datum und Uhrzeit taugen nicht als Analogwert
            }
            $cmds[] = array(
                'title'   => str_replace('/', '_', wi_topic($d)),
                'comment' => $d['geraet'] . ' ' . $d['name'],
                'check'   => ' ',
            );
        }
        return array('wolf_mqtt_input.xml', wi_xml_virtual_in_http(array(
            'title'   => 'Wolf ISM8 MQTT',
            'address' => 'http://localhost',
            'polling' => '604800',
            'comment' => $fuss,
        ), $cmds));
    }

    if ($art === 'mqtt_out') {
        $port = wi_mqtt_udpinport();
        $cmds = array();
        foreach ($dps as $d) {
            if (strpos($d['io'], 'In') === false || !$gewaehlt($d)) {
                continue;
            }
            $thema = wi_topic($d);
            $cmds[] = array(
                'title'   => str_replace('/', '_', $thema),
                'comment' => $d['geraet'] . ' ' . $d['name'],
                'on'      => 'retain ' . $thema . ' <v>',
            );
        }
        return array('wolf_mqtt_output.xml', wi_xml_virtual_out(array(
            'title'   => 'Wolf ISM8 MQTT',
            'address' => '/dev/udp/' . $ip . '/' . $port,
            'comment' => $fuss,
        ), $cmds));
    }

    return array('', '');
}
