<?php
/**
 * Wolf ISM8 - gemeinsame Hilfsfunktionen fuer Oberflaeche und Testseite
 *
 * Die Konfigurationsdatei config/wolf_ism8i.conf bleibt unveraendert im
 * Format "schluessel wert" (durch Leerzeichen getrennt, eine Zeile je
 * Eintrag), damit bin/wolf_ism8i.pl, daemon/daemon und postupgrade.sh ohne
 * Anpassung weiterlesen koennen.
 *
 * WICHTIG zum Dateiformat: wolf_ism8i.pl ueberspringt in loadConfig() seit
 * 2.5.2 nur noch Zeilen, die MIT einem "#" beginnen. Dieser Dateikopf und
 * wi_config_read() haben bis 3.0.7 noch die alte Regel beschrieben bzw.
 * angewandt - zwei Leser derselben Datei mit verschiedenen Regeln.
 * Ein Kommentar hinter einem Wert bleibt trotzdem unzulaessig: die Zeile
 * haette dann drei Felder, und der Dienst verwirft sie.
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


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

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
    if (!$home) {
        $home = lb_wurzel_ermitteln();
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

/**
 * Merkmal gegen fremde Absender.
 *
 * Bis 3.0.7 trug keines der zwoelf Formulare eines. Ein POST von einer
 * fremden Seite mit save=1 und sonst nichts erreichte den Speicher-Handler,
 * setzte enable auf 0, schaltete MQTT und Direktausgabe ab und rief
 * anschliessend wolf_server stop. htmlauth/ schuetzt gegen den
 * unangemeldeten Aufruf, nicht gegen diesen Fall - die Anmeldung des
 * Bedieners geht automatisch mit.
 *
 * Das Merkmal steht in einer eigenen Datei neben der Konfiguration, nicht
 * IN ihr: das Format "schluessel wert" liest auch der Dienst, und der
 * schreibt jede Zeile klein.
 */
function wi_formkey()
{
    static $k = null;
    if ($k !== null) {
        return $k;
    }
    $datei = dirname(wi_paths()['config']) . '/wi_formkey';
    if (is_file($datei)) {
        $k = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32}$/', $k)) {
            return $k;
        }
    }
    // Rechte VOR dem Inhalt, sonst steht das Merkmal kurzzeitig offen da.
    $k = bin2hex(function_exists('random_bytes')
        ? random_bytes(16)
        : pack('H*', md5(uniqid('wi', true) . getmypid())));
    // is_dir() vor mkdir(): sonst warnt es, sobald der Ordner schon da
    // ist - und das @ haelt einen eigenen Fehler-Aufnehmer nicht auf.
    $ordner = dirname($datei);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }
    $tmp = $datei . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $k) !== false) {
        @chmod($tmp, 0600);
        @rename($tmp, $datei);
    }
    return $k;
}

/** Verstecktes Feld, das in JEDES Formular gehoert. */
function wi_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . wi_e(wi_formkey()) . '">';
}

/** Voreinstellungen. MQTT ein, TCP/UDP-Direktausgabe aus (Hausstandard).
 *
 * Diese Liste MUSS mit %hash in bin/wolf_ism8i.pl uebereinstimmen. Bis
 * 3.0.7 wichen zwei Werte ab: output stand hier auf 'none' und dort auf
 * 'fhem', mqtt hier auf '1' und dort auf '0'. Fehlte einer der Schluessel
 * in der Datei, zeigte die Oberflaeche einen Betriebszustand an, den es
 * nicht gab. Beide Listen sind seit 3.0.8 gleich. */
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
        /* Bis 3.0.8 fehlten die folgenden drei hier - und wi_config_write()
         * schreibt NUR, was in dieser Liste steht. Wirkung, am 25.08.2026
         * an einem nachgebauten LoxBerry gemessen: ein Speichervorgang
         * loeschte sie aus der Konfiguration, der Dienst fiel auf seine
         * eigenen Vorgaben zurueck, und mit dem Praefix wanderten SAEMTLICHE
         * MQTT-Themen. Die Oberflaeche bot die Felder an, das Speichern
         * verwarf sie - lautlos.
         *
         * Die Werte sind die des Dienstes (bin/wolf_ism8i.pl, %hash), damit
         * beide Seiten dasselbe meinen. */
        'praefix'        => 'wolf_ng',
        'herzschlag'     => '60',
        'abgleich_takt'  => '0',
        /* Welche Stoercodetabelle gilt. Leer heisst KEINE - siehe
         * wi_stoercode_tabellen(). Eine falsche Tabelle waere schlimmer
         * als gar keine, deshalb wird nicht geraten. */
        'stoercodes'     => '',
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
        $t = trim($line);
        // Nur Zeilen ueberspringen, die MIT einem Doppelkreuz beginnen -
        // genau wie loadConfig() in wolf_ism8i.pl seit 2.5.2. Vorher fiel
        // hier jede Zeile weg, in der irgendwo eines stand: der Dienst
        // benutzte sie, die Oberflaeche sah sie nicht und schrieb beim
        // naechsten Speichern den Vorgabewert darueber.
        if ($t !== '' && $t[0] === '#') {
            continue;
        }
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
    $ordner = dirname($file);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }

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


/* ==================================================================
 * Das Zustandsabbild des Dienstes (V1)
 *
 * bin/wolf_ism8i.pl schreibt bei jeder Aenderung nach
 * data/plugins/<ordner>/zustand.json - temp und rename, also unteilbar.
 * Bis 3.0.8 hatte die Oberflaeche keinen einzigen Messwert anzuzeigen und
 * musste das Protokoll durchsuchen.
 * ================================================================== */

function wi_zustand_datei()
{
    $p = wi_paths();
    if ($p['home']) {
        return $p['home'] . '/data/plugins/' . $p['plugin'] . '/zustand.json';
    }
    return dirname(dirname(__DIR__)) . '/data/zustand.json';
}

/** Rueckgabe: Feld, oder null wenn es (noch) keines gibt. */
function wi_zustand()
{
    static $z = false;
    if ($z !== false) {
        return $z;
    }
    $z = null;
    $f = wi_zustand_datei();
    if (!is_file($f)) {
        return $z;
    }
    $roh = @file_get_contents($f);
    if ($roh === false || trim($roh) === '') {
        return $z;
    }
    $j = json_decode($roh, true);
    // Eine beschaedigte Datei ist ein Fehler, kein leerer Wert - aber sie darf
    // die Oberflaeche nicht mitreissen. Gemeldet wird sie im Reiter Test.
    $z = is_array($j) ? $j : null;
    return $z;
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function wi_zustand_alter()
{
    $z = wi_zustand();
    if (!is_array($z) || !isset($z['zeit'])) {
        return -1;
    }
    return max(0, time() - (int) $z['zeit']);
}

/**
 * Datenpunkte, fuer die schon einmal ein Telegramm kam (V2).
 * Rueckgabe: Feld id => true.
 */
function wi_gesehen()
{
    $z = wi_zustand();
    $aus = array();
    if (is_array($z) && isset($z['werte']) && is_array($z['werte'])) {
        foreach ($z['werte'] as $id => $unused) {
            $aus[(int) $id] = true;
        }
    }
    return $aus;
}

/**
 * Aus unbekannten Datenpunktkennungen die Firmware erschliessen (V4).
 *
 * Rueckgabe: null, wenn nichts Unbekanntes kam; sonst ein Feld mit
 * anzahl, min, max und - wenn es passt - der vermuteten Firmware.
 *
 * GRENZE, und sie gehoert in die Anzeige: so laesst sich nur der Fall
 * "Tabelle zu alt" erkennen. Ist die eingestellte Tabelle zu NEU, kommen die
 * zusaetzlichen Kennungen schlicht nie an, und Ausbleiben ist kein Befund.
 */
function wi_fw_verdacht()
{
    $z = wi_zustand();
    if (!is_array($z) || !isset($z['zaehler']['unbekannt'])) {
        return null;
    }
    $n = (int) $z['zaehler']['unbekannt'];
    if ($n < 1) {
        return null;
    }
    $min = isset($z['unbekannt_min']) ? (int) $z['unbekannt_min'] : -1;
    $max = isset($z['unbekannt_max']) ? (int) $z['unbekannt_max'] : -1;
    $vermutet = '';
    // Welche mitgelieferte Tabelle kennt ALLE unbekannten Kennungen?
    foreach (array('1.9', '1.8', '1.7', '1.5', '1.4') as $fw) {
        $dps = wi_datenpunkte($fw);
        if (!$dps) {
            continue;
        }
        $ids = array();
        foreach ($dps as $dp) {
            $ids[$dp['id']] = true;
        }
        if ($min > 0 && $max > 0 && isset($ids[$min]) && isset($ids[$max])) {
            $vermutet = $fw;
            break;
        }
    }
    return array('anzahl' => $n, 'min' => $min, 'max' => $max, 'fw' => $vermutet);
}

/* ==================================================================
 * Betriebsarten im Klartext (V9)
 *
 * Die Tabellen stehen im Perl in getCsvResult() - dort werden sie fuer die
 * Ausgabe gebraucht. Ueber MQTT geht aber die nackte ZAHL hinaus, und die
 * Klartexte tauchten in der Oberflaeche nirgends auf. Hier stehen sie ein
 * zweites Mal, und das ist die schlechtere Loesung - ueber die Sprachgrenze
 * hinweg gibt es keine gemeinsame Funktion. Der Reiter Test zaehlt sie
 * deshalb gegen das Perl nach.
 * ================================================================== */
function wi_betriebsarten($fw)
{
    $neu = ($fw === '1.8' || $fw === '1.9');
    return array(
        'DPT_HVACMode' => array(
            'Heizkreis|Mischerkreis' => array(
                0 => 'Automatikbetrieb', 1 => 'Heizbetrieb', 2 => 'Standby',
                3 => 'Sparbetrieb', 4 => $neu ? 'Permanent Kühlen' : '-'),
            'CWL' => array(
                0 => 'Automatikbetrieb', 1 => 'Nennlüftung', 2 => '-',
                3 => 'Reduzierung Lüftung', 4 => $neu ? 'Feuchteschutz' : '-'),
        ),
        'DPT_DHWMode' => array(
            'Warmwasser' => array(
                0 => 'Automatikbetrieb', 1 => '-', 2 => 'Dauerbetrieb',
                3 => '-', 4 => 'Standby'),
        ),
        'DPT_HVACContrMode' => array(
            'CGB-2|MGK-2|TOB|COB-2|TGB' => array(
                0 => 'Schornsteinferger', 1 => 'Heiz- Warmwasserbetrieb',
                6 => 'Standby', 7 => 'GLT', 11 => 'Frostschutz', 15 => 'Kalibration'),
            'BWL-1-S|CHA|Wärmepumpe' => array(
                0 => 'Antilegionellenfunktion', 1 => 'Heiz- Warmwasserbetrieb',
                2 => 'Vorwärmung', 3 => 'Aktive Kühlung', 6 => 'Standby',
                7 => 'GLT', 11 => 'Frostschutz'),
        ),
    );
}

/** Welche Betriebsart-Tabelle gilt fuer diesen Datenpunkt? */
function wi_art_tabelle($d, $fw)
{
    $alle = wi_betriebsarten($fw);
    if (!isset($alle[$d['dpt']])) {
        return null;
    }
    foreach ($alle[$d['dpt']] as $muster => $werte) {
        foreach (explode('|', $muster) as $wort) {
            if (strpos($d['geraet'], $wort) !== false) {
                return $werte;
            }
        }
    }
    return null;
}

/* ==================================================================
 * Stoercodes (V23)
 *
 * Die Zuordnung steht in der Wolf-Dokumentation, NICHT im Plugin. Sie wird
 * deshalb aus einer Tabellendatei gelesen, die ab Werk nur die Kopfzeile
 * enthaelt - erfunden wird hier nichts. Wer die Schnittstellenbeschreibung
 * seines Geraets hat, traegt sie ein; die Datei ueberlebt ein Update, weil
 * sie unter config/ liegt.
 * ================================================================== */
/**
 * Die mitgelieferten Stoercodetabellen.
 *
 * Rueckgabe: schluessel => array(Anzeigename, Pfad, Zahl der Zeilen)
 *
 * Der Schluessel ist der Namensbestandteil hinter "wolf_stoercodes_", der
 * Anzeigename steht in der zweiten Kopfzeile der Datei ("# Geraete: ...").
 * Beides wird GELESEN und nicht im Code gefuehrt - sonst laufen Dateibestand
 * und Auswahlfeld auseinander, sobald eine Tabelle dazukommt.
 */
function wi_stoercode_tabellen()
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = array();
    $orte = array(
        wi_paths()['bindir'],
        dirname(dirname(__DIR__)) . '/bin',
    );
    foreach ($orte as $ort) {
        if (!is_dir($ort)) {
            continue;
        }
        $treffer = @glob($ort . '/wolf_stoercodes_*.csv');
        if (!is_array($treffer)) {
            continue;
        }
        foreach ($treffer as $f) {
            $s = basename($f, '.csv');
            $s = substr($s, strlen('wolf_stoercodes_'));
            if ($s === '' || isset($t[$s])) {
                continue;
            }
            $name = $s;
            $n = 0;
            foreach (preg_split('/\R/', (string) @file_get_contents($f)) as $z) {
                $z = trim($z);
                if ($z === '') {
                    continue;
                }
                if ($z[0] === '#') {
                    if (strpos($z, '# Geraete:') === 0) {
                        $name = trim(substr($z, strlen('# Geraete:')));
                    }
                    continue;
                }
                $p = explode(';', $z, 2);
                if (count($p) === 2 && ctype_digit(trim($p[0]))) {
                    $n++;
                }
            }
            if ($n > 0) {
                $t[$s] = array($name, $f, $n);
            }
        }
        if ($t) {
            break;
        }
    }
    ksort($t);
    return $t;
}

function wi_stoercodes()
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = array();

    /* Reihenfolge, und jeder Schritt hat einen Grund:
     *
     *   1. Die eigene Datei des Anwenders unter config/. Sie schlaegt alles -
     *      wer seine Tabelle selbst pflegt, will nicht von einem Update
     *      ueberstimmt werden. Sie ueberlebt ein Update auch.
     *   2. Die GEWAEHLTE mitgelieferte Tabelle. Ist nichts gewaehlt, wird
     *      nichts geraten: der Datenpunkt 372 bedeutet je nach Waermeerzeuger
     *      Verschiedenes (gemessen: von 72 Nummern sind 12 mehrdeutig, und
     *      Code 116 heisst am Kessel etwas anderes als an der Waermepumpe).
     *      Eine falsche Tabelle ist schlimmer als gar keine.
     *   3. bin/wolf_stoercodes.csv - die leere Beispieldatei. Sie liefert
     *      nichts und dient nur als Formbeschreibung.
     */
    $wahl = wi_cfg(wi_config_read(), 'stoercodes', '');
    $kandidaten = array(dirname(wi_paths()['config']) . '/wolf_stoercodes.csv');
    if ($wahl !== '') {
        $tabellen = wi_stoercode_tabellen();
        if (isset($tabellen[$wahl])) {
            $kandidaten[] = $tabellen[$wahl][1];
        }
    }
    $kandidaten[] = wi_paths()['bindir'] . '/wolf_stoercodes.csv';
    $kandidaten[] = dirname(dirname(__DIR__)) . '/bin/wolf_stoercodes.csv';
    foreach ($kandidaten as $f) {
        if (!is_file($f)) {
            continue;
        }
        foreach (preg_split('/\R/', (string) @file_get_contents($f)) as $z) {
            $z = trim($z);
            if ($z === '' || $z[0] === '#') {
                continue;
            }
            $s = explode(';', $z, 2);
            if (count($s) === 2 && ctype_digit(trim($s[0]))) {
                $t[(int) trim($s[0])] = trim($s[1]);
            }
        }
        if ($t) {
            break;
        }
    }
    return $t;
}

/**
 * Woher die geltende Stoercodetabelle stammt - fuer die Anzeige.
 * Rueckgabe: array(Herkunft, Anzeigename, Zahl der Zeilen)
 * Herkunft ist 'eigen', 'mitgeliefert' oder 'keine'.
 */
function wi_stoercode_herkunft()
{
    $eigen = dirname(wi_paths()['config']) . '/wolf_stoercodes.csv';
    $n = count(wi_stoercodes());
    if (is_file($eigen) && $n > 0) {
        /* Nur dann ist die eigene Datei auch WIRKSAM: eine vorhandene, aber
         * leere Datei laesst wi_stoercodes() weitersuchen. */
        $roh = (string) @file_get_contents($eigen);
        foreach (preg_split('/\R/', $roh) as $z) {
            $z = trim($z);
            if ($z !== '' && $z[0] !== '#' && strpos($z, ';') !== false) {
                return array('eigen', $eigen, $n);
            }
        }
    }
    $wahl = wi_cfg(wi_config_read(), 'stoercodes', '');
    $tabellen = wi_stoercode_tabellen();
    if ($wahl !== '' && isset($tabellen[$wahl])) {
        return array('mitgeliefert', $tabellen[$wahl][0], $n);
    }
    return array('keine', '', $n);
}

/** Klartext zu einem Stoercode, oder '' wenn die Tabelle ihn nicht kennt. */
function wi_stoercode($nr)
{
    $t = wi_stoercodes();
    return isset($t[(int) $nr]) ? $t[(int) $nr] : '';
}

/* ==================================================================
 * Alle Themen, die das Plugin je veroeffentlicht (V13)
 *
 * Gebraucht zum Aufraeumen retained gebliebener Werte - nach einem
 * Firmwarewechsel, nach einer Praefix-Aenderung und vor dem Deinstallieren.
 * ================================================================== */
function wi_alle_themen($cfg)
{
    $pre = wi_cfg($cfg, 'praefix', 'wolf_ng');
    $aus = array($pre . '/online', $pre . '/zeitstempel', $pre . '/zaehler');
    $zeit = array('DPT_TimeOfDay', 'DPT_Date');
    // ALLE Firmwarefassungen, nicht nur die eingestellte: nach einem Wechsel
    // stehen die Themen der alten noch im Broker.
    foreach (array('1.4', '1.5', '1.7', '1.8', '1.9') as $fw) {
        foreach (wi_datenpunkte($fw) as $d) {
            if (strpos($d['io'], 'Out') === false || in_array($d['dpt'], $zeit, true)) {
                continue;
            }
            $t = wi_topic_pre($pre, $d);
            $aus[$t] = $t;
        }
    }
    return array_values(array_unique($aus));
}

/** Thema mit ausdruecklichem Praefix - wi_topic() nimmt das eingestellte. */
function wi_topic_pre($pre, $d)
{
    return $pre . '/' . wi_mqtt_friendly($d['geraet']) . '/' . wi_mqtt_friendly($d['name']);
}

/* ==================================================================
 * Schreibprobe (V14)
 *
 * Trockenlauf und Ernstfall laufen durch DIESELBE Funktion; der Unterschied
 * ist ein Parameter, keine zweite Umsetzung. Und der Trockenlauf spricht
 * nicht die Sprache des Ernstfalls: er sagt, was geschehen WUERDE.
 * ================================================================== */
function wi_schreibprobe($cfg, $id, $wert, $ernst)
{
    $id = trim((string) $id);
    $wert = trim((string) $wert);
    if (!ctype_digit($id)) {
        return array(false, sprintf(wi_t('PRUEF.SP_ID_UNGUELTIG'), wi_e($id)));
    }
    $dps = wi_datenpunkte(wi_cfg($cfg, 'fw_version', '1.8'));
    $dp = null;
    foreach ($dps as $x) {
        if ($x['id'] === (int) $id) {
            $dp = $x;
            break;
        }
    }
    if ($dp === null) {
        return array(false, sprintf(wi_t('PRUEF.SP_UNBEKANNT'), (int) $id,
                                    wi_e(wi_cfg($cfg, 'fw_version', '1.8'))));
    }
    if (strpos($dp['io'], 'In') === false) {
        return array(false, sprintf(wi_t('PRUEF.SP_NICHT_SCHREIBBAR'),
                                    (int) $id, wi_e($dp['name'])));
    }
    if ($wert === '') {
        return array(false, wi_t('PRUEF.SP_KEIN_WERT'));
    }

    $zeile = $id . ';' . $wert;
    if (!$ernst) {
        // Der Trockenlauf sendet NICHTS. Er sagt, was hinausginge - und zwar
        // mit einem Satz, den man nicht mit dem Ernstfall verwechseln kann.
        return array(true, sprintf(wi_t('PRUEF.SP_TROCKEN'),
                                   wi_e($dp['geraet']), wi_e($dp['name']),
                                   wi_e($dp['dpt']), wi_e($zeile),
                                   wi_e(wi_localip()),
                                   wi_e(wi_cfg($cfg, 'input_port', '12005'))));
    }

    $antwort = wi_befehl_senden($cfg, $zeile);
    return array($antwort !== '' && substr($antwort, 0, 2) === 'OK',
                 sprintf(wi_t('PRUEF.SP_ERNST'), wi_e($zeile),
                         wi_e($antwort !== '' ? $antwort : wi_t('PRUEF.SP_KEINE_ANTWORT'))));
}

/**
 * Eine Befehlszeile an den eigenen Befehls-Port schicken und die Antwort
 * lesen. Seit 3.0.8 antwortet der Dienst mit OK oder ERR (V20).
 */
function wi_befehl_senden($cfg, $zeile)
{
    $port = (int) wi_cfg($cfg, 'input_port', '12005');
    if ($port < 1 || $port > 65535) {
        return '';
    }
    // Wo ein Fehlschlag ein vorgesehener Ausgang ist, wird der
    // Fehlerbehandler getauscht - ein @ haelt einen eigenen Aufnehmer nicht auf.
    set_error_handler(function () { return true; });
    $fp = fsockopen('127.0.0.1', $port, $nr, $txt, 3);
    restore_error_handler();
    if (!$fp) {
        return '';
    }
    stream_set_timeout($fp, 3);
    fwrite($fp, $zeile . "\n");
    $antwort = '';
    $ende = microtime(true) + 3;
    while (!feof($fp) && microtime(true) < $ende) {
        $s = fgets($fp, 512);
        if ($s === false) {
            break;
        }
        $antwort .= $s;
    }
    fclose($fp);
    return trim($antwort);
}

/* ==================================================================
 * Sichern und Zurueckspielen (V17)
 * ================================================================== */

/** Die Konfiguration als Text - genau das Format, das der Dienst liest. */
function wi_konfig_text($cfg)
{
    $t = "# Wolf ISM8 - Sicherung der Einstellungen\n";
    $t .= '# ' . date('d.m.Y H:i') . "\n";
    foreach (wi_defaults() as $k => $vorgabe) {
        $t .= $k . ' ' . (isset($cfg[$k]) && $cfg[$k] !== '' ? $cfg[$k] : $vorgabe) . "\n";
    }
    return $t;
}

/**
 * Eine hochgeladene Sicherung pruefen und uebernehmen.
 * Rueckgabe: array(uebernommen, Liste der Beanstandungen)
 *
 * Es werden ALLE Beanstandungen gesammelt, nicht nur die erste - und eine
 * halb gueltige Datei ueberschreibt die bestehende Konfiguration NICHT.
 */
function wi_konfig_einlesen($roh)
{
    $mangel = array();
    $neu = wi_defaults();
    $bekannt = array_keys($neu);
    $gefunden = 0;
    foreach (preg_split('/\R/', (string) $roh) as $z) {
        $t = trim($z);
        if ($t === '' || $t[0] === '#') {
            continue;
        }
        $f = preg_split('/\s+/', $t);
        if (count($f) !== 2) {
            $mangel[] = sprintf(wi_t('MELDUNG.SICH_ZEILE'), wi_e($t));
            continue;
        }
        $k = strtolower($f[0]);
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(wi_t('MELDUNG.SICH_SCHLUESSEL'), wi_e($k));
            continue;
        }
        $neu[$k] = $f[1];
        $gefunden++;
    }
    if ($gefunden === 0) {
        $mangel[] = wi_t('MELDUNG.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel);
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

/**
 * Auskunft ueber das MQTT-Gateway: Autostart und Fassung, aus EINEM Lesen.
 *
 * Stand bis 3.0.7 als einzeilige Funktionsdefinition mitten im HTML des
 * Reiters - ein Helfer gehoert in die Bibliothek, nicht in die Ansicht.
 */
function wi_gateway_info()
{
    $f = wi_paths()['home'] . '/config/system/general.json';
    if (!is_file($f)) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($f), true);
    if (!is_array($j) || !isset($j['Mqtt']) || !is_array($j['Mqtt'])) {
        return null;
    }
    return array(
        'autostart' => !empty($j['Mqtt']['Gatewayautostart']),
        'fassung'   => isset($j['Mqtt']['Gatewayversion'])
                       ? (int) $j['Mqtt']['Gatewayversion'] : 0,
    );
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
    // Ohne /proc gibt es hier nichts zu suchen. Die Pruefung spart eine
    // Warnung je Aufruf; das @ haelt einen eigenen Fehler-Aufnehmer nicht auf.
    if (!is_dir('/proc')) {
        return 0;
    }
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

/** Ein Alter in Sekunden als lesbarer Satzteil. */
function wi_alter_text($s)
{
    if ($s < 0) {
        return wi_t('ALTER.UNBEKANNT');
    }
    if ($s < 90) {
        return sprintf(wi_t('ALTER.SEK'), (int) $s);
    }
    if ($s < 5400) {
        return sprintf(wi_t('ALTER.MIN'), (int) round($s / 60));
    }
    return sprintf(wi_t('ALTER.STD'), (int) round($s / 3600));
}

/**
 * Eine geaenderte Konfiguration vom Dienst uebernehmen lassen (V16).
 *
 * Bis 3.0.8 loeste JEDE Aenderung einen vollstaendigen Neustart aus - dabei
 * bricht die TCP-Verbindung zum ISM8 ab, und bis das Geraet neu verbindet und
 * wieder sendet, steht in Loxone der alte Wert. Ein Neustart ist nur noch
 * noetig, wenn sich ein Port aendert oder wenn ueberhaupt nichts laeuft.
 */
function wi_dienst_uebernehmen($neu, $vorher)
{
    if (wi_cfg($neu, 'enable', '0') !== '1') {
        return trim(wi_server('stop'));
    }
    $pid = wi_ism8i_pid();
    if (!$pid || !is_array($vorher)) {
        return trim(wi_server('restart'));
    }
    foreach (array('ism8i_port', 'input_port', 'multicast_port') as $k) {
        if (wi_cfg($vorher, $k, '') !== wi_cfg($neu, $k, '')) {
            return trim(wi_server('restart'));
        }
    }
    // SIGHUP ist 1 - die Konstante gibt es nur mit der Erweiterung pcntl, und
    // die ist auf einem LoxBerry nicht garantiert geladen.
    if (function_exists('posix_kill') && @posix_kill((int) $pid, 1)) {
        return wi_t('MELDUNG.UEBERNOMMEN');
    }
    $aus = array();
    $rc = 1;
    @exec('kill -HUP ' . (int) $pid . ' 2>&1', $aus, $rc);
    if ($rc === 0) {
        return wi_t('MELDUNG.UEBERNOMMEN');
    }
    return trim(wi_server('restart'));
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
/**
 * Grenzen, Einheit und Art eines Befehls aus dem KNX-Datenpunkttyp.
 *
 * Bis 3.0.7 stand in JEDEM Befehl MinVal="-2147483647" MaxVal="2147483647".
 * Loxone zieht daraus die Reglergrenzen und die Plausibilitaetspruefung; wer
 * alles offen laesst, verschenkt beides. Bei einem Schreibbefehl ist das mehr
 * als Kosmetik - ein Schieberegler fuer eine Warmwasser-Solltemperatur reichte
 * damit von minus zwei Milliarden bis plus zwei Milliarden.
 *
 * HERKUNFT DER ZAHLEN, und das gehoert dazu: die Bereiche der Aufzaehlungen
 * (HVACMode 0..4, DHWMode 0..4, HVACContrMode 0..15, Scaling 0..100,
 * Ucount 0..255 bzw. 0..65535) stammen AUS DEM EIGENEN CODE - aus den
 * Wertetabellen und Masken in getCsvResult() von bin/wolf_ism8i.pl. Die
 * Bereiche der Messgroessen (Temperatur, Druck, Leistung, Durchfluss) sind
 * dagegen fuer eine Heizungsanlage ABGELEITET, nicht am Geraet gemessen. Sie
 * sind bewusst weit gefasst, damit kein echter Wert abgeschnitten wird.
 */
function wi_grenzen($dpt, $einheit)
{
    $e = ($einheit !== '' && $einheit !== '-') ? $einheit : '';
    // dpt => array(MinVal, MaxVal, Nachkommastellen im Unit, analog)
    $t = array(
        'DPT_Switch'             => array(0, 1, 0, false),
        'DPT_Bool'               => array(0, 1, 0, false),
        'DPT_Enable'             => array(0, 1, 0, false),
        'DPT_OpenClose'          => array(0, 1, 0, false),
        'DPT_Scaling'            => array(0, 100, 0, true),
        'DPT_Value_1_Ucount'     => array(0, 255, 0, true),
        'DPT_Value_2_Ucount'     => array(0, 65535, 0, true),
        'DPT_Value_Temp'         => array(-60, 250, 1, true),
        'DPT_Value_Tempd'        => array(-100, 100, 1, true),
        'DPT_Value_Pres'         => array(-10000, 10000, 1, true),
        'DPT_Power'              => array(-100, 1000, 1, true),
        'DPT_Value_Volume_Flow'  => array(0, 65535, 1, true),
        'DPT_FlowRate_m3/h'      => array(0, 1000, 2, true),
        'DPT_ActiveEnergy'       => array(0, 2147483647, 0, true),
        'DPT_ActiveEnergy_kWh'   => array(0, 2147483647, 0, true),
        'DPT_HVACMode'           => array(0, 4, 0, true),
        'DPT_DHWMode'            => array(0, 4, 0, true),
        'DPT_HVACContrMode'      => array(0, 15, 0, true),
    );
    if (!isset($t[$dpt])) {
        // Ein unbekannter Typ bekommt KEINE erfundenen Grenzen.
        return array('', '', '', true);
    }
    list($min, $max, $stellen, $analog) = $t[$dpt];
    $platz = $stellen > 0 ? '<v.' . $stellen . '>' : '<v>';
    $unit = $e !== '' ? ($platz . ' ' . $e) : $platz;
    return array((string) $min, (string) $max, $unit, $analog);
}

/** Ein Attribut nur schreiben, wenn es einen Wert hat. */
function wi_attr($name, $wert)
{
    return $wert === '' ? '' : ($name . '="' . wi_x($wert) . '" ');
}

/** Das Kindelement <Info>, erstes Element jeder Ausfuhr aus Loxone Config. */
function wi_xml_info($art)
{
    // templateType: 1 = UDP-Eingang, 2 = HTTP-Eingang, 3 = Ausgang.
    return "\t" . '<Info templateType="' . (int) $art . '" minVersion="17010727"/>' . "\r\n";
}

function wi_xml_virtual_in_udp($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInUdp ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . wi_x($kopf['title']) . '" ';
    $o .= 'Comment="' . wi_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . wi_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'Port="' . wi_x(isset($kopf['port']) ? $kopf['port'] : '') . '"';
    $o .= '>' . $crlf;
    $o .= wi_xml_info(1);
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInUdpCmd ';
        $o .= 'Title="' . wi_x($c['title']) . '" ';
        $o .= 'Comment="' . wi_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Address="" ';
        $o .= 'Check="' . wi_x(isset($c['check']) ? $c['check'] : '') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="' . (isset($c['analog']) && !$c['analog'] ? 'false' : 'true') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= wi_attr('MinVal', isset($c['min']) ? $c['min'] : '');
        $o .= wi_attr('MaxVal', isset($c['max']) ? $c['max'] : '');
        $o .= wi_attr('Unit', isset($c['unit']) ? $c['unit'] : '');
        $o .= 'HintText=""';
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
    $o .= 'HintText="" ';
    $o .= 'Title="' . wi_x($kopf['title']) . '" ';
    $o .= 'Comment="' . wi_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . wi_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . wi_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= wi_xml_info(2);
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . wi_x($c['title']) . '" ';
        $o .= 'Comment="' . wi_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . wi_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="' . (isset($c['analog']) && !$c['analog'] ? 'false' : 'true') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= wi_attr('MinVal', isset($c['min']) ? $c['min'] : '');
        $o .= wi_attr('MaxVal', isset($c['max']) ? $c['max'] : '');
        $o .= wi_attr('Unit', isset($c['unit']) ? $c['unit'] : '');
        $o .= 'HintText=""';
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
    $o .= 'HintText="" ';
    $o .= 'Title="' . wi_x($kopf['title']) . '" ';
    $o .= 'Comment="' . wi_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . wi_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=";"';
    $o .= '>' . $crlf;
    $o .= wi_xml_info(3);
    // Kein ID-Attribut mehr: in keiner der drei Ausfuhren aus Loxone Config
    // steht eines. Die Reihenfolge der Attribute ist die gemessene -
    // CmdOffMethod folgt unmittelbar auf CmdOnMethod, CmdAnswer steht vor
    // Analog, und HintText schliesst ab.
    foreach ($cmds as $c) {
        $analog = !(isset($c['analog']) && !$c['analog']);
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . wi_x($c['title']) . '" ';
        $o .= 'Comment="' . wi_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="' . strtoupper(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . wi_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="' . wi_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="' . ($analog ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        if ($analog) {
            // Nur der ANALOGE Befehl traegt diese vier - gemessen an
            // VQU_Govee UDP-Ausgang_Test.xml, wo der digitale sie nicht hat.
            $o .= 'SourceValLow="0" ';
            $o .= 'DestValLow="0" ';
            $o .= 'SourceValHigh="100" ';
            $o .= 'DestValHigh="100" ';
        }
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Die vier Vorlagen erzeugen.
 * $art: udp_in | tcp_out | mqtt_in | mqtt_out
 * $geraete: Liste der ausgewaehlten Geraetenamen
 * $nurgesehen: null = alle Datenpunkte des Geraets; sonst ein Feld
 *   id => true aus wi_gesehen(). Dann kommen NUR die Datenpunkte in die
 *   Vorlage, fuer die schon einmal ein Telegramm kam (V2). Aus 267 Zeilen
 *   wird damit die tatsaechliche Anlage - und ein virtueller Eingang, der
 *   nie einen Wert bekommt, sieht in Loxone genauso aus wie einer mit 0.
 * Rueckgabe: array(dateiname, inhalt, anzahl)
 *
 * Die Zahl der Befehle kommt mit zurueck, damit der Aufrufer eine LEERE
 * Vorlage abweisen kann. Bis 3.0.7 wurde sie ausgeliefert: wohlgeformt,
 * ohne einen einzigen Befehl, ohne jede Meldung. Ausgeloest hat das ein
 * Geraet, das die eingestellte Firmware nicht kennt, oder ein rein
 * lesendes Geraet bei einer Ausgangsvorlage (Solarmodul und Kaskadenmodul
 * haben keinen einzigen In-Datenpunkt).
 */
function wi_vorlage($art, $cfg, $geraete, $nurgesehen = null)
{
    $dps = wi_datenpunkte(wi_cfg($cfg, 'fw_version', '1.8'));
    $ip = wi_localip();
    // Monat ist einstellig gezaehlt korrekt - die alte Perl-Fassung nahm
    // localtime()[4] ohne +1 und schrieb dadurch stets den Vormonat.
    $stempel = date('d.m.Y');
    $fuss = 'Erzeugt vom LoxBerry-Plugin Wolf ISM8 (' . $stempel . ')';

    $gewaehlt = function ($d) use ($geraete, $nurgesehen) {
        if (!in_array($d['geraet'], $geraete, true)) {
            return false;
        }
        return $nurgesehen === null || isset($nurgesehen[$d['id']]);
    };

    // Uhrzeit und Datum taugen auch im UDP-Weg nicht als Analogwert. Die
    // MQTT-Vorlage filtert sie seit jeher, die UDP-Vorlage nahm sie bis
    // 3.0.8 mit - in Firmware 1.9 acht Befehle, die nur eine KNX-Rohzahl
    // liefern. Gefunden von der Vorlagenpruefung ueber die fehlende Einheit.
    $zeit_typen = array('DPT_TimeOfDay', 'DPT_Date');

    if ($art === 'udp_in') {
        $cmds = array(
            array('title' => 'Online', 'check' => 'online;\\v',
                  'min' => '0', 'max' => '1', 'unit' => '<v>', 'analog' => false),
            // V3: Lebenszeichen. Ohne einen Wert, der sich zuverlaessig
            // aendert, laesst sich ein toter Dienst nicht von einer ruhigen
            // Heizung unterscheiden.
            array('title' => 'Zaehler (Lebenszeichen)', 'check' => 'zaehler;\\v',
                  'min' => '0', 'max' => '999', 'unit' => '<v>', 'analog' => true),
            array('title' => 'Zeitstempel', 'check' => 'zeitstempel;\\v',
                  'min' => '0', 'max' => '2147483647', 'unit' => '<v>', 'analog' => true),
        );
        foreach ($dps as $d) {
            if (strpos($d['io'], 'Out') === false || !$gewaehlt($d)) {
                continue;
            }
            if (in_array($d['dpt'], $zeit_typen, true)) {
                continue;
            }
            list($min, $max, $unit, $analog) = wi_grenzen($d['dpt'], $d['einheit']);
            $cmds[] = array(
                'title' => $d['geraet'] . ' ' . $d['name'],
                'check' => sprintf('%03d', $d['id']) . ';\\v',
                'min' => $min, 'max' => $max, 'unit' => $unit, 'analog' => $analog,
            );
        }
        return array('wolf_udp_input.xml', wi_xml_virtual_in_udp(array(
            'title'   => 'Wolf ISM8',
            'address' => $ip,
            'port'    => wi_cfg($cfg, 'multicast_port', '35353'),
            'comment' => $fuss,
        ), $cmds), count($cmds));
    }

    if ($art === 'tcp_out') {
        $cmds = array();
        foreach ($dps as $d) {
            if (strpos($d['io'], 'In') === false || !$gewaehlt($d)) {
                continue;
            }
            list($min, $max, $unit, $analog) = wi_grenzen($d['dpt'], $d['einheit']);
            $cmds[] = array(
                'title'  => $d['geraet'] . ' ' . $d['name'],
                'method' => 'get',
                'on'     => sprintf('%03d', $d['id']) . ';\\v',
                'analog' => $analog,
            );
        }
        return array('wolf_output.xml', wi_xml_virtual_out(array(
            'title'   => 'Wolf ISM8',
            'address' => 'tcp://' . $ip . ':' . wi_cfg($cfg, 'input_port', '12005'),
            'comment' => $fuss,
        ), $cmds), count($cmds));
    }

    if ($art === 'mqtt_in') {
        $pre = wi_cfg($cfg, 'praefix', 'wolf_ng');
        $cmds = array(
            array('title' => $pre . '_online',
                  'comment' => 'Zustand der ISM8-Verbindung',
                  'check' => ' ', 'min' => '0', 'max' => '1',
                  'unit' => '<v>', 'analog' => false),
            array('title' => $pre . '_zaehler',
                  'comment' => 'Lebenszeichen, 0..999 umlaufend',
                  'check' => ' ', 'min' => '0', 'max' => '999',
                  'unit' => '<v>', 'analog' => true),
            array('title' => $pre . '_zeitstempel',
                  'comment' => 'Unix-Zeit des letzten Lebenszeichens',
                  'check' => ' ', 'min' => '0', 'max' => '2147483647',
                  'unit' => '<v>', 'analog' => true),
        );
        foreach ($dps as $d) {
            if (strpos($d['io'], 'Out') === false || !$gewaehlt($d)) {
                continue;
            }
            if (in_array($d['dpt'], $zeit_typen, true)) {
                continue;   // Datum und Uhrzeit taugen nicht als Analogwert
            }
            list($min, $max, $unit, $analog) = wi_grenzen($d['dpt'], $d['einheit']);
            $cmds[] = array(
                'title'   => str_replace('/', '_', wi_topic($d)),
                'comment' => $d['geraet'] . ' ' . $d['name'],
                'check'   => ' ',
                'min' => $min, 'max' => $max, 'unit' => $unit, 'analog' => $analog,
            );
        }
        // Der Online-Befehl zaehlt nicht als Datenpunkt.
        return array('wolf_mqtt_input.xml', wi_xml_virtual_in_http(array(
            'title'   => 'Wolf ISM8 MQTT',
            'address' => 'http://localhost',
            'polling' => '604800',
            'comment' => $fuss,
        ), $cmds), count($cmds) - 3);
    }

    if ($art === 'mqtt_out') {
        $port = wi_mqtt_udpinport();
        $cmds = array();
        foreach ($dps as $d) {
            if (strpos($d['io'], 'In') === false || !$gewaehlt($d)) {
                continue;
            }
            $thema = wi_topic($d);
            list($min, $max, $unit, $analog) = wi_grenzen($d['dpt'], $d['einheit']);
            $cmds[] = array(
                // <v.0> ist der Wertplatzhalter eines Analogbefehls; bis
                // 3.0.7 stand hier <v>.
                'title'   => str_replace('/', '_', $thema),
                'comment' => $d['geraet'] . ' ' . $d['name'],
                'on'      => 'retain ' . $thema . ' <v.0>',
                'analog'  => $analog,
            );
        }
        return array('wolf_mqtt_output.xml', wi_xml_virtual_out(array(
            'title'   => 'Wolf ISM8 MQTT',
            'address' => '/dev/udp/' . $ip . '/' . $port,
            'comment' => $fuss,
        ), $cmds), count($cmds));
    }

    return array('', '', 0);
}
