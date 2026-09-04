# LoxBerry-Plugin WOLF ISM NG

Nimmt die Daten des Wolf-Schnittstellenmoduls **ISM8** entgegen, übersetzt die
KNX-Telegramme in lesbare Werte und reicht sie an den Loxone Miniserver weiter —
per MQTT oder, auf dem alten Weg, per UDP. In der Gegenrichtung nimmt es
Schreibbefehle des Miniservers entgegen.

## Bitte zuerst lesen: Nutzungsbedingungen

Der Kern dieses Plugins — `bin/wolf_ism8i.pl` und `bin/wolf_server` — stammt
von **Dr. Mugur Dietrich** und steht unter den Bedingungen, die er selbst in
den Dateikopf geschrieben hat:

> Frei für den privaten und schulischen Einsatz.
> **Kommerziellen Einsatz nur nach vorhergehender Genehmigung!**

Das ist keine freie Lizenz im Sinne der OSI. Wer dieses Plugin gewerblich
einsetzen will, muss ihn vorher fragen (`m.i.dietrich@gmx.de`). Einzelheiten
und die vollständige Kette der Werke stehen in [LICENSE](LICENSE) und
[NOTICE](NOTICE).

Die Kette: **Dr. Mugur Dietrich** (Auswertungsmodul, 2017) → **Dominik
Holland** ([Gagi2k/LoxBerry-Plugin-WolfIsm8](https://github.com/Gagi2k/LoxBerry-Plugin-WolfIsm8),
Einbettung als LoxBerry-Plugin) → diese Fortführung.

## Version 3.1.0 — SG-Ready und § 14a EnWG

Ein neuer Reiter **SG-Ready** zwischen *MQTT* und *Einbindung in Loxone*. Er
rechnet aus Stundenpreisen Ladefenster aus, hebt in diesen Fenstern den
Warmwassersollwert und die Sollwertkorrektur an und stellt außerhalb die
Normalwerte wieder her. Ein Dimmsignal des Netzbetreibers nach § 14a EnWG hat
Vorrang vor allem anderen.

### Zuerst das Wichtigste: was hier nicht gemessen ist

**Kein einziger der gebildeten Befehle ist je an einem WOLF-Gerät angekommen.**
Dieses Plugin wurde ohne ISM8 gebaut. Belegt ist:

* dass die Datenpunkte 56 bis 105 in den mitgelieferten Tabellen als
  beschreibbar geführt sind (Spalte `Out/In`),
* welche Zahlen die Betriebsarten bedeuten (`wi_betriebsarten()`, deckungsgleich
  mit `getCsvResult()` im Auswertungsmodul),
* dass `parseInput()` diese Werte annimmt und daraus ein Telegramm baut.

**Nicht belegt ist, was Ihre Heizung daraufhin tut.** Deshalb sind zwei
Schalter nötig, nicht einer, und deshalb steht der Vorbehalt im Reiter selbst
noch einmal in ganzen Sätzen.

### Zwei Schalter

| Schalter | Was er tut |
|---|---|
| **SG-Ready rechnen** | Das Modul plant, zeigt den Fahrplan und veröffentlicht ihn über MQTT. Es schreibt **nichts** an die Heizung. |
| **An die Heizung schreiben** | Erst mit diesem zweiten Schalter gehen Befehle wirklich hinaus. |

Beide stehen ab Werk auf 0. Der Grund für den zweiten: eine Plugin-Einstellung
lässt sich zurücknehmen, ein an die Heizung geschriebener Sollwert wirkt sofort
und in einem Haus, in dem Menschen wohnen. Wer das einschaltet, soll es zweimal
getan haben. Der zweite Schalter lässt sich außerdem nicht setzen, solange der
erste aus ist — ein scharfer Schalter an einem stillen Modul wäre eine Falle.

### Woher die Preise kommen

Zwei Wege, und jeder sagt von sich, wie belastbar er ist:

1. **Eine eigene Datei** `config/plugins/<ordner>/sg_preise.json`. Format:

       {"preise": {"1757116800": 12.34, "1757120400": 9.87}}

   Schlüssel ist der Unix-Zeitstempel des Stundenbeginns, Wert der Preis in
   ct/kWh. Diesen Weg kann jedes Plugin und jedes Skript bedienen, und er ist
   der einzige, dessen Format hier zugesagt wird.

2. **Der Zwischenspeicher des aWATTar-Plugins**
   (`data/plugins/spotpreis/markt_<tld>_<JJJJMMTT>.json`). Bequemer, aber das
   ist dessen **interne Ablage und keine Schnittstelle** — gemessen am
   04.09.2026 an `LoxBerry-Plugin-Spotpreis-aWATTar-1.2.20`, Funktion
   `spot_day()`. Ändert jenes Plugin sein Format, liefert dieser Weg **nichts**
   statt etwas Falsches. Viertelstundenwerte werden zu Stundenmitteln
   zusammengefasst, nicht ausgewählt — dieselbe Rechnung wie im Quellplugin.

Gezeigt wird der **Börsenpreis**, nicht Ihr Arbeitspreis. Netzentgelte, Umlagen
und Steuern kommen hinzu. Für die Frage, welche Stunde die günstigste ist,
genügt das, weil die übrigen Bestandteile über den Tag gleich sind; für eine
Kostenaussage genügt es nicht.

### Wie die Ladefenster entstehen

Das Verfahren ist gierig und in einem Satz erklärbar: es sucht das billigste
zusammenhängende Fenster der eingestellten Mindestlänge, bucht es, und
wiederholt das, bis die gewünschte Stundenzahl zusammen ist. Es ist **nicht
optimal**. Es ist dafür nachvollziehbar, wenn jemand um drei Uhr nachts wissen
will, warum die Heizung gerade lädt.

Ein Fenster muss **lückenlos** sein. Fehlt eine Stunde im Preisbestand, wird
sie nicht überbrückt — sonst wäre „zwei Stunden am Stück" eine Zusage, die der
Plan nicht hält.

**Warum hier nicht `planer.php` aus den Spotpreis-Plugins steht:** der
Fahrplaner dort beantwortet eine andere Frage — welche Zeitscheiben ein
Verbraucher mit Energiemenge, Frist, Rang und Leistungsbudget belegt. Eine
Heizung hat keine Frist und keine vorher bekannte Energiemenge; sie hat einen
Speicher, der sich zu füllen lohnt, solange der Strom billig ist. Das ist eine
Auswahl, keine Belegung. Ihn hierher zu kopieren hieße außerdem, eine dritte
Kopie derselben Datei zu führen.

### Was gestellt wird

Drei Lagen, die einander ausschließen:

| Lage | Heizkreis | Warmwasser | Sollwertkorrektur |
|---|---|---|---|
| **gedimmt (§ 14a)** | Sparbetrieb oder Standby (einstellbar) | Standby | 0 |
| **Ladefenster** | Automatik | Automatik, angehobener Sollwert | angehoben |
| **normal** | Automatik | Automatik, normaler Sollwert | 0 |

Die Datenpunktnummern werden **aus der Tabelle der eingestellten Firmware
gelesen**, nicht im Modul hinterlegt — sonst gäbe es eine zweite Wahrheit über
die Anlage. Kennt die Firmware einen der vier gebrauchten Punkte nicht, wird
**gar nichts** gestellt und die Oberfläche sagt, welcher fehlt: ein halb
gestellter Heizkreis ist schlimmer als ein ungestellter.

Gesendet wird nur bei einem **Wechsel** der Lage. Ein Cron alle fünf Minuten,
der jedes Mal vier Befehle schickt, erzeugte 1152 Schreibvorgänge am Tag an
einer Heizung, die sich dreimal ändert. Und der Merker wird nur fortgeschrieben,
wenn **alle** Befehle angekommen sind — sonst stünde beim nächsten Lauf
„unverändert", während die Heizung halb gestellt ist.

### § 14a EnWG

**Das Signal kommt nicht von hier.** Es kommt von der Steuerbox Ihres
Netzbetreibers. Dieses Modul ist die Energiemanagement-Seite daneben, kein
Ersatz für die netzdienliche Steuerung — die ist eine Pflicht des Betreibers
und hängt an dessen Gerät.

Gelesen wird `data/plugins/<ordner>/sg_14a.json`:

    {"dimmen": 1, "ts": 1757116800, "quelle": "Loxone VQ Steuerbox"}

Wer sie schreibt, ist offen: ein virtueller Ausgang in Loxone, ein Shelly, ein
Skript. Der Befehls-Port des Plugins kann es nicht — der nimmt nur
Datenpunktbefehle.

**Ist das Signal älter als die eingestellte Grenze, wird NICHT gedimmt.** Das
ist eine bewusste Entscheidung und keine Nachlässigkeit: ein ausgefallener
Melder ist kein Befehl des Netzbetreibers, und würde hier vorsorglich gedimmt,
kühlte das Haus aus, weil ein Draht locker ist. Gemeldet wird es dafür laut —
die Zeile im Reiter Test wird rot, und das Thema `sg/dimmen` trägt `-1`.

### Über MQTT

Sechs neue Themen unter `<präfix>/sg/`: `lage`, `laden`, `dimmen`, `fenster`,
`naechster_start`, `naechster_preis` — Zustände, also retained — und `ts` als
Lebenszeichen der Rechnung, nicht retained. Damit lässt sich der Fahrplan in
Loxone anzeigen, ohne dass Loxone irgendetwas rechnen muss.

### Der Reiter Test

Zwei neue Zeilen: *Ist das SG-Ready-Modul stimmig eingerichtet?* (mit Zahl der
Preise, Zahl der Fenster und der aktuellen Lage) und *Ist das Dimmsignal nach
§ 14a frisch?* Die zweite ist grün nur bei einem wirklich frischen Signal, ein
Strich bei ausgeschalteter Auswertung und sonst ein Kreuz — auch bei einem
veralteten Signal, denn ein Haken neben dem Satz „das Signal ist veraltet" wäre
genau der Fehlalarm, den diese Reihe sonst überall beseitigt.

Der Rechenkern hat einen eigenen Selbsttest ohne Anlage:

```bash
php bin/wolf_sg.php --selbsttest
```

13 Fälle, unter PHP 7.4 und 8.4 gemessen, darunter die Gegenprobe, dass eine
Lücke im Preisbestand kein Fenster überbrückt.

## Version 3.0.11 — dreiundzwanzig Befunde behoben

Eine reine Korrekturfassung. Sie bringt keine neue Funktion; jeder Punkt unten
ist auf diesem Rechner gemessen, keiner an einem WOLF-Gerät.

### Wichtig für alle, die das Themen-Präfix geändert haben

**Die MQTT-Vorlagen und die Themenliste zeigten `wolf_ng/…`, gleichgültig was
eingestellt war.** `wi_topic()` trug das Vorgabepräfix fest verdrahtet, während
der Kommentar daneben das Gegenteil zusicherte. Gemessen mit `praefix heizung`:
von 223 Titeln der MQTT-Eingangsvorlage trugen 220 das falsche Präfix, und alle
71 Befehle der Ausgangsvorlage schrieben auf ein Thema, das der Dienst gar nicht
abonniert. Der Dienst selbst sendete immer richtig — falsch war die Anzeige und
die daraus erzeugte Vorlage. Wer sein Präfix geändert hat, holt die Vorlagen
bitte neu und liest sie erneut ein.

**Der Dienst hörte nach einer Präfix-Änderung weiter auf das alte Thema.**
`connect_MQTT()` lief genau einmal beim Start; Abonnement und letzter Wille
hingen daran. Über die Oberfläche erzwingt eine Präfix-Änderung jetzt einen
Neustart des Dienstes, und der Dienst selbst verbindet bei SIGHUP neu, wenn
sich Präfix oder MQTT-Schalter geändert haben.

### Retain: Zustände ja, Messwerte nein, Lebenszeichen nie

Bis 3.0.10 ging **jeder** Wert retained hinaus — vier `retain`-Aufrufe, kein
einziger `publish`. Ein Miniserver, der sich verband, während die Heizung ruhte,
bekam eine Vorlauftemperatur von vorgestern und konnte sie von einem frischen
Wert nicht unterscheiden. Und das Lebenszeichen war als Ausfallerkennung
entwertet: ein toter Dienst lieferte einem neu abonnierenden Empfänger weiterhin
Zeitstempel und Zählerstand.

Jetzt entscheidet der KNX-Datenpunkttyp: Schalter, Freigaben, Betriebsarten und
die beiden Zähltypen gehen retained hinaus, alle Messgrößen mit Zeitbezug nicht.
`zeitstempel` und `zaehler` sind nicht mehr retained, der letzte Wille auf
`<präfix>/online` dagegen schon — erst damit hält er, was der Hilfetext ihm
zuschreibt. Die Namenstabelle im Reiter MQTT hat dafür eine dritte Spalte
bekommen, und der Reiter Test zählt die Typenliste der Oberfläche gegen die des
Dienstes.

**Für bestehende Anlagen:** Messwerte, die bisher retained im Broker standen,
bleiben dort stehen, bis jemand sie räumt. Der Knopf *Retained-Themen aufräumen*
im Reiter MQTT tut das.

### Sichern und Zurückspielen passten im Werkszustand nicht zueinander

Solange keine Störcodetabelle gewählt war — der Auslieferungszustand —, schrieb
*Einstellungen sichern* die Zeile `stoercodes` ohne Wert, und *Einstellungen
zurückspielen* lehnte deshalb **die ganze Datei** ab. Der Umzug auf einen
zweiten LoxBerry war damit auf jeder frischen Anlage unmöglich, und die Meldung
zeigte auf eine Einstellung, die der Anwender nie angefasst hatte. Eine Zeile
mit nur einem Schlüssel gilt jetzt als leerer Wert — dieselbe Regel in allen
drei Lesestellen. Der Reiter Test fährt den Rundlauf als eigene Prüfzeile.

Zusätzlich prüft das Zurückspielen jetzt **jeden Wert**, nicht nur den
Schlüssel, und zwar mit derselben Positivliste wie das Formular. Vorher kam
alles durch, was keinen Leerraum enthielt.

### Der Befehls-Port nahm negative Datenpunktkennungen an

Perl zählt negative Feldindizes vom Ende. Die Kennung `-1` wählte damit den
höchsten belegten Datenpunkt aus; unter Firmware 1.5 ist das die schreibbare
Kesselsolltemperaturvorgabe. Der Befehl `-1;25` wurde angenommen, und das
Telegramm ging mit der Kennung `0xFFFF` an den Heizungsbus. Unter 1.4, 1.8 und
1.9 wurde er zufällig abgewiesen — es hing allein daran, ob der höchste
Datenpunkt gerade schreibbar ist. Die Kennung muss jetzt eine Zahl sein.

Derselbe Zugriff blähte außerdem das Datenpunktfeld dauerhaft auf (gemessen:
372 → 65535 → 300000 Plätze). In einem Dauerläufer mit offenem Befehls-Port war
das Speicherwachstum auf Zuruf von außen.

### Ein Befehl mit Zeilenumbruch wurde weggeworfen

Der getrimmte Befehl wurde nur für den Antworttext benutzt, geprüft wurden die
rohen Daten. Ein `\r\n` — die Form, die ein virtueller Loxone-Ausgang je nach
Einstellung schickt — kam als `1\r` an und fiel bei `DPT_Switch`, `DPT_Bool`,
`DPT_Enable`, `DPT_OpenClose`, `DPT_HVACMode` und `DPT_DHWMode` durch die
Wertprüfung. Die Zahlentypen nahmen ihn an, weil ihr Muster ein `\s*` führt —
genau das machte den Fehler so schwer zu sehen.

### Die Deinstallation ließ die Zweitschrift liegen

`config/plugins/<ordner>.backup.wolf_ism8i.conf` liegt neben dem
Konfigurationsordner, und der Installer räumt nur den Ordner selbst ab. Sie
blieb also liegen, und `postinstall.sh` spielte sie bei der nächsten
Installation zurück: eine „saubere" Neuinstallation stand danach wieder auf
`enable 1`, den alten Ports und der alten Multicast-Adresse. `uninstall`
entfernt sie jetzt und sagt, ob es geklappt hat.

### Ausgangsvorlagen: `CmdOff` war leer, und acht Befehle konnten nie wirken

Alle 71 Befehle beider Ausgangsvorlagen hatten ein leeres `CmdOff` — die
Schalt-Datenpunkte ließen sich einschalten und nicht wieder aus. Digitale
Befehle tragen jetzt feste Nutzlasten für Ein und Aus. Und der Filter, der
Uhrzeit- und Datumstypen aus den **Eingangs**vorlagen hält, gilt jetzt auch für
die Ausgangsvorlagen: acht analoge Befehle je Datei konnten der Dienst
grundsätzlich nicht annehmen und erzeugten nur Fehlerzeilen im Protokoll.

**Für bestehende Anlagen:** Die Titel der Ausgangsbefehle tragen jetzt den
Zusatz `setzen`. Vorher hießen 63 von ihnen genauso wie der virtuelle Eingang
desselben Datenpunkts, und das MQTT-Gateway spricht seine Objekte über den
Namen an. Wer die Ausgangsvorlage neu einliest, bekommt die Befehle unter dem
neuen Namen **neben** den alten; die alten dürfen weg.

### Die Oberfläche

* **Die Selbstprüfung lief bei jedem Seitenaufruf**, auch beim Öffnen der
  Logdateien — gemessen 1,2 bis 1,7 Sekunden, darunter sechs Perl-Starts und die
  Erzeugung aller vier Loxone-Vorlagen. Sie läuft jetzt nur noch, wenn der
  Reiter Test der offene ist.
* **Die Reiterleiste war eine Schleife.** Damit war sie für
  `hausstandard_pruefen.py` unsichtbar, und die eigene Prüfzeile im Reiter Test
  meldete auf jeder Installation „Leiste 0, Bereiche 5, Liste 5" — ein
  Daueralarm, der nichts bedeutete. Die Leiste ist ausgeschrieben.
* **Der Speichern-Knopf war grün** und stand unter einer Legende, die Grün als
  „fragt nur ab, verändert nichts" erklärt. Er ist jetzt orange. Die
  Vorlage-Knöpfe sind grau statt orange, und die Legende dazu nennt keine
  zweite Bedeutung für dieselbe Farbe mehr.
* **Schritt 3 der Loxone-Anleitung** bekam das Abonnement statt einer Zahl
  eingesetzt und las sich „die vollständige Tabelle hätte `wolf_ng/#` Einträge".
* **133 von 228 Suchschlüsseln der Datenpunkttabelle waren leer** — gemessen
  unter PHP 7.4 mit einem deutschen Locale. `strtolower()` arbeitet byteweise
  und zerstörte die Umlaute, und `htmlspecialchars()` machte aus dem
  ungültigen UTF-8 stillschweigend eine leere Zeichenkette. Betroffen war jeder
  Datenpunkt mit Umlaut im Namen; über das Suchfeld war er nicht mehr zu finden.
* Der Rollbehälter breiter Tabellen wirkte nicht (`min-width` fehlte), ein
  abgewiesenes Formular sprang auf den falschen Reiter zurück, zwei Hinweistexte
  sagten etwas zu, was der Code nicht tut, und acht Sprachschlüssel waren tot.
* Der Reiter Test rät nicht mehr jedem Anwender zur Firmware 1.9. Die
  Kandidatenliste lief absteigend, und weil die fünf Tabellen echte Obermengen
  ineinander sind, traf 1.9 immer zuerst.

### Installation und Betrieb

* `bin/wolf_server` leitet seinen Pfad aus dem eigenen Ablageort ab statt aus
  `${LBPBIN}/wolf_ng`. War `LBPBIN` nicht gesetzt, startete `start` nichts und
  meldete trotzdem Erfolg, und `stop` beendete nichts und meldete ebenfalls
  Erfolg. Bei einer Zweitinstallation bediente das zweite Exemplar das erste.
* `start` und `restart` messen jetzt die Wirkung, statt den Rückgabewert eines
  Hintergrundstarts zu lesen. Dasselbe gilt für den Protokollordner des
  Watchdogs.
* Der Cron startet den Dienst als `loxberry`, nicht als root — sonst gehören
  Protokoll- und Zustandsdatei danach root, und die Oberfläche kommt nicht mehr
  daran.
* Die mitgelieferte `config/wolf_ism8i.conf` führt jetzt alle fünfzehn
  Einstellungen; `postinstall.sh` rechnet ihre Prüfsumme, statt sie
  einzutragen.
* Der Online-Zustand geht nicht mehr per UDP hinaus, wenn die Direktausgabe
  ausgeschaltet ist.

### Nicht gemessen — und deshalb nicht behauptet

Es gibt weiterhin kein WOLF-Gerät und kein ISM8. Ungeprüft bleiben: ob
Datenpunkt 372 die Nummer aus der Gerätetabelle sendet, ob das ISM8 den
Vollabzug hinter `F0 D0` annimmt, ob das MQTT-Gateway eine leere Nutzlast als
Löschung weiterreicht, und wie die Oberfläche im LoxBerry-Rahmen aussieht.

## Version 3.0.10 — der Abo-Text folgt der Gateway-Fassung

Der Reiter MQTT nannte bis dahin für beide Gateway-Fassungen denselben Schritt.
Jetzt steht die Themengruppe zuerst als eigene Zeile, und darunter genau der
Satz, der zur gemessenen Fassung passt: unter Gateway 1 die Aufforderung, das
Abonnement einzutragen, mit dem Hinweis, dass ohne diesen Eintrag am Miniserver
nichts ankommt; unter Gateway 2 die Feststellung, dass dort nichts einzutragen
ist. Ist die Fassung nicht lesbar, stehen beide Sätze da — eine Fassung zu
behaupten wäre für die Hälfte der Anlagen falsch.

## Version 3.0.9 — Störcodes im Klartext, und ein stiller Fehler behoben

### Zuerst der Fehler, denn er betrifft jede laufende 3.0.8

**Ein Speichervorgang löschte drei Einstellungen** — `praefix`,
`herzschlag` und `abgleich_takt`. Die Oberfläche bot die Felder an, der Dienst
las sie, aber sie fehlten in `wi_defaults()` — und `wi_config_write()` schreibt
ausschließlich die Schlüssel aus dieser Liste. Wer also ein eigenes
MQTT-Präfix gesetzt hatte und danach irgendetwas anderes speicherte, verlor es:
der Dienst fiel auf `wolf_ng` zurück, und **sämtliche MQTT-Themen wanderten
mit**. In Loxone standen danach virtuelle Eingänge, die nie wieder einen Wert
bekamen.

Gemessen an einem nachgebauten LoxBerry, in beide Richtungen: auf 3.0.8 sind
alle drei nach einem Speichern **fort**, auf 3.0.9 stehen sie unverändert da.
Die Gegenprobe läuft im selben Durchgang mit `fw_version` — einem Schlüssel,
der in `wi_defaults()` steht und deshalb auch auf 3.0.8 überlebt.

**Was zu tun ist:** nach dem Update Präfix, Lebenszeichen und Vollabzug einmal
nachsehen und neu setzen, falls sie auf der Vorgabe stehen. Der Reiter *Test*
hat dafür eine neue Zeile: *Speichern erhält alle Einstellungen*. Sie
vergleicht, was in der Konfiguration steht, mit dem, was ein Schreibvorgang
zurückschreiben würde, und nennt jede Einstellung, die dabei verlorenginge.

Der Fehler wirkte auch auf die Sicherung: sie wird ebenfalls aus
`wi_defaults()` gebildet und trug die drei Schlüssel deshalb nicht. Eine
zurückgespielte Sicherung war damit unvollständig. Jetzt trägt sie **alle**
Einstellungen — nachgemessen: 15 von 15.

### Störcodes im Klartext — sieben Tabellen, und keine Sammeltabelle

Datenpunkt 372 „Zuletzt aktiver Störcode“ liefert eine Zahl. In 3.0.8 blieb
sie eine Zahl, weil die Zuordnung nicht erfunden werden sollte. Sie ist jetzt
aus den offiziellen WOLF-Betriebsanleitungen ausgelesen — **und dabei kam
heraus, dass es die eine Tabelle gar nicht gibt:**

    72 verschiedene Codenummern über alle Geräte
    60 bedeuten überall dasselbe
    12 sind mehrdeutig

| Code | Brennwertgerät | Wärmepumpe | |
|---|---|---|---|
| 15 | Außentemperaturfühler defekt | T_Aussen | dieselbe Störung, anders benannt |
| **116** | **Externe Störung Eingang E1** | **ESM** | **andere Störung** |

Eine gemeinsame Tabelle wäre also nicht bloss ungenau, sondern stellenweise
falsch — und in Loxone stünde ein Klartext, auf den jemand eine
Benachrichtigung legt. **Deshalb liegt eine Tabelle je Gerätefamilie bei, und
im Reiter *Einstellungen* wählt man seinen Wärmeerzeuger.** Ohne Auswahl
bleibt der Störcode eine Zahl — geraten wird nicht.

| Tabelle | Geräte | Zeilen |
|---|---|---|
| CGB-2-38, CGB-2-55 | Gas-Brennwerttherme | 37 |
| CGB-2-75, CGB-2-100 | Gas-Brennwerttherme | 39 |
| TGB-2 | Gas-Brennwertkessel | 39 |
| COB-2 | Öl-Brennwertkessel | 35 |
| TOB | Öl-Brennwertkessel | 42 |
| CHA-07/10, CHA-16/20, CHA-20/24 | Wärmepumpe (HCM-4) | 25 |
| FHA-Standard, FHA-Center | Wärmepumpe (HCM-5) | 27 |

Die drei CHA-Anleitungen tragen **wortgleich** dieselbe Tabelle, die beiden
COB-2-Anleitungen ebenso — auch das gemessen, nicht angenommen. Daneben
liegen fünf `wolf_warncodes_*.csv`: die *Warn*meldungen sind eine eigene
Tabelle, die dieselben Nummern anders belegt. Sie gehören **nicht** zu
Datenpunkt 372 und werden nur mitgeliefert, damit niemand beides vermischt.

Eine eigene Datei unter `config/plugins/<ordner>/wolf_stoercodes.csv` schlägt
weiterhin alles. Der Reiter *Test* sagt jetzt, welche Tabelle gerade gilt und
woher sie stammt — mit drei Ausgängen, denn „keine gewählt“ ist weder
Fehler noch Erfolg.

### Wie belastbar der Auszug ist

Ausgelesen über die Wortkoordinaten der PDF-Seiten, Spaltengrenzen aus dem
Tabellenkopf. Gegengeprüft mit einem zweiten Werkzeug (`pdftotext`): 266 der
329 Zeilen unabhängig belegt. Die übrigen 63 sind nicht falsch, sondern mit
diesem Mittel nicht prüfbar — bei mehrzeiligen Zellen schiebt `pdftotext` den
Text der Nachbarspalte dazwischen. Dafür kam ein drittes Mittel dazu: vier
Tabellenseiten wurden gerendert und abgelesen, 19 Zeilen aus beiden
Gerätefamilien, darunter gezielt die offengebliebenen — alle 19 stimmten
zeichengenau.

**Und die Gegenrichtung:** jede Zahl, die auf einer Tabellenseite in der
Codespalte steht, ist auch im Auszug — null Fehlende über zehn Anleitungen,
geprüft mit einer Probe, die eine künstlich entfernte Zeile meldet.

**Was offen bleibt:** ob Datenpunkt 372 tatsächlich die Nummer aus der
Gerätetabelle sendet und nicht eine intern umgerechnete, ist ohne ein echtes
ISM8 nicht messbar. Das ist die eine Annahme, auf der die Klartexte stehen.
Wer es an seiner Anlage nachsehen kann, möge es melden.

## Version 3.0.8 — dreissig Befunde behoben, dreiundzwanzig Funktionen dazu

Aus einer zeilenweisen Durchsicht der 3.0.7 mit zwei Gegenlesern. Die
Prüfstände liegen unter `Pruefung-WOLF-ISM-NG-3.0.8/`; jede Korrektur ist in
beide Richtungen geeicht — die Prüfreihen sind auf der alten Fassung rot und
auf dieser grün.

### Neue Funktionen

**Das Wichtigste zuerst: die Oberfläche zeigt jetzt Werte.** Bis 3.0.7 gab es
dort keinen einzigen Messwert — wer wissen wollte, welche Vorlauftemperatur
anliegt, brauchte den MQTT-Finder oder eine Textsuche im Protokoll. Der Dienst
schreibt sein Zustandsabbild jetzt nach `data/plugins/<ordner>/zustand.json`
(unteilbar über `rename`), und der Reiter *Test* zeigt daraus eine Tabelle mit
Gerät, Datenpunkt, Wert, Einheit und Alter — samt Klartext für Betriebsarten
und Störcodes.

**Lebenszeichen.** `<präfix>/zeitstempel` und `<präfix>/zaehler` gehen bei jedem
Takt hinaus, auch wenn sich kein Wert geändert hat — sonst wäre der Zeitstempel
selbst der älteste Wert im Broker. Der Zähler läuft 0…999 um; darauf legt man in
Loxone eine Änderungsüberwachung. Ohne ihn ist ein toter Dienst von einer ruhigen
Heizung nicht zu unterscheiden. Ab Werk alle 60 s.

**Firmware-Plausibilität.** Unbekannte Datenpunktkennungen werden gezählt und ihr
Bereich gemerkt. Der Reiter *Test* sagt dann konkret: *39 Telegramme trugen
Kennungen zwischen 212 und 250, die in der eingestellten Tabelle nicht stehen —
diese kennt Firmware 1.9.* **Die Grenze steht dabei:** so lässt sich nur der Fall
„Tabelle zu alt“ erkennen. Ist sie zu neu, kommen die zusätzlichen Kennungen nie
an, und Ausbleiben ist kein Befund.

**Eigener Reiter MQTT.** Haken, Themen-Präfix, Gateway-Zustand, die vollständige
Themenliste mit Bedeutung — und der Satz, der bisher ganz fehlte: **welches Abo
im Gateway einzutragen ist.** Er hängt an `Mqtt.Gatewayversion`: unter Fassung 1
das Abonnement `<präfix>/#`, unter Fassung 2 ist nichts einzutragen. Ist die
Fassung nicht lesbar, stehen beide Sätze da — einen von beiden zu behaupten
wäre für die Hälfte der Anlagen falsch.

**Das Themen-Präfix ist einstellbar** (Vorgabe unverändert `wolf_ng`). Wer zwei
ISM8 am selben Broker betreibt, kann sie damit trennen. Eine Änderung benennt
**sämtliche** Themen um — dafür gibt es das Aufräumen.

**Selbstprüfung im Reiter Test.** Siebzehn Zeilen mit Haken, Kreuz oder Strich,
ohne einen einzigen Klick. Drei Ausgänge, nicht zwei: der Strich heißt *hier war
nichts zu messen* und zählt in keiner Zusammenfassung als bestanden. Und jede
Zeile, die über eine Menge urteilt, prüft zuerst, ob die Menge leer ist.

**Baustein-Liste.** Acht Bausteine zum 1:1-Nachbauen, mit Typ, Namensvorschlag,
Parametern und Anschlüssen — dazu die Erläuterungen, an denen es sonst scheitert
(der Benachrichtigungsbaustein sendet nur beim Wechsel von Aus auf Ein; mehrere
Quellen niemals direkt an seinen Eingang).

**Betriebsarten im Klartext.** Über MQTT geht die nackte Zahl hinaus; die
Klartexte standen bisher nur im Perl. Jetzt stehen sie als Tabelle im Reiter
*Einbindung in Loxone*, mit fertigem Statustext zum Kopieren. Der Reiter *Test*
zählt sie gegen das Auswertungsmodul nach, damit die beiden Tabellen nicht
auseinanderlaufen.

**Vorlagen nur für die tatsächliche Anlage.** Ein Haken schränkt die
Loxone-Vorlage auf die Datenpunkte ein, für die schon ein Telegramm kam. Aus 267
Zeilen wird damit die eigene Heizung — ein virtueller Eingang, der nie einen Wert
bekommt, sieht in Loxone genauso aus wie einer mit 0.

**Die Loxone-Vorlagen selbst** entsprechen jetzt den Ausfuhren aus Loxone Config:
`HintText`, `<Info templateType=…>`, `CmdAnswer`, die gemessene
Attributreihenfolge — und **Einheiten und Grenzen je KNX-Datenpunkttyp** statt
pauschal ±2147483647. 125 der 267 lesbaren Datenpunkte tragen eine Einheit, die
bisher nirgends ankam.

**Schreibprobe mit Trockenlauf.** Ein Schaltbefehl lässt sich erproben, ohne
Loxone einzurichten. Der Trockenlauf läuft durch **dieselbe** Funktion wie der
Ernstfall und sendet nichts — und er spricht nicht die Sprache des Ernstfalls.

**Wächter über den Wächter.** `cron/cron.05min` startet den Watchdog nach, wenn
er nicht mehr läuft. Er schweigt im Normalfall und tut nichts, wenn das Plugin
ausgeschaltet ist. Der Reiter *Test* prüft, ob der Eintrag überhaupt angekommen
ist — und ob er eine **Datei** ist.

**Weiteres:** Konfiguration ohne Neustart (`SIGHUP`; nur ein Portwechsel braucht
noch einen) · Rückmeldung `OK`/`ERR` auf dem TCP-Befehlsweg · Kappung des
Datenpunkt-Protokolls bei 512 kB, mit Knopf zum Leeren · Einstellungen sichern
und zurückspielen · Ausgabeformat als Auswahlfeld (`none`/`data`/`csv`/`fhem`)
· Multicast-Gruppe wieder wählbar · vollständige, filterbare Datenpunkttabelle
· Aufräumen retained gebliebener Themen · periodischer Vollabzug (ab Werk aus).

**Ein Vorschlag ist nur zur Hälfte umgesetzt, und das steht hier:** Die
**Störcode-Tabelle** (Datenpunkt 372) ist gebaut, aber **leer ausgeliefert**. Die
Zuordnung steht in der Wolf-Dokumentation, nicht im Plugin — und sie wurde
bewusst nicht erfunden. Wer sie hat, trägt sie in
`config/plugins/<ordner>/wolf_stoercodes.csv` ein; dort überlebt sie ein Update.
Die Form steht in `bin/wolf_stoercodes.csv`.

### Der Tippfehler im Gerätenamen

Der Datenpunkt 372 hieß in den Firmwaretabellen 1.8 und 1.9 **„Allgmein“**.
Er heißt jetzt **„Allgemein“**. Damit wandert genau ein MQTT-Thema:

    <präfix>/Allgmein/Zuletzt/aktiver/Störcode
    <präfix>/Allgemein/Zuletzt/aktiver/Störcode

Wer diesen Datenpunkt benutzt, zieht den virtuellen Eingang nach. Der alte Wert
bleibt retained im Broker stehen — dafür gibt es im Reiter *MQTT* das
Aufräumen.

### Was falsche Werte erzeugt hat

- **Die KNX-Kennung `0x7FFF` wurde zu 670760,96.** Der Dateikopf von
  `pdt_knx_float` nennt sie seit jeher als Kennzeichen für *ungültige Daten*;
  geprüft wurde sie nie. Ein abgezogener oder defekter Fühler lieferte damit
  einen Extremwert in die Loxone-Regelung — und der sah aus wie ein Messwert.
  Jetzt wird nichts gesendet und eine Meldung geschrieben.
- **`100;abc` hat den Datenpunkt ausgeschaltet.** Der Vergleich `$data < 0`
  machte aus einer Zeichenkette eine 0, die Prüfung bestand, und
  `pack("C", $data)` erzeugte das Byte `0x00`. Der Befehl wirkte, nur anders
  als gemeint, und im Protokoll stand kein Wort davon. Jetzt abgewiesen.
- **Der Fehlerwert `-1` wurde zu einem Telegramm.** `to_pdt_time` und
  `to_pdt_date` gaben ihn im Fehlerfall zurück, `parseInput` reichte ihn
  ungeprüft weiter, und auf dem Bus kamen die ASCII-Zeichen `2d 31` an.
- **`12:30:00` wurde zu „Sonntag 00:00:00“.** Ohne Wochentag lieferte
  `split / /` nur ein Feld, die Bereichsprüfungen behandelten `undef` als 0,
  und ein unbekannter Wochentag ergab über `-1 << 5` den Tag 7. KNX kennt
  Tag 0 — eine Angabe ohne Wochentag ist jetzt gültig, ein falscher Wochentag
  nicht.
- **Beim Kodieren fehlte der Bereichsschutz.** Der Exponent war nicht
  begrenzt; aus 700000 wurde −9,8. Nachgerechnet kamen schon die im Code
  dokumentierten Bereichsgrenzen nicht heil durch die eigene Kodierung.
- **Die Fehlerkennung sah das Wertfeld nicht an.** `ERR:NoResult` und
  `ERR:TypeNotFound` landen dort — auf den Wegen `fhem` und `csv` gingen sie
  als „Wert“ an den Miniserver.

### Verbindung und Rahmen

- **Eine zweite TCP-Verbindung hat die bestehende, gesunde hinausgeworfen.**
  Solange schon ein Client stand, wurde der Annahmezweig übersprungen; die
  wartende Verbindung blieb im Rückstau, `can_read` feuerte dauernd, und der
  Abbruchzähler riss die alte Verbindung binnen Millisekunden ab. Gemessen:
  26 von 30 folgenden Telegrammen wurden nie mehr verarbeitet. Der häufigste
  Auslöser im Alltag ist das ISM8 selbst, das nach einer Netzstörung neu
  verbindet. Jetzt werden mehrere Verbindungen nebeneinander gehalten, jede
  mit eigenem Puffer; abgeräumt wird eine erst, wenn die Gegenstelle sie
  wirklich schließt. Der Abbruchzähler ist ersatzlos entfallen.
- **Telegramme über Puffergrenzen gingen verloren.** Was ein einzelner `recv`
  lieferte, galt als vollständige Telegrammfolge. Ein Telegramm, das sich auf
  zwei Lesevorgänge verteilte, wurde in **beiden** Hälften verworfen — und da
  4096 kein Vielfaches der Telegrammlänge ist, trat das beim Vollabzug
  planmäßig ein. Jetzt wird angesammelt und nur zerlegt, was vollständig da
  ist.
- **Der Pull-Request hat 22 Byte behauptet und war 17 lang.** Zwölf Elemente,
  eine Vorlage für siebzehn, fünf stillschweigende Nullbytes. Das Längenfeld
  wird jetzt aus dem Rahmen gerechnet.
  **Offen und ausdrücklich nicht gemessen:** ob das ISM8 hinter `F0 D0` noch
  Nutzdaten erwartet. Dort stehen wie bisher Nullbytes; erfunden wurde nichts.
- **Ein misslungener UDP-Versand hat den ganzen Server beendet** — ein
  `or die` auf der Zeile, durch die jeder Weg zum Miniserver läuft.
- **MQTT wurde geprüft, nachdem es benutzt war.** War der Broker beim
  Hochfahren nicht erreichbar, starb der Dauerläufer, bevor die ISM8-Ports
  offen waren; der Wächter gab nach zwanzig Anläufen endgültig auf.
- **Der Online-Zustand geht jetzt `retain`** und wird einmal beim Start
  angesagt. Ein Miniserver, der sich nach dem Plugin verbindet, sah ihn sonst
  erst beim nächsten Wechsel.
- `SIGPIPE` wird ignoriert, `SIGTERM` meldet `online;0` und schließt das
  Protokoll — ein `LOGEND` kam in der ganzen Datei nicht vor.

### Oberfläche

- **Kein Merkmal gegen fremde Absender.** Zwölf Formulare, kein Wachposten:
  ein POST von einer fremden Seite mit `save=1` setzte `enable` auf 0,
  schaltete MQTT und Direktausgabe ab und hielt den Server an. Jetzt ein
  Merkmal je Formular und **ein** Wachposten davor.
- **Ein unvollständiger POST setzte Ports auf die Vorgabe** statt auf den
  bestehenden Wert. Und unzulässige Eingaben wurden stillschweigend
  zurechtgebogen; sie werden jetzt gesammelt beanstandet, ohne das Speichern
  der übrigen Felder zu verhindern.
- **Zwei Vorgabelisten widersprachen sich** in zwei von elf Werten (`output`,
  `mqtt`). Fehlte ein Schlüssel in der Datei, zeigte die Oberfläche einen
  Betriebszustand an, den es nicht gab.
- **`%MS_IP%` wurde nie ersetzt.** Der Platzhalter stand in der
  mitgelieferten Konfiguration, kein Installationsskript ersetzte ihn, und
  die Adressprüfung setzte still `239.7.7.77`. Bis zum ersten Speichern
  gingen die UDP-Daten damit an die Multicast-Gruppe statt an den
  Miniserver.
- **Die MQTT-Ausgangsvorlage wurde auch mit Port 0 ausgeliefert** — die
  Oberfläche warnte davor und der Knopf daneben lieferte sie trotzdem. Eine
  Vorlage ohne einen einzigen Datenpunkt ebenso.
- **Die Themenliste im Reiter Test nannte acht Themen, die nie erscheinen.**
- Ein Reiterklick verwarf Eingaben in den anderen Reitern (`preventDefault`
  fehlte). Die Reiterleiste ist wieder für `hausstandard_pruefen.py`
  messbar. Knöpfe tragen `!important` und je Gruppe eine eigene Hover-Farbe.
- Der Konfigurationsleser der Oberfläche hält sich an dieselbe
  Kommentarregel wie der Dienst — vorher waren es zwei Leser derselben Datei
  mit verschiedenen Regeln.

### Kleineres

- Der Doppelt-senden-Filter verglich den Zeitstempel mit und prüfte nur gegen
  den unmittelbaren Vorgänger; jetzt je Datenpunkt.
- MQTT-Weg und UDP-Weg tragen dieselben Werte — vorher wichen sie bei
  Uhrzeit, Datum und den beiden Ucount-Typen ab.
- Eine unbekannte Firmware (`fw_version 1.6`) hat das Plugin dauerhaft
  getötet; jetzt Rückfall auf 1.8 mit Meldung.
- `enable` wird mitgeschrieben, wenn der Dienst die Konfiguration neu anlegt.
- `wolf_server status` prüft beide Prozesse, `stop` misst seine Wirkung.
- Sieben Helfer ohne Aufrufer entfernt; `create_logdir` wird jetzt wirklich
  gerufen.
- Bytefolgemarke aus `wolf_datenpunkte_15.csv`, `defined(&ReusePort)` in
  `ism8i_comtest.pl` berichtigt, deutsche Anführungszeichen im englischen
  Text.

### Was sich für bestehende Anlagen ändert

Drei Punkte, die an einer laufenden Anlage sichtbar werden können:

1. **Auf dem UDP-Weg `data`** gehen Uhrzeit und Datum jetzt als Zahl hinaus
   statt als Text (`Mo 12:30:00`), und die beiden Ucount-Typen aufbereitet
   statt roh. Betroffen sind in Firmware 1.9 siebzehn Datenpunkte.
2. **Auf dem Weg `fhem`** enthalten Werte keine Leerzeichen mehr
   (`Heiz-_Warmwasserbetrieb`) — das Format trennt Name und Wert durch ein
   Leerzeichen, die Werte enthielten selbst welche.
3. **Ein defekter Fühler sendet keinen Wert mehr**, statt 670760,96 zu
   senden. In Loxone bleibt der letzte Wert stehen — wer das erkennen will,
   braucht die Ausfallerkennung (`online_timeout`, ab Werk aus).

Die Ports (12004, 12005, 35353) und die MQTT-Themen ändern sich **nicht**.

### Was an keiner echten Anlage gemessen ist

**Diese Fassung ist ohne ein ISM8 entstanden.** Alle 122 Prüfzeilen laufen
gegen Attrappen; jeder Prüfstand ist in beide Richtungen geeicht (rot auf
3.0.7, grün auf 3.0.8), aber ein Prüfstand misst den Quelltext, nicht die
Heizung. Was das offen lässt, steht hier zusammen — statt verteilt in den
Abschnitten darüber:

* **Der periodische Vollabzug wird ausgeschaltet ausgeliefert.** Der Rahmen
  lügt seit 3.0.8 nicht mehr über seine eigene Länge, aber ob das ISM8 die
  Nutzlast hinter `F0 D0` annimmt, ist ungemessen. Wer ihn einschaltet,
  probiert etwas aus.
* **Ob das MQTT-Gateway eine leere Nutzlast als Löschung weiterreicht**, ist
  ungemessen. Das Aufräumen sagt das in seiner eigenen Ausgabe.
* **Die Störcode-Tabelle ist leer.** Die Zuordnung steht in der
  Wolf-Dokumentation, nicht im Plugin — und sie wurde bewusst nicht erfunden.
* **Das Schreiben zurück zur Anlage** ist nur im Trockenlauf geprüft. Der
  läuft durch dieselbe Funktion wie der Ernstfall und sendet nichts.
* **Firmware 1.9** ist aus den Tabellen abgeleitet, nicht an einem Gerät
  gesehen. Die Erkennung meldet nur den Fall „Tabelle zu alt“; ist sie zu
  neu, kommen die zusätzlichen Kennungen nie an, und Ausbleiben ist kein
  Befund.
* **Langzeitverhalten** — Speicher und Verbindungsabbrüche über Tage — ist
  nicht beobachtet.

**Wer ein ISM8 hat, kann hier mehr beitragen als jeder weitere Prüfstand.**
Der Reiter *Test* zeigt siebzehn Selbstprüfungen und die empfangenen Werte;
ein Bildschirmfoto davon, zusammen mit Firmwarestand und Heizungstyp, genügt
für eine Rückmeldung:

    https://github.com/timanders22/LoxBerry-Plugin-WOLF-ISM-NG/issues

Besonders wertvoll: eine Störcode-Zuordnung aus der Wolf-Dokumentation, und
ob der Vollabzug an einem echten Gerät etwas auslöst.

## Umstieg auf 3.0.0 — bitte vor dem Update lesen

> **Diese Fassung heißt anders und wird deshalb nicht als Update angeboten.**

Ordner und MQTT-Thema hießen bisher `wolfism8` — genauso wie in dem Plugin,
aus dem diese Fassung hervorgegangen ist. Wer beide installiert hatte, bekam
die Datenpunkte zweier Installationen unter denselben Themen. Ab 3.0.0 heißt
beides `wolf_ng`.

Zwei Folgen:

1. **LoxBerry sieht ein anderes Plugin.** Die Kennung entsteht aus Autorname,
   E-Mail und Plugin-Name. Eine vorhandene Installation bekommt dieses Update
   **nicht** angeboten und muss einmal von Hand installiert werden.
2. **Die MQTT-Themen wandern:**

       wolfism8/<Gerät>/<Datenpunkt>   →   wolf_ng/<Gerät>/<Datenpunkt>
       wolfism8/online                 →   wolf_ng/online

   Jeder virtuelle Eingang im Miniserver, der darauf hört, muss nachgezogen
   werden. **An den Ports (12004, 12005, 35353) ändert sich nichts**, der
   UDP-Weg bleibt also unberührt.

Das Repository heißt jetzt `LoxBerry-Plugin-WOLF-ISM-NG`.

## Version 2.5.2 — nachgemessen und korrigiert

Vierzehn Punkte aus einer Durchsicht. Neun trafen zu, drei teilweise, zwei
nicht.

### `use bignum` kostete Faktor 1100

Der schwerste Fund. Das Pragma macht aus jeder Zahl im lexikalischen Bereich
ein `Math::BigInt`/`BigFloat`-Objekt — auch aus Schleifenzählern, Bitmasken
und Schiebeoperationen. Nachgemessen mit der KNX-Float-Dekodierung dieses
Plugins, 200 000 Durchläufe:

| | Dauer |
|---|---|
| ohne `bignum` | **0,053 s** |
| mit `bignum` | **58,621 s** |

Der Vorschlag sprach von „einem Vielfachen" — es ist rund das
Elfhundertfache. Gegenprobe auf die Werte: dieselben Rechnungen mit und ohne
Pragma liefern zeichengleich dasselbe Ergebnis, auch an den Rändern (0,
65535, 2147483647, 4294967295). Das ist auch zu erwarten — KNX liefert 16-
und 32-Bit-Werte, die in einem Perl-Integer und in einem `double` exakt
darstellbar sind. Genauigkeit geht keine verloren.

`use diagnostics;` ist ebenfalls entfallen (0,13 s Startzeit, vor allem aber
seitenlange Erklärungen zu jeder Warnung im Protokoll).

### `to_pdt_time` zerlegte die Uhrzeit falsch

Trifft zu und ist ein echter Fehler: `split / /, $d[1]` auf `"HH:MM:SS"`
liefert **ein** Feld, `$min` und `$sec` blieben `undef`. Jeder Zeit-Schaltbefehl
schickte damit Müll an den Heizungsbus. Jetzt `split /:/`.

### Absturz bei getrenntem ISM8i

Trifft zu. `$wolf_client->send(...)` ohne Prüfung war bei fehlender
Verbindung ein *„Can't call method \"send\" on an undefined value"* — und
damit das Ende des ganzen Servers, nicht nur dieses einen Befehls. Geprüft
wird jetzt an beiden Stellen (MQTT-Befehl und Pull-Request).

### Verbindungsabbruch — Befund richtig, Abhilfe hätte geschadet

Beanstandet war, bei null gelesenen Bytes solle sofort abgebaut werden statt
zehnmal zu zählen. Der Befund stimmt: `can_read` feuert bei geschlossenem
Socket sofort wieder, gemessen **10 Runden ohne Pause**.

Die vorgeschlagene Abhilfe wäre aber falsch gewesen. Null Bytes heißen
**zweierlei**, und die Unterscheidung steht nicht in der Länge, sondern im
Rückgabewert von `recv`. An einem echten Socket gemessen:

| Lage | `recv` | Länge | `errno` |
|---|---|---|---|
| Verbindung offen, nichts da | **undef** | 0 | EAGAIN (11) |
| Gegenstelle hat geschlossen | **definiert** | 0 | 0 |

Bei null Bytes bedingungslos abzubauen hätte also auch eine gesunde
Verbindung bei einem Weckruf ohne Daten weggeworfen. `read_wolf_messages`
liefert jetzt `-1` für „Gegenstelle zu, sofort abbauen" und `0` für „diesmal
nichts, weiter warten".

### Weitere zutreffende Punkte

**`loadConfig` verwarf zu viel.** `if ($line !~ m/#/)` warf jede Zeile weg,
in der irgendwo ein Doppelkreuz stand — ein Kommentar hinter dem Wert
reichte, und die Einstellung fiel stillschweigend auf den Vorgabewert
zurück. Jetzt zählt nur noch ein Doppelkreuz am Zeilenanfang. Der Hinweis in
der Kopfzeile der erzeugten Konfigurationsdatei, der genau diese Einschränkung
beschrieb, ist mit angepasst.

**Doppelter Shebang** in `ism8i_comtest.pl` (Zeile 1 und 7) — entfernt.

**Konfiguration nicht atomar** geschrieben. Fällt der Serverprozess in das
Fenster zwischen Kürzen und Füllen, liest er eine halbe Datei und fällt auf
lauter Vorgabewerte zurück — andere Ports, andere Firmware-Fassung. Jetzt
temp und `rename`.

**Sicherung beim Upgrade in `/tmp`.** Dass `/tmp` flüchtig ist, stimmt.
Nicht zutreffend ist der übliche Zusatz, `$1` sei der vom Installer
bereitgestellte Ordner: `$1` ist eine zehnstellige Zufallskennung
(`&generate(10)` in `plugininstall.pl`), der absolute Arbeitsordner kommt als
**sechstes** Argument — das dieses Skript oben ohnehin schon einliest.
Gesichert wird jetzt dorthin, mit Rückfall auf den alten Weg. Beide Wege
nachgestellt: Ports, Firmware-Fassung und Freigabe überstehen den
Schadensfall.

### Teilweise

**Log-Tail über `tail`.** Der Speicherhinweis war berechtigt — mit
eingeschalteter Datenpunkt-Protokollierung wächst die Datei schnell. `tail`
ist aber der langsamste der drei Wege (rund 1,9 ms gegen 0,05 ms beim
Rückwärtslesen mit `fseek`). Umgestellt auf `fseek`.

**Barrierefreiheit der Datenpunkttabelle.** Die Behauptung, die
Schreibbarkeit werde „nur über farbige Status-Spalten" dargestellt, trifft
nicht zu — dort stand schon immer Text, nämlich die Herstellerschreibweise
`Out/-`, `-/In`, `Out/In`. Verständlich war der Text nur nicht. Daneben steht
jetzt ein Wort in Klartext: *nur lesen*, *nur schreiben*, *lesen und
schreiben*.

### Was nicht zutraf

**`postinstall.sh` sei leer und müsse `chmod +x` setzen.** Die Datei ist ein
Gerüst aus Variablenzuweisungen — insofern tut sie tatsächlich nichts. Das
`chmod` braucht sie aber nicht: der Installer setzt die Rechte selbst,
`plugininstall.pl` ruft nach dem Kopieren
`setrights("755", "1", "$lbhomedir/bin/plugins/$pfolder", "BIN files")` auf,
und die `1` steht für rekursiv.

**`ARCHITECTURE=raspberry,x86` schließe 64-Bit-Systeme aus.** Der aktuelle
Installer liest `SYSTEM.ARCHITECTURE` zwar aus (`$parch`), benutzt den Wert
danach an **keiner einzigen Stelle**. Auf `false` gesetzt wurde er trotzdem —
der Eintrag war unwahr, und ältere oder künftige Fassungen könnten ihn sehr
wohl auswerten.

**Token-Konzept für einen Webhook.** Das Plugin hat keinen HTTP-Endpunkt; es
hört auf TCP-Sockets, wie das ISM8i-Protokoll es vorgibt. Der Vorschlag
beginnt mit „wenn Loxone-Befehle künftig auch über HTTP abgebildet werden
sollen" — das ist eine Anregung für eine andere Bauform, kein Mangel an
dieser.

## Version 2.5.1 — Abspaltung, Hausstandard, zwei Sprachen

- **Zweisprachig, deutsch und englisch.** In 2.5.0 stand `templates/lang` als
  leerer Ordner da — das Gerüst war gebaut, gefüllt hat es niemand; sämtliche
  Texte lagen im `index.php`. Jetzt gehen **183 Schlüssel** durch `wi_t()`,
  beide Dateien sind deckungsgleich, Englisch ist die Rückfallebene für jede
  Sprache ohne eigene Datei.
  Zwei Schreibweisen mit Absicht: Die Abschnitte der Oberfläche enthalten HTML
  samt Entitäten, der Abschnitt `[PRUEF]` mit den Testausgaben nicht — der wird
  von `wi_e()` maskiert, eine Entität stünde dort wörtlich da.
  Mehrzeilige Ausgaben stehen als ein Schlüssel **je Zeile**; INI-Werte über
  mehrere Zeilen wären mit `INI_SCANNER_RAW` eine Wette auf den Parser.
- **Ein toter Verweis dabei gefunden.** Die Meldung „noch kein Datenpunkt im
  Protokoll" verwies zur Abhilfe auf einen Knopf *Verbindung zum ISM8 prüfen* —
  den gibt es im Reiter *Test* nicht. Der Hinweis zeigt jetzt auf
  *Multicast 20 s mitlesen*.
- **Reiter sind echte Verweise** mit serverseitig gesetztem `sm-active`. Vorher
  waren es `<div>`-Elemente, und da alle Flächen bis zum Lauf des JavaScripts
  auf `display:none` stehen, war die Seite ohne JavaScript vollständig leer.
- **Die Modulprüfung prüfte am Bedarf vorbei.** Die Diagnose *Umgebung und
  Perl-Module* fragte nach `Config::Simple` — das Wort kommt im ganzen Plugin
  kein einziges Mal vor. `List::MoreUtils` dagegen fehlte in der Liste **und**
  in `dpkg/apt`, obwohl `wolf_ism8i.pl` es in Zeile 14 ohne `eval` lädt und
  `first_index` in Zeile 1315 wirklich braucht. Auf einem LoxBerry ohne dieses
  Modul startet der Server nicht — und die Diagnose hätte „alles vorhanden"
  gemeldet. Geprüft und angefordert wird jetzt, was die Skripte tatsächlich
  laden: `List::MoreUtils`, `IO::Socket::Multicast`, `Math::Round`,
  `Net::MQTT::Simple`, `HTML::Entities`, `IO::Select`.
- **Prozesserkennung** über PID-Datei und argumentweisen Vergleich gegen
  `/proc/<pid>/cmdline` statt `pgrep -f`. Ein Suchmuster wie `wolf` trifft auch
  fremde Prozesse; `cron.php` heißt bei einem Dutzend Plugins so.
- **`uninstall`** ergänzt, **eigenes Symbol** für die Abspaltung (sichtbar anders
  als das Original), CSS-Klassen auf das `sm-`-Schema vereinheitlicht und
  `data-role="none"` an **allen 39** Bedienelementen — sonst baut jQuery Mobile
  die Formulare um und die Oberfläche sieht auf jedem Gerät anders aus.
- Geprüft gegen **PHP 7.4 und PHP 8.1**: beide Fassungen liefern zeichengleiche
  Ausgabe ohne eine einzige Meldung, in beiden Sprachen.

## Version 2.5.0 — Oberfläche neu

- **Neue Oberfläche als `index.php`** mit vier Reitern: *Einstellungen*,
  *Einbindung in Loxone*, *Test*, *Logdateien*. Die alte Perl-CGI-Oberfläche
  (`index.cgi`, `ajax.cgi`, HTML::Template, zwei Sprachdateien) entfällt.
- **Reiter „Einbindung in Loxone"** erklärt in vier Schritten, wie die Werte in
  den Miniserver kommen, zeigt die Datenpunkttabelle der eingestellten Firmware
  mit dem jeweiligen MQTT-Thema und bündelt die vier Vorlagen-Downloads.
- **Reiter „Test"** nach Hausstandard mit drei Gruppen und Legende: *Ansehen*
  (grün), *Technische Auskunft* (blaugrau), *Löst etwas aus* (orange).
  Geprüft werden Watchdog und Auswertungsmodul getrennt, die beiden
  Netzwerkports, die benötigten Perl-Module und das MQTT-Gateway.
- **MQTT ist die Voreinstellung**, die TCP/UDP-Direktausgabe ist ab Werk aus.
- **Loxone-Vorlagen ohne `LoxBerry::LoxoneTemplateBuilder`.** Das Perl-Modul
  steht in PHP nicht zur Verfügung; die drei Bausteine (VirtualInUdp,
  VirtualInHttp, VirtualOut) sind in `wi_lib.php` nachgebaut. Die Ausgabe wurde
  Byte für Byte gegen das Original geprüft.
- **Ein bewusster Unterschied:** Die Perl-Fassung ruft auf ihre fertige Ausgabe
  noch einmal `decode_entities()` auf, macht die XML-Maskierung also wieder
  rückgängig. Ein `&` oder ein Anführungszeichen in einem Datenpunktnamen hätte
  die Datei zerlegt. Hier bleibt die Maskierung stehen.
- **Datumsfehler behoben:** der Kommentar in den erzeugten Vorlagen nannte
  bisher stets den Vormonat — `localtime()` zählt den Monat ab null.
- **Auto-Update abgeschaltet.** `plugin.cfg` stand auf `AUTOMATIC_UPDATES=true`
  mit `RELEASECFG` auf dem Repository des Originalautors — LoxBerry hätte diese
  umgebaute Fassung beim nächsten Update-Lauf durch die Originalversion ersetzt.
  Schalter auf `false`, beide URLs geleert.

## Aus Version 2.4.0 übernommen

- **Firmware 1.9**: `bin/wolf_datenpunkte_19.csv` mit den Datenpunkten 212–250
  (Wärmepumpe 2–4, BWL-1-S/CHA), Auswahl 1.9 in den Einstellungen, HVACMode- und
  HVACContrMode-Tabellen für 1.9 erweitert.
- **Fehler in `wolf_ism8i.pl`**: `$hash{ism8i_ip}` speicherte das Socket-Objekt
  statt der IP; Tippfehler in `loadConfig`; zwei fehlende undef-Prüfungen im
  Ereignisumlauf (ein Reconnect des ISM8 wurde nie angenommen, ein
  Verbindungsabbruch führte zur 100-%-Schleife); Kommando-Socket wurde nach
  `shutdown` nicht geschlossen; unbenutzte `$day_str` in `pdt_time`.
- **`ism8i_comtest.pl`**: UTF-8-BOM vor dem Shebang entfernt.
- **`daemon` und `postupgrade.sh`**: Absturz bei fehlendem `enable`-Eintrag
  abgefangen, awk-Muster auf `/^enable /` präzisiert.
- **Versionsversatz behoben**: `plugin.cfg` stand auf 2.1 bei `release.cfg` 2.2 —
  das hätte nach Einschalten des Auto-Updates eine Endlosschleife ausgelöst.

## Unterstützte Firmware

1.4, 1.5, 1.7, 1.8, 1.9 — im Plugin einstellbar, Datenpunktlisten in
`bin/wolf_datenpunkte_XX.csv`. Die eingestellte Version muss zu der passen, die
in der Weboberfläche des ISM8 steht, sonst stimmen die Datenpunktnummern nicht.

## Dateien

| Datei | Zweck |
|---|---|
| `webfrontend/htmlauth/index.php` | Oberfläche, vier Reiter |
| `webfrontend/htmlauth/wi_lib.php` | Konfiguration, Datenpunkte, Loxone-XML |
| `webfrontend/htmlauth/wi_test.php` | Aktionen des Reiters Test |
| `bin/wolf_ism8i.pl` | Auswertungsmodul, unverändert aus 2.4.0 |
| `bin/wolf_server`, `bin/wolf_watchdog.sh` | Start, Stopp, Überwachung |
| `bin/wolf_datenpunkte_1*.csv` | Datenpunkttabellen je Firmware |
| `config/wolf_ism8i.conf` | Konfiguration, Format unverändert |

Das Format der Konfigurationsdatei bleibt bewusst wie bisher (`schlüssel wert`,
durch Leerzeichen getrennt), damit `wolf_ism8i.pl`, `daemon` und
`postupgrade.sh` ohne Anpassung weiterlesen.

**Wichtig zum Dateiformat:** `loadConfig()` überspringt seit 2.5.2 nur noch
Zeilen, die **mit** einem `#` beginnen. Dieser Absatz beschrieb bis 3.0.7 noch
die alte Regel — im selben README, in dem die Änderung weiter oben steht.
Kommentare hinter einem Wert bleiben trotzdem unzulässig: die Zeile hätte dann
drei Felder statt zwei, und der Server verwirft sie.
