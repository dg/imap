# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

## Overview

`dg/imap` — a thin object-oriented wrapper over PHP's `ext-imap`, for reading mailboxes over IMAP/POP3/NNTP. Published on Packagist. Requires PHP 8.1+ and `ext-imap`. Four classes in `src/`, classmap autoloading (not PSR-4), namespace `DG\Imap`.

## Commands

```bash
# Run all tests
composer tester

# Run a single test
vendor/bin/tester tests/connect.phpt -s

# Static analysis (PHPStan level 8, src only)
composer phpstan
```

Coding standards (Nette Code Checker + Nette Coding Standard) run only in CI, not installed locally.

## Architecture

The object graph is a strict chain: `Mailbox` → `Message` → `MessagePart`. Only `Mailbox` has a public constructor; `Message` and `MessagePart` are marked `@internal` and are handed the raw `IMAP\Connection` plus the ext-imap `stdClass` they wrap. Nothing in the library keeps state beyond that connection, so a closed `Mailbox` leaves its already-fetched `Message` objects holding a dead handle.

- **`Mailbox`** — connects lazily: `getMessages()` calls `connect()` itself if needed, so an explicit `connect()` is optional. It fetches the whole overview (`1:Nmsgs`) in one call; there is no paging or search.
- **`Message`** — the overview fields (subject, from, date) come from the cheap `imap_fetch_overview` data; anything structural triggers `imap_fetchstructure` lazily, cached in `$this->struct` via `??=` on an uninitialized typed property.
- **`MessagePart`** — flattens the `parameters` list into a name→value map in the constructor, and decodes content on read (quoted-printable / base64 per `encoding`, then `iconv` from the `CHARSET` parameter to UTF-8).

### Error handling: the global imap error stack

Every ext-imap call is `@`-silenced and a failure throws `DG\Imap\Exception`. That exception takes **no arguments** — its constructor pops the message off `imap_errors()`, the process-global error stack of ext-imap.

Consequences that are easy to get wrong:

- `imap_errors()` is destructive and global. Anything else that reads it before the exception is constructed steals the message, and the exception falls back to `'Unknown error'`.
- Errors left on the stack are emitted by PHP as notices at shutdown. Any test that provokes a failure must drain it with a bare `imap_errors();` afterwards — see `tests/connect.phpt`.

### Part numbering

IMAP part numbers are 1-based strings; `Message::getPart()` takes a **0-based** index and converts (`(string) ($id + 1)`). Only the top level of `$struct->parts` is exposed, so nested multipart bodies are not walked recursively.

### Read-only by default

Body fetches pass `FT_PEEK`, so reading a message does not set the `\Seen` flag. `Message::delete()` only *marks* for deletion; nothing in the library calls `imap_expunge()`, and `Mailbox::close()` uses plain `imap_close()` without `CL_EXPUNGE`, so marked messages survive unless the caller expunges themselves.

## Testing

Nette Tester with `.phpt` files in `tests/`. Coverage is minimal — the single test only asserts that a connection with bogus credentials throws. There is no mock IMAP server, so anything beyond connection failure needs a live mailbox; keep that in mind before assuming a change is covered.
