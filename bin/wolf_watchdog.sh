#!/bin/bash

. $LBHOMEDIR/libs/bashlib/loxberry_log.sh

# ---------------------------------------------------------------------------
# Prozesssuche - bitte nicht auf pkill zurueckbauen
#
# "pkill -f wolf_ism8i.pl" durchsucht die GANZE Befehlszeile jedes Prozesses
# und trifft damit auch einen Editor mit offener Datei oder ein zweites
# Exemplar des Plugins. "ps -C" und "killall" vergleichen den comm-Namen, der
# bei einem Skript mit Shebang "bash" bzw. "perl" lautet - die finden nichts.
#
# Ein Treffer liegt vor, wenn das erste Argument der volle Skriptpfad ist
# (Shebang-Start) oder wenn das erste Argument ein Interpreter ist UND der
# volle Pfad unter den Argumenten steht (der Watchdog startet das
# Auswertungsmodul als "perl -X <pfad>").
# ---------------------------------------------------------------------------
wolf_beenden() {
    SKRIPT="$1"
    for D in /proc/[0-9]*; do
        P=$(basename "$D")
        [ -r "$D/cmdline" ] || continue
        ARGS=$(tr '\0' '\n' < "$D/cmdline" 2>/dev/null)
        ERSTES=$(printf '%s\n' "$ARGS" | head -1)
        if [ "$ERSTES" = "$SKRIPT" ] ||
           { case "$(basename "$ERSTES")" in perl|bash|sh|dash) true ;; *) false ;; esac &&
             printf '%s\n' "$ARGS" | grep -qxF "$SKRIPT"; }; then
            kill "$P" 2>/dev/null
        fi
    done
}

SCRIPTPATH=`dirname "$0"`;
# Der Ordnername kommt aus dem eigenen Ablageort. Fest verdrahtet
# schrieb eine Zweitinstallation (wolf_ng_01) ihr Protokoll in den
# Ordner der ersten - dieselbe Bauart wie in bin/wolf_server.
PACKAGE=$(basename "$(cd "$SCRIPTPATH" 2>/dev/null && pwd)")
[ -n "$PACKAGE" ] || PACKAGE=wolf_ng
NAME=watchdog
LOGDIR=${LBPLOG}/${PACKAGE}
ADDTIME=1

LOGSTART

on_die()
{
        wolf_beenden "$SCRIPTPATH/wolf_ism8i.pl"
        LOGEND "Server stopped"

        # Need to exit the script explicitly when done.
        # Otherwise the script would live on, until system
        # realy goes down, and KILL signals are send.
        #
        exit 0
}

start_server()
{
LOGINF "Starting 'Wolf ISM8 Server'"
perl -X $SCRIPTPATH/wolf_ism8i.pl >> ${FILENAME} 2>&1
}

trap 'on_die' TERM

starttime=`date +%s`
restart_counter=0
time_threshold=10
restart_threshold=20
until start_server; do
    LOGERR "Server 'Wolf ISM8 Server' crashed with exit code $?.  Respawning.." >&2
    stoptime=`date +%s`
    timediff=$((stoptime-starttime))
    if  ((restart_counter >= restart_threshold)); then
        LOGCRIT "Server crashed $restart_threshold times in a row! Stopping watchdog."
        LOGEND ""
        exit 1;
    fi
    if  ((timediff <= time_threshold)); then
        restart_counter=$((restart_counter+1));
        LOGWARN "Server crashed within $time_threshold Seconds.. Attempt: $restart_counter"
    else
        restart_counter=0
    fi
    sleep_time=$(((restart_counter+1) * (restart_counter+1) * 5))
    LOGINF "Sleeping $sleep_time seconds until next Restart"
    sleep $sleep_time
    starttime=`date +%s`
done
