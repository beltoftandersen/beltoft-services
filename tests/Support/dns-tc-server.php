<?php
// A name server for one test: answers the first UDP question as truncated, and the TCP question with a full answer.
// Usage: php dns-tc-server.php. Prints its port on the first line, then serves one UDP and one TCP query and exits.
$udp = stream_socket_server( 'udp://127.0.0.1:0', $errno, $err, STREAM_SERVER_BIND );
$port = (int) substr( strrchr( stream_socket_get_name( $udp, false ), ':' ), 1 );
$tcp = stream_socket_server( 'tcp://127.0.0.1:' . $port, $errno, $err );
echo $port, "\n";
fflush( STDOUT );

$query = stream_socket_recvfrom( $udp, 4096, 0, $peer );
$id    = substr( $query, 0, 2 );
$qlen  = strlen( $query );
// The question ends before the optional OPT record: name, type, class.
$end   = 12;
while ( 0 !== ord( $query[ $end ] ) ) { $end += ord( $query[ $end ] ) + 1; }
$question = substr( $query, 12, $end + 1 + 4 - 12 );
stream_socket_sendto( $udp, $id . pack( 'nnnnn', 0x8600, 1, 0, 0, 0 ) . $question, 0, $peer );

$conn = stream_socket_accept( $tcp, 5 );
$len  = unpack( 'n', fread( $conn, 2 ) )[1];
$tq   = fread( $conn, $len );
$id   = substr( $tq, 0, 2 );
$name = "\xc0\x0c";
$answers = '';
for ( $i = 0; $i < 40; ++$i ) {
	$rdata    = str_repeat( 'k', 40 );
	$answers .= $name . pack( 'nnNn', 48, 1, 300, strlen( $rdata ) ) . $rdata;
}
$reply = $id . pack( 'nnnnn', 0x8400, 1, 40, 0, 0 ) . $question . $answers;
fwrite( $conn, pack( 'n', strlen( $reply ) ) . $reply );
fclose( $conn );
