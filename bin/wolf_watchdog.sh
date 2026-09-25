#!/bin/bash

# Die Protokollbibliothek der Anlage. bin/wolf_server setzt LBHOMEDIR auf die
# gepruefte Wurzel, bevor es diesen Watchdog startet. Bis 3.1.4 stand hier
# ". $LBHOMEDIR/..." ohne Pruefung - bei leerem LBHOMEDIR ein Pfad ab der
# Laufwerkswurzel (Muster 2 der Nachlese). Ohne Bibliothek laeuft nichts los:
# das Protokoll, in das der Server schreibt, benennt erst LOGSTART.
if [ -z "${LBHOMEDIR:-}" ] || [ ! -r "$LBHOMEDIR/libs/bashlib/loxberry_log.sh" ]; then
    echo "Wolf ISM8 Watchdog: LBHOMEDIR fehlt oder traegt libs/bashlib/loxberry_log.sh nicht - Abbruch." >&2
    exit 1
fi
. "$LBHOMEDIR/libs/bashlib/loxberry_log.sh"

# ---------------------------------------------------------------------------
# Prozesssuche ARGUMENTWEISE ueber /proc/<pid>/cmdline - nie pkill -f
#
# Ein Treffer hat genau eine dieser Formen, und der Prozess gehoert dem
# Dienstbenutzer (loxberry, wo es ihn nicht gibt der eigene):
#   <bash|sh|dash|perl> <skript>      Start ueber den Shebang
#   perl -X <skript>                  so startet der Watchdog das Modul
# <skript> ist zeichengenau der erwartete Pfad (relativ gestartet gegen
# /proc/<pid>/cwd aufgeloest, zusaetzlich ueber readlink -f verglichen). Ein
# weiteres Argument ist ein Einmallauf, kein Dienst.
#
# Bis 3.1.4 genuegte "Interpreter vorn und der Pfad IRGENDWO unter den
# Argumenten" (grep -qxF): ein "perl -e '...' <modulpfad>" galt als Dienst
# und wurde beendet (Faelle W4, W5, W15, Pruefung-WOLF-ISM-NG-3.1.4), und vor
# kill -9 wurde nicht noch einmal geprueft. Bauart tb_ist_dienst()
# (Spotpreis-Tibber 0.9.19); dieselbe Regel steht in wi_ist_prozess()
# (webfrontend/htmlauth/wi_lib.php).
# ---------------------------------------------------------------------------
WI_DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)
wi_ist_dienst() {   # $1 PID  $2 erwarteter Skriptpfad
    local a0 a1 a2 a3 pfad wd
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$WI_DIENST_UID" ] || return 1
    a0=""; a1=""; a2=""; a3=""
    { IFS= read -r -d "" a0; IFS= read -r -d "" a1; IFS= read -r -d "" a2
      IFS= read -r -d "" a3; } 2>/dev/null < "/proc/$1/cmdline"
    pfad=""
    case "${a0##*/}" in
        bash|sh|dash|perl)
            if [ -n "$a1" ] && [ -z "$a2" ]; then
                pfad="$a1"
            elif [ "${a0##*/}" = "perl" ] && [ "$a1" = "-X" ] && [ -n "$a2" ] && [ -z "$a3" ]; then
                pfad="$a2"
            fi ;;
    esac
    [ -n "$pfad" ] || return 1
    case "$pfad" in
        /*) ;;
        *)  wd=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            pfad="${wd% (deleted)}/$pfad" ;;
    esac
    [ "$pfad" = "$2" ] && return 0
    [ -n "$(readlink -f "$2" 2>/dev/null)" ] || return 1
    [ "$(readlink -f "$pfad" 2>/dev/null)" = "$(readlink -f "$2" 2>/dev/null)" ]
}

wi_dienste() {   # PIDs zu einem oder mehreren Skriptpfaden
    local d p s
    for d in /proc/[0-9]*; do
        p="${d#/proc/}"
        for s in "$@"; do
            if wi_ist_dienst "$p" "$s"; then echo "$p"; break; fi
        done
    done
}

wolf_laeuft() {   # laeuft ein Prozess zu diesem Skriptpfad?
    local d
    for d in /proc/[0-9]*; do
        wi_ist_dienst "${d#/proc/}" "$1" && return 0
    done
    return 1
}

# Alle Treffer der uebergebenen Pfade gemeinsam beenden: erst SIGTERM an
# alle - dann endet der Watchdog nach seinem Modul von selbst (on_die) -,
# bis zu fuenf Sekunden warten, und vor kill -9 jeden Treffer noch einmal
# argumentweise pruefen (eine freigewordene Nummer kann inzwischen einem
# fremden Prozess gehoeren).
wolf_beenden() {
    local pids p i offen s
    pids=$(wi_dienste "$@")
    [ -n "$pids" ] || return 0
    for p in $pids; do kill "$p" 2>/dev/null; done
    for i in 1 2 3 4 5; do
        offen=0
        for p in $pids; do
            for s in "$@"; do
                wi_ist_dienst "$p" "$s" && offen=1
            done
        done
        [ "$offen" -eq 0 ] && break
        sleep 1
    done
    for p in $pids; do
        for s in "$@"; do
            if wi_ist_dienst "$p" "$s"; then kill -9 "$p" 2>/dev/null; break; fi
        done
    done
    return 0
}
SCRIPTPATH=`dirname "$0"`;
# Der Ordnername kommt aus dem eigenen Ablageort. Fest verdrahtet
# schrieb eine Zweitinstallation (wolf_ng_01) ihr Protokoll in den
# Ordner der ersten - dieselbe Bauart wie in bin/wolf_server.
PACKAGE=$(basename "$(cd "$SCRIPTPATH" 2>/dev/null && pwd)")
[ -n "$PACKAGE" ] || PACKAGE=wolf_ng
NAME=watchdog
LOGDIR=${LBPLOG:-$LBHOMEDIR/log/plugins}/${PACKAGE}
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
