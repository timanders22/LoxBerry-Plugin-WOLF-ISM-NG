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

**Wichtig zum Dateiformat:** `loadConfig()` überspringt jede Zeile, die
irgendwo ein `#` enthält. Kommentare dürfen deshalb nur auf eigenen Zeilen
stehen, niemals hinter einem Wert.
