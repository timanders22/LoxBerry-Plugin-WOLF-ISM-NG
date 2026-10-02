#!/bin/bash

# Shell script which is executed by bash *AFTER* complete installation is done
# (but *BEFORE* postupdate). Use with caution and remember, that all systems may
# be different!
#
# Exit code must be 0 if executed successfull. 
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# Will be executed as user "loxberry".
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry
PTEMPPATH=$6  # Sixth argument is full temp path during install (see also $1)

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

# Exit with Status 0

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
#
# Entschieden wird nach INHALT, nie nach Groesse (Muster 9 der Nachlese):
# Inhalt heisst eine Zeile "enable 0|1" (wi_hat_inhalt, dieselbe Regel wie in
# preupgrade.sh). Bis 3.1.4 wurde eine Zweitschrift OHNE Inhalt ueber die
# Vorgabe gelegt und "wiederhergestellt" gemeldet (Fall W19,
# Pruefung-WOLF-ISM-NG-3.1.4).
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-wolf_ng}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
wi_hat_inhalt() {
    [ -f "$1" ] && grep -Eiq '^[[:space:]]*enable[[:space:]]+[01][[:space:]]*$' "$1"
}
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if ! wi_hat_inhalt "$ziel"; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if ! wi_hat_inhalt "$zweit"; then
            echo "<WARNING> Die Zweitschrift $zweit traegt keinen Inhalt -"
            echo "<WARNING> $datei wurde NICHT zurueckgespielt; es gilt die Vorgabe."
        elif cp -p "$zweit" "$ziel" 2>/dev/null && cmp -s "$zweit" "$ziel"; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
if [ -z "$NETZ_BASE" ] || [ ! -d "$NETZ_BASE/config/plugins" ]; then
    echo "<WARNING> Die LoxBerry-Wurzel ('$NETZ_BASE') traegt kein config/plugins -"
    echo "<WARNING> die Zweitschrift wurde nicht geprueft."
    exit 1
fi
# Die Pruefsumme der mitgelieferten Vorgabe wird GERECHNET, nicht
# eingetragen. Bis 3.0.10 stand sie als Zeichenkette hier; wer
# config/wolf_ism8i.conf um ein Zeichen aendert, ohne diese Zeile
# nachzuziehen, schaltet das zweite Netz still ab - netz_zurueck
# erkennt die frisch kopierte Vorgabe dann nicht mehr als "verloren".
# Der Archivordner steht zur Laufzeit von postinstall noch
# (aufgeraeumt wird erst in plugininstall.pl:1455).
NETZ_VORGABE="${6:-}/config/wolf_ism8i.conf"
if [ -f "$NETZ_VORGABE" ]; then
    NETZ_SOLL=$(sha256sum "$NETZ_VORGABE" 2>/dev/null | cut -d" " -f1)
else
    NETZ_SOLL=""
fi
if [ -z "$NETZ_SOLL" ]; then
    echo "<INFO> Die mitgelieferte Vorgabe war nicht lesbar - es wird nur"
    echo "<INFO> auf fehlende oder leere Konfiguration geprueft."
fi
# ---------------------------------------------------------------------------
# I1 (Durchgang 02.10.2026, Entscheidung 1): zurueckgespielt wird NUR bei
# einer Aktualisierung, erkennbar an der Marke aus preupgrade.sh
# (data/plugins/<ordner>.upgrade_laeuft) - ohne Altersvergleich; die
# Stunde gilt nur fuer die Startsperre des Dienstes. Fehlt sie, ist dies eine
# NEUINSTALLATION: liegengebliebene Zweitschriften wandern nach <name>.alt,
# einmal <WARNING> mit den Pfaden, nichts wird zurueckgespielt. Bis 3.1.5
# lief eine "saubere" Neuinstallation binnen fuenf Minuten mit alten Ports,
# altem Praefix und alter Stoercodewahl los und meldete das als Erfolg
# (Bericht installer, Befund I1; Kette, Bauart F). Reste entstehen etwa nach
# der Deinstallation einer Fassung bis 3.0.10. Die .alt liest nichts mehr;
# uninstall raeumt sie ab.
# ---------------------------------------------------------------------------
WI_MARKE="$NETZ_BASE/data/plugins/$NETZ_PDIR.upgrade_laeuft"
if [ ! -f "$WI_MARKE" ]; then
    WI_ALT=""
    for WI_D in wolf_ism8i.conf wolf_stoercodes.csv; do
        WI_Z="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$WI_D"
        [ -f "$WI_Z" ] || continue
        if mv -f "$WI_Z" "$WI_Z.alt" 2>/dev/null; then
            WI_ALT="$WI_ALT $WI_Z.alt"
        else
            WI_ALT="$WI_ALT $WI_Z (liess sich nicht verschieben)"
        fi
    done
    # Nachtrag 02.10.2026: der SG-Bestand (Merker) neben dem Datenordner ist ein
    # Bestand im Sinne von Entscheidung 1 und geht ebenfalls nach .alt.
    WI_B="$NETZ_BASE/data/plugins/$NETZ_PDIR.bestand"
    if [ -d "$WI_B" ]; then
        case "$WI_B" in
            */data/plugins/?*.bestand) [ -d "$WI_B.alt" ] && rm -rf "${WI_B:?}.alt" ;;
        esac
        if mv "$WI_B" "$WI_B.alt" 2>/dev/null; then
            WI_ALT="$WI_ALT $WI_B.alt"
        else
            WI_ALT="$WI_ALT $WI_B (liess sich nicht verschieben)"
        fi
    fi
    if [ -n "$WI_ALT" ]; then
        echo "<WARNING> Neuinstallation: liegengebliebene Zweitschriften und Bestaende wurden NICHT zurueckgespielt, sondern beiseitegelegt:$WI_ALT"
    fi
    exit 0
fi

netz_zurueck "wolf_ism8i.conf" "$NETZ_SOLL"

# ---------------------------------------------------------------------------
# I3 (Durchgang 02.10.2026): die eigene Stoercodetabelle hat eine eigene
# Zweitschrift (preupgrade.sh). Zurueckgespielt wird sie nur bei liegender
# Marke (oben) und nur, wenn das Ziel fehlt - unabhaengig davon, ob die
# Konfiguration zurueckkam. Bis 3.1.5 hing sie allein an postupgrade.sh; lief
# das nicht durch, war die von Hand gepflegte Tabelle fort (Befund I3, F3b).
# ---------------------------------------------------------------------------
WI_CSV="$NETZ_CFG/wolf_stoercodes.csv"
WI_CSV_Z="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.wolf_stoercodes.csv"
if [ -f "$WI_CSV_Z" ] && [ ! -f "$WI_CSV" ]; then
    if cp -p "$WI_CSV_Z" "$WI_CSV" 2>/dev/null && cmp -s "$WI_CSV_Z" "$WI_CSV"; then
        echo "<OK> wolf_stoercodes.csv aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> wolf_stoercodes.csv liess sich nicht zurueckspielen. Die Sicherung"
        echo "<WARNING> liegt unter $WI_CSV_Z und kann von Hand kopiert werden."
    fi
fi

exit 0
