IMAP for PHP
============

[![Coverage Status](https://coveralls.io/repos/github/dg/imap/badge.svg?branch=master)](https://coveralls.io/github/dg/imap?branch=master)
[![Latest Stable Version](https://poser.pugx.org/dg/imap/v/stable)](https://github.com/dg/imap/releases)

 <!---->

**PHP 8.4 threw the `imap` extension out. This is the replacement for what you were actually using it for:** reading a mailbox, pulling the attachments out, and getting the message out of the way afterwards.

The extension was unbundled because the C library underneath it, c-client, has had no release since 2007 and no update at all since 2018. This library speaks IMAP over a socket instead, in plain PHP, and needs nothing but extensions your PHP already has.

It comes from David Grudl, the author of [Nette](https://nette.org), [Latte](https://latte.nette.org) and [Tracy](https://tracy.nette.org). The whole thing is under a thousand lines and reads as if you had written it yourself:

```php
$mailbox = new DG\Imap\Mailbox('{imap.gmail.com:993/ssl}', $username, $password);

foreach ($mailbox->getMessages() as $message) {
	if (str_contains($message->getSubject(), 'Invoice')) {
		file_put_contents('invoice.pdf', $message->getPart(1)->getContents());
		$message->trash();
	}
}
```

 <!---->

Why this one
============

**Nothing else comes with it.** No Laravel collections, no Carbon, no Symfony components, no DI container, none of the transitive baggage the other pure-PHP IMAP libraries arrive with. `composer require dg/imap` adds exactly one directory to your vendor.

**Only what you need is downloaded.** Subjects and senders for the whole mailbox cost one round trip; a body is fetched the first time you ask for it. Skip a message on its subject and its ten-megabyte attachment never crosses the wire.

**Deleting is a solved problem here.** IMAP is genuinely ambiguous about what removing a message means, and this library says so out loud instead of picking one meaning and hoping. See below.

**Real mail, not the RFC.** Suppliers send `Content-Type: base64`, boundaries in capitals, `charset = "utf-8"` with spaces around the equals sign, and subjects in raw 8-bit UTF-8 that no encoded-word parser will touch. All of that is in the test suite because all of it turned up in a live mailbox.

**Your own code becomes testable.** `Message::fromString()` builds a message out of an `.eml` file, so whatever you wrote to process mail can be tested against real captured messages with no server, no network and no fixtures you had to invent.

**Honest about its size.** It connects, lists, reads, and gets rid of messages. No POP3, no NNTP, no `SEARCH`, no IDLE. The boundaries are written down in [docs/capabilities.md](docs/capabilities.md), including what might be added later.

 <!---->

Installation
============

```shell
composer require dg/imap
```

Requires PHP 8.1 or later and the `iconv`, `mbstring` and `openssl` extensions, all of which are enabled in a default PHP build. **Not** `ext-imap`.

 <!---->

Connecting
==========

The mailbox is named with the same string the old extension took, so a configuration you already have keeps working:

```php
use DG\Imap\Mailbox;

$mailbox = new Mailbox(
	'{imap.gmail.com:993/ssl}',
	'robot@example.com',
	$password,
);
```

Host, port and the `/ssl` flag are all optional: the port defaults to 993 with TLS and 143 without, and anything after the closing brace names the folder, `INBOX` when omitted. Connecting happens on first use, so an explicit `connect()` is only worth calling when you want a bad password to be reported early.

Gmail wants an [app password](https://support.google.com/accounts/answer/185833) rather than your account password.

 <!---->

Reading messages
================

```php
foreach ($mailbox->getMessages() as $message) {
	echo $message->getSubject(), "\n";   // decoded, even from =?utf-8?B?...?=
	echo $message->getFrom(), "\n";
	echo $message->getDate()?->format('Y-m-d'), "\n";
}
```

Those three come from a single fetch across the whole mailbox. Subject and sender are decoded; `getDate()` returns `null` when the message carries no parseable `Date` header. Everything below reaches for the body, which is downloaded once and cached:

```php
$message->countParts();          // 0 for a single-part message
$part = $message->getPart(1);    // parts are indexed from zero
$message->getParts();
```

Reading never marks anything as seen, so a mailbox someone else is watching stays untouched.

A part hands you its content decoded, both the transfer encoding and the charset:

```php
$part->getContents();            // base64 or quoted-printable undone, text converted to UTF-8
$part->getParameter('name');     // 'invoice.pdf'
$part->getParameters();
```

A part that is itself `multipart/*` stays one part and returns its raw inner body, which is what the old extension did for a section too.

 <!---->

Getting rid of a message
========================

Four ways, because IMAP means four different things:

```php
$message->delete();              // the server removes it, however it understands that
$message->trash();               // into the trash, whatever the server calls it
$message->archive();             // out of the mailbox, but not destroyed
$message->moveTo('Invoices');    // exactly where you say
```

**`delete()` is the treacherous one, and no library can fix that.** Flagging `\Deleted` and expunging destroys the message on a plain IMAP server, while Gmail intercepts the same commands and applies the account's Auto-Expunge setting, which by default merely archives. Same protocol, opposite outcome. If you want a guaranteed result, do not use it.

The other three move the message, which is predictable everywhere. The destination is never guessed from a hardcoded name like `[Gmail]/Trash`: the server itself says which folder is which through the `SPECIAL-USE` extension, and the call throws if it advertises none.

```php
$mailbox->getSpecialFolder('\Trash');    // '[Gmail]/Bin'
$mailbox->getFolders();                  // ['INBOX' => ['\HasNoChildren'], ...]
```

Folder names are UTF-8 in both directions; the modified UTF-7 that travels on the wire never reaches your code.

Two details worth knowing. Removal happens immediately, not at some later `close()`, and where the server supports `UIDPLUS` it touches only this one message, so anything flagged by another client survives. And a message that has been removed refuses to do anything else, because after a move its identifier belongs to the destination folder and would address a stranger.

 <!---->

Testing without a server
========================

Point `Message::fromString()` at a raw message and everything except the removal operations works on it:

```php
$message = DG\Imap\Message::fromString(file_get_contents('tests/invoice.eml'));

Assert::same('Invoice 2026/114', $message->getSubject());
Assert::same(2, $message->countParts());
```

Capture a handful of the messages you actually receive, drop the `.eml` files into your test suite, and the code that processes them is testable offline. This is how the library tests its own MIME handling, and it is usually the fastest way to find out that a sender has quietly changed their format.

 <!---->

Error handling
==============

Anything the server or the network does wrong is a `DG\Imap\Exception`:

```php
try {
	foreach ($mailbox->getMessages() as $message) {
		// ...
	}
} catch (DG\Imap\Exception $e) {
	// connection refused, authentication rejected, NO/BAD response, broken pipe
}
```

Mistakes in your own code raise `LogicException` instead, so they surface in development rather than being swallowed by a `catch` in production: acting on a message that has already been removed, or on a detached one created by `fromString()`. Acting on a message whose session was closed or replaced in the meantime throws `DG\Imap\Exception`: identifiers are only meaningful inside the session that produced them, and the library will not quietly open a new one behind your back to make an old identifier work again.

 <!---->

[Support the project](https://github.com/sponsors/dg)
====================================================

Do you find this library useful?

[![Buy me a coffee](https://files.nette.org/icons/donation-3.svg)](https://github.com/sponsors/dg)

Thank you!
