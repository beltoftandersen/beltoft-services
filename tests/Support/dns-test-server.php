<?php
// A scriptable name server for one test, on 127.0.0.1 and an ephemeral port (never 53).
// Usage: php dns-test-server.php <scenario>. Prints its port on the first line, then serves one UDP question and, when the
// scenario truncates, one TCP question, and exits. Every answer is for 40 DNSKEY records of "example.com" unless said.
// Scenarios:
//   wrong_id          UDP: a full answer with another id.
//   tc_empty          UDP: truncated with no answer; TCP: one full answer.
//   tc_two_segments   UDP: truncated; TCP: the answer in two writes with a pause between.
//   tc_short_body     UDP: truncated; TCP: a length prefix larger than the body, then the connection closes.
//   tc_closed         UDP: truncated; TCP: accepts and closes without a byte.
//   tc_empty_loop     UDP: truncated; TCP: truncated again (an answer that never fits).
//   silent            UDP: no answer at all (the test lowers the timeout).
$scenario = $argv[1] ?? 'tc_empty';
$udp  = stream_socket_server( 'udp://127.0.0.1:0', $errno, $err, STREAM_SERVER_BIND );
$port = (int) substr( strrchr( stream_socket_get_name( $udp, false ), ':' ), 1 );
$tcp  = stream_socket_server( 'tcp://127.0.0.1:' . $port, $errno, $err );
echo $port, "\n";
fflush( STDOUT );

function question_of( string $query ): string {
	$end = 12;
	while ( 0 !== ord( $query[ $end ] ) ) { $end += ord( $query[ $end ] ) + 1; }
	return substr( $query, 12, $end + 1 + 4 - 12 );
}
function full_answer( string $id, string $question, int $count ): string {
	$answers = '';
	for ( $i = 0; $i < $count; ++$i ) {
		$rdata    = str_repeat( 'k', 40 );
		$answers .= "\xc0\x0c" . pack( 'nnNn', 48, 1, 300, strlen( $rdata ) ) . $rdata;
	}
	return $id . pack( 'nnnnn', 0x8400, 1, $count, 0, 0 ) . $question . $answers;
}

stream_set_blocking( $udp, true );
$read = array( $udp );
$w = $e = null;
if ( ! stream_select( $read, $w, $e, 8 ) ) { exit( 0 ); }
$query    = stream_socket_recvfrom( $udp, 4096, 0, $peer );
$id       = substr( $query, 0, 2 );
$question = question_of( $query );

if ( 'silent' === $scenario ) { sleep( 4 ); exit( 0 ); }
if ( 'wrong_id' === $scenario ) {
	stream_socket_sendto( $udp, pack( 'n', ( unpack( 'n', $id )[1] + 1 ) & 0xffff ) . substr( full_answer( $id, $question, 2 ), 2 ), 0, $peer );
	exit( 0 );
}
stream_socket_sendto( $udp, $id . pack( 'nnnnn', 0x8600, 1, 0, 0, 0 ) . $question, 0, $peer );

$conn = stream_socket_accept( $tcp, 5 );
if ( ! $conn ) { exit( 0 ); }
$len = unpack( 'n', fread( $conn, 2 ) )[1];
$tq  = fread( $conn, $len );
$id  = substr( $tq, 0, 2 );
$q   = question_of( $tq );
switch ( $scenario ) {
	case 'tc_empty':
		$reply = full_answer( $id, $q, 1 );
		fwrite( $conn, pack( 'n', strlen( $reply ) ) . $reply );
		break;
	case 'tc_two_segments':
		$reply = full_answer( $id, $q, 3 );
		$all   = pack( 'n', strlen( $reply ) ) . $reply;
		fwrite( $conn, substr( $all, 0, 1 ) );
		fflush( $conn );
		usleep( 200000 );
		fwrite( $conn, substr( $all, 1, 30 ) );
		fflush( $conn );
		usleep( 200000 );
		fwrite( $conn, substr( $all, 31 ) );
		break;
	case 'tc_short_body':
		$reply = full_answer( $id, $q, 3 );
		fwrite( $conn, pack( 'n', strlen( $reply ) + 500 ) . $reply );
		break;
	case 'tc_empty_loop':
		$reply = $id . pack( 'nnnnn', 0x8600, 1, 0, 0, 0 ) . $q;
		fwrite( $conn, pack( 'n', strlen( $reply ) ) . $reply );
		break;
	case 'tc_closed':
	default:
		break;
}
fclose( $conn );
