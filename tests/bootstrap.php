<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();
Tester\Environment::setupFunctions();


/** Converts a heredoc-written message to the CRLF line endings mail travels with. */
function crlf(string $s): string
{
	return preg_replace('~\r?\n~', "\r\n", $s);
}


/**
 * Creates a connection reading from a scripted server dialogue, and returns it with the server
 * end of the socket. The server ends are held in a static registry, otherwise they would be
 * garbage collected and the socket closed mid-test.
 * @return array{DG\Imap\Connection, resource}
 */
function scriptedConnection(string $script): array
{
	static $servers = [];

	[$client, $server] = stream_socket_pair(
		DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX,
		STREAM_SOCK_STREAM,
		STREAM_IPPROTO_IP,
	);
	$servers[] = $server;
	fwrite($server, "* OK server ready\r\n" . $script);
	return [DG\Imap\Connection::fromStream($client), $server];
}
