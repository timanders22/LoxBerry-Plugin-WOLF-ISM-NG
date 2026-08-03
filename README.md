# LoxBerry-Plugin Wolf ISM8

Nimmt die Daten des Wolf-Schnittstellenmoduls **ISM8** entgegen, übersetzt die
KNX-Telegramme in lesbare Werte und reicht sie an den Loxone Miniserver weiter —
per MQTT oder, auf dem alten Weg, per UDP. In der Gegenrichtung nimmt es
Schreibbefehle des Miniservers entgegen.

Grundlage ist das Plugin von Dominik Holland
([Gagi2k/LoxBerry-Plugin-WolfIsm8](https://github.com/Gagi2k/LoxBerry-Plugin-WolfIsm8)),
Auswertungsmodul ursprünglich von Dr. Mugur Dietrich. Die Autorenangabe in
`plugin.cfg` bleibt unverändert — LoxBerry identifiziert das Plugin darüber.

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
