<?php declare(strict_types=1);

use DG\Imap\Mailbox;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('parses mailbox specification', function () {
	$mailbox = new Mailbox('{imap.gmail.com:993/imap/ssl}', 'user', 'password');
	Assert::with($mailbox, function () {
		Assert::same('imap.gmail.com', $this->host);
		Assert::same(993, $this->port);
		Assert::true($this->ssl);
		Assert::same('INBOX', $this->folder);
	});

	$mailbox = new Mailbox('{example.com}Sent', 'user', 'password');
	Assert::with($mailbox, function () {
		Assert::same('example.com', $this->host);
		Assert::same(143, $this->port);
		Assert::false($this->ssl);
		Assert::same('Sent', $this->folder);
	});

	$mailbox = new Mailbox('{example.com/ssl}', 'user', 'password');
	Assert::with($mailbox, function () {
		Assert::same(993, $this->port);
		Assert::true($this->ssl);
	});

	$mailbox = new Mailbox('{example.com:0}', 'user', 'password');
	Assert::with($mailbox, function () {
		Assert::same(0, $this->port); // a given port is kept even when it is falsy
	});
});


test('rejects invalid mailbox specification', function () {
	Assert::exception(
		fn() => new Mailbox('imap.gmail.com', 'user', 'password'),
		DG\Imap\Exception::class,
		"Invalid mailbox specification 'imap.gmail.com'",
	);
});


test('getFolders decodes UTF-7 names and handles a name sent as a literal', function () {
	[$connection] = scriptedConnection(implode('', [
		"* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n",
		"* LIST (\\HasNoChildren \\Trash) \"/\" \"Ko&AWE-\"\r\n",
		"* LIST (\\Noselect) \"/\" {15}\r\nFolder \"quoted\"\r\n",
		"T1 OK done\r\n",
	]));
	$mailbox = new Mailbox('{example.com}', 'user', 'password');
	Assert::with($mailbox, function () use ($connection) {
		$this->connection = $connection;
	});

	$folders = $mailbox->getFolders();
	Assert::same(['\HasNoChildren'], $folders['INBOX']);
	Assert::same(['\HasNoChildren', '\Trash'], $folders['Koš']);
	Assert::same(['\Noselect'], $folders['Folder "quoted"']);
	Assert::same('Koš', $mailbox->getSpecialFolder('\Trash'));
});


test('a failed connect() leaves the previous session alone', function () {
	[$connection] = scriptedConnection("T1 OK NOOP completed\r\n");
	$mailbox = new Mailbox('{127.0.0.1:0}', 'user', 'password'); // port 0 fails without a network round trip
	Assert::with($mailbox, function () use ($connection) {
		$this->connection = $connection;
	});

	Assert::exception(
		fn() => $mailbox->connect(),
		DG\Imap\Exception::class,
		'Cannot connect to 127.0.0.1:0%a%',
	);
	Assert::same($connection, $mailbox->getConnection()); // the mailbox still holds it
	Assert::same([], $connection->command('NOOP')); // and it was not taken down
});


test('a failed folder listing is not cached as an empty one', function () {
	[$connection] = scriptedConnection(implode('', [
		"T1 NO LIST failed\r\n",
		"* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n",
		"T2 OK done\r\n",
	]));
	$mailbox = new Mailbox('{example.com}', 'user', 'password');
	Assert::with($mailbox, function () use ($connection) {
		$this->connection = $connection;
	});

	Assert::exception(
		fn() => $mailbox->getFolders(),
		DG\Imap\Exception::class,
		'Command failed: NO LIST failed',
	);
	Assert::same(['INBOX' => ['\HasNoChildren']], $mailbox->getFolders()); // retried, not served from a poisoned cache
});


test('rejects STARTTLS instead of silently connecting in plaintext', function () {
	Assert::exception(
		fn() => new Mailbox('{example.com/tls}', 'user', 'password'),
		DG\Imap\Exception::class,
		'STARTTLS is not supported, use implicit TLS via /ssl',
	);
});
