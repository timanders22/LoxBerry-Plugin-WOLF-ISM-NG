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
                if (preg_match('/\d{1,3}\s*;|wolf_ng\//u', $z)) {
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
            $t .= "wolf_ng/online\n";
            foreach ($dps as $d) {
                if (strpos($d['io'], 'Out') === false) {
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
