#!/bin/bash

# Shell script which is executed in case of an update (if this plugin is already
# installed on the system). This script is executed as very first step (*BEFORE*
# preinstall.sh) and can be used e.g. to save existing configfiles to /tmp 
# during installation. Use with caution and remember, that all systems may be
# different!
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

# ---------------------------------------------------------------------------
# WOHIN GESICHERT WIRD
#
# Bis 2.5.1 nach /tmp/<ordner>.SAVE. Berechtigt ist der Einwand, dass /tmp
# auf dem LoxBerry fluechtig ist: bricht das Upgrade ab oder startet das
# Geraet dazwischen neu, ist die Sicherung fort.
#
# Nicht berechtigt ist der uebliche Zusatz, man solle statt dessen "$1"
# nehmen, das sei der vom Installer bereitgestellte Ordner. Der Installer
# ruft dieses Skript so auf (sbin/plugininstall.pl):
#   cd "$tempfolder" && "$script" "$tempfile" "$pname" "$pfolder" \
#                       "$pversion" "$lbhomedir" "$tempfolder"
# $1 ist $tempfile - eine Zufallskennung aus zehn Zeichen (&generate(10)),
# KEIN Pfad. Der absolute Arbeitsordner kommt als SECHSTES Argument; dieses
# Skript liest ihn oben bereits als $PTEMPPATH ein. Er liegt unter
# data/system/tmp und wird vom Installer selbst aufgeraeumt - erst NACH
# postupgrade.
# ---------------------------------------------------------------------------
if [ -n "$PTEMPPATH" ] && [ -d "$PTEMPPATH" ]; then
    SICHERUNG="$PTEMPPATH/wolf_ng_upgrade"
else
    echo "<INFO> Kein Arbeitsordner uebergeben - Rueckfall auf /tmp"
    SICHERUNG="/tmp/${PDIR}.SAVE"
fi
mkdir -p "$SICHERUNG"
# Den benutzten Ort hinterlegen, damit postupgrade.sh ihn nicht raten muss.
echo "$SICHERUNG" > "$PCONFIG/.upgrade_pfad" 2>/dev/null

echo "<INFO> Backing up existing config files $PCONFIG/* -> $SICHERUNG/"
cp -p -r "$PCONFIG/." "$SICHERUNG/" 2>/dev/null || true

exit 0
