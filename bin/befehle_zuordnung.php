<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie WOLF ISM NG.
 *
 * Gruppe 5 / MQTT (3.1.12; Bauvorschrift wz_bild/GRUPPE5_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe5_wo.txt
 * Abschnitt 3, ENTSCHEIDUNGEN_GRUPPE5_MQTT.md; Muster vb_g4_wp_bau/bin/befehle_zuordnung.php). Die Bruecke selbst
 * (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese
 * Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 *
 * Die Befehle baut wi_bb_befehle() (webfrontend/htmlauth/wi_lib.php) beim Laden aus der Datenpunkttabelle der
 * eingestellten Firmware (Entwurf wo E3): EIN Befehl je schreibbarem Datenpunkt ausser Datum/Uhrzeit, Name aus
 * Geraet und Datenpunkt (direkter/heizkreis/warmwassersolltemperatur), Art und Bereich aus dem KNX-Typ (E4).
 * 'tun' gibt den Befehl an wi_befehl_senden() - den Befehls-Port des Dienstes, denselben Weg wie Schreibprobe und
 * SG-Modul; Plausibilitaet, 60-s-Gleichwertsperre und ISM8-Pruefung bleiben im Dienst (E1). Alle Befehle schreiben
 * an die Heizung: zweiter Haken "Heizung ueber MQTT stellen" (befehle_mqtt_steuern, ab Werk aus, E6). Sperrzeit 0 s
 * mit Begruendung (E5). Eine Firmware-Aenderung aendert die Tabelle: die Bruecke wird beim Speichern neu gestartet.
 */
if (!function_exists('wi_bb_befehle')) {
    $wi_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $wi_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/wi_lib.php';
    } else {
        $wi_kand[] = dirname(__DIR__) . '/webfrontend/htmlauth/wi_lib.php';
    }
    foreach ($wi_kand as $wi_k) {
        if (is_file($wi_k)) {
            require_once $wi_k;
            break;
        }
    }
}
return array(
    'fassung' => 1,
    'linie'   => 'wolf_ng',
    'plugin'  => 'WOLF ISM NG',
    'geladen' => function_exists('wi_bb_befehle') && function_exists('wi_befehl_senden') && function_exists('wi_befehl_bruecke'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        return wi_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        wi_log_bruecke($stufe, $text);
    },
    'befehle' => function_exists('wi_bb_befehle') ? wi_bb_befehle(wi_config_read()) : array(),
);
