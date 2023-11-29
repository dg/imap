<?php

declare(strict_types=1);

use DG\Imap\Mailbox;
use Tester\Assert;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();


$mailbox = new Mailbox(
	'{imap.gmail.com:993/imap/ssl}',
	'your_username@gmai.com',
	'your_password',
);

Assert::exception(
	fn() => $mailbox->connect(),
	DG\Imap\Exception::class,
);
imap_errors();
