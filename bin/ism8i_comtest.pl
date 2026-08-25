#!/usr/bin/perl

use strict;
use warnings;
use utf8;

 # client

use Socket;
use IO::Socket::Multicast;

#use constant GROUP => '239.7.7.77';
#use constant PORT  => '35353';

my $mc_addr = '239.7.7.77';
my $mc_port = '35353';

my $sock = IO::Socket::Multicast->new(
           Proto     => 'udp',
           LocalPort => $mc_port,
           ReuseAddr => '1',
           # defined(&ReusePort) prueft, ob es ein UNTERPROGRAMM dieses
           # Namens gibt - das gibt es nicht, der Ausdruck war immer falsch.
           # Gemeint war die Konstante SO_REUSEPORT, und die gibt es nicht
           # auf jeder Plattform. Deshalb wird sie geprueft, nicht geraten.
           ReusePort => (eval { Socket::SO_REUSEPORT(); 1 } ? 1 : 0),
  ) or die "ERROR: Cant create socket: $@!";

$sock->mcast_add($mc_addr) or die "ERROR: Couldn't set group: $@!";
  
#my $sock = IO::Socket::Multicast->new(Proto=>'udp',LocalPort=>PORT);
#$sock->mcast_add(GROUP) || die "Couldn't set group: $!\n";

 while (1) {
   my $data;
   next unless $sock->recv($data,4096);
   print $data."\n";
 }