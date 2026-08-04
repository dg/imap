<?php declare(strict_types=1);

use DG\Imap\Connection;
use DG\Imap\Mailbox;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


// Builds a mailbox talking to a scripted server instead of the network. The server ends are
// kept in a static registry, otherwise GC collects them and the socket closes.
function scriptedMailbox(string $script): array
{
	static $servers = [];

	[$client, $server] = stream_socket_pair(
		DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX,
		STREAM_SOCK_STREAM,
		STREAM_IPPROTO_IP,
	);
	$servers[] = $server;
	// connect() is bypassed, the connection is handed to the mailbox directly, so the dialogue starts with the greeting
	fwrite($server, "* OK ready\r\n" . $script);

	$mailbox = new Mailbox('{example.com/ssl}', 'user', 'pass');
	Assert::with($mailbox, function () use ($client) {
		$this->connection = Connection::fromStream($client);
	});
	return [$mailbox, $server];
}


const ListResponse = '* LIST (\HasNoChildren) "/" "INBOX"
* LIST (\HasNoChildren \Trash) "/" "[Gmail]/Ko&AWE-"
* LIST (\All \HasChildren) "/" "[Gmail]/V&AWE-echny zpr&AOE-vy"
* LIST (\HasNoChildren \Junk) "/" "[Gmail]/Spam"
T1 OK LIST completed
';


test('folder names are decoded from modified UTF-7', function () {
	[$mailbox] = scriptedMailbox(str_replace("\n", "\r\n", ListResponse));
	$folders = $mailbox->getFolders();

	Assert::same(['INBOX', '[Gmail]/Koš', '[Gmail]/Všechny zprávy', '[Gmail]/Spam'], array_keys($folders));
	Assert::same(['\HasNoChildren', '\Trash'], $folders['[Gmail]/Koš']);
});


test('special folders are looked up by attribute, case-insensitively', function () {
	[$mailbox] = scriptedMailbox(str_replace("\n", "\r\n", ListResponse));
	Assert::same('[Gmail]/Koš', $mailbox->getSpecialFolder('\Trash'));
	Assert::same('[Gmail]/Koš', $mailbox->getSpecialFolder('\TRASH'));
	Assert::same('[Gmail]/Všechny zprávy', $mailbox->getSpecialFolder('\All'));
	Assert::same('[Gmail]/Spam', $mailbox->getSpecialFolder('\Junk'));
	Assert::null($mailbox->getSpecialFolder('\Archive'));
});


test('a server without SPECIAL-USE offers nothing', function () {
	[$mailbox] = scriptedMailbox("* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\nT1 OK done\r\n");
	Assert::same(['INBOX'], array_keys($mailbox->getFolders()));
	Assert::null($mailbox->getSpecialFolder('\Trash'));
});
