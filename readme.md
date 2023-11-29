IMAP Library for PHP
=====================

[![Downloads this Month](https://img.shields.io/packagist/dm/dg/imap.svg)](https://packagist.org/packages/dg/imap)
[![Tests](https://github.com/dg/imap/workflows/Tests/badge.svg?branch=master)](https://github.com/dg/imap/actions)
[![Latest Stable Version](https://poser.pugx.org/dg/imap/v/stable)](https://github.com/dg/imap/releases)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/dg/imap/blob/master/license.md)


This IMAP Library for PHP provides an intuitive and easy-to-use interface to interact with POP3, IMAP and NNTP mail servers. It allows for operations such as connecting to mailboxes, fetching messages, handling message parts, and managing email content.

- Connect to IMAP servers with ease
- Fetch and manage email messages
- Handle different parts of an email such as attachments and text
- Decode email content and headers
- Supports message deletion and structure analysis


Installation
------------

To install the library, you can use Composer. Run the following command in your project directory:

```bash
composer require dg/imap
```

It requires PHP version 8.1 with extension imap.


Connecting to a Mailbox
-----------------------

To connect to an IMAP mailbox, create an instance of the `Mailbox` class.

```php
use DG\Imap\Mailbox;

$mailbox = new Mailbox(
	'{imap.gmail.com:993/imap/ssl}',
	'your_username@gmai.com',
	'your_password',
);

$mailbox->connect();
```

Fetching Messages
-----------------

Fetch all messages from the mailbox:

```php
$messages = $mailbox->getMessages();
foreach ($messages as $message) {
	echo $message->getSubject() . "\n";
}
```

Handling Message Parts
----------------------

To handle different parts of a message, such as attachments:

```php
foreach ($messages as $message) {
	$parts = $message->getParts();
	foreach ($parts as $part) {
		// Process each part
	}
}
```
Certainly, here's the additional information regarding error handling through exceptions:


Error Handling
--------------

In case of any issue, such as a failure to connect to the IMAP server, fetch messages, or process message parts, the library will throw an `DG\Imap\Exception`:

```php
try {
    $mailbox->connect();
    $messages = $mailbox->getMessages();
    // ... additional operations
} catch (DG\Imap\Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```


Support Project
---------------

Do you like this project?

[![Donate](https://files.nette.org/icons/donation-1.svg?)](https://nette.org/make-donation?to=imap)
