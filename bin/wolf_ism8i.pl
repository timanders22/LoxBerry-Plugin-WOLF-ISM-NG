#!/usr/bin/perl

###########################################################################################
###########################################################################################
##  Server Software zum Empangen, Auswerten und Weitergeben von Wolf ISM8i Datenpunkten  ##
##  Copyright (C) 2017 bei Dr Mugur Dietrich                                             ##
##  Frei für den privaten und schulischen Einsatz                                        ##
##  Kommerziellen Einsatz nur nach vorhergehender Genehmigung !                          ##
##  Support Seite: http://tips-und-mehr.de und das FHEM User Forum                       ##
##  Mailadresse: m.i.dietrich@gmx.de                                                     ##
###########################################################################################
###########################################################################################

use List::MoreUtils qw(first_index);
use IO::Select;
use LoxBerry::System;
use LoxBerry::Log;

use strict;
use warnings;
use utf8;
use Encode 'encode';
# use bignum; ist ENTFERNT.
#
# Das Pragma macht aus jeder Zahl im lexikalischen Bereich ein
# Math::BigInt/BigFloat-Objekt - auch aus Schleifenzaehlern, Bitmasken und
# Schiebeoperationen. Nachgemessen mit der KNX-Float-Dekodierung dieses
# Plugins, 200000 Durchlaeufe:
#
#     ohne bignum    0,053 s
#     mit  bignum   58,621 s      (Faktor rund 1100)
#
# Gegenprobe auf die Werte: dieselben Rechnungen mit und ohne Pragma
# liefern zeichengleich dasselbe Ergebnis, auch an den Raendern (0, 65535,
# 2147483647, 4294967295). Das ist auch zu erwarten - KNX liefert 16- und
# 32-Bit-Werte, die in einem Perl-Integer und in einem double (53 Bit
# Mantisse) exakt darstellbar sind. Genauigkeit geht also keine verloren.

use IO::Socket::INET;
use IO::Socket::Multicast; #   apt install libio-socket-multicast-perl
use Data::Dumper qw(Dumper);
use HTML::Entities;
use File::Basename;
use Math::Round qw(round); # apt isntall libmath-round-perl

use LoxBerry::IO;
use Net::MQTT::Simple;

binmode(STDOUT, ":utf8");


## Prototypen definieren: ###################################################
sub start_IGMPserver;
sub send_IGMPmessage($);
sub start_WolfServer;
sub start_CommandServer;
sub start_event_loop($$);
sub read_command_messages($$);
sub read_wolf_messages($);
sub createRequest($$);
sub create_answer($);
sub log_msg_data($$);
sub getLoggingTime;
sub r_trim;
sub l_trim;
sub dbl_trim;
sub l_r_dbl_trim;
sub max($$);
sub getFhemFriendly($);
sub decodeTelegram($);
sub loadConfig;
sub schreibe_zustand;
sub js_str;
sub loadDatenpunkte;
sub writeDatenpunkteToLog;
sub getDatenpunkt($$);
sub getCsvResult($$);
sub parseInput($);
sub pdt_knx_float($);
sub pdt_long($);
sub pdt_time($);
sub pdt_date($);


## Globale Variablen: #######################################################

my $script_path = dirname(__FILE__);
my $fw_actualize = time - 1;
my @datenpunkte;
my %letzter_wert;      # zuletzt gesendeter Wert JE Datenpunkt (B5)
my $igmp_sock;
my $mqtt;
my %mqtt_values;
my $online_state = 0;
my $online_gesendet;   # undef, bis der Zustand einmal hinausgegangen ist
my %ism8_puffer;       # Rest eines unvollstaendig gelesenen Telegramms, JE Verbindung
my %ism8_clients;      # alle offenen ISM8-Verbindungen, nach fileno
my %cmd_clients;       # offene Befehlsverbindungen, nach fileno
my %cmd_zeit;          # wann sie angenommen wurden - fuer den Aufraeumer
my $wolf_client;
my $pull_request_timer = -1;
my $udp_fehler = 0;
my $verworfen = 0;     # Telegramme ohne verwertbaren Wert (B24)
my $online_reset_timer = -1;

# --- Zustandsabbild und Zaehler (V1, V3, V4) --------------------------
# Der Dienst haelt den letzten Wert je Datenpunkt ohnehin im Speicher; bis
# 3.0.8 kam er nur nicht nach draussen. Die Oberflaeche hatte deshalb keinen
# einzigen Messwert anzuzeigen und musste das Protokoll durchsuchen.
my %zustand;              # DP-Kennung => [Geraet, Name, Wert, Einheit, Zeit]
my $zustand_schmutzig = 0;
my $zustand_letzt = 0;
my %zaehler = (
    telegramme   => 0,   # angenommene Rahmen
    datenpunkte  => 0,   # darin ausgewertete Datenpunkte
    gesendet     => 0,   # weitergegebene Werte
    verworfen    => 0,   # ohne verwertbaren Wert
    unbekannt    => 0,   # Kennung steht nicht in der Tabelle
    rahmenfehler => 0,
    udp_fehler   => 0,
    befehle_ok   => 0,
    befehle_weg  => 0,
    verbindungen => 0,
);
my $unbekannt_min;        # kleinste und groesste unbekannte Kennung -
my $unbekannt_max;        # daraus laesst sich die Firmware erschliessen
my $zaehler_umlauf = 0;   # 0..999, Lebenszeichen (V3)
my $herzschlag_letzt = 0;
my $abgleich_letzt = 0;
my $start_zeit = time;
my $neu_laden = 0;        # von SIGHUP gesetzt (V16)
my $ereignis_auswahl;     # IO::Select der Ereignisschleife
# Diese Liste MUSS mit wi_defaults() in webfrontend/htmlauth/wi_lib.php
# uebereinstimmen. Bis 3.0.7 wichen zwei Werte ab: output stand hier auf
# 'fhem' und dort auf 'none', mqtt hier auf '0' und dort auf '1'. Fehlte
# einer der Schluessel in der Datei, bedeutete dasselbe Fehlen in der
# Oberflaeche "MQTT an, keine Direktausgabe" und im Dienst das Gegenteil -
# die Oberflaeche zeigte einen Betriebszustand an, den es nicht gab.
# Massgeblich ist, was das Plugin seit 2.5.0 ausliefert: MQTT ein,
# TCP/UDP-Direktausgabe aus.
my %hash = (
             enable         => '0' ,
             ism8i_ip       => '?.?.?.?' ,
             port           => '12004' ,
             inport         => '12005' ,
             fw             => '1.8' ,
             mcip           => '239.7.7.77' ,
             mcport         => '35353' ,
             dplog          => '0' ,
             output         => 'none' ,
             mqtt           => '1' ,
             pull_on_write  => '0' ,
             online_timeout => '-1' ,
             praefix        => 'wolf_ng' ,
             herzschlag     => '60' ,
             abgleich_takt  => '0' ,
			);

# Version of this script
my $version = LoxBerry::System::pluginversion();

my $log = LoxBerry::Log->new ( name => 'server' , addtime => 1);

## Ablauf starten ###########################################################

LOGSTART "Starting $0 Version $version";

LOGINF "############ Starte Wolf ISM8i Auswertungs-Modul ############";

#Subs aufrufen:
# create_logdir war seit jeher definiert und wurde nie gerufen. Fehlt der
# Protokollordner - und log/plugins liegt auf einer Ramdisk -, starb der
# Server beim ersten Schreibversuch mit "Could not open file".
create_logdir();

loadConfig();

loadDatenpunkte();

writeDatenpunkteToLog();

start_IGMPserver();

connect_MQTT();

start_event_loop(start_WolfServer(), start_CommandServer());

## STDOUT/STDERR wiederherstellen
#close $log_fh;
#*STDOUT = *OLD_STDOUT;
#*STDERR = *OLD_STDERR;


## Sub Definitionen #########################################################

sub connect_MQTT
{
    if ($hash{mqtt} eq '1') {
        # Die Pruefung stand eine Zeile zu spaet: last_will wurde auf dem
        # Rueckgabewert gerufen, BEVOR gefragt wurde, ob es einen gibt.
        # Liefert mqtt_connect() undef - Broker beim Hochfahren nicht
        # erreichbar -, starb der Dauerlaeufer mit "Can't call method
        # last_will on an undefined value", und zwar bevor die ISM8-Ports
        # ueberhaupt geoeffnet waren. Der Waechter startete zwanzigmal neu
        # und gab dann endgueltig auf.
        $mqtt = mqtt_connect();
        if ($mqtt) {
            $mqtt->last_will("$hash{praefix}/online", "0");
            $mqtt->subscribe("$hash{praefix}/#", \&received_MQTT);
        } else {
            LOGWARN("Keine Verbindung zum MQTT-Broker. Der Server laeuft "
                  . "weiter; MQTT-Werte gehen bis auf Weiteres nicht hinaus.");
        }
    }
}

sub publish_MQTT($$$)
{
    my $id = $_[0];
    my $topic = $_[1];
    my $value = $_[2];
    $mqtt_values{$topic} = [$id, $value];
    LOGDEB(encode('UTF-8', "Saving state for topic $topic: $id: $value"));
    if ($mqtt) {
        LOGINF(encode('UTF-8', "publish Data: $id on MQTT topic $topic: $value"));
        $mqtt->retain($topic, $value);
    }
}

sub received_MQTT
{
    my ($topic, $message, $retained) = @_;
    LOGDEB(encode('UTF-8', "incoming MQTT message: $topic: $message retained: $retained"));
    if ($retained) {
        LOGDEB("Ignoring retained state...");
        return;
    }
    if (!exists $mqtt_values{$topic}) {
        LOGDEB("No Saved state for topic yet! Ignoring...");
        return;
    }
    my $id = $mqtt_values{$topic}[0];
    my $value = $mqtt_values{$topic}[1];
    LOGDEB(encode('UTF-8', "Saved state for topic: $id: $value"));
    if ($value eq $message) {
        LOGDEB("Value didn't change. Ignoring...");
        return;
    }
    if ($message eq "") {
        LOGDEB("Empty Message, clearing State");
        delete $mqtt_values{$topic};
        return;
    }
    LOGINF(encode('UTF-8', "received MQTT input ID: $id topic $topic: $message"));

    my $send_data = parseInput("$id;$message");
    if ($send_data) {
        # Ohne Verbindung zum ISM8i gibt es nichts zu senden. Der
        # unmittelbare Aufruf war ein "Can't call method \"send\" on an
        # undefined value" - und damit das Ende des ganzen Servers, nicht nur
        # dieses einen Befehls.
        if (!defined $wolf_client) {
            LOGWARN("Keine Verbindung zum ISM8i-Modul - MQTT-Befehl verworfen: $id;$message");
            return;
        }
        $wolf_client->send($send_data);
        if ($hash{pull_on_write} eq '1') {
            startPullRequestTimer();
        }
    } else {
        LOGINF(encode('UTF-8', "resetting MQTT to previous state: $id topic $topic: $value"));
        publish_MQTT($id, $topic, $value);
    }
}

sub getMQTTFriendly($)
#Ersetzt alle Zeichen so, dass das Ergebnis als MQTT Topic taugt.
{
    my $working_string = shift;

    # Alles nach einem Klammer Anfang wird verworfen
    $working_string =~ s/\(.*//;

    # Alles nach einem + wird verworfen
    $working_string =~ s/\+.*//;

    # Leerzeichen am Ende wird verworfen
    $working_string =~ s/\s+$//;

    my @tbr = ("/", "_", ":", "", " ", "/");

    for (my $i=0; $i <= scalar(@tbr)-1; $i+=2)
    {
        my $f = $tbr[$i];
        if ($working_string =~ /$f/)
        {
            my $r = $tbr[$i+1];
            $working_string =~ s/$f/$r/g;
        }
    }

    return $working_string;
}

sub send_OnlineState($)
{
    my $online = $_[0];
    my $erstmals = !defined $online_gesendet;
    if ($erstmals or $online_state != $online) {
        # Zwei Berichtigungen an dieser Stelle:
        #  - $mqtt wurde ungeprueft benutzt, waehrend publish_MQTT zwei
        #    Funktionen weiter oben sehr wohl prueft.
        #  - Gesendet wurde mit publish statt retain. Alle Messwerte gehen
        #    retained hinaus; ausgerechnet der Zustand nicht. Ein
        #    Miniserver, der sich NACH dem Plugin verbindet, sah das Thema
        #    deshalb erst beim naechsten Zustandswechsel - und der kann
        #    ausbleiben, solange alles laeuft.
        # Dazu wird der Zustand jetzt einmal beim Start angesagt, statt nur
        # bei Aenderung: $online_state stand auf 0 vorbelegt, eine 0 wurde
        # also nie gesendet.
        if ($hash{mqtt} eq '1' and $mqtt) {
            LOGINF("publish online state to MQTT topic $hash{praefix}/online: $online");
            $mqtt->retain("$hash{praefix}/online", $online);
        }
        send_IGMPmessage("online;".$online);
        $online_gesendet = 1;
    }
    $online_state = $online;
}

sub start_IGMPserver
# Startet einen Multicast Server
{
   LOGINF("Creating multicast group server $hash{mcip}:$hash{mcport}:");

   $igmp_sock = IO::Socket::Multicast->new(
           Proto     => 'udp',
           PeerAddr  => "$hash{mcip}:$hash{mcport}",
           ReusePort => '1',
   ) or die "ERROR: Cant create socket: $@! ";

   # ACHTUNG: kein $igmp_sock->mcast_add() bei Server!

   LOGINF("   Creating to multicast group success.");
}


sub send_IGMPmessage($)
{
   my $message = shift;
   my $host = $igmp_sock->peerhost();
   my $port = $igmp_sock->peerport();
   LOGINF("Sende UDP Daten zu $host:$port: $message");

   # Ein misslungener UDP-Versand hat den ganzen Dauerlaeufer beendet - ein
   # "or die" auf einer Zeile, durch die JEDER Weg zum Miniserver laeuft.
   # Eine voruebergehende Netzstoerung reichte, und MQTT lief dabei
   # tadellos weiter. Gemeldet wird jetzt, gestorben nicht.
   # (Die Zeile darunter war ausserdem unerreichbar: nach einem "or die"
   # kann $ok nie 0 sein.)
   my $ok = $igmp_sock->send($message);
   if (!defined $ok) {
       $udp_fehler++;
       $zaehler{udp_fehler} = $udp_fehler;
       LOGWARN("UDP-Versand an $host:$port fehlgeschlagen ($!) - "
             . "insgesamt $udp_fehler mal. Der Server laeuft weiter.");
       return 0;
   }
   return $ok;
}

#
# ID, value
sub createRequest($$)
{
    my $dp_id = $_[0];
    my $dp_state = "00";

    my $dp_value = $_[1];
    my $dp_length = length($dp_value);

    # Building the msg from behind
    my @obj_header = ("F0","C1");
    my $obj_frame = pack("H2 H2 n n n C C ", @obj_header, $dp_id, 1, $dp_id, 0 , $dp_length);

    my @conn_header = ("04","00","00","00");
    my $conn_frame = pack("H2" x 4, @conn_header);

    my @knx_header = ("06","20","F0","80");
    my $knx_frame = pack("H2" x 4 ."n", @knx_header, 6 + 4 + length($obj_frame) + $dp_length);

    my $request = $knx_frame.$conn_frame.$obj_frame.$dp_value;

    LOGDEB("Sende Daten (".length($request)." Bytes):");
    LOGDEB(join(" ", unpack("H2" x length($request), $request)));

    return $request;
}

sub startPullRequestTimer()
{
    LOGINF("Start Pull Request Timer (5 seconds)");
    $pull_request_timer = 5;
}

sub createPullRequest()
{
    # Das Laengenfeld hat 22 Byte behauptet und der Rahmen war 17 lang:
    # @a hatte zwoelf Elemente, die Vorlage verlangte siebzehn, und Perl
    # hat die fehlenden fuenf stillschweigend mit Nullbytes gefuellt.
    # Zum Vergleich: create_answer weiter unten benutzt dieselbe Vorlage
    # mit wirklich siebzehn Elementen und traegt folgerichtig 00 11 ein.
    #
    # Das Laengenfeld wird jetzt aus dem Rahmen GERECHNET. Damit kann er
    # nie wieder ueber sich selbst luegen.
    #
    # NICHT GEMESSEN und offen: ob das ISM8 hinter F0 D0 noch Nutzdaten
    # erwartet (bei den verwandten Objektserver-Diensten sind das
    # Startdatenpunkt, Anzahl und Filter). Hier stehen wie bisher
    # Nullbytes. Wer die Schnittstellenbeschreibung des ISM8 zur Hand hat,
    # prueft das nach - erfunden wird hier nichts.
    my @a = ("06","20","F0","80","00","00","04","00","00","00","F0","D0",
             "00","00","00","00","00");
    my $rahmen = pack("H2" x scalar(@a), @a);
    substr($rahmen, 4, 2) = pack("n", length($rahmen));
    LOGDEB("Sende Pull Request (".length($rahmen)." Byte): "
         . join(" ", unpack("H2" x length($rahmen), $rahmen)));
    return $rahmen;
}

sub sendPullRequest
{
    LOGINF("Send Pull Request");
    my $pull_request = createPullRequest();
    if (length($pull_request) > 0) { $wolf_client->send($pull_request); }
}

sub start_CommandServer()
#Startet einen blocking Server(Loop) an dem sich das Wolf ISM8i Modul verbinden und seine Daten schicken kann.
{
   # auto-flush on socket
   $| = 1;

   # creating a listening socket
   my $socket = new IO::Socket::INET (
      LocalHost => '0.0.0.0',
      LocalPort => $hash{inport},
      Proto => 'tcp',
      Listen => 5,
      Reuse => 1
   );
   die "Cannot create socket $!\n" unless $socket;
   LOGINF("Server wartet auf Loxone Verbindung auf Port $hash{inport}:");

   return $socket;
}

sub start_WolfServer()
#Startet einen blocking Server(Loop) an dem sich das Wolf ISM8i Modul verbinden und seine Daten schicken kann.
{
   # auto-flush on socket
   $| = 1;

   # creating a listening socket
   my $socket = new IO::Socket::INET (
      LocalHost => '0.0.0.0',
      LocalPort => $hash{port},
      Proto => 'tcp',
      Listen => 5,
      Reuse => 1,
      Timeout => 5,
   );
   die "Cannot create socket $!\n" unless $socket;
   LOGINF("Server wartet auf ISM8i Verbindung auf Port $hash{port}:");

   return $socket;
}

sub start_event_loop($$) {
    my $wolf_socket = $_[0];
    my $command_socket = $_[1];

    my $read_select  = IO::Select->new();
    $ereignis_auswahl = $read_select;   # damit der Sekundentakt aufraeumen kann

    $read_select->add($wolf_socket);
    $read_select->add($command_socket);

    # SIGPIPE beendet einen Perl-Prozess mit der Standardbehandlung. Der
    # Server schreibt fuer JEDES empfangene Telegramm eine Bestaetigung -
    # bei einem Vollabzug also hunderte Male hintereinander. Setzt die
    # Gegenstelle dabei zurueck, war das bisher das Ende. Jetzt kommt der
    # Fehler ueber den Rueckgabewert von send zurueck.
    $SIG{PIPE} = 'IGNORE';

    # Sauberes Herunterfahren: wolf_server beendet den Prozess mit kill.
    # Auf der MQTT-Seite greift der letzte Wille, auf dem UDP-Weg bekam der
    # Miniserver bisher KEIN online;0, und das Protokoll endete mitten im
    # Satz - ein LOGEND kam in der ganzen Datei nicht vor.
    my $ende = sub {
        LOGINF("Signal empfangen - Server wird beendet.");
        eval { send_OnlineState(0); };
        LOGEND "Wolf ISM8i Auswertungs-Modul beendet";
        exit 0;
    };
    $SIG{TERM} = $ende;
    $SIG{INT}  = $ende;

    # V16: Konfiguration ohne Neustart uebernehmen. Bis 3.0.8 musste die
    # Oberflaeche fuer JEDE Aenderung den ganzen Server neu starten - dabei
    # bricht die TCP-Verbindung zum ISM8 ab. Nur eine Portaenderung braucht
    # das noch; alles andere geht ueber SIGHUP.
    # Gearbeitet wird nur mit einem Merker: in einem Signalbehandler etwas
    # zu lesen, das gerade geschrieben wird, ist die naechste Falle.
    $SIG{HUP} = sub { $neu_laden = 1; };

    # Callback to start actions after x seconds
    $SIG{ALRM} = sub
    {
        #LOGINF("TIMEOUT $pull_request_timer");

        # Send Pull Request after x seconds
        if ($pull_request_timer > 0) {
            $pull_request_timer--;
        } elsif ($pull_request_timer == 0) {
            sendPullRequest();
            $pull_request_timer = -1;
        }

        # Reset online state after x seconds
        if ($online_reset_timer > 0) {
            $online_reset_timer--;
        } elsif ($online_reset_timer == 0) {
            LOGWARN("Keine Daten innerhalb von $hash{online_timeout} Sekunden. ISM8 offline!");
            send_OnlineState(0);
            $online_reset_timer = -1;
        }

        # --- V3: Lebenszeichen -------------------------------------------
        # Ein Wert, der sich nicht aendert, ist von einem toten Dienst nicht
        # zu unterscheiden - das ISM8 sendet nur bei Wertaenderung, und ueber
        # MQTT stehen die Werte retained im Broker. Der Zeitstempel geht
        # deshalb bei JEDEM Takt hinaus, auch wenn sich sonst nichts geruehrt
        # hat; sonst waere er selbst der aelteste Wert im Broker.
        my $jetzt = time;
        my $hz = $hash{herzschlag} + 0;
        if ($hz > 0 and $jetzt - $herzschlag_letzt >= $hz) {
            $herzschlag_letzt = $jetzt;
            $zaehler_umlauf = ($zaehler_umlauf + 1) % 1000;
            if ($hash{mqtt} eq '1' and $mqtt) {
                $mqtt->retain("$hash{praefix}/zeitstempel", $jetzt);
                $mqtt->retain("$hash{praefix}/zaehler", $zaehler_umlauf);
            }
            # Auf dem UDP-Weg gibt es keinen retained-Speicher; dort ist der
            # umlaufende Zaehler das einzige, woran eine Aenderungs-
            # ueberwachung in Loxone anschlagen kann.
            if ($hash{output} ne 'none') {
                send_IGMPmessage("zeitstempel;$jetzt");
                send_IGMPmessage("zaehler;$zaehler_umlauf");
            }
        }

        # --- V21: periodischer Abgleich ----------------------------------
        # Ab Werk AUS. Solange nicht gemessen ist, was das ISM8 hinter F0 D0
        # erwartet (siehe B18), waere ein haeufigerer Pull-Request bestenfalls
        # wirkungslos. Wer es an seiner Anlage geprueft hat, schaltet es ein.
        my $at = $hash{abgleich_takt} + 0;
        if ($at > 0 and $jetzt - $abgleich_letzt >= $at and defined $wolf_client) {
            $abgleich_letzt = $jetzt;
            LOGINF("Periodischer Abgleich (alle $at s)");
            sendPullRequest();
        }

        # --- Befehlsverbindungen, die nie etwas schicken ------------------
        # Ohne diesen Aufraeumer haelt ein Portscan den Platz in der Auswahl
        # dauerhaft belegt. Zehn Sekunden sind reichlich fuer eine Zeile.
        for my $fn (keys %cmd_zeit) {
            next if $jetzt - $cmd_zeit{$fn} < 10;
            my $s = $cmd_clients{$fn};
            LOGWARN("Befehlsverbindung ohne Befehl nach 10 s - abgeraeumt.");
            eval { $ereignis_auswahl->remove($s); shutdown($s, 2); close($s); };
            delete $cmd_clients{$fn};
            delete $cmd_zeit{$fn};
        }

        # --- V1: Zustandsabbild, hoechstens alle zwei Sekunden ------------
        if ($zustand_schmutzig and $jetzt - $zustand_letzt >= 2) {
            schreibe_zustand();
        }

        alarm(1);
    };
    alarm(1);

    while (1) {

        ## No timeout specified (see docs for IO::Select).  This will block until a TCP
        ## client connects or we have data.
        if ($neu_laden) {
            $neu_laden = 0;
            my $alt_port  = $hash{port};
            my $alt_in    = $hash{inport};
            my $alt_mcp   = $hash{mcport};
            my $alt_fw    = $hash{fw};
            LOGINF("SIGHUP - Konfiguration wird neu gelesen.");
            loadConfig();
            loadDatenpunkte() if $hash{fw} ne $alt_fw;
            if ($hash{port} ne $alt_port or $hash{inport} ne $alt_in
                or $hash{mcport} ne $alt_mcp) {
                LOGWARN("Ein Port hat sich geaendert - dafuer ist ein Neustart "
                      . "noetig. Die uebrigen Aenderungen sind uebernommen.");
            }
            %letzter_wert = ();   # damit alle Werte einmal neu hinausgehen
        }

        LOGDEB("Warte auf neue ISM8 Daten");
        my @read = $read_select->can_read(1);

        foreach my $read (@read) {
            LOGDEB("Lese Daten");
            if ($read == $wolf_socket) {
                # Eine WARTENDE Verbindung wird jetzt IMMER angenommen.
                #
                # Vorher wurde dieser Zweig uebersprungen, solange schon ein
                # Client stand. Die wartende Verbindung blieb damit im
                # Rueckstau und hielt can_read dauerhaft ausloesend; gelesen
                # wurde derweil auf dem ALTEN Client, der nichts hatte, und
                # der drop_counter riss die bestehende, GESUNDE Verbindung
                # binnen Millisekunden ab. Gemessen: nach dem Oeffnen einer
                # zweiten Verbindung wurden 26 von 30 Telegrammen der ersten
                # nie mehr verarbeitet.
                #
                # Der haeufigste Ausloeser im Alltag ist das ISM8 selbst, das
                # nach einer kurzen Netzstoerung neu verbindet, waehrend der
                # alte Socket noch halboffen dasteht.
                #
                # Die neue Verbindung DRAENGT die alte aber nicht hinaus -
                # sonst raeumte ein Portscan eine arbeitende Verbindung ab.
                # Beide werden gehalten, jede mit eigenem Puffer, und eine
                # wird erst abgeraeumt, wenn die Gegenstelle sie wirklich
                # schliesst. Gesendet wird an die zuletzt aktive.
                my $neu = $wolf_socket->accept();
                if (!defined $neu) {
                    LOGWARN("accept() auf dem ISM8-Port fehlgeschlagen: $!");
                } else {
                    my $fn = fileno($neu);
                    my $client_address = eval { $neu->peerhost() } // "?";
                    my $client_port    = eval { $neu->peerport() } // "?";
                    $zaehler{verbindungen}++;
                    $ism8_clients{$fn} = $neu;
                    $ism8_puffer{$fn}  = '';
                    $wolf_client = $neu;
                    $hash{ism8i_ip} = $client_address;
                    my $anz = scalar(keys %ism8_clients);
                    LOGINF("   Verbindung eines ISM8i Moduls von $client_address:$client_port"
                         . ($anz > 1 ? " ($anz Verbindungen offen)" : ""));

                    LOGINF("Sende Pull Request zum ISM8i Modul: $client_address");
                    my $pull_request = createPullRequest();
                    if (length($pull_request) > 0) { $neu->send($pull_request); }

                    $read_select->add($neu);
                }
                next;   # in derselben Runde nicht auch noch lesen
            }

            if (exists $ism8_clients{fileno($read) // -1}) {
                # Der drop_counter ist ersatzlos entfallen. Er hat gezaehlt,
                # wie oft ein Lesevorgang nichts brachte - und genau das ist
                # kein Grund, etwas abzubauen: can_read meldet einen Socket
                # nur, wenn Daten da sind oder die Gegenstelle zu ist.
                # Abgebaut wird jetzt ausschliesslich bei einem echten
                # Verbindungsende (read_wolf_messages liefert -1).
                my $gelesen = read_wolf_messages($read);
                if ($gelesen > 0) {
                    $wolf_client = $read;   # die zuletzt aktive Verbindung
                    send_OnlineState(1);
                } elsif ($gelesen < 0) {
                    my $fn = fileno($read) // -1;
                    my $client_address = eval { $read->peerhost() } // "?";
                    LOGINF("Verbindung zu $client_address beendet.");
                    $read_select->remove($read);
                    shutdown($read, 2);
                    close($read);
                    delete $ism8_clients{$fn};
                    delete $ism8_puffer{$fn};
                    if (defined $wolf_client and (fileno($wolf_client) // -2) == $fn) {
                        # Zum Senden die naechstbeste offene Verbindung nehmen.
                        my ($erste) = values %ism8_clients;
                        $wolf_client = $erste;
                    }
                    send_OnlineState(scalar(keys %ism8_clients) ? 1 : 0);
                }
            }

            if ($read == $command_socket) {
                # accept() wird geprueft: bricht die Gegenstelle zwischen
                # can_read und accept ab, war der peerhost-Aufruf darauf das
                # Ende des Dauerlaeufers.
                #
                # Und gelesen wird NICHT mehr im selben Durchgang. Bis 3.0.8
                # stand hier ein recv mit MSG_DONTWAIT unmittelbar nach dem
                # accept - die Nutzdaten des Miniservers sind zu dem Zeitpunkt
                # aber oft noch unterwegs. Im Protokoll stand dann "Read
                # command " ohne Inhalt und "Invalid command". Der Befehl war
                # verloren, und niemand hat es gemerkt, weil der Weg ohnehin
                # nie geantwortet hat.
                my $neu = $command_socket->accept();
                if (!defined $neu) {
                    LOGWARN("accept() auf dem Befehls-Port fehlgeschlagen: $!");
                } else {
                    my $fn = fileno($neu) // -1;
                    my $adr = eval { $neu->peerhost() } // "?";
                    my $prt = eval { $neu->peerport() } // "?";
                    LOGINF("   Verbindung eines Clients von $adr:$prt");
                    $cmd_clients{$fn} = $neu;
                    $cmd_zeit{$fn} = time;
                    $read_select->add($neu);
                }
                next;
            }

            if (exists $cmd_clients{fileno($read) // -1}) {
                my $fn = fileno($read) // -1;
                read_command_messages($read, $wolf_client);
                # Ein Befehl je Verbindung - so war es schon immer, und der
                # Miniserver macht es auch so (CloseAfterSend).
                $read_select->remove($read);
                shutdown($read, 1);
                close($read);
                delete $cmd_clients{$fn};
                delete $cmd_zeit{$fn};
                next;
            }
        }

        if ($mqtt) {
            $mqtt->tick();
        }
    }

    if ($wolf_client) {
        # notify client that response has been sent
        shutdown($wolf_client, 1);

        $wolf_socket->close();
    }
}

sub read_command_messages($$) {
   my $client_socket = $_[0];
   my $ism8_socket = $_[1];

   # read up to 4096 characters from the connected client
   my $rec_data = "";

   LOGDEB("Lese Command Socket");
   $client_socket->recv($rec_data, 4096, MSG_DONTWAIT);

   # V20: der Befehlsweg hat bis 3.0.8 NIE geantwortet - bei Erfolg wie bei
   # Ablehnung wurde die Verbindung einfach geschlossen. Ein verworfener
   # Schaltbefehl sah fuer den Miniserver genauso aus wie ein angenommener;
   # der Grund stand nur im Serverprotokoll. Jetzt kommt eine Zeile zurueck,
   # die ein virtueller Ausgang auswerten kann.
   my $befehl = $rec_data;
   $befehl =~ s/\s+$//;

   if (!$ism8_socket) {
        LOGINF("No ISM8 connection, ignoring command!");
        $zaehler{befehle_weg}++;
        eval { $client_socket->send("ERR KEINE_ISM8_VERBINDUNG\n"); };
        return;
   }

   LOGINF("Read command $befehl");
   my $send_data = parseInput($rec_data);
   if ($send_data) {
        $ism8_socket->send($send_data);
        $zaehler{befehle_ok}++;
        eval { $client_socket->send("OK $befehl\n"); };
        if ($hash{pull_on_write} eq '1') {
            startPullRequestTimer();
        }
   } else {
        $zaehler{befehle_weg}++;
        eval { $client_socket->send("ERR ABGEWIESEN $befehl\n"); };
   }
   $zustand_schmutzig = 1;
}

sub read_wolf_messages($) {
   my $client_socket = $_[0];

   # read up to 4096 characters from the connected client
   my $rec_data = "";

   LOGDEB("Lese Wolf Socket");
   $! = 0;
   my $erg = $client_socket->recv($rec_data, 4096, MSG_DONTWAIT);

   # Null Bytes heissen ZWEIERLEI, und die Unterscheidung steht nicht in der
   # Laenge, sondern im Rueckgabewert. Nachgemessen an einem echten Socket:
   #
   #   Verbindung offen, nichts da : recv liefert undef,     Laenge 0, errno EAGAIN
   #   Gegenstelle hat geschlossen : recv liefert definiert, Laenge 0, errno 0
   #
   # Bis 2.5.1 wurde beides gleich behandelt (Rueckgabe 0) und der
   # drop_counter hochgezaehlt - bei einem geschlossenen Socket zehnmal
   # hintereinander, weil can_read sofort wieder feuert (gemessen: 10 Runden
   # ohne Pause). Erst danach wurde aufgeraeumt.
   #
   # Die naheliegende Abhilfe - bei null Bytes sofort abbauen - waere aber
   # falsch: sie traefe auch den ersten Fall und wuerde eine gesunde
   # Verbindung bei einem Weckruf ohne Daten wegwerfen. Unterschieden wird
   # deshalb sauber: -1 heisst "Gegenstelle zu, sofort abbauen", 0 heisst
   # "diesmal nichts, weiter warten".
   if (length($rec_data) == 0) {
       return defined($erg) ? -1 : 0;
   }

   LOGDEB("Daten Empfang (".length($rec_data)." Bytes):");
   LOGDEB(join(" ", unpack("H2" x length($rec_data), $rec_data)));

   # Bis 3.0.7 wurde das, was ein einzelner recv geliefert hat, als
   # vollstaendige Telegrammfolge behandelt - ohne Rest ueber
   # Aufrufgrenzen hinweg. Ein Telegramm, das ueber zwei Lesevorgaenge
   # verteilt ankam, wurde in BEIDEN Haelften verworfen; im Protokoll
   # standen dann zwei "TelegrammLength/FrameSize missmatch". Da 4096
   # kein Vielfaches der Telegrammlaenge ist, faellt beim Vollabzug jede
   # Puffergrenze mitten in ein Telegramm - dort trat es planmaessig ein.
   #
   # Jetzt wird angesammelt und nur zerlegt, was vollstaendig da ist. Die
   # Laenge steht im Rahmen selbst (Byte 4 und 5).
   my $starter = chr(0x06).chr(0x20).chr(0xf0).chr(0x80);
   my $fn = fileno($client_socket) // -1;
   $ism8_puffer{$fn} = '' unless defined $ism8_puffer{$fn};
   $ism8_puffer{$fn} .= $rec_data;

   while (1) {
       last if length($ism8_puffer{$fn}) < 6;

       my $p = index($ism8_puffer{$fn}, $starter);
       if ($p < 0) {
           # Nichts Verwertbares. Die letzten drei Byte koennen der Anfang
           # einer Startkennung sein und bleiben deshalb stehen.
           LOGWARN("Keine Startkennung in " . length($ism8_puffer{$fn}) . " Byte - verworfen.");
           $ism8_puffer{$fn} = substr($ism8_puffer{$fn}, -3);
           last;
       }
       if ($p > 0) {
           LOGWARN("$p Byte vor der Startkennung verworfen.");
           $ism8_puffer{$fn} = substr($ism8_puffer{$fn}, $p);
           next;
       }

       my $laenge = unpack("n", substr($ism8_puffer{$fn}, 4, 2));
       if ($laenge < 6 or $laenge > 65535) {
           LOGERR("*** ERROR: unglaubwuerdige Rahmenlaenge $laenge - "
                . "Startkennung uebersprungen. ***");
           $ism8_puffer{$fn} = substr($ism8_puffer{$fn}, 4);
           next;
       }
       last if length($ism8_puffer{$fn}) < $laenge;   # noch nicht vollstaendig

       my $r = substr($ism8_puffer{$fn}, 0, $laenge);
       $ism8_puffer{$fn} = substr($ism8_puffer{$fn}, $laenge);

       # Falls ein SetDatapointValue.Req gesendet wurde wird mit einem SetDatapointValue.Res als Bestätigung geantwortet.
       my $send_data = create_answer($r);
       if (length($send_data) > 0) { $client_socket->send($send_data); }

       $zaehler{telegramme}++;
       decodeTelegram($r);
   }

    # Start the online reset timeout
    if ($hash{online_timeout} > 0) {
        $online_reset_timer = $hash{online_timeout};
    }

    return 1;
}


sub create_answer($)
#Erzeugt ein SetDatapointValue.Res Telegramm das an das ISM8i zurückgeschickt wird.
{
   my @h = unpack("H2" x length($_[0]), $_[0]);

   if (length($_[0]) < 14)
      {
	   return "";
	  }
   elsif ($h[10] eq "f0" and $h[11] eq "06")
      {
       my @a = ($h[0],$h[1],$h[2],$h[3],"00","11",$h[6],$h[7],$h[8],$h[9],$h[10],"86",$h[12],$h[13],"00","00","00");
       LOGDEB("Antwort: ".join(" ", @a));
       return pack("H2" x 17, @a);
	  }
   else
      {
	   return "";
	  }
}

sub create_logdir
# Erstellt den Ordner für Logs.
{
   my $log_ordner = $lbplogdir;
   if (not (-e "$log_ordner")) {
      my $ok = mkdir("$log_ordner",0755);
      if ($ok == 0) { die "Could not create dictionary '$log_ordner' $!"; }
   }
}


sub log_msg_data($$)
# Loggt die als Multicast versendeten Daten in ein Logfile.
{
   my ($msg,$format) = @_;
   my $filename = $lbplogdir."/wolf_data.log";

   # V11: bis 3.0.8 wuchs diese Datei unbegrenzt, und die mitgelieferte
   # Konfiguration bat den Anwender, sie von Hand zu pruefen und zu
   # loeschen. log/plugins liegt auf einer Ramdisk - sie frisst also
   # Arbeitsspeicher, nicht Plattenplatz. Ab 512 kB bleiben die letzten
   # 200 Zeilen stehen (Hausmuster).
   if (-s $filename and (-s $filename) > 512000) {
      if (open(my $alt, '<:encoding(UTF-8)', $filename)) {
         my @zeilen = <$alt>;
         close $alt;
         my @rest = scalar(@zeilen) > 200 ? @zeilen[-200 .. -1] : @zeilen;
         if (open(my $neu, '>:encoding(UTF-8)', $filename)) {
            print $neu @rest;
            close $neu;
            LOGINF("Datenpunkt-Protokoll gekappt, die letzten "
                 . scalar(@rest) . " Zeilen bleiben stehen.");
         }
      }
   }
   open(my $fh, '>>:encoding(UTF-8)', $filename) or die "Could not open file '$filename' $!";

   if ($format eq 'fhem') {
      print $fh getLoggingTime()." $msg\n";
   } elsif ($format eq 'csv') {
      print $fh getLoggingTime().";$msg\n";
   } elsif ($format eq 'data') {
      print $fh getLoggingTime().";$msg\n";
   }

   close $fh;
}

sub getLoggingTime
#Returnt eine gut lesbare Zeit für Logeinträge.
{
    my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime(time);
    my $nice_timestamp = sprintf ("%04d.%02d.%02d %02d:%02d:%02d", $year+1900,$mon+1,$mday,$hour,$min,$sec);
    return $nice_timestamp;
}


###############################################################
# Sieben Helfer ohne Aufrufer sind entfallen: dec2ip, ip2dec, all_trim,
# min, getBitweise, showDatenpunkte - und create_logdir, das als einziges
# gebraucht wurde und jetzt beim Start wirklich gerufen wird.
# Ein Helfer, den niemand aufruft, ist eine falsche Faehrte fuer den
# naechsten Umbau.

###############################################################
### Whitespace (v.a. CR LF) rechts im String löschen

sub r_trim { my $s = shift; $s =~ s/\s+$//; return $s; }

###############################################################
### Whitespace links im String löschen

sub l_trim { my $s = shift; $s =~ s/^\s+//; return $s; }

###############################################################
### Doppelten Whitespace im String durch ein Leezeichen ersetzen

sub dbl_trim { my $s = shift; $s =~ s/\s+/ /g; return $s; }

###############################################################
### r_trim, l_trim, dbl_trim zusammen auf einen String anwenden

sub l_r_dbl_trim { my $s = shift; my $r = l_trim(r_trim(dbl_trim($s))); return $r; }


sub max($$) { $_[$_[0] < $_[1]]; }



sub getFhemFriendly($)
#Ersetzt alle Zeichen so, dass das Ergebnis als FHEM Reading Name taugt.
{
my $working_string = shift;
my @tbr = ("ö","oe","ä","ae","ü","ue","Ö","Oe","Ä","Ae","Ü","Ue","ß","ss","³","3","²","2","°C","C","%","proz","[[:punct:][:space:][:cntrl:]]","_","___","_","__","_","^_","","_\$","");

for (my $i=0; $i <= scalar(@tbr)-1; $i+=2)
  {
   my $f = $tbr[$i];
   if ($working_string =~ /$f/)
      {
       my $r = $tbr[$i+1];
	   $working_string =~ s/$f/$r/g;
	  }
   }
return $working_string;
}


sub decodeTelegram($)
#Telegramme entschlüsseln und die entsprechenden Werte zur weiteren Entschlüsselung weiterreichen an sub getCsvResult
{
   my $TelegrammLength = length($_[0]);
   my @h = unpack("H2" x $TelegrammLength, $_[0]);

   my $hex_result = join(" ", @h);
   LOGDEB("ISM8 Daten: $hex_result");

   my $FrameSize = hex($h[4].$h[5]);
   my $MainService = hex($h[10]);
   my $SubService = hex($h[11]);

   if ($FrameSize != $TelegrammLength) {
        $zaehler{rahmenfehler}++;
        LOGERR("*** ERROR: TelegrammLength/FrameSize missmatch. [".$FrameSize."/".$TelegrammLength."] ***");
   } elsif ($SubService != 0x06) {
        LOGERR("*** WARNING: No SetDatapointValue.Req. [".sprintf("%x", $SubService)."] ***");
   } elsif ($MainService == 0xF0 and $SubService == 0x06) {
      my $StartDatapoint = hex($h[12].$h[13]);
      my $NumberOfDatapoints = hex($h[14].$h[15]);
	  my $Position = 0;

	  for (my $n=1; $n <= $NumberOfDatapoints; $n++) {
         my $DP_ID = hex($h[$Position + 16].$h[$Position + 17]);
         my $DP_command = hex($h[$Position + 18]);
         my $DP_length = hex($h[$Position + 19]);
         my $v = "";
		 my $send_msg = "";
         for (my $i=0; $i <= $DP_length - 1; $i++) { $v .= $h[$Position + 20 + $i]; }
         my $DP_value = hex($v);
	     # Der Filter hat den Zeitstempel mitverglichen und nur gegen den
	     # UNMITTELBAR vorhergehenden Datenpunkt geprueft. Zwei gleiche
	     # Werte fuenf Sekunden auseinander unterschieden sich damit schon
	     # in der Sekunde, und abwechselnde Datenpunkte wurden nie
	     # verglichen. Wirksam war er nur fuer ein woertlich wiederholtes
	     # Telegramm innerhalb derselben Sekunde.
	     #
	     # Jetzt wird je Datenpunkt der zuletzt GESENDETE Wert gemerkt,
	     # ohne Zeitstempel.
	     my $csv = getCsvResult($DP_ID, $DP_value);
	     my $auswertung = getLoggingTime.";".$csv;
		 if (!defined $letzter_wert{$DP_ID} or $letzter_wert{$DP_ID} ne $csv) {
			$letzter_wert{$DP_ID} = $csv;

			$zaehler{datenpunkte}++;
			my @fields = split(/;/, $auswertung); # [0]=Timestamp, [1]=DP ID, [2]=Geraet, [3]=Datenpunkt, [4]=Wert, optional [5]=Einheit

                        # getCsvResult legt seine Fehlerzeichenketten in das
                        # WERTFELD (ERR:NoResult, ERR:TypeNotFound, seit 3.0.8
                        # auch ERR:Ungueltig) - und genau das wurde nie
                        # angesehen. Abgefangen war nur "Datenpunkt gar nicht
                        # in der Tabelle", weil getDatenpunkt sein
                        # ERR:NotFound in das Geraetefeld schreibt. Auf den
                        # Wegen fhem und csv gingen die Zeichenketten deshalb
                        # als "Wert" an den Miniserver.
                        # V4: unbekannte Datenpunktkennungen werden gezaehlt und
                        # ihr Bereich gemerkt. Bis 3.0.8 wurden sie kommentarlos
                        # verworfen - und genau daran laesst sich erkennen, dass
                        # im ISM8 eine andere Firmware eingestellt ist als hier.
                        if (defined $fields[2] and $fields[2] =~ m/^ERR:NotFound/) {
                            $zaehler{unbekannt}++;
                            $unbekannt_min = $DP_ID
                                if !defined $unbekannt_min or $DP_ID < $unbekannt_min;
                            $unbekannt_max = $DP_ID
                                if !defined $unbekannt_max or $DP_ID > $unbekannt_max;
                            $zustand_schmutzig = 1;
                        }
                        if (grep { defined $_ and m/^ERR/ } @fields[2..4]) {
                            $verworfen++;
                            $zaehler{verworfen} = $verworfen;
                            LOGDEB("Kein verwertbarer Wert fuer Datenpunkt "
                                 . "$fields[1]: $fields[4] - nicht gesendet.");
                            goto err;
                        }

			if ($hash{output} eq 'fhem') {
			   ## Auswertung für FHEM erstellen ##
                           $send_msg = getFhemFriendly($fields[2]).".".$fields[1].".".getFhemFriendly($fields[3]); # Geraet - DP ID - Datenpunkt
			   if (scalar(@fields) == 6) { $send_msg .= ".".getFhemFriendly($fields[5]); } # Einheit (wenn vorhanden)
			   # Das fhem-Format trennt Name und Wert durch ein LEERZEICHEN -
			   # und die Werte enthielten selbst welche ("Heiz- Warmwasser-
			   # betrieb", "Mo 12:30:00"). Der Empfaenger konnte das nicht
			   # auseinanderhalten. Nur die Geraetenamen liefen durch
			   # getFhemFriendly, die Werte nicht.
			   my $wert = $fields[4];
			   $wert =~ s/\s+/_/g;
			   $send_msg .= " ".$wert; # Wert (nach Leerstelle!)
			} elsif ($hash{output} eq 'csv') {
			   ## Auswertung als CSV erstellen ##
                           $send_msg = $fields[1].";".$fields[2].";".$fields[3].";".$fields[4];
			   if (scalar(@fields) == 6) { $send_msg .= ";".$fields[5]; }
                        } elsif ($hash{output} eq 'data') {
                            # Die beiden Typlisten sind jetzt deckungsgleich mit
                            # der des MQTT-Weges. Vorher wichen sie an vier
                            # Typen ab: Uhrzeit und Datum gingen hier als TEXT
                            # hinaus ("Mo 12:30:00") - in einem virtuellen
                            # Analogeingang ist das kein Wert -, und die beiden
                            # Ucount-Typen ungefiltert statt aufbereitet.
                            # Betroffen in Firmware 1.9: 8 Zeit-/Datumspunkte
                            # und 9 Ucount-Punkte.
                            my @types = ("DPT_Scaling","DPT_Value_Temp","DPT_Value_Tempd","DPT_Value_Pres",
                                       "DPT_Power","DPT_Value_Volume_Flow",
                                       "DPT_FlowRate_m3/h","DPT_ActiveEnergy",
                                       "DPT_ActiveEnergy_kWh","DPT_Value_1_Ucount","DPT_Value_2_Ucount" );
                            my $datatype = getDatenpunkt($DP_ID, 3);

                            my $id = sprintf "%03d", $fields[1];
                            if (grep( /^$datatype$/, @types )) {
                                $send_msg = $id.";".$fields[4];
                            } else {
                                $send_msg = $id.";".$DP_value;
                            }
                        }

			## Auswertung an Multicast Gruppe schicken ..
                        if ($send_msg ne "") {
                            send_IGMPmessage($send_msg);
                        }

			## V1: Zustandsabbild fuellen - unabhaengig davon, welcher
			## Ausgabeweg eingestellt ist. Die Oberflaeche liest es.
			$zustand{$DP_ID} = [ $fields[2], $fields[3], $fields[4],
			                     (scalar(@fields) == 6 ? $fields[5] : '-'),
			                     time ];
			$zaehler{gesendet}++;
			$zustand_schmutzig = 1;

			## Protokoll und Kennfelder stehen VOR dem MQTT-Block.
			## Vorher standen sie dahinter, und das 'goto err' fuer
			## Uhrzeit- und Datumstypen sprang an beiden vorbei: mit
			## eingeschaltetem MQTT fehlten diese Datenpunkte deshalb im
			## Datenpunkt-Protokoll, mit ausgeschaltetem standen sie drin.
			if ($hash{dplog} eq '1') { log_msg_data($send_msg,$hash{output}); }

			## Wolf ISMi basierte Werte alle 60 Minuten schicken ##
			if (time >= $fw_actualize and $hash{output} eq 'fhem') {
			   send_IGMPmessage("ISM8i.997.IP $hash{ism8i_ip}");
			   send_IGMPmessage("ISM8i.998.Port $hash{port}");
			   send_IGMPmessage("ISM8i.999.Firmware $hash{fw}");
			   $fw_actualize = time + 3600;
			}

                        ## Auswertung an MQTT schicken ..
                        if ($hash{mqtt} eq '1') {
                             # <praefix>/Geraet/Datenpunkt
                             my $topic = "$hash{praefix}/".getMQTTFriendly($fields[2])."/".getMQTTFriendly($fields[3]);
                             #if (scalar(@fields) == 6) { $topic .= "/".getMQTTFriendly($fields[5]); } # Einheit (wenn vorhanden)

                             my @types = ("DPT_Scaling","DPT_Value_Temp","DPT_Value_Tempd","DPT_Value_Pres",
                                        "DPT_Power","DPT_Value_Volume_Flow",
                                        ,"DPT_FlowRate_m3/h","DPT_ActiveEnergy",
                                        "DPT_ActiveEnergy_kWh","DPT_Value_1_Ucount", "DPT_Value_2_Ucount" );
                             my @bool_types = ("DPT_Switch","DPT_Bool","DPT_Enable","DPT_OpenClose");
                             my @ignored_types = ("DPT_TimeOfDay","DPT_Date");
                             my $datatype = getDatenpunkt($DP_ID, 3);
                             my $value;
                             if (grep( /^$datatype$/, @types )) {
                                $value = $fields[4];
                             } elsif (grep( /^$datatype$/, @bool_types )) {
                                if ($DP_value == 1) {
                                    $value = "true";
                                } else {
                                    $value = "false";
                                }
                             } elsif (grep( /^$datatype$/, @ignored_types )) {
                                goto err;
                             } else {
                                $value = $DP_value;
                             }

                             publish_MQTT($DP_ID, $topic, $value);
                        }

                    err:
		 }
		 $Position += 4 + $DP_length;
	  }
   }
}


sub js_str
# Eine Zeichenkette fuer JSON maskieren. Bewusst von Hand statt ueber ein
# Modul: JSON::PP ist zwar Kernbestandteil, aber eine Abhaengigkeit, die
# niemand gemessen hat, gehoert nach dpkg/apt - und dafuer ist das hier zu
# wenig. Behandelt werden genau die Zeichen, die JSON verbietet.
{
   my $s = defined $_[0] ? $_[0] : '';
   $s =~ s/\\/\\\\/g;
   $s =~ s/"/\\"/g;
   $s =~ s/\n/\\n/g;
   $s =~ s/\r/\\r/g;
   $s =~ s/\t/\\t/g;
   $s =~ s/([\x00-\x1f])/sprintf('\\u%04x', ord($1))/ge;
   return '"' . $s . '"';
}

sub schreibe_zustand
# V1: das Zustandsabbild fuer die Oberflaeche.
#
# Geschrieben wird daneben und dann umbenannt - rename ist im selben
# Dateisystem unteilbar. Ohne das kann die Oberflaeche eine halb
# geschriebene Datei lesen und zeigt dann gar nichts an.
{
   my $ordner = $lbpdatadir;
   return unless defined $ordner and length $ordner;
   if (not -d $ordner) { mkdir($ordner, 0755); }
   my $ziel = $ordner . '/zustand.json';
   my $tmp  = $ziel . '.tmp.' . $$;

   my $fh;
   if (!open($fh, '>:encoding(UTF-8)', $tmp)) {
      LOGWARN("Zustandsabbild nicht schreibbar: $tmp ($!)");
      return;
   }
   my $jetzt = time;
   print $fh "{\n";
   print $fh '  "fassung": ' . js_str($version) . ",\n";
   print $fh '  "zeit": ' . $jetzt . ",\n";
   print $fh '  "start": ' . $start_zeit . ",\n";
   print $fh '  "firmware": ' . js_str($hash{fw}) . ",\n";
   print $fh '  "praefix": ' . js_str($hash{praefix}) . ",\n";
   print $fh '  "ism8_ip": ' . js_str($hash{ism8i_ip}) . ",\n";
   print $fh '  "online": ' . ($online_state ? 1 : 0) . ",\n";
   print $fh '  "verbindungen_offen": ' . scalar(keys %ism8_clients) . ",\n";
   print $fh '  "zaehler_umlauf": ' . $zaehler_umlauf . ",\n";
   print $fh '  "unbekannt_min": ' . (defined $unbekannt_min ? $unbekannt_min : -1) . ",\n";
   print $fh '  "unbekannt_max": ' . (defined $unbekannt_max ? $unbekannt_max : -1) . ",\n";
   print $fh "  \"zaehler\": {\n";
   my @k = sort keys %zaehler;
   for my $i (0 .. $#k) {
      print $fh '    ' . js_str($k[$i]) . ': ' . ($zaehler{$k[$i]} + 0)
              . ($i < $#k ? ',' : '') . "\n";
   }
   print $fh "  },\n";
   print $fh "  \"werte\": {\n";
   my @ids = sort { $a <=> $b } keys %zustand;
   for my $i (0 .. $#ids) {
      my $z = $zustand{$ids[$i]};
      print $fh '    "' . $ids[$i] . '": {'
              . '"g": ' . js_str($z->[0]) . ', '
              . '"n": ' . js_str($z->[1]) . ', '
              . '"w": ' . js_str($z->[2]) . ', '
              . '"e": ' . js_str($z->[3]) . ', '
              . '"t": ' . ($z->[4] + 0) . '}'
              . ($i < $#ids ? ',' : '') . "\n";
   }
   print $fh "  }\n}\n";
   close $fh;

   if (!rename($tmp, $ziel)) {
      LOGWARN("Zustandsabbild liess sich nicht umbenennen: $!");
      unlink($tmp);
      return;
   }
   $zustand_schmutzig = 0;
   $zustand_letzt = $jetzt;
}

sub loadConfig
#Config Datei laden und Werte zwischenspeichern. Wenn keine Config Datei vorhanden ist wird eine angelegt.
#Wenn die Werte in der Config nicht den Vorgaben entsprechen, dann werden die Standardwerte genommen.
#
#Bedeutung der Einträge der Config:
#   ism8i_port = Port auf dem das Modul auf den TCP Trafic des Wolf ISM8i Schnittstellenmoduls hört.
#                Die IP und der Port wird im Webinterface des Schnittstellenmoduls eingestellt. Die IP
#                ist die IP des PCs/Raspis auf dem dieses Modul läuft.
#                Default ist 12004.
#   input_port = Port auf dem das Modul auf den TCP Trafic des Loxone Miniservers hört.
#                Default ist 12005.
#   fw_version = Die Firmware Version des Wolf ISM8i Schnittstellenmoduls. Diese steht im Webinterface des Schnittstellenmoduls.
#                Möglich sind 1.4, 1.5, 1.7, 1.8 oder 1.9.
#                Default ist 1.8.
#   multicast_ip = die IPv4 Adresse der Multicast Gruppe an der die die entschlüsselten Datagramme geschickt werden. Default ist
#                  Bitte beim Ändern auf die Vorgaben für Multicast Adressen achten!
#                  Default ist 239.7.7.77.
#   multicast_port = Der Port der Multicast Gruppe. Möglich von 1 bis 65535.
#                    Default ist 35353.
#   dp_log = Gibt an ob die empfangenen Datenpunkte als Log ausgegeben werden sollnen.
#            Wenn geloggt wird bitte in regelmäßigen Abständen die Größe des Logfiles prüfen und ggf. löschen, dader Log schnell sehr groß werden kann.
#            Möglich sind 0 oder 1.
#            Default ist 0.
#   output = Das Format in welchem die Datenpunkte an die Multicast Gruppe oder an das Datenpunkte-Log gesickt wird.
#            Möglich ist 'csv' für das CSV Format (mit Semikolon (;) separiert) z.B. zum Importieren in Tabekkenkalkulationen.
#            Möglich ist 'fhem' als Spezialformat für das ISM8I Modul.
#            Default ist 'fhem'.
#
{
   my $file = $lbpconfigdir."/wolf_ism8i.conf";
   LOGINF("Reading Config:");
   if (-e $file) {
	  my $data;
      open($data, '<:encoding(UTF-8)', $file) or die "Could not open '$file' $!\n";
      LOGINF("   Config file '$file' found and opened for reading.");
      while (my $line = <$data>) {
	    $line = lc($line); # alles lowe case
		# Nur Zeilen ueberspringen, die MIT einem # beginnen. Vorher wurde
		# jede Zeile verworfen, in der irgendwo ein # vorkam - ein
		# Multicast-Kommentar hinter dem Wert reichte, und die Einstellung
		# fiel stillschweigend auf den Vorgabewert zurueck.
		next if $line =~ m/^\s*#/;
		{
		   my @fields = split(/ /, l_r_dbl_trim($line));
	       if (scalar(@fields) == 2) {
              LOGINF("      $fields[0] -> $fields[1]");
	          if ($fields[0] eq "enable") {
	             if ($fields[1] =~ m/^(1|0)$/) {
	                $hash{enable} = $fields[1]; } else { $hash{enable} = '0'; }
	          } elsif ($fields[0] eq "ism8i_port") {
		         if ($fields[1] =~ m/^([0-9]{1,4}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$/ and $fields[1] > 0 and $fields[1] <= 65535) {
			        $hash{port} = $fields[1]; } else { $hash{port} = '12004'; }
                      } elsif ($fields[0] eq "input_port") {
                         if ($fields[1] =~ m/^([0-9]{1,4}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$/ and $fields[1] > 0 and $fields[1] <= 65535) {
                                $hash{inport} = $fields[1]; } else { $hash{inport} = '12005'; }
                      } elsif ($fields[0] eq "fw_version") {
		         if ($fields[1] =~ m/^\d{1}\.\d{1}$/) {
                         $hash{fw} = $fields[1]; } else { $hash{fw} = '1.8'; }
		      } elsif ($fields[0] eq "multicast_ip") {
		         # Bis 3.0.7 stand in der mitgelieferten Konfiguration der
		         # Platzhalter %MS_IP%, den kein Installationsskript ersetzt.
		         # Die Pruefung hier hat ihn abgewiesen und STILL 239.7.7.77
		         # gesetzt - bis der Anwender die Einstellungsseite einmal
		         # speicherte, gingen die UDP-Daten damit an die Multicast-
		         # Gruppe statt an den Miniserver. Jetzt steht der echte
		         # Vorgabewert in der Datei, und ein unbrauchbarer Wert wird
		         # gemeldet statt stillschweigend ersetzt.
     		     if ($fields[1] =~ m/^(?:(?:\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5])\.){3}(?:\d|[1-9]\d|1\d\d|2[0-4]\d|25[0-5])$/) {
		         $hash{mcip} = $fields[1]; } else {
		            LOGWARN("multicast_ip ist keine gueltige IPv4-Adresse: "
		                  . "'$fields[1]' - es gilt der Vorgabewert 239.7.7.77. "
		                  . "Im Reiter Einstellungen den Miniserver waehlen und speichern.");
		            $hash{mcip} = '239.7.7.77'; }
		      } elsif ($fields[0] eq "multicast_port") {
		         if ($fields[1] =~ m/^([0-9]{1,4}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$/ and $fields[1] > 0 and $fields[1] <= 65535) {
			        $hash{mcport} = $fields[1]; } else { $hash{mcport} = '35353'; }
		      } elsif ($fields[0] eq "dp_log") {
		         if ($fields[1] =~ m/^(1|0)$/) {
		            $hash{dplog} = $fields[1]; } else { $hash{dplog} = '0'; }
		      } elsif ($fields[0] eq "output") {
                         if ($fields[1] =~ m/^(csv|fhem|data|none)$/) {
		            $hash{output} = $fields[1]; } else { $hash{output} = 'none'; }
                      } elsif ($fields[0] eq "mqtt") {
                         if ($fields[1] =~ m/^(1|0)$/) {
                            $hash{mqtt} = $fields[1]; } else { $hash{mqtt} = '1'; }
                      } elsif ($fields[0] eq "pull_on_write") {
                         if ($fields[1] =~ m/^(1|0)$/) {
                            $hash{pull_on_write} = $fields[1]; } else { $hash{pull_on_write} = '0'; }
                      } elsif ($fields[0] eq "online_timeout") {
                      if ($fields[1] =~ m/^([0-9]*|-1)$/) {
                         $hash{online_timeout} = $fields[1]; } else { $hash{online_timeout} = '-1'; }
	          } elsif ($fields[0] eq "praefix") {
	             # Das Themen-Praefix war bis 3.0.8 fest verdrahtet. Wer zwei
	             # ISM8 am selben Broker betreibt, konnte sie nicht trennen.
	             # loadConfig schreibt jede Zeile klein, der Wert ist also
	             # ohnehin kleingeschrieben.
	             if ($fields[1] =~ m/^[a-z0-9_-]{1,32}$/) {
	                $hash{praefix} = $fields[1]; } else {
	                LOGWARN("Unbrauchbares MQTT-Praefix '$fields[1]' - es gilt wolf_ng.");
	                $hash{praefix} = 'wolf_ng'; }
	          } elsif ($fields[0] eq "herzschlag") {
	             if ($fields[1] =~ m/^[0-9]+$/) {
	                $hash{herzschlag} = $fields[1]; } else { $hash{herzschlag} = '60'; }
	          } elsif ($fields[0] eq "abgleich_takt") {
	             if ($fields[1] =~ m/^[0-9]+$/) {
	                $hash{abgleich_takt} = $fields[1]; } else { $hash{abgleich_takt} = '0'; }
	          }
		   }
	    }
      }
	  close $data;
   } else {
     LOGINF("   Config file not found, creating new config file.");
     open(my $fh, '>:encoding(UTF-8)', $file) or die "Could not open/write file '$file' $!";

     print $fh "######################################################################################################################################################\n";
     print $fh "#Config Datei laden und Werte zwischenspeichern. Wenn keine Config Datei vorhanden ist wird eine angelegt.\n";
     print $fh "#Wenn die Werte in der Config nicht den Vorgaben entsprechen, dann werden die Standardwerte genommen.\n";
     print $fh "######################################################################################################################################################\n";
     print $fh "#Bedeutung der Einträge der Config:\n";
     print $fh "#\n";
     print $fh "#   ism8i_port = Port auf dem das Modul auf den TCP Trafic des Wolf ISM8i Schnittstellenmoduls hört.\n";
     print $fh "#                Die IP und der Port wird im Webinterface des Schnittstellenmoduls eingestellt. Die IP\n";
     print $fh "#                ist die IP des PCs/Raspis auf dem dieses Modul läuft.\n";
     print $fh "#                Default ist 12004.\n";
     print $fh "#   input_port = Port auf dem das Modul auf den TCP Trafic des Loxone Miniservers hört.\n";
     print $fh "#                Default ist 12005.\n";
     print $fh "#   fw_version = Die Firmware Version des Wolf ISM8i Schnittstellenmoduls. Diese steht im Webinterface des Schnittstellenmoduls.\n";
     print $fh "#                Möglich sind: 1.4 1.5 1.7 1.8 1.9\n";
     print $fh "#                Default ist 1.8\n";
     print $fh "#   multicast_ip = die IPv4 Adresse der Multicast Gruppe an der die die entschlüsselten Datagramme geschickt werden. Default ist\n";
     print $fh "#                  Bitte beim Ändern auf die Vorgaben für Multicast Adressen achten!\n";
     print $fh "#                  Default ist 239.7.7.77.\n";
     print $fh "#   multicast_port = Der Port der Multicast Gruppe. Möglich von 1 bis 65535.\n";
     print $fh "#                    Default ist 35353.\n";
     print $fh "#   dp_log = Gibt an ob die empfangenen Datenpunkte als Log ausgegeben werden sollnen.\n";
     print $fh "#            Wenn geloggt wird bitte in regelmäßigen Abständen die Größe des Logfiles prüfen und ggf. löschen, dader Log schnell sehr groß werden kann.\n";
     print $fh "#            Möglich sind 0 oder 1.\n";
     print $fh "#            Default ist 0.\n";
     print $fh "#   output = Das Format in welchem die Datenpunkte an die Multicast Gruppe oder an das Datenpunkte-Log gesickt wird.\n";
     print $fh "#            Möglich ist 'csv' für das CSV Format (mit Semikolon (;) separiert) z.B. zum Importieren in Tabekkenkalkulationen.\n";
     print $fh "#            Möglich ist 'fhem' als Spezialformat für das ISM8I Modul.\n";
     print $fh "#            Default ist 'fhem'\n";
     print $fh "#   online_timeout = Wenn innerhalb von X Sekunden keine Daten mehr vom Modul geschickt werden, wird der online state resetet.\n";
     print $fh "#                    -1 deaktiviert den periodischen online check.\n";
     print $fh "#                    Default is -1.;\n";
     print $fh "######################################################################################################################################################\n\n";

	 # enable fehlte in der Liste. Legte der Dienst die Konfiguration neu an,
	 # verschwand der Schluessel - und daemon/daemon wie postupgrade.sh lesen
	 # ihn mit Rueckfall auf 0. Der Autostart war danach still aus.
	 print $fh "enable $hash{enable}\n";
	 print $fh "ism8i_port $hash{port}\n";
         print $fh "input_port $hash{inport}\n";
	 print $fh "fw_version $hash{fw}\n";
	 print $fh "multicast_ip $hash{mcip}\n";
	 print $fh "multicast_port $hash{mcport}\n";
	 print $fh "dp_log $hash{dplog}\n";
	 print $fh "output $hash{output}\n";
         print $fh "mqtt $hash{mqtt}\n";
         print $fh "pull_on_write $hash{pull_on_write}\n";
         print $fh "online_timeout $hash{online_timeout}\n";
         print $fh "praefix $hash{praefix}\n";
         print $fh "herzschlag $hash{herzschlag}\n";
         print $fh "abgleich_takt $hash{abgleich_takt}\n";

     close $fh;
   }
}


sub loadDatenpunkte
#Datenpunkte aus einem CSV File (Semikolon-separiert) laden.
#Die Reihenfolge der CSV Spalten lautet: DP ID, Gerät, Datenpunkt KNX-Datenpunkttyp, Output/Input, Einheit
#Getrennt wird ausschliesslich am Semikolon. Kommas, Leerstellen und Klammern
#in den Feldern sind deshalb unschaedlich - die mitgelieferten Tabellen
#enthalten sie in fast jeder Zeile ("Heizgeraet 1 (TOB,CGB-2,...)"). Bis 3.0.7
#stand hier das Gegenteil; das war unwahr.
{
   #erstmal vorsichtshalber datenpunkte array löschen:
   while(@datenpunkte) { shift(@datenpunkte); }

   my $fw_version = $hash{fw};
   $fw_version =~ s/\.//g;
   my $file = $script_path."/wolf_datenpunkte_".$fw_version.".csv";

   # Fehlt die Tabelle, ist der Dauerlaeufer bisher gestorben - und der
   # Waechter hat ihn zwanzigmal in Folge neu gestartet und dann endgueltig
   # aufgegeben. Das Plugin war danach bis zum naechsten Neustart des
   # Rechners tot. Ausgeloest hat das eine Zeile wie "fw_version 1.6": die
   # Pruefung in loadConfig nimmt jede Form "Ziffer.Ziffer" an, mitgeliefert
   # sind aber nur fuenf Tabellen.
   if (! -r $file) {
       LOGERR("Keine Datenpunkttabelle fuer Firmware $hash{fw} ($file).");
       LOGERR("Mitgeliefert sind 1.4, 1.5, 1.7, 1.8 und 1.9. Es wird mit 1.8 "
            . "weitergearbeitet - bitte im Reiter Einstellungen berichtigen.");
       $hash{fw} = '1.8';
       $file = $script_path."/wolf_datenpunkte_18.csv";
   }
   my $data;
   open($data, '<:encoding(UTF-8)', $file) or die "Could not open '$file' $!\n";
   while (my $line = <$data>)
    {
     my @fields = split(/;/, r_trim($line));
	 if (scalar(@fields) == 6)
	   {
	    $datenpunkte[0 + $fields[0]] = [ @fields ]; # <-so hinzufügen, damit der Index mit der DP ID übereinstimmt zu einfacheren Suche.
	   }
    }
	close $data;
}


sub writeDatenpunkteToLog
#Devloper Sub zur Kontrolle des eingelesenen CSV Files
{
   my $filename = $lbplogdir."/wolf_ism8i_datenpunkte.log";
   open(my $fh, '>:encoding(UTF-8)', $filename) or die "Could not open file '$filename' $!";
   print $fh "\n";
   foreach my $o (@datenpunkte)
     {
	  foreach my $i (@$o) { print $fh $i."  "; }
	  print $fh "\n";
	 }
   close $fh;
}


sub getDatenpunkt($$)
#Returnt aus dem 2D Array mit Datenpunkten den Datenpunkt als Array mit der übergebenen DP ID.
#$1 = DP ID , $2 = Index des Feldes (0 = DP ID, 1 = Gerät, 2 = Datenpunkt, 3 = KNX-Datenpunkttyp, 4 = Output/Input, 5 = Einheit)
{
   my $d = $datenpunkte[$_[0]][$_[1]];
   if ( (defined $d) and (length($d)>0) ) { return $d; } else { return "ERR:NotFound"; }
}


sub getCsvResult($$)
#Berchnet den Inhalt des Telegrams und gibt das Ergebnis im CSV Mode ';'-separiert.
#$1 = DP ID , $2 = DP Value
#Ergebnis: DP_ID [1]; Gerät [2]; Erignis [3]; Wert [4]; Einheit [5] (falls vorhanden)
{
   my $dp_id = $_[0];
   my $dp_val = $_[1];
   my $geraet = getDatenpunkt($dp_id, 1);
   my $ereignis = getDatenpunkt($dp_id, 2);
   my $datatype = getDatenpunkt($dp_id, 3);
   my $result = $dp_id.";".$geraet.";".$ereignis.";";
   my $v = "ERR:NoResult";

   if ($datatype eq "DPT_Switch")
     {
	  if ($dp_val == 0) {$v = "Aus";} elsif ($dp_val == 1) {$v = "An";}
	  $result .= $v;
	 }
   elsif ($datatype eq "DPT_Bool")
     {
	  if ($dp_val == 0) {$v = "Falsch";} elsif ($dp_val == 1) {$v = "Wahr";}
	  $result .= $v;
	 }
   elsif ($datatype eq "DPT_Enable")
     {
	  if ($dp_val == 0) {$v = "Deaktiviert";} elsif ($dp_val == 1) {$v = "Aktiviert";}
	  $result .= $v;
	 }
   elsif ($datatype eq "DPT_OpenClose")
     {
	  if ($dp_val == 0) {$v = "Offen";} elsif ($dp_val == 1) {$v = "Geschlossen";}
	  $result .= $v;
	 }
   elsif ($datatype eq "DPT_Scaling")
     {
          $result .= round(($dp_val & 0xff) * 100 / 255).";%";
	 }
   elsif ($datatype eq "DPT_Value_1_Ucount")
     {
          $result .= ($dp_val & 0xff);
         }
   elsif ($datatype eq "DPT_Value_2_Ucount")
     {
          $result .= $dp_val;
         }
   elsif ($datatype eq "DPT_Value_Temp")
     {
	  my $f = pdt_knx_float($dp_val);
	  $result .= defined $f ? $f.";°C" : "ERR:Ungueltig";
	 }
   elsif ($datatype eq "DPT_Value_Tempd")
     {
	  my $f = pdt_knx_float($dp_val);
	  $result .= defined $f ? $f.";K" : "ERR:Ungueltig";
	 }
   elsif ($datatype eq "DPT_Value_Pres")
     {
	  my $f = pdt_knx_float($dp_val);
	  $result .= defined $f ? $f.";Pa" : "ERR:Ungueltig";
	 }
   elsif ($datatype eq "DPT_Power")
     {
	  my $f = pdt_knx_float($dp_val);
	  $result .= defined $f ? $f.";kW" : "ERR:Ungueltig";
	 }
   elsif ($datatype eq "DPT_Value_Volume_Flow")
     {
	  my $f = pdt_knx_float($dp_val);
	  $result .= defined $f ? $f.";l/h" : "ERR:Ungueltig";
	 }
   elsif ($datatype eq "DPT_TimeOfDay")
     {
	  $result .= pdt_time($dp_val);
	 }
   elsif ($datatype eq "DPT_Date")
     {
	  $result .= pdt_date($dp_val);
	 }
   elsif ($datatype eq "DPT_FlowRate_m3/h")
     {
	  $result .= (pdt_long($dp_val) * 0.0001).";m³/h";
	 }
   elsif ($datatype eq "DPT_ActiveEnergy")
     {
	  $result .= pdt_long($dp_val).";Wh";
	 }
   elsif ($datatype eq "DPT_ActiveEnergy_kWh")
     {
	  $result .= pdt_long($dp_val).";kWh";
	 }
   elsif ($datatype eq "DPT_HVACMode")
     {
          my @Heizkreis = ("Automatikbetrieb","Heizbetrieb","Standby","Sparbetrieb","-");
	  my @CWL = ("Automatikbetrieb","Nennlüftung","-","Reduzierung Lüftung","-");
          if ($hash{fw} eq '1.8' or $hash{fw} eq '1.9') {
            @Heizkreis = ("Automatikbetrieb","Heizbetrieb","Standby","Sparbetrieb","Permanent Kühlen");
            @CWL = ("Automatikbetrieb","Nennlüftung","-","Reduzierung Lüftung","Feuchteschutz");
          }

      if ($geraet =~ /Heizkreis/ or $geraet =~ /Mischerkreis/)
	   	{ $v = $Heizkreis[$dp_val]; }
	  elsif ($geraet =~ /CWL/)
	   	{ $v = $CWL[$dp_val]; }

	  if (defined $v) { $result .= $v; } else { $result .= "ERR:NoResult[".$dp_id."/".$dp_val."]";}
	 }
   elsif ($datatype eq "DPT_DHWMode")
     {
	  my @Warmwasser = ("Automatikbetrieb","-","Dauerbetrieb","-","Standby");

      if ($geraet =~ /Warmwasser/) { $v = $Warmwasser[$dp_val]; }

	  if (defined $v) { $result .= $v; } else { $result .= "ERR:NoResult[".$dp_id."/".$dp_val."]";}
	 }
   elsif ($datatype eq "DPT_HVACContrMode")
     {
          my @CGB2_MGK2_TOB = ("Schornsteinferger","Heiz- Warmwasserbetrieb","-","-","-","-","Standby","GLT","-","-","-","Frostschutz","-","-","-","Kalibration");

          my @BWL1S = ("Antilegionellenfunktion","Heiz- Warmwasserbetrieb","Vorwärmung","Aktive Kühlung","-","-","Standby","GLT","-","-","-","Frostschutz","-","-","-","-");

          if ($geraet =~ /CGB-2/ or $geraet =~ /MGK-2/ or $geraet =~ /TOB/ or $geraet =~ /COB-2/ or $geraet =~ /TGB/)
	    { $v = $CGB2_MGK2_TOB[$dp_val]; }
	  elsif ($geraet =~ /BWL-1-S/ or $geraet =~ /CHA/ or $geraet =~ /Wärmepumpe/)
	   	{ $v = $BWL1S[$dp_val]; }

	  if (defined $v) { $result .= $v; } else { $result .= "ERR:NoResult[".$dp_id."/".$dp_val."]";}
	 }
	else
	 {
	  $result .= "ERR:TypeNotFound[".$datatype."]";
	 }

   return $result;
}

# "<ID> <VALUE>"
# test parseInput("104;-30");
sub parseInput($)
{
    my @input = split /;/, $_[0];
    my $id = $input[0];
    my $data = $input[1];
    if (scalar(@input) != 2) {
        LOGERR("Invalid command, expected the format: ID;VALUE");
        return;
    }
    my $geraet = getDatenpunkt($id, 1);
    my $datatype = getDatenpunkt($id, 3);
    my $writeable = getDatenpunkt($id, 4) =~ m/In/;
    if (!$writeable) {
        LOGERR("Datenpunkt $id kann nicht beschrieben werden!");
        return;
    }

    LOGDEB("VALUE: ".$data);

    my $enc_value;

    if ($datatype eq "DPT_Switch" ||
        $datatype eq "DPT_Bool" ||
        $datatype eq "DPT_Enable" ||
        $datatype eq "DPT_OpenClose") {
        if ($data eq "true") {
            $data = 1;
        } elsif ($data eq "false") {
            $data = 0;
        }
        # Eine Zeichenkette wurde in diesem Vergleich zu 0 und BESTAND die
        # Pruefung; pack("C", "abc") erzeugte danach das Byte 0x00. Der
        # Befehl "100;abc" hat den Datenpunkt also AUSGESCHALTET, statt
        # abgewiesen zu werden - und im Protokoll stand kein Wort davon,
        # weil keine Ablehnung stattfand.
        if ($data !~ m/^[01]$/) {
            LOGERR("Ungueltiger Schaltwert: '$data' (erwartet 0, 1, true oder false)");
            return;
        }
        $enc_value = pack("C", $data);
    }
    elsif ($datatype eq "DPT_Scaling") {
        if ($data !~ m/^\s*-?\d+(\.\d+)?\s*$/) {
            LOGERR("Ungueltiger Zahlenwert: '$data'");
            return;
        }
        if ($data < 0 || $data > 100) {
            LOGERR("DPT_Scaling erwartet 0 bis 100 Prozent, bekommen: $data");
            return;
        }
        $enc_value = pack("C", round($data / 100 * 255));
    }
    elsif ($datatype eq "DPT_Value_Temp" ||
           $datatype eq "DPT_Value_Tempd" ||
           $datatype eq "DPT_Value_Pres" ||
           $datatype eq "DPT_Power" ||
           $datatype eq "DPT_Value_Volume_Flow")
    {
        $enc_value = to_pdt_float($data);
    }
    elsif ($datatype eq "DPT_TimeOfDay")
    {
        $enc_value = to_pdt_time($data);
    }
    elsif ($datatype eq "DPT_Date")
    {
        $enc_value = to_pdt_date($data);
    }
    elsif ($datatype eq "DPT_FlowRate_m3/h")
    {
        if ($data !~ m/^\s*-?\d+(\.\d+)?\s*$/) {
            LOGERR("Ungueltiger Zahlenwert: '$data'");
            return;
        }
        $enc_value = to_pdt_long($data * 10000);
    }
    elsif ($datatype eq "DPT_ActiveEnergy" ||
           $datatype eq "DPT_ActiveEnergy_kWh") {
        if ($data !~ m/^\s*-?\d+\s*$/) {
            LOGERR("Ungueltiger Ganzzahlwert: '$data'");
            return;
        }
        $enc_value = to_pdt_long($data);
    }
    elsif ($datatype eq "DPT_HVACMode")  {
        if ($data !~ m/^\d+$/) {
            LOGERR("Ungueltige Betriebsart: '$data' (erwartet eine Zahl)");
            return;
        }
        if ($geraet =~ /Heizkreis/ or $geraet =~ /Mischerkreis/) {
            if ($data < 0 || $data > 4) {
                LOGERR("Invalid input!");
                return;
            }
            $enc_value = pack("C", $data);
        } elsif ($geraet =~ /CWL/) {
            if (!($data == 0 || $data == 1 || $data == 3 || $data == 4)) {
                LOGERR("Invalid input!");
                return;
            }
            $enc_value = pack("C", $data);
        } else {
            LOGERR("Invalid input!");
            return;
        }
    }
    elsif ($datatype eq "DPT_DHWMode") {
        if ($data !~ m/^\d+$/) {
            LOGERR("Ungueltige Betriebsart: '$data' (erwartet eine Zahl)");
            return;
        }
        if ($geraet =~ /Warmwasser/) {
            if (!($data == 0 || $data == 2 || $data == 4)) {
                LOGERR("Invalid input!");
                return;
            }
            $enc_value = pack("C", $data);
        } else {
            LOGERR("Invalid input!");
            return;
        }
    }
    else {
        LOGERR("Invalid type!");
        return;
    }

    # Ohne diese Wache wurde aus dem Fehlerwert der Kodierfunktionen ein
    # Telegramm: createRequest hat die -1 als Nutzlast vermessen und roh
    # angehaengt - auf dem Bus kamen die ASCII-Zeichen "2d 31" an, mit
    # einem Laengenfeld von 2 statt 3.
    if (!defined $enc_value) {
        LOGERR("Kein gueltiges Telegramm fuer Datenpunkt $id - Befehl verworfen.");
        return;
    }

    return createRequest($id, $enc_value);
}

sub pdt_knx_float($)
{
# Format:
#   2 octets: F16
#   octet nr: 2MSB 1LSB
#   field names: FloatValue
#   encoding: MEEEEMMMMMMMMMMM
# Encoding:
#   Float Value = (0,01*M)*2**(E)
#   E = [0...15]
#   M = [-2048...2047], two‘s complement notation
#   For all Datapoint Types 9.xxx, the encoded value 7FFFh shall always be used to denote invalid data.
# Range: [-671088,64...670760,96]
# PDT: PDT_KNX_FLOAT
#
   my $val = $_[0];

   # 0x7FFF ist bei ALLEN Typen 9.xxx die Kennung fuer UNGUELTIGE Daten -
   # der Kommentar oben sagt es seit jeher, geprueft wurde es bis 3.0.7
   # nicht. Gerechnet ergab die Kennung 670760.96, und das sieht in Loxone
   # aus wie ein Messwert: ein abgezogener oder defekter Fuehler lieferte
   # damit einen Extremwert in die Regelung.
   #
   # Rueckgabe undef. Der Aufrufer macht daraus einen Fehlerwert und sendet
   # NICHTS - fail closed statt einer erfundenen Zahl.
   return undef if ($val & 0xFFFF) == 0x7FFF;

   my $m = (-2048 * (($val & 0b10000000_00000000) >> 15)) + ($val & 0b111_11111111);
   my $e = ($val & 0b01111000_00000000) >> 11;

   return (0.01 * $m) * (2 ** $e);
}

sub to_pdt_float($)
{
    # Der Exponent wurde nie begrenzt. Ab Exponent 16 schiebt die Zeile
    # weiter unten in das Vorzeichenbit hinein, und aus 700000 wurde -9.8 -
    # ein plausibel aussehender, falscher Sollwert auf dem Heizungsbus.
    # Nachgemessen: schon die im Kopf von pdt_knx_float dokumentierten
    # Bereichsgrenzen kamen nicht heil durch die eigene Kodierung.
    #
    # Die Untergrenze der Schleife war ausserdem -2047 statt -2048. Damit
    # war -671088.64 - das dokumentierte Minimum - nicht darstellbar.
    if (!defined $_[0] || $_[0] !~ m/^\s*-?\d+(\.\d+)?\s*$/) {
        LOGERR("Kein gueltiger Zahlenwert: " . (defined $_[0] ? $_[0] : "(leer)"));
        return undef;
    }
    my $mant = int(100 * $_[0]);
    my $exp = 0;
    while($mant < -2048 || $mant > 2047) {
        $mant = int($mant / 2);
        $exp += 1;
        if ($exp > 15) {
            LOGERR("Wert ausserhalb des KNX-Bereichs [-671088.64 ... 670433.28]: $_[0]");
            return undef;
        }
    }
    my $sign = 0;
    if ($mant < 0) {
        $sign = 1;
        $mant = -$mant;
        $mant = (~$mant + 1) & 0x7FF;
    }

    $exp = $exp << 11;
    $sign = $sign << 15;
    my $val = $mant | $exp | $sign;

    # 0x7FFF ist die Kennung "ungueltig" (siehe pdt_knx_float). Ein
    # Sollwert, der zufaellig genau darauf faellt, waere fuer die
    # Gegenstelle kein Wert, sondern eine Stoerungsmeldung.
    if ($val == 0x7FFF) {
        LOGERR("Wert ergibt die KNX-Kennung fuer ungueltige Daten: $_[0]");
        return undef;
    }
#    printf ("mant %10b %3d\n",$mant,$mant);
#    printf ("exp %10b %3d\n",$exp,$exp);
#    printf ("sign %10b %3d\n",$sign,$sign);
#    printf ("val %10b %3d\n",$val,$val);

    return pack("n", $val);
}


sub pdt_long($)
{
# Format: 4 octets: V32
# octet nr: 4MSB 3 2 1LSB
# field names: SignedValue
# encoding: VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV
# Encoding: Two‘s complement notation
# Range: SignedValue = [-2 147 483 648 ... 2 147 483 647]
# PDT: PDT_LONG   my $val = $_[0];
#
   my $val = $_[0];
   my $r = (-1 * ($val & 0b10000000_00000000_00000000_00000000)) + ($val & 0b1111111_11111111_11111111_11111111);

   return $r;
}

sub to_pdt_long($)
{
    return pack("N", $_[0]);
}

sub pdt_time($)
{
# 3 Byte Time
# DDDHHHHH RRMMMMMM RRSSSSSS
# R Reserved
# D Weekday
# H Hour
# M Minutes
# S Seconds
   my $b1 = ($_[0] & 0xff0000) >> 16;
   my $b2 = ($_[0] & 0x00ff00) >> 8;
   my $b3 = ($_[0] & 0x0000ff);
   my $weekday = ($b1  & 0xe0) >> 5;
   my @weekdays = ("","Mo","Di","Mi","Do","Fr","Sa","So");
   my $day_str = "";
   if ($weekday > 0) {
       $day_str = $weekdays[$weekday]." ";
   }
   my $hour = $b1 & 0x1f;
   my $min = $b2 & 0x3f;
   my $sec = $b3 & 0x3f;
   return sprintf("%s%02d:%02d:%02d", $day_str, $hour, $min, $sec);
}

sub to_pdt_time($)
{
    # Ohne Wochentag ergab die alte Fassung "So 00:00:00", bei einem
    # unbekannten Wochentag ebenfalls Sonntag: split / / lieferte nur ein
    # Feld, $hour/$min/$sec blieben undef, und die Bereichspruefungen
    # behandelten undef als 0. first_index gab fuer einen unbekannten Tag
    # -1 zurueck, und -1 << 5 wurde von pack("C") zu 0xE0, also Tag 7.
    #
    # Jetzt wird die Form zuerst geprueft. KNX kennt Tag 0 ("kein Tag"),
    # eine Angabe ohne Wochentag ist also gueltig - ein falscher Wochentag
    # dagegen nicht. Abgewiesen wird mit undef, nicht mit -1: die -1 kam
    # als ASCII "2d 31" auf dem Heizungsbus an.
    my $ein = defined $_[0] ? $_[0] : "";
    $ein =~ s/^\s+//;
    $ein =~ s/\s+$//;

    my @weekdays = ("","Mo","Di","Mi","Do","Fr","Sa","So");
    my ($day, $hour, $min, $sec);

    if ($ein =~ m/^(\d{1,2}):(\d{1,2}):(\d{1,2})$/) {
        ($day, $hour, $min, $sec) = (0, $1, $2, $3);
    } elsif ($ein =~ m/^(\S+)\s+(\d{1,2}):(\d{1,2}):(\d{1,2})$/) {
        my $name = $1;
        ($hour, $min, $sec) = ($2, $3, $4);
        $day = first_index { $_ eq $name } @weekdays;
        if ($day < 1) {
            LOGERR("Unbekannter Wochentag: $name (erwartet Mo Di Mi Do Fr Sa So)");
            return undef;
        }
    } else {
        LOGERR("Ungueltige Zeitangabe: '$ein' (erwartet 'HH:MM:SS' oder 'Mo HH:MM:SS')");
        return undef;
    }

    if ($hour > 23) { LOGERR("Invalid hour: $hour");    return undef; }
    if ($min  > 59) { LOGERR("Invalid minute: $min");   return undef; }
    if ($sec  > 59) { LOGERR("Invalid seconds: $sec");  return undef; }

    return pack("C C C", ($day << 5) | $hour, $min, $sec);
}

sub pdt_date($)
{
# 3 byte Date
# RRRDDDDD RRRRMMMM RYYYYYYY
# R Reserved
# D Day
# M Month
# Y Year
   my $b1 = ($_[0] & 0xff0000) >> 16;
   my $b2 = ($_[0] & 0x00ff00) >> 8;
   my $b3 = ($_[0] & 0x0000ff);
   my $day = $b1 & 0x1f;
   my $mon = $b2 & 0xf;
   my $year = $b3 & 0x7f;
   if ($year < 90) { $year += 2000; } else { $year += 1900; }
   return sprintf("%02d.%02d.%04d", $day, $mon, $year);
}

sub to_pdt_date($)
{
    # Wie to_pdt_time: erst die Form pruefen, dann rechnen, und im
    # Fehlerfall undef statt -1 zurueckgeben. Die -1 wurde von parseInput
    # ungeprueft weitergereicht und landete als ASCII "2d 31" im Telegramm.
    # Ausserdem waren Tag 0 und Monat 0 zugelassen - beides gibt es nicht.
    my $ein = defined $_[0] ? $_[0] : "";
    $ein =~ s/^\s+//;
    $ein =~ s/\s+$//;

    unless ($ein =~ m/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/) {
        LOGERR("Ungueltige Datumsangabe: '$ein' (erwartet 'TT.MM.JJJJ')");
        return undef;
    }
    my ($day, $mon, $year) = ($1, $2, $3);
    if ($year >= 2000) { $year = $year % 2000 } elsif ($year >= 1900) { $year = $year % 1900 }

    if ($day < 1 || $day > 31) { LOGERR("Invalid day: $day");   return undef; }
    if ($mon < 1 || $mon > 12) { LOGERR("Invalid month: $mon"); return undef; }
    if ($year > 99)            { LOGERR("Invalid year: $year"); return undef; }

    return pack("C C C", $day, $mon, $year);
}

exit 0;
