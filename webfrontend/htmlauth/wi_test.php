<?php
/**
 * Wolf ISM8 - Aktionen des Reiters Test
 *
 * Jede Funktion liefert array(Ueberschrift, Text). Der Text wird von der
 * Oberflaeche maskiert ausgegeben, hier also bewusst als Klartext erzeugt.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

require_once __DIR__ . '/wi_lib.php';

/** Shell-Befehl ausfuehren und Ausgabe einsammeln. */
function wi_sh($cmd)
{
    $out = array();
    @exec($cmd . ' 2>&1', $out);
    return implode("\n", $out);
}

function wi_test_ausfuehren($was)
{
    $p = wi_paths();
    $cfg = wi_config_read();

    switch ($was) {

        case 'status':
            $wd = wi_server_pid();
            $sv = wi_ism8i_pid();
            $t = "Watchdog:            " . ($wd ? "laeuft (PID $wd)" : 'laeuft nicht') . "\n";
            $t .= "Auswertungsmodul:    " . ($sv ? "laeuft (PID $sv)" : 'laeuft nicht') . "\n";
            $t .= "In der Konfiguration: " . (wi_cfg($cfg, 'enable', '0') === '1' ? 'eingeschaltet' : 'ausgeschaltet') . "\n\n";
            if ($wd && !$sv) {
                $t .= "Der Watchdog laeuft, das Auswertungsmodul nicht. Das ist genau der\n"
                    . "Zustand kurz nach einem Absturz - der Watchdog startet es gleich neu.\n"
                    . "Bleibt es dabei, sagt das Protokoll im Reiter Logdateien warum.\n\n";
            }
            if (!$wd && wi_cfg($cfg, 'enable', '0') === '1') {
                $t .= "In der Konfiguration eingeschaltet, aber nichts laeuft. Einmal\n"
                    . "\"Server neu starten\" druecken.\n\n";
            }
            $t .= wi_sh('ps -o pid,etime,rss,args -C perl 2>/dev/null | grep -i -E "wolf|PID" ');
            return array('Serverzustand', $t !== '' ? $t : 'Keine Angaben.');

        case 'werte':
            $datei = wi_log_file('server');
            if ($datei === '') {
                return array('Zuletzt empfangene Werte',
                    "Es gibt noch keine Protokolldatei.\n\n"
                    . "Sie entsteht, sobald der Server das erste Mal laeuft.");
            }
            $zeilen = wi_log_tail($datei, 400);
            $treffer = array();
            foreach ($zeilen as $z) {
                if (preg_match('/\d{1,3}\s*;|wolfism8\//u', $z)) {
                    $treffer[] = $z;
                }
                if (count($treffer) >= 60) {
                    break;
                }
            }
            if (!$treffer) {
                return array('Zuletzt empfangene Werte',
                    "Im Protokoll steht noch kein Datenpunkt.\n\n"
                    . "Moegliche Gruende:\n"
                    . "- Das ISM8 sendet nicht. Ziel-IP und Port in seiner Weboberflaeche pruefen.\n"
                    . "- Der Server laeuft nicht (Reiter Test, Serverzustand).\n"
                    . "- Es wurde noch kein Wert geaendert; das ISM8 sendet nur bei Aenderungen.\n"
                    . "  Mit \"Verbindung zum ISM8 pruefen\" laesst sich das nachsehen.");
            }
            return array('Zuletzt empfangene Werte (neueste zuerst)', implode("\n", $treffer));

        case 'themen':
            $dps = wi_datenpunkte(wi_cfg($cfg, 'fw_version', '1.8'));
            if (!$dps) {
                return array('MQTT-Themen', 'Zur eingestellten Firmware gibt es keine Datenpunkttabelle.');
            }
            $t = "Firmware " . wi_cfg($cfg, 'fw_version', '1.8') . " - " . count($dps) . " Datenpunkte\n";
            $t .= "MQTT ist derzeit " . (wi_cfg($cfg, 'mqtt', '0') === '1' ? 'eingeschaltet' : 'AUSGESCHALTET') . ".\n\n";
            $t .= "wolfism8/online\n";
            foreach ($dps as $d) {
                if (strpos($d['io'], 'Out') === false) {
                    continue;
                }
                $t .= wi_topic($d) . "\n";
            }
            return array('MQTT-Themen dieser Firmware', $t);

        case 'konfig':
            $t = "Datei: " . $p['config'] . "\n\n";
            if (is_file($p['config'])) {
                $t .= (string) @file_get_contents($p['config']);
            } else {
                $t .= "Datei nicht vorhanden - es gelten die Vorgabewerte:\n\n";
                foreach (wi_defaults() as $k => $v) {
                    $t .= $k . ' ' . $v . "\n";
                }
            }
            return array('Konfiguration', $t);

        case 'ports':
            $t = "LoxBerry-Adresse: " . wi_localip() . "\n\n";
            $t .= "Erwartet werden zwei horchende TCP-Ports:\n";
            $t .= "  " . wi_cfg($cfg, 'ism8i_port', '12004') . "  vom ISM8 (dort als Ziel eintragen)\n";
            $t .= "  " . wi_cfg($cfg, 'input_port', '12005') . "  fuer Schreibbefehle des Miniservers\n\n";
            $ss = wi_sh('ss -lntup 2>/dev/null || netstat -lntup 2>/dev/null');
            $gefiltert = array();
            foreach (preg_split('/\R/', $ss) as $z) {
                if (preg_match('/(State|:' . preg_quote(wi_cfg($cfg, 'ism8i_port', '12004'), '/') . '\b|:'
                    . preg_quote(wi_cfg($cfg, 'input_port', '12005'), '/') . '\b|wolf)/i', $z)) {
                    $gefiltert[] = $z;
                }
            }
            $t .= $gefiltert ? implode("\n", $gefiltert)
                : "Keiner der beiden Ports horcht. Laeuft der Server?";
            return array('Netzwerkports', $t);

        case 'umgebung':
            $t = "PHP:        " . PHP_VERSION . "\n";
            $t .= "LBHOMEDIR:  " . ($p['home'] !== '' ? $p['home'] : '(nicht gesetzt)') . "\n";
            $t .= "Plugin:     " . $p['plugin'] . "\n";
            $t .= "bin:        " . $p['bindir'] . "\n";
            $t .= "log:        " . $p['logdir'] . "\n\n";
            $t .= wi_sh('perl -v 2>/dev/null | grep -m1 version');
            $t .= "\n\nBenoetigte Perl-Module:\n";
            foreach (array('IO::Socket::Multicast', 'Math::Round', 'IO::Select', 'Config::Simple') as $m) {
                $r = wi_sh('perl -M' . escapeshellarg($m) . ' -e 1 2>&1');
                $t .= sprintf("  %-26s %s\n", $m, $r === '' ? 'vorhanden' : 'FEHLT');
            }
            $t .= "\nDatenpunkttabellen:\n";
            foreach (array('14', '15', '17', '18', '19') as $fw) {
                $f = $p['bindir'] . '/wolf_datenpunkte_' . $fw . '.csv';
                $t .= sprintf("  %-6s %s\n", substr($fw, 0, 1) . '.' . substr($fw, 1),
                    is_file($f) ? (count(wi_datenpunkte(substr($fw, 0, 1) . '.' . substr($fw, 1))) . ' Datenpunkte') : 'fehlt');
            }
            return array('Umgebung', $t);

        case 'mqttinfo':
            $broker = wi_mqtt_broker();
            $udp = wi_mqtt_udpinport();
            $t = "Broker:            " . ($broker !== '' ? $broker : 'nicht gefunden') . "\n";
            $t .= "UDP-Relay (UDP In): " . ($udp ? $udp : 'nicht gesetzt') . "\n";
            $t .= "MQTT im Plugin:     " . (wi_cfg($cfg, 'mqtt', '0') === '1' ? 'eingeschaltet' : 'ausgeschaltet') . "\n\n";
            if ($broker === '') {
                $t .= "Ohne MQTT-Gateway kann das Plugin nichts veroeffentlichen.\n"
                    . "Das Gateway ist ein eigenes LoxBerry-Plugin und muss installiert sein.\n\n";
            }
            if (!$udp) {
                $t .= "Der UDP-Eingangsport des Gateways wird fuer die Vorlage der\n"
                    . "MQTT-Ausgaenge gebraucht. Im Gateway unter \"UDP In\" einen Port setzen.\n\n";
            }
            $t .= "Zum Mitlesen eignet sich der MQTT Finder des Gateways;\n"
                . "dort auf das Thema wolfism8/# achten.";
            return array('MQTT-Gateway', $t);

        case 'restart':
            $a = wi_server('restart');
            sleep(2);
            $t = ($a !== '' ? $a . "\n\n" : '');
            $wd = wi_server_pid();
            $t .= $wd ? "Watchdog laeuft jetzt (PID $wd)." : "Der Watchdog laeuft nicht. Protokoll pruefen.";
            return array('Server neu starten', $t);

        case 'stop':
            $a = wi_server('stop');
            sleep(1);
            $t = ($a !== '' ? $a . "\n\n" : '');
            $t .= wi_server_pid() ? "Es laeuft noch etwas - bitte Protokoll pruefen."
                : "Angehalten.\n\nHinweis: Steht in den Einstellungen \"Server einschalten\",\n"
                . "startet er beim naechsten Systemstart wieder.";
            return array('Server anhalten', $t);

        case 'comtest':
            $skript = $p['bindir'] . '/ism8i_comtest.pl';
            if (!is_file($skript)) {
                return array('Multicast mitlesen', 'ism8i_comtest.pl nicht gefunden: ' . $skript);
            }
            $kopf = "ism8i_comtest.pl horcht 20 Sekunden auf der Multicast-Gruppe\n"
                  . "239.7.7.77:35353 - also auf das, was dieses Plugin selbst per\n"
                  . "TCP/UDP-Direktausgabe verschickt. Es liest NICHT direkt am ISM8 mit.\n\n";
            if (wi_cfg($cfg, 'output', 'none') !== 'data') {
                return array('Multicast mitlesen', $kopf
                    . "Die TCP/UDP-Direktausgabe ist ausgeschaltet - es wird nichts gesendet,\n"
                    . "also kann hier auch nichts ankommen. Das ist bei MQTT-Betrieb normal.\n\n"
                    . "Zum Pruefen des MQTT-Wegs eignet sich der MQTT Finder des Gateways\n"
                    . "(Thema wolfism8/#) oder \"Zuletzt empfangene Werte\".");
            }
            $ausgabe = wi_sh('timeout 20 perl ' . escapeshellarg($skript));
            if (trim($ausgabe) === '') {
                $ausgabe = "(keine Ausgabe in 20 Sekunden)\n\n"
                    . "Das ISM8 sendet nur bei Wertaenderungen. Am Wolf-Geraet eine\n"
                    . "Solltemperatur zu verstellen erzwingt ein Telegramm.\n"
                    . "Kommt auch dann nichts: laeuft der Server, und ist im ISM8 die\n"
                    . "richtige Ziel-IP eingetragen?";
            }
            return array('Multicast mitlesen', $kopf . $ausgabe);
    }

    return array('Unbekannt', 'Diese Aktion gibt es nicht.');
}
