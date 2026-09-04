#!/bin/sh

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR
PTEMPPATH=$6  # Sechstes Argument: voller Arbeitsordner des Installers

# Zum Sicherungsort siehe preupgrade.sh. Er wird aus DEMSELBEN Argument
# gerechnet wie dort - das ist die eine Stelle, an der beide Skripte nicht
# auseinanderlaufen koennen. Ein Merker .upgrade_pfad im Konfigurationsordner
# stand hier bis 02.09.2026 an erster Stelle; purge_installation entfernt
# dieses Verzeichnis, bevor dieses Skript laeuft, der Zweig war also tot.
if [ -n "$PTEMPPATH" ] && [ -d "$PTEMPPATH" ]; then
    SICHERUNG="$PTEMPPATH/wolf_ng_upgrade"
else
    SICHERUNG="/tmp/${PDIR}.SAVE"
fi

if [ -d "$SICHERUNG" ]; then
    echo "<INFO> Copy back existing config files $SICHERUNG/* -> $PCONFIG/"
    cp -p -r "$SICHERUNG/." "$PCONFIG/" 2>/dev/null && echo "<OK> Konfiguration wiederhergestellt."
else
    echo "<WARNING> Keine gesicherte Konfiguration unter $SICHERUNG gefunden."
fi

# Hier stand "rm -f $MERKER". Mit dem Merker ist auch das entfallen - die
# Variable gab es danach nicht mehr, und "rm -f ''" ist kein Aufraeumen.
# Der Arbeitsordner des Installers wird von LoxBerry selbst aufgeraeumt.
# Nur der Rueckfallweg unter /tmp gehoert uns.
case "$SICHERUNG" in
    /tmp/*) rm -rf "$SICHERUNG" ;;
esac

enabled=$(awk '/^enable[ \t]/{print $2}' $PCONFIG/wolf_ism8i.conf 2>/dev/null)
enabled=${enabled:-0}

if [ "$enabled" -eq "1" ]; then
    # Enable
    echo "<INFO> Restarting server"
    $PBIN/wolf_server restart > /dev/null 2>&1
fi

# Exit with Status 0
exit 0
