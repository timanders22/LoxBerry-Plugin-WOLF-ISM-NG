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
    if ($d === null || !isset($d['dimmen'])) {
        return array(null, -1, 'SG.E14_KAPUTT');
    }
    $ts = isset($d['ts']) && ctype_digit((string) $d['ts']) ? (int) $d['ts'] : (int) (is_file($f) ? @filemtime($f) : 0);
    $alter = max(0, time() - $ts);
    $grenze = (int) wi_cfg($cfg, 'sg_14a_alter', '900');
    if ($grenze > 0 && $alter > $grenze) {
        /* VERALTET. Und jetzt kommt die Entscheidung, die man aussprechen
         * muss, statt sie im Code zu verstecken:
         *
         * Es wird NICHT gedimmt. Ein ausgefallener Melder ist kein Befehl
         * des Netzbetreibers, und die netzdienliche Steuerung haengt
         * ohnehin an dessen Steuerbox - nicht an diesem Plugin. Wuerde hier
         * vorsorglich gedimmt, kuehlte das Haus aus, weil ein Draht locker
         * ist. Gemeldet wird es dafuer laut: die Zeile im Reiter Test wird
         * rot, und das MQTT-Thema sg/14a traegt -1. */
        return array(false, $alter, 'SG.E14_VERALTET');
    }
    $dimmen = ($d['dimmen'] === 1 || $d['dimmen'] === '1'
               || $d['dimmen'] === true || $d['dimmen'] === 'true');
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
    $laden = wi_sg_im_fenster($fenster);
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
    $p = wi_paths();
    if ($p['home'] !== '') {
        return $p['home'] . '/data/plugins/' . $p['plugin'] . '/sg_stand.json';
    }
    return sys_get_temp_dir() . '/wolf_sg_stand.json';
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
    // Nur bei WECHSEL stellen. Ein Cron alle fuenf Minuten, der jedes Mal
    // vier Befehle schickt, erzeugt 1152 Schreibvorgaenge am Tag an einer
    // Heizung, die sich dreimal aendert.
    $merker = wi_sg_json_lesen(wi_sg_merker_datei());
    $vorher = $merker !== null && isset($merker['lage']) ? (string) $merker['lage'] : '';
    if ($vorher === $l['lage']) {
        return array(0, count($l['befehle']), array(array('SG.M_UNVERAENDERT', array($l['lage']))));
    }
    if (!$ernst || !$l['senden']) {
        $meld[] = array('SG.M_TROCKEN', array($l['lage'], count($l['befehle'])));
        return array(0, 0, $meld);
    }

    $n = 0;
    foreach ($l['befehle'] as $b) {
        $antwort = wi_befehl_senden($cfg, $b['id'] . ';' . $b['wert']);
        $meld[] = array('SG.M_GESENDET', array($b['id'], $b['wert'], $antwort));
        if (strpos((string) $antwort, 'OK') === 0) {
            $n++;
        }
    }
    if ($n === count($l['befehle'])) {
        // Der Merker wird NUR fortgeschrieben, wenn wirklich alles ankam.
        // Sonst stuende beim naechsten Lauf "unveraendert", waehrend die
        // Heizung halb gestellt ist.
        wi_sg_merker_schreiben($l['lage'], $n);
    } else {
        $meld[] = array('SG.M_TEILWEISE', array($n, count($l['befehle'])));
    }
    return array($n, 0, $meld);
}

/** Merker unteilbar schreiben: Nebendatei mit PID, Rechte vor Inhalt, rename. */
function wi_sg_merker_schreiben($lage, $anzahl)
{
    $ziel = wi_sg_merker_datei();
    $ordner = dirname($ziel);
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }
    $js = json_encode(array('lage' => $lage, 'ts' => time(), 'befehle' => (int) $anzahl));
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
    return @rename($tmp, $ziel);
}

/** Die zuletzt gestellte Lage, fuer die Anzeige. */
function wi_sg_merker()
{
    $d = wi_sg_json_lesen(wi_sg_merker_datei());
    return $d !== null && isset($d['lage']) ? $d : null;
}
