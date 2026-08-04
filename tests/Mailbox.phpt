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


test('rejects STARTTLS instead of silently connecting in plaintext', function () {
	Assert::exception(
		fn() => new Mailbox('{example.com/tls}', 'user', 'password'),
		DG\Imap\Exception::class,
		'STARTTLS is not supported, use implicit TLS via /ssl',
	);
});
