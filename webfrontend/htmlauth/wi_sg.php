<?php
/**
 * Wolf ISM8 - SG-Ready und § 14a EnWG (V24, neu in 3.1.0)
 *
 * ==================================================================
 * WAS DIESES MODUL TUT - UND WAS NICHT
 * ==================================================================
 *
 * Es rechnet aus den Stundenpreisen des Folgetages LADEFENSTER aus und gibt
 * waehrend dieser Fenster andere Sollwerte an die Wolf-Steuerung. Ausserhalb
 * stellt es den Normalwert wieder her. Kommt vom Netzbetreiber ein
 * Dimmsignal nach § 14a EnWG, hat das Vorrang vor allem anderen.
 *
 * Es ist AUSDRUECKLICH KEIN Ersatz fuer die Steuerbox des Netzbetreibers.
 * Die netzdienliche Steuerung nach § 14a ist eine Pflicht des Betreibers und
 * haengt an dessen Geraet - dieses Modul ist die Energiemanagement-Seite
 * daneben. Es empfaengt das Signal, es erzeugt es nicht, und es kann sich
 * darauf auch nicht verlassen.
 *
 * ------------------------------------------------------------------
 * Zwei Schalter, nicht einer
 * ------------------------------------------------------------------
 *
 * sg_ein    schaltet die RECHNUNG ein. Das Modul plant, zeigt und
 *           veroeffentlicht, schreibt aber nichts an die Heizung.
 * sg_senden schaltet das SCHREIBEN ein. Ohne diesen zweiten Schalter
 *           bleibt jeder Befehl ein Trockenlauf.
 *
 * Beide stehen ab Werk auf 0. Der Grund fuer den zweiten Schalter: eine
 * Plugin-Einstellung laesst sich zuruecknehmen, ein an die Heizung
 * geschriebener Sollwert wirkt sofort und in einem Haus, in dem Menschen
 * wohnen. Wer das einschaltet, soll es zweimal getan haben.
 *
 * ------------------------------------------------------------------
 * Was NICHT gemessen ist
 * ------------------------------------------------------------------
 *
 * Es gibt hier kein WOLF-Geraet und kein ISM8. Kein einziger der unten
 * gebildeten Befehle ist je an einer Heizung angekommen. Belegt ist nur:
 *
 *   - dass die Datenpunkte 56..105 in den mitgelieferten Tabellen als
 *     schreibbar gefuehrt sind (Spalte "Out/In"),
 *   - welche Zahlen die Betriebsarten bedeuten (wi_betriebsarten()),
 *   - dass parseInput() im Auswertungsmodul diese Werte annimmt.
 *
 * NICHT belegt ist, was die Heizung daraufhin tut. Jeder erzeugte Befehl
 * traegt deshalb den Vermerk "nicht am Geraet erprobt", und die Oberflaeche
 * sagt es noch einmal in ganzen Saetzen.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

require_once __DIR__ . '/wi_lib.php';

/* ==================================================================
 * 1. Die Heizkreise, in die geschrieben werden kann
 *
 * Die Datenpunktnummern stehen NICHT hier als Zahlenreihe, sondern werden
 * aus der mitgelieferten Tabelle gelesen - sonst haette dieses Modul eine
 * zweite Wahrheit ueber die Anlage. Gesucht wird ueber Geraetename und
 * Datenpunktname, beides woertlich aus der CSV.
 * ================================================================== */

/** Die vier Kreise, die Wolf ueber das ISM8 schreibbar macht. */
function wi_sg_kreise()
{
    return array(
        'direkt'   => 'Direkter Heizkreis + direktes Warmwasser',
        'mischer1' => 'Mischerkreis 1 + Warmwasser 1',
        'mischer2' => 'Mischerkreis 2 + Warmwasser 2',
        'mischer3' => 'Mischerkreis 3 + Warmwasser 3',
    );
}

/**
 * Die Datenpunktnummern eines Kreises, aus der Tabelle GELESEN.
 * Rueckgabe: array(schluessel => array('id'=>n,'dpt'=>..,'name'=>..)) oder
 * ein leeres Feld, wenn der Kreis in dieser Firmware nicht vorkommt.
 */
function wi_sg_punkte($cfg, $kreis)
{
    $kreise = wi_sg_kreise();
    if (!isset($kreise[$kreis])) {
        return array();
    }
    $geraet = $kreise[$kreis];
    // Die Namen stehen woertlich so in wolf_datenpunkte_*.csv. Wer sie
    // aendert, aendert die Tabelle - dann findet diese Funktion nichts und
    // sagt das, statt eine falsche Nummer zu nehmen.
    $gesucht = array(
        'ww_soll'    => 'Warmwassersolltemperatur',
        'hk_modus'   => array('Programmwahl Heizkreis', 'Programmwahl Mischer'),
        'ww_modus'   => 'Programmwahl Warmwasser',
        'korrektur'  => 'Sollwertkorrektur',
        'sparfaktor' => 'Sparfaktor',
    );
    $out = array();
    foreach (wi_datenpunkte(wi_cfg($cfg, 'fw_version', '1.8')) as $d) {
        if ($d['geraet'] !== $geraet || strpos($d['io'], 'In') === false) {
            continue;
        }
        foreach ($gesucht as $schluessel => $namen) {
            $namen = is_array($namen) ? $namen : array($namen);
            if (in_array($d['name'], $namen, true)) {
                $out[$schluessel] = array('id' => (int) $d['id'], 'dpt' => $d['dpt'],
                                          'name' => $d['name'], 'einheit' => $d['einheit']);
            }
        }
    }
    return $out;
}

/* Der Datenpunkt "1x Warmwasserladung global" (194 in Firmware 1.9) waere
 * der naechstliegende SG-Ready-Anlaufbefehl. Er wird BEWUSST NICHT
 * benutzt: er stoesst eine Ladung an, die das Geraet selbst beendet, und
 * wann es das tut, ist hier nicht gemessen. Ein Befehl, dessen Wirkung
 * niemand kennt und den man nicht zuruecknehmen kann, gehoert nicht in
 * die erste Fassung eines Moduls, das ohne Geraet gebaut wurde. Die
 * Anhebung des Warmwassersollwerts erreicht dasselbe und laesst sich
 * jederzeit zuruecknehmen.
 *
 * Es stand hier eine Funktion wi_sg_ww_einmal(), die den Punkt gesucht
 * hat und von niemandem gerufen wurde - tote_helfer.py hat sie gefunden.
 * Entfernt statt auskommentiert; dieser Absatz sagt, warum es sie nicht
 * gibt. */

/* ==================================================================
 * 2. Die Preise
 *
 * Drei Wege, in dieser Reihenfolge, und jeder sagt von sich, wie belastbar
 * er ist. Geraten wird keiner.
 * ================================================================== */

/**
 * Stundenpreise als [Unix-Stundenbeginn => ct/kWh], aufsteigend nach Zeit.
 * Rueckgabe: array(preise, quelle, hinweis)
 *
 * quelle ist 'datei', 'awattar' oder '' (nichts gefunden).
 */
function wi_sg_preise($cfg)
{
    $quelle = wi_cfg($cfg, 'sg_quelle', 'aus');
    if ($quelle === 'aus') {
        return array(array(), '', wi_t('SG.Q_AUS'));
    }

    /* --- Weg 1: die eigene Datei ------------------------------------
     *
     * config/plugins/<ordner>/sg_preise.json, Form:
     *     {"preise": {"1757116800": 12.34, "1757120400": 9.87}}
     * Schluessel ist der Unix-Zeitstempel des Stundenbeginns, Wert der
     * Arbeitspreis in ct/kWh. Diesen Weg kann jedes andere Plugin und jedes
     * Skript bedienen, und er ist der einzige, der hier auch pruefbar ist. */
    if ($quelle === 'datei') {
        $f = dirname(wi_paths()['config']) . '/sg_preise.json';
        if (!is_file($f)) {
            return array(array(), '', sprintf(wi_t('SG.Q_DATEI_FEHLT'), $f));
        }
        $d = wi_sg_json_lesen($f);
        if ($d === null || !isset($d['preise']) || !is_array($d['preise'])) {
            return array(array(), '', sprintf(wi_t('SG.Q_DATEI_KAPUTT'), $f));
        }
        $p = array();
        foreach ($d['preise'] as $ts => $wert) {
            if (!ctype_digit((string) $ts) || !is_numeric($wert)) {
                continue;
            }
            $ts = (int) $ts;
            $p[$ts - ($ts % 3600)] = (float) $wert;
        }
        ksort($p);
        return array($p, 'datei', sprintf(wi_t('SG.Q_DATEI_OK'), count($p)));
    }

    /* --- Weg 2: der Zwischenspeicher des aWATTar-Plugins -------------
     *
     * data/plugins/<ordner>/markt_<tld>_<JJJJMMTT>.json traegt die rohe
     * Antwort von api.awattar.de: {"data":[{"start_timestamp":ms,
     * "end_timestamp":ms,"marketprice":EUR/MWh}]}. Gemessen am 04.09.2026 an
     * LoxBerry-Plugin-Spotpreis-aWATTar-1.2.20, Funktion spot_day().
     *
     * VORBEHALT, der hierher gehoert: das ist der INTERNE Zwischenspeicher
     * eines fremden Plugins, keine zugesagte Schnittstelle. Aendert jenes
     * Plugin sein Ablageformat, faellt dieser Weg aus - und dann liefert er
     * NICHTS statt etwas Falsches. Wer sich darauf verlassen will, nimmt
     * Weg 1 und laesst das andere Plugin dorthin schreiben.
     *
     * Boersenpreis ist NETTO und ohne Netzentgelte, Umlagen und Steuern.
     * Fuer die Frage "welche Stunde ist die guenstigste" genuegt das, weil
     * alle uebrigen Bestandteile ueber den Tag gleich sind. Fuer eine
     * Kostenaussage genuegt es NICHT - deshalb steht an jeder Anzeige
     * "Boersenpreis", nicht "Arbeitspreis". */
    $ordner = wi_cfg($cfg, 'sg_awattar_ordner', 'spotpreis');
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ordner)) {
        return array(array(), '', wi_t('SG.Q_ORDNER_UNGUELTIG'));
    }
    $home = wi_paths()['home'];
    if ($home === '') {
        return array(array(), '', wi_t('SG.Q_KEIN_HOME'));
    }
    $verz = $home . '/data/plugins/' . $ordner;
    if (!is_dir($verz)) {
        return array(array(), '', sprintf(wi_t('SG.Q_KEIN_ORDNER'), $verz));
    }
    $p = array();
    $dateien = 0;
    foreach (array(time(), time() + 86400) as $tag) {
        foreach (array('de', 'at') as $tld) {
            $f = $verz . '/markt_' . $tld . '_' . date('Ymd', $tag) . '.json';
            if (!is_file($f)) {
                continue;
            }
            $d = wi_sg_json_lesen($f);
            if ($d === null || !isset($d['data']) || !is_array($d['data'])) {
                continue;
            }
            $dateien++;
            // Feiner als eine Stunde wird zum Stundenmittel zusammengefasst,
            // nicht ausgewaehlt - dieselbe Rechnung wie im Quellplugin. Der
            // deutsche Day-Ahead-Handel geht auf Viertelstunden ueber; wer
            // dann jede vierte Zahl nimmt, bekommt einen Tag, der voellig
            // normal aussieht und zu drei Vierteln falsch ist.
            $summe = array();
            $zahl = array();
            foreach ($d['data'] as $row) {
                if (!isset($row['start_timestamp']) || !isset($row['marketprice'])) {
                    continue;
                }
                $ts = (int) ((int) $row['start_timestamp'] / 1000);
                $stunde = $ts - ($ts % 3600);
                if (!isset($summe[$stunde])) {
                    $summe[$stunde] = 0.0;
                    $zahl[$stunde] = 0;
                }
                // EUR/MWh -> ct/kWh ist der Faktor 0,1.
                $summe[$stunde] += ((float) $row['marketprice']) * 0.1;
                $zahl[$stunde]++;
            }
            foreach ($summe as $stunde => $s) {
                $p[$stunde] = round($s / max(1, $zahl[$stunde]), 4);
            }
        }
    }
    ksort($p);
    if (!$p) {
        return array(array(), '', sprintf(wi_t('SG.Q_LEER'), $verz));
    }
    return array($p, 'awattar', sprintf(wi_t('SG.Q_AWATTAR_OK'), count($p), $dateien, $ordner));
}

/* ==================================================================
 * 3. Der Fahrplan
 *
 * WARUM HIER NICHT planer.php AUS DEN SPOTPREIS-PLUGINS STEHT
 *
 * Der Fahrplaner dort beantwortet eine andere Frage: "welche Zeitscheiben
 * belege ich fuer einen Verbraucher mit Energiemenge, Frist, Rang und
 * Leistungsbudget". Eine Heizung hat keine Frist und keine Energiemenge,
 * die man vorher kennt - sie hat einen Speicher, der sich lohnt zu fuellen,
 * solange der Strom billig ist. Das ist eine Auswahl, keine Belegung.
 *
 * Ihn hierher zu kopieren hiesse ausserdem, eine DRITTE Kopie derselben
 * Datei zu fuehren; zwei sind schon eine Pruefsumme wert. Deshalb steht hier
 * eine eigene, kurze Rechnung - und dieser Absatz sagt, warum.
 * ================================================================== */

/**
 * Die guenstigsten Stunden im Horizont auswaehlen.
 *
 * $preise    [stundenbeginn => ct/kWh]
 * $anzahl    wie viele Stunden insgesamt
 * $block     Mindestlaenge eines zusammenhaengenden Fensters in Stunden
 * $ab        fruehester Stundenbeginn (Unix), Vorgabe: die laufende Stunde
 * $horizont  wie viele Stunden nach vorn geschaut wird
 *
 * Rueckgabe: array(fenster, begruendung)
 *   fenster: Liste aus array('von'=>ts,'bis'=>ts,'schnitt'=>ct)
 *
 * Das Verfahren ist gierig und in einem Satz erklaerbar: es sucht das
 * billigste zusammenhaengende Fenster der Laenge $block, bucht es, und
 * wiederholt das, bis $anzahl Stunden zusammen sind. Es ist NICHT optimal.
 * Es ist dafuer nachvollziehbar, wenn jemand um drei Uhr nachts wissen will,
 * warum die Waermepumpe gerade laeuft.
 */
function wi_sg_fahrplan($preise, $anzahl, $block, $ab = null, $horizont = 24)
{
    $anzahl = max(0, (int) $anzahl);
    $block = max(1, (int) $block);
    $horizont = max(1, (int) $horizont);
    if ($ab === null) {
        $ab = time() - (time() % 3600);
    }
    $bis = $ab + $horizont * 3600;

    // Erst die Menge pruefen, dann ueber sie urteilen.
    $kandidaten = array();
    foreach ($preise as $ts => $ct) {
        if ($ts >= $ab && $ts < $bis) {
            $kandidaten[$ts] = $ct;
        }
    }
    ksort($kandidaten);
    if (!$kandidaten) {
        return array(array(), 'SG.PLAN_KEINE_PREISE');
    }
    if ($anzahl === 0) {
        return array(array(), 'SG.PLAN_NULL');
    }
    if (count($kandidaten) < $block) {
        return array(array(), 'SG.PLAN_ZU_KURZ');
    }

    $stunden = array_keys($kandidaten);
    $belegt = array();
    $fenster = array();
    $offen = $anzahl;

    while ($offen >= $block) {
        $bestes = null;
        $bester_schnitt = null;
        for ($i = 0; $i + $block <= count($stunden); $i++) {
            // Ein Fenster muss LUECKENLOS sein - sonst waere "zwei Stunden
            // am Stueck" eine Zusage, die der Plan nicht haelt. Eine Luecke
            // entsteht, wenn eine Stunde im Preisbestand fehlt.
            $summe = 0.0;
            $ganz = true;
            for ($k = 0; $k < $block; $k++) {
                $ts = $stunden[$i + $k];
                if (isset($belegt[$ts]) || $ts !== $stunden[$i] + $k * 3600) {
                    $ganz = false;
                    break;
                }
                $summe += $kandidaten[$ts];
            }
            if (!$ganz) {
                continue;
            }
            $schnitt = $summe / $block;
            if ($bester_schnitt === null || $schnitt < $bester_schnitt) {
                $bester_schnitt = $schnitt;
                $bestes = $i;
            }
        }
        if ($bestes === null) {
            break;   // nichts Zusammenhaengendes mehr frei
        }
        $von = $stunden[$bestes];
        for ($k = 0; $k < $block; $k++) {
            $belegt[$von + $k * 3600] = true;
        }
        $fenster[] = array('von' => $von, 'bis' => $von + $block * 3600,
                           'schnitt' => round($bester_schnitt, 4));
        $offen -= $block;
    }

    // Nach Zeit sortieren - der Anwender liest einen Tagesablauf, keine
    // Rangfolge.
    usort($fenster, function ($a, $b) {
        return $a['von'] < $b['von'] ? -1 : ($a['von'] > $b['von'] ? 1 : 0);
    });
    return array($fenster, $fenster ? '' : 'SG.PLAN_NICHTS_FREI');
}

/**
 * C1 (Durchgang 02.10.2026, Entscheidung 31): Hoechstdauer der Anhebung.
 *
 * Bis 3.1.5 galt "laden" so lange, wie der Plan Fenster aneinanderreihte -
 * mit sg_stunden 24 und sg_block 12 waren es 24 h am Stueck (Bericht code,
 * Befund 1, S5). Jetzt endet eine zusammenhaengende Anhebung nach sg_laden_max
 * Stunden; danach gilt "normal", bis der Plan das Ladefenster verlaesst. Die
 * naechste Ladung darf wieder die volle Dauer laufen.
 *
 * Gemessen wird ab dem Zeitpunkt, an dem "laden" WIRKLICH gestellt wurde
 * (Merker laden_seit) - nicht ab dem Fensterbeginn im Plan: der Plan wird in
 * jedem Lauf vom Beginn der laufenden Stunde an neu gerechnet, ein
 * angefangenes Fenster steht darin nicht mehr.
 *
 * GRENZE, ehrlich: faellt der LoxBerry oder der Cron selbst aus, faengt das
 * nichts ab - die Werte liegen im Wolf-Regler. Das sagt auch der Hilfetext.
 *
 * Rueckgabe array(laden, gekappt, laden_seit)
 */
function wi_sg_kappen($laden, $merker, $max_h, $jetzt)
{
    $seit = (is_array($merker) && !empty($merker['laden_seit'])) ? (int) $merker['laden_seit'] : 0;
    if (!$laden) {
        return array(false, false, 0);
    }
    if ($seit > 0 && $max_h > 0 && $jetzt - $seit >= $max_h * 3600) {
        return array(false, true, $seit);
    }
    return array(true, false, $seit);
}

/** Liegt $jetzt in einem der Fenster? */
function wi_sg_im_fenster($fenster, $jetzt = null)
{
    if ($jetzt === null) {
        $jetzt = time();
    }
    foreach ($fenster as $f) {
        if ($jetzt >= $f['von'] && $jetzt < $f['bis']) {
            return true;
        }
    }
    return false;
}

/* ==================================================================
 * 4. Das Dimmsignal nach § 14a EnWG
 *
 * Das Signal kommt NICHT von hier. Es kommt von der Steuerbox des
 * Netzbetreibers und liegt in dieser Anlage als Wert vor - ueber Loxone,
 * einen Shelly oder was auch immer daran haengt. Dieses Modul liest es aus
 * einer Datei, die jemand anders schreibt:
 *
 *     data/plugins/<ordner>/sg_14a.json
 *     {"dimmen": 1, "ts": 1757116800, "quelle": "Loxone VQ Steuerbox"}
 *
 * Ein virtueller Ausgang in Loxone kann das ueber den Befehls-Port des
 * Plugins nicht - der nimmt nur Datenpunktbefehle. Deshalb die Datei; sie
 * laesst sich aus jeder Richtung schreiben und ist hier pruefbar.
 * ================================================================== */

/**
 * Rueckgabe: array(dimmen, alter, hinweisschluessel)
 *   dimmen: true | false | null  (null = nicht feststellbar)
 */
function wi_sg_14a($cfg)
{
    if (wi_cfg($cfg, 'sg_14a', '0') !== '1') {
        return array(false, -1, 'SG.E14_AUS');
    }
    $f = wi_paths()['home'] !== ''
        ? wi_paths()['home'] . '/data/plugins/' . wi_paths()['plugin'] . '/sg_14a.json'
        : sys_get_temp_dir() . '/sg_14a.json';
    if (!is_file($f)) {
        return array(null, -1, 'SG.E14_KEINE_DATEI');
    }
    $d = wi_sg_json_lesen($f);
    if ($d === null) {
        return array(null, -1, 'SG.E14_KAPUTT');
    }
    return wi_sg_14a_auswerten($d, (int) @filemtime($f), time(),
                               (int) wi_cfg($cfg, 'sg_14a_alter', '900'));
}

/**
 * Das gelesene Signal auswerten - ohne Datei, ohne Uhr (fuer den Selbsttest).
 *
 * C4 (Durchgang 02.10.2026). Bis 3.1.5 machte max(0, time()-ts) einen
 * Zeitstempel in MILLISEKUNDEN oder einen aus der ZUKUNFT fuer immer
 * "frisch": stirbt der Schreiber, blieb die Dimmung unbegrenzt stehen, und
 * der Reiter Test zeigte dazu einen Haken (Bericht code, Befund 4, S6b/S6c).
 * Ein unbekannter Wert ("dimmen": 2) wurde still als "nicht dimmen" gelesen.
 * Jetzt:
 *   - dimmen nimmt nur 0, 1, true oder false an (auch als Zeichenkette);
 *   - ein ts mit 13 und mehr Ziffern (Millisekunden) oder mehr als 300 s in
 *     der Zukunft ist KAPUTT; ein ts, der keine ganze Zahl ist, ebenso;
 *   - ohne ts gilt das Dateidatum wie bisher.
 * Kaputt und veraltet liefern null: es wird NICHT gedimmt, und sg/dimmen
 * traegt -1, wie README und Kommentar es zusagen (Befund 5).
 * Rueckgabe: array(dimmen true|false|null, alter, hinweisschluessel)
 */
function wi_sg_14a_auswerten($d, $mtime, $jetzt, $grenze)
{
    if (!is_array($d) || !array_key_exists('dimmen', $d)) {
        return array(null, -1, 'SG.E14_KAPUTT');
    }
    $roh = $d['dimmen'];
    if ($roh === 1 || $roh === '1' || $roh === true || $roh === 'true') {
        $dimmen = true;
    } elseif ($roh === 0 || $roh === '0' || $roh === false || $roh === 'false') {
        $dimmen = false;
    } else {
        return array(null, -1, 'SG.E14_KAPUTT_WERT');
    }
    if (array_key_exists('ts', $d)) {
        $t = $d['ts'];
        if (!(is_int($t) || (is_string($t) && ctype_digit($t)))) {
            return array(null, -1, 'SG.E14_KAPUTT_TS');
        }
        $t = (string) $t;
        if (strlen($t) >= 13) {
            return array(null, -1, 'SG.E14_KAPUTT_TS');   // Millisekunden
        }
        $ts = (int) $t;
        if ($ts > $jetzt + 300) {
            return array(null, -1, 'SG.E14_KAPUTT_TS');   // Zukunft
        }
    } else {
        $ts = (int) $mtime;
    }
    $alter = max(0, $jetzt - $ts);
    if ($grenze > 0 && $alter > $grenze) {
        /* VERALTET. Es wird NICHT gedimmt: ein ausgefallener Melder ist kein
         * Befehl des Netzbetreibers, und die netzdienliche Steuerung haengt
         * an dessen Steuerbox, nicht an diesem Plugin. Gemeldet wird es
         * laut: die Zeile im Reiter Test wird rot, und sg/dimmen traegt -1
         * (seit dem Durchgang 02.10.2026 wirklich; bis 3.1.5 ging 0 hinaus,
         * Bericht code, Befund 5). */
        return array(null, $alter, 'SG.E14_VERALTET');
    }
    return array($dimmen, $alter, $dimmen ? 'SG.E14_AKTIV' : 'SG.E14_RUHE');
}

/* ==================================================================
 * 5. Aus Lage wird Befehl
 *
 * Drei Zustaende, und sie schliessen einander aus:
 *
 *   dimmen   § 14a hat Vorrang vor allem. Heizkreis in die eingestellte
 *            Absenkung, Warmwasser in Standby.
 *   laden    Ladefenster: Warmwassersollwert hoch, Sollwertkorrektur hoch.
 *   normal   die hinterlegten Normalwerte.
 *
 * Erzeugt werden BEFEHLSZEILEN in der Form, die der Befehls-Port des
 * Auswertungsmoduls annimmt: "<id>;<wert>". Gesendet wird hier nichts -
 * das tut wi_sg_senden(), und nur mit dem zweiten Schalter.
 * ================================================================== */

/**
 * Rueckgabe: array('lage' => 'dimmen'|'laden'|'normal',
 *                  'befehle' => array(array('id','wert','warum')),
 *                  'punkte' => ..., 'fehlt' => array())
 */
function wi_sg_befehle($cfg, $laden, $dimmen)
{
    $kreis = wi_cfg($cfg, 'sg_kreis', 'direkt');
    $pk = wi_sg_punkte($cfg, $kreis);
    $fehlt = array();
    foreach (array('ww_soll', 'hk_modus', 'ww_modus', 'korrektur') as $k) {
        if (!isset($pk[$k])) {
            $fehlt[] = $k;
        }
    }
    $lage = $dimmen ? 'dimmen' : ($laden ? 'laden' : 'normal');
    $befehle = array();
    if ($fehlt) {
        // Fehlt auch nur ein Datenpunkt, wird GAR NICHTS gebildet. Ein halb
        // gestellter Heizkreis ist schlimmer als ein ungestellter.
        return array('lage' => $lage, 'befehle' => array(), 'punkte' => $pk, 'fehlt' => $fehlt);
    }

    $ww_normal = (float) wi_cfg($cfg, 'sg_ww_normal', '48');
    $ww_laden  = (float) wi_cfg($cfg, 'sg_ww_laden', '55');
    $korr      = (float) wi_cfg($cfg, 'sg_korrektur', '2');

    if ($lage === 'dimmen') {
        // Betriebsartzahlen aus wi_betriebsarten(): Heizkreis 2 = Standby,
        // 3 = Sparbetrieb; Warmwasser 4 = Standby.
        $hk = wi_cfg($cfg, 'sg_14a_modus', 'spar') === 'standby' ? 2 : 3;
        $befehle[] = array('id' => $pk['hk_modus']['id'], 'wert' => (string) $hk, 'warum' => 'SG.W_DIMM_HK');
        $befehle[] = array('id' => $pk['ww_modus']['id'], 'wert' => '4', 'warum' => 'SG.W_DIMM_WW');
        $befehle[] = array('id' => $pk['korrektur']['id'], 'wert' => '0', 'warum' => 'SG.W_DIMM_KORR');
        $befehle[] = array('id' => $pk['ww_soll']['id'], 'wert' => wi_sg_zahl($ww_normal), 'warum' => 'SG.W_DIMM_SOLL');
    } elseif ($lage === 'laden') {
        $befehle[] = array('id' => $pk['hk_modus']['id'], 'wert' => '0', 'warum' => 'SG.W_LADEN_HK');
        $befehle[] = array('id' => $pk['ww_modus']['id'], 'wert' => '0', 'warum' => 'SG.W_LADEN_WW');
        $befehle[] = array('id' => $pk['ww_soll']['id'], 'wert' => wi_sg_zahl($ww_laden), 'warum' => 'SG.W_LADEN_SOLL');
        $befehle[] = array('id' => $pk['korrektur']['id'], 'wert' => wi_sg_zahl($korr), 'warum' => 'SG.W_LADEN_KORR');
    } else {
        $befehle[] = array('id' => $pk['hk_modus']['id'], 'wert' => '0', 'warum' => 'SG.W_NORM_HK');
        $befehle[] = array('id' => $pk['ww_modus']['id'], 'wert' => '0', 'warum' => 'SG.W_NORM_WW');
        $befehle[] = array('id' => $pk['ww_soll']['id'], 'wert' => wi_sg_zahl($ww_normal), 'warum' => 'SG.W_NORM_SOLL');
        $befehle[] = array('id' => $pk['korrektur']['id'], 'wert' => '0', 'warum' => 'SG.W_NORM_KORR');
    }
    return array('lage' => $lage, 'befehle' => $befehle, 'punkte' => $pk, 'fehlt' => array());
}

/** Zahl fuer den Befehls-Port: Punkt als Dezimaltrennzeichen, kein Exponent. */
function wi_sg_zahl($f)
{
    return rtrim(rtrim(number_format((float) $f, 2, '.', ''), '0'), '.') ?: '0';
}

/* ==================================================================
 * 6. Die Gesamtlage in einem Aufruf
 * ================================================================== */

/**
 * Alles zusammen: Preise, Plan, § 14a, Befehle, Merker.
 * Rein lesend - schreibt nichts und sendet nichts.
 */
function wi_sg_lage($cfg)
{
    $ein = wi_cfg($cfg, 'sg_ein', '0') === '1';
    list($preise, $quelle, $qhinweis) = wi_sg_preise($cfg);
    list($fenster, $planhinweis) = wi_sg_fahrplan(
        $preise,
        (int) wi_cfg($cfg, 'sg_stunden', '4'),
        (int) wi_cfg($cfg, 'sg_block', '2'),
        null,
        (int) wi_cfg($cfg, 'sg_horizont', '24'));
    list($dimmen, $alter14a, $h14a) = wi_sg_14a($cfg);
    $laden_plan = wi_sg_im_fenster($fenster);
    $max_h = (int) wi_cfg($cfg, 'sg_laden_max', '6');
    $merker = wi_sg_merker();
    list($laden, $gekappt, $laden_seit) = wi_sg_kappen($laden_plan && $dimmen !== true,
                                                         $merker, $max_h, time());
    $b = wi_sg_befehle($cfg, $laden, $dimmen === true);
    return array(
        'ein'        => $ein,
        'senden'     => wi_cfg($cfg, 'sg_senden', '0') === '1',
        'preise'     => $preise,
        'quelle'     => $quelle,
        'qhinweis'   => $qhinweis,
        'fenster'    => $fenster,
        'planhinweis' => $planhinweis,
        'dimmen'     => $dimmen,
        'alter14a'   => $alter14a,
        'h14a'       => $h14a,
        'laden'      => $laden,
        'laden_plan' => $laden_plan,
        'gekappt'    => $gekappt,
        'laden_seit' => $laden_seit,
        'laden_max'  => $max_h,
        'merker'     => $merker,
        'lage'       => $b['lage'],
        'befehle'    => $b['befehle'],
        'fehlt'      => $b['fehlt'],
        'kreis'      => wi_cfg($cfg, 'sg_kreis', 'direkt'),
    );
}

/* ==================================================================
 * 7. Senden - nur mit dem zweiten Schalter, und nur bei Wechsel
 * ================================================================== */

/**
 * Eine JSON-Datei lesen, die es geben KANN oder auch nicht.
 *
 * is_file() davor ist Pflicht, nicht Zierde: ein vorangestelltes @ unterdrueckt
 * nur die Standardbehandlung, es haelt einen ueber set_error_handler()
 * eingehaengten Aufnehmer NICHT auf. Genau daran hat rendern.py diese Datei
 * beim ersten Lauf beanstandet - vier Renderlaeufe mit einer WARNUNG fuer eine
 * Merkerdatei, die es beim ersten Start naturgemaess noch nicht gibt.
 */
function wi_sg_json_lesen($pfad)
{
    if ($pfad === '' || !is_file($pfad)) {
        return null;
    }
    $roh = @file_get_contents($pfad);
    if ($roh === false || $roh === '') {
        return null;
    }
    $d = json_decode((string) $roh, true);
    return is_array($d) ? $d : null;
}

/** Merkerdatei: welche Lage wurde zuletzt wirklich gestellt? */
function wi_sg_merker_datei()
{
    $o = wi_sg_bestand_ordner();
    return $o !== '' ? $o . '/sg_stand.json' : sys_get_temp_dir() . '/wolf_sg_stand.json';
}

/**
 * Der Bestand des SG-Moduls NEBEN dem Datenordner: data/plugins/<ordner>.bestand/
 * (Durchgang 02.10.2026, Nachtrag). Bis dahin lag der Merker in
 * data/plugins/<ordner>/, und purge_installation loescht genau diesen Ordner
 * bei jedem Upgrade: nach einem Update waehrend einer Ladung wusste das Modul
 * nichts mehr von dem gesendeten Zwang - Hoechstdauer und Zuruecknehmen beim
 * Ausschalten griffen nie. Eine Neuinstallation legt den Bestand nach .alt
 * (postinstall.sh, Entscheidung 1), die Deinstallation raeumt ihn ab.
 * Leer ohne LoxBerry-Wurzel.
 */
function wi_sg_bestand_ordner()
{
    $p = wi_paths();
    return $p['home'] !== '' ? $p['home'] . '/data/plugins/' . $p['plugin'] . '.bestand' : '';
}

/** Befehlszeilen "<id>;<wert>" aus einer Befehlsliste (C3). */
function wi_sg_zeilen($befehle)
{
    $z = array();
    foreach ($befehle as $b) {
        $z[] = $b['id'] . ';' . $b['wert'];
    }
    return $z;
}

/**
 * C3: Steht an der Heizung schon genau das? Verglichen werden Lage, Kreis UND
 * Befehlszeilen. Bis 3.1.5 nur die Lage: nach einem Kreiswechsel waehrend
 * einer Ladung blieb der alte Kreis angehoben und der neue wurde nie
 * gestellt (Bericht code, Befund 3, S4); ebenso nach einem geaenderten
 * Ladesollwert. Ein Merker ohne Kreis (bis 3.1.5 geschrieben) gilt als
 * verschieden - es wird einmal neu gestellt.
 */
function wi_sg_gleich($merker, $lage, $kreis, $zeilen)
{
    if (!is_array($merker) || !isset($merker['lage'], $merker['kreis'], $merker['zeilen'])
        || !is_array($merker['zeilen'])) {
        return false;
    }
    return (string) $merker['lage'] === (string) $lage
        && (string) $merker['kreis'] === (string) $kreis
        && array_values($merker['zeilen']) === array_values($zeilen);
}

/** Befehle senden; Rueckgabe array(angekommen, meldungen). */
function wi_sg_befehle_senden($cfg, $befehle)
{
    $n = 0;
    $meld = array();
    foreach ($befehle as $b) {
        $antwort = wi_befehl_senden($cfg, $b['id'] . ';' . $b['wert']);
        $meld[] = array('SG.M_GESENDET', array($b['id'], $b['wert'], $antwort));
        if (strpos((string) $antwort, 'OK') === 0) {
            $n++;
        }
    }
    return array($n, $meld);
}

/** Die Normalbefehle fuer einen bestimmten Kreis (C2, C3). */
function wi_sg_normal_fuer($cfg, $kreis)
{
    $c = $cfg;
    $c['sg_kreis'] = $kreis;
    return wi_sg_befehle($c, false, false);
}

/**
 * Die Lage stellen.
 *
 * $ernst = false ist der Trockenlauf: er bildet dieselben Befehle, prueft
 * dieselben Wachen und sendet nur nichts. Kein zweiter Weg, sondern ein
 * Parameter - zwei Wege liefen auseinander.
 *
 * Rueckgabe: array(gesendet, uebersprungen, meldungen)
 */
function wi_sg_stellen($cfg, $ernst)
{
    $l = wi_sg_lage($cfg);
    $meld = array();
    if (!$l['ein']) {
        return array(0, 0, array(array('SG.M_AUS', array())));
    }
    if ($l['fehlt']) {
        return array(0, 0, array(array('SG.M_FEHLT', array(implode(', ', $l['fehlt'])))));
    }
    if (!$l['befehle']) {
        return array(0, 0, array(array('SG.M_NICHTS', array())));
    }
    if ($l['gekappt']) {
        $meld[] = array('SG.M_GEKAPPT', array((int) $l['laden_max'],
                                              date('d.m. H:i', (int) $l['laden_seit'])));
    }
    // Nur bei WECHSEL stellen. Ein Cron alle fuenf Minuten, der jedes Mal
    // vier Befehle schickt, erzeugt 1152 Schreibvorgaenge am Tag an einer
    // Heizung, die sich dreimal aendert.
    $merker = $l['merker'];
    $zeilen = wi_sg_zeilen($l['befehle']);
    if (wi_sg_gleich($merker, $l['lage'], $l['kreis'], $zeilen)) {
        // C1: endet die Ladung im Plan, faellt laden_seit - sonst kappte die
        // naechste Ladung sofort. Dafuer wird nichts gesendet.
        if ($ernst && $l['senden'] && !empty($merker['laden_seit']) && !$l['gekappt']
            && $l['lage'] !== 'laden') {
            $merker['laden_seit'] = 0;
            wi_sg_merker_schreiben($merker);
        }
        $meld[] = array('SG.M_UNVERAENDERT', array($l['lage']));
        return array(0, count($l['befehle']), $meld);
    }
    if (!$ernst || !$l['senden']) {
        $meld[] = array('SG.M_TROCKEN', array($l['lage'], count($l['befehle'])));
        return array(0, 0, $meld);
    }

    $vorher_lage = is_array($merker) && isset($merker['lage']) ? (string) $merker['lage'] : '';
    $vorher_kreis = is_array($merker) && isset($merker['kreis']) ? (string) $merker['kreis'] : '';
    /* C3: Kreiswechsel waehrend eines Zwangs - zuerst den ALTEN Kreis auf
     * normal stellen. Kommt das nicht ganz an, wird der neue nicht gestellt
     * und der Merker bleibt: der naechste Lauf versucht es wieder. */
    if ($vorher_kreis !== '' && $vorher_kreis !== $l['kreis']
        && in_array($vorher_lage, array('laden', 'dimmen'), true)) {
        $alt = wi_sg_normal_fuer($cfg, $vorher_kreis);
        if ($alt['fehlt'] || !$alt['befehle']) {
            $meld[] = array('SG.M_KREIS_ALT_FEHLT', array($vorher_kreis));
        } else {
            list($na, $ma) = wi_sg_befehle_senden($cfg, $alt['befehle']);
            $meld = array_merge($meld, $ma);
            if ($na !== count($alt['befehle'])) {
                $meld[] = array('SG.M_TEILWEISE', array($na, count($alt['befehle'])));
                return array($na, 0, $meld);
            }
            $meld[] = array('SG.M_KREIS_ZURUECK', array($vorher_kreis, $l['kreis']));
        }
    }

    list($n, $ms) = wi_sg_befehle_senden($cfg, $l['befehle']);
    $meld = array_merge($meld, $ms);
    if ($n === count($l['befehle'])) {
        // Der Merker wird NUR fortgeschrieben, wenn wirklich alles ankam.
        // Sonst stuende beim naechsten Lauf "unveraendert", waehrend die
        // Heizung halb gestellt ist.
        if ($l['gekappt']) {
            $seit = (int) $l['laden_seit'];
        } elseif ($l['lage'] === 'laden') {
            $seit = ($vorher_lage === 'laden' && !empty($merker['laden_seit']))
                ? (int) $merker['laden_seit'] : time();
        } else {
            $seit = 0;
        }
        wi_sg_merker_schreiben(array('lage' => $l['lage'], 'kreis' => $l['kreis'],
                                     'zeilen' => $zeilen, 'laden_seit' => $seit,
                                     'gekappt' => $l['gekappt'] ? 1 : 0));
    } else {
        $meld[] = array('SG.M_TEILWEISE', array($n, count($l['befehle'])));
    }
    return array($n, 0, $meld);
}

/**
 * C2 (Durchgang 02.10.2026): einen gesendeten Zwang zuruecknehmen.
 *
 * Bis 3.1.5 liess das Ausschalten (sg_ein oder sg_senden auf 0) und die
 * Deinstallation die Heizung DAUERHAFT im angehobenen (WW-Soll 55, +2 K)
 * oder gedimmten Zustand stehen (Bericht code, Befund 2, S2/S3). Jetzt wird
 * einmal "normal" gesendet - nur wenn der Merker einen Zwang (laden oder
 * dimmen) traegt, fuer den Kreis aus dem Merker. Kommt nicht alles an,
 * bleibt der Merker stehen, und der naechste Lauf des Waechters versucht es
 * wieder.
 *
 * Rueckgabe array(rc, meldungen): rc 0 = nichts zu tun oder zurueckgenommen,
 * 1 = nicht (ganz) angekommen.
 */
function wi_sg_zuruecknehmen($cfg)
{
    $m = wi_sg_merker();
    if ($m === null || !in_array((string) $m['lage'], array('laden', 'dimmen'), true)) {
        return array(0, array(array('SG.M_ZURUECK_NICHTS', array())));
    }
    $kreis = (isset($m['kreis']) && isset(wi_sg_kreise()[(string) $m['kreis']]))
        ? (string) $m['kreis'] : wi_cfg($cfg, 'sg_kreis', 'direkt');
    $b = wi_sg_normal_fuer($cfg, $kreis);
    if ($b['fehlt'] || !$b['befehle']) {
        return array(1, array(array('SG.M_FEHLT', array(implode(', ', $b['fehlt'])))));
    }
    list($n, $meld) = wi_sg_befehle_senden($cfg, $b['befehle']);
    if ($n === count($b['befehle'])) {
        wi_sg_merker_schreiben(array('lage' => 'normal', 'kreis' => $kreis,
                                     'zeilen' => wi_sg_zeilen($b['befehle']),
                                     'laden_seit' => 0, 'gekappt' => 0));
        $meld[] = array('SG.M_ZURUECK_OK', array((string) $m['lage'], $kreis));
        return array(0, $meld);
    }
    $meld[] = array('SG.M_ZURUECK_TEIL', array($n, count($b['befehle'])));
    return array(1, $meld);
}

/** Merkdatei: hat ein Lauf mit sg_ein 1 schon SG-Themen gesendet? (C2) */
function wi_sg_mqtt_merker_datei()
{
    $o = wi_sg_bestand_ordner();
    return $o !== '' ? $o . '/sg_mqtt_ein' : '';
}

/**
 * C2: beim Ausschalten EINMAL ueber MQTT sg/laden 0, sg/dimmen 0 und sg/lage
 * aus senden (Regeln/07: "Eine Empfehlung, die verstummt, sendet 0, nicht
 * nichts"). Bis 3.1.5 behielt der virtuelle Eingang in Loxone die 1 auf
 * Dauer (Bericht mqtt, Befund 8). Einmal heisst: nur, wenn ein Lauf mit
 * sg_ein 1 vorher etwas gesendet hat (Merkdatei), oder ausdruecklich
 * ($immer, Deinstallation). Rueckgabe: Zahl der gesendeten Zeilen.
 */
function wi_sg_mqtt_aus($cfg, $immer)
{
    $merk = wi_sg_mqtt_merker_datei();
    if (!$immer && ($merk === '' || !is_file($merk))) {
        return 0;
    }
    $port = wi_mqtt_udpinport();
    if (!$port || wi_cfg($cfg, 'mqtt', '0') !== '1') {
        return 0;
    }
    $pre = wi_cfg($cfg, 'praefix', 'wolf_ng');
    set_error_handler(function () { return true; });
    $sock = fsockopen('udp://127.0.0.1', (int) $port, $nr, $txt, 3);
    restore_error_handler();
    if (!$sock) {
        return 0;
    }
    $n = 0;
    foreach (array('sg/laden' => '0', 'sg/dimmen' => '0', 'sg/lage' => 'aus') as $t => $w) {
        $z = 'publish ' . $pre . '/' . $t . ' ' . $w . "\n";
        if (@fwrite($sock, $z) === strlen($z)) {
            $n++;
        }
        usleep(2000);
    }
    fclose($sock);
    if ($merk !== '' && is_file($merk)) {
        @unlink($merk);
    }
    return $n;
}

/**
 * Merker unteilbar schreiben: Nebendatei mit PID, Rechte vor Inhalt, rename.
 * Seit dem Durchgang 02.10.2026 mit Kreis, Befehlszeilen und laden_seit (C1, C3).
 */
function wi_sg_merker_schreiben(array $daten)
{
    $ziel = wi_sg_merker_datei();
    $ordner = dirname($ziel);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }
    $daten['ts'] = time();
    $daten['befehle'] = isset($daten['zeilen']) && is_array($daten['zeilen']) ? count($daten['zeilen']) : 0;
    $js = json_encode($daten);
    if ($js === false) {
        return false;
    }
    $tmp = $ziel . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if (!$fh) {
        return false;
    }
    @chmod($tmp, 0644);
    $ok = @ftruncate($fh, 0) && @fwrite($fh, $js) === strlen($js);
    @fclose($fh);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $ziel)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Die zuletzt gestellte Lage, fuer die Anzeige. */
function wi_sg_merker()
{
    $d = wi_sg_json_lesen(wi_sg_merker_datei());
    if ($d === null) {
        // Ein Merker am alten Ort (bis zum Nachtrag 02.10.2026) gilt noch,
        // bis der naechste Lauf am neuen Ort schreibt.
        $p = wi_paths();
        if ($p['home'] !== '') {
            $d = wi_sg_json_lesen($p['home'] . '/data/plugins/' . $p['plugin'] . '/sg_stand.json');
        }
    }
    return $d !== null && isset($d['lage']) ? $d : null;
}
