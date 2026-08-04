# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

## Overview

`dg/imap` — a small object-oriented IMAP client for reading mailboxes. Published on Packagist. Requires PHP 8.1+ and `ext-iconv` + `ext-mbstring` + `ext-openssl`; **no `ext-imap`**, the protocol is spoken directly over a socket. Six classes in `src/`, classmap autoloading (not PSR-4), namespace `DG\Imap`.

Since 2.0 the library no longer depends on the unmaintained `imap` extension that PHP 8.4 unbundled. The reading API (`Mailbox` → `Message` → `MessagePart`) is unchanged from 1.x, including the c-client mailbox syntax `{imap.gmail.com:993/imap/ssl}`; what a message can do with itself grew — see the section on getting rid of a message.

The scope is deliberately narrow: connect, list, read, and get rid of a message. What is in and what is out — plus ideas for later — is written down in [`docs/capabilities.md`](docs/capabilities.md). **Read it before adding a feature**; most of the obvious gaps (POP3, STARTTLS, OAuth, `SEARCH`, per-part fetch) are omissions by choice, not oversights.

## Commands

```bash
# Run all tests
composer tester

# Run a single test
vendor/bin/tester tests/MimeParser.phpt -s

# Static analysis (PHPStan level 8, src only)
composer phpstan
```

Coding standards (Nette Code Checker + Nette Coding Standard) run only in CI, not installed locally.

## Architecture

Two layers. The public chain `Mailbox` → `Message` → `MessagePart` is what callers see; underneath sit two `@internal` helpers that replace what ext-imap used to do:

- **`Connection`** — the IMAP protocol over a TCP/TLS socket. Owns tagging, reading responses and error detection. Created through `Connection::connect()`; the constructor is private.
- **`MimeParser`** — static functions splitting a raw message into headers/body, parsing structured header values, and cutting a multipart body on its boundary.

Public classes:

- **`Mailbox`** — parses the c-client spec in the constructor (so a bad spec fails immediately, before any I/O), then connects lazily: `getMessages()` calls `connect()` itself if needed. One `UID FETCH 1:*` retrieves the UID and Subject/From/Date of every message; there is no paging or search. It also owns everything folder-shaped: `getFolders()`, `getSpecialFolder()`, and the `@internal` `deleteMessage()`/`moveMessage()` that `Message` delegates to — a message knows its UID, the mailbox knows the server.
- **`Message`** — holds a UID and the overview headers. Bodies are fetched **lazily**: the first `countParts()`, `getPart()`, `getParts()` or `getContents()` issues `UID FETCH <uid> (BODY.PEEK[])` and caches the result. Messages whose headers do not match the caller's criteria are therefore never downloaded — worth preserving, since attachments run to megabytes.
- **`MessagePart`** — a raw part; it parses its own headers on construction and decodes content on read (quoted-printable / base64 per `Content-Transfer-Encoding`; a `text/*` part is then converted from its `CHARSET` parameter to UTF-8, and falls back to the undecoded bytes when the charset is unknown — a charset wrongly declared on a binary attachment is ignored).

`Message::fromString()` builds a detached message from a raw RFC 5322 string (an `.eml` file). Everything except the removal operations works on it. This is how the MIME half is tested without a server, and how the code that consumes this library tests itself.

### The protocol layer

`Connection::command()` sends one tagged command (`T1`, `T2`, …) and returns the untagged `*` lines up to the tagged completion; a `NO` or `BAD` response throws.

The one genuinely tricky part is **literals**. A server may end a line with `{n}\r\n`, meaning "the next n bytes are data, then the line continues". `readResponse()` therefore loops: read line, and while it ends in a literal marker, append exactly n bytes plus the rest of the line. One logical line can contain several literals. `Connection::parseLiteral()` then extracts the first literal from such a line and returns it alongside the line with the literal cut out — that pair is how `Mailbox` and `Message` split "the message data" from "the FETCH envelope holding the UID".

Do not "simplify" this with `stream_get_contents()` or a line-based read: literal payloads contain CRLFs and can hold the whole message body.

### Part numbering and nesting

`Message::getPart()` takes a **0-based** index into the top-level parts. Only the top level is exposed: a child that is itself `multipart/*` stays one part whose `getContents()` returns the raw inner body, boundaries and all. This deliberately mirrors what `imap_fetchbody($c, $n, '2')` returned for a section, so 1.x callers keep working. Recursive walking would be a BC break, not a fix.

`Message::getContents()` is likewise a 1.x compatibility shape: it returns the **raw, undecoded** first part of a multipart message (or the whole body when the message is single-part). Transfer encoding is not applied. Use `getPart()->getContents()` for decoded content.

### Real-world MIME quirks

The parser is deliberately tolerant, and the tests pin these against messages from actual supplier systems. Do not tighten them into RFC purity:

- `Content-Type: base64` — a transfer encoding sent as the content type. Decoding follows `Content-Transfer-Encoding`, never the content type.
- `MULTIPART/mixed; BOUNDARY="…"` in uppercase; header names and structured values are matched case-insensitively.
- `charset = "utf-8"` with spaces around the equals sign.
- A subject sent as raw 8-bit UTF-8 instead of encoded-words. `getSubject()` and `getFrom()` return the raw value when `iconv_mime_decode()` fails, rather than throwing.
- LF-only line endings, which appear in archived or reconstructed messages.
- A missing, empty or malformed `Date` header — `getDate()` returns `null` instead of guessing "now". The empty case is the trap: `new DateTimeImmutable('')` is *now*, not an error, so an `isset()` check alone is not enough.
- An illegal byte inside a text part. The conversion is retried with `iconv(…, 'UTF-8//IGNORE', …)` and falls back to the undecoded bytes if that fails too, so one bad byte never costs the whole part. **How much the retry salvages is not portable:** libiconv drops the offending bytes, glibc can refuse even in IGNORE mode, so a test must never pin the exact output for such input. mbstring is not the way out either, it knows neither `windows-1250` nor `cp852`, both of which arrive in real mail here.

### Getting rid of a message

Body fetches pass `BODY.PEEK[]`, so reading a message never sets `\Seen`. Removing one is the only destructive thing the library does, and it offers four operations because IMAP itself is ambiguous here:

| Method | Wire | What it guarantees |
|---|---|---|
| `delete()` | `STORE (\Deleted)` + expunge | the server removes it — *how* is the server's policy |
| `trash()` | `MOVE` to the `\Trash` folder | ends up in the trash, wherever that is |
| `archive()` | `MOVE` to `\Archive`, else `\All` | leaves the mailbox, is not destroyed |
| `moveTo($folder)` | `MOVE` to a named folder | exactly where the caller says |

**`delete()` is the one that cannot be made portable, and that is the point.** `\Deleted` + `EXPUNGE` destroys the message on a plain IMAP server, while Gmail intercepts it and applies the account's Auto-Expunge setting, which by default archives. Same commands, opposite outcome. Do not paper over this by making `delete()` move somewhere — callers who need a guaranteed outcome have the other three.

The move-based operations are portable precisely because they do not guess: the target comes from the server itself via the `SPECIAL-USE` extension (RFC 6154), read through `Mailbox::getSpecialFolder('\Trash')`. When the server advertises no such folder, `trash()`/`archive()` throw rather than fall back to a hardcoded name like `[Gmail]/Trash`.

Implementation notes that are easy to get wrong:

- **`delete()` expunges immediately**, so the outcome does not depend on anyone calling `close()`. With `UIDPLUS` it issues `UID EXPUNGE <uid>`, touching only this message; without it, plain `EXPUNGE` is the only option and it also drops messages flagged by somebody else. `Mailbox::close()` sends `LOGOUT` alone — never `CLOSE`, which would expunge as a side effect.
- **UIDs, not sequence numbers**, everywhere. Sequence numbers renumber after an expunge, so deleting inside a loop over sequence numbers hits the wrong message.
- **`MOVE` is an extension** (RFC 6851). Without it the fallback is `UID COPY` + `\Deleted` + expunge.
- **A removed message is dead.** After `delete()`, `trash()`, `archive()` or `moveTo()` the object refuses further work with `LogicException` — after a move the UID belongs to the destination folder, so reusing it would address a stranger. Data downloaded beforehand stays readable; only a fetch that still has to happen fails.
- **A UID is worthless outside its session.** `Mailbox::getConnection()` therefore never reconnects — the mailbox-level entry points (`getMessages()`, `getFolders()`) connect lazily, but a `Message` held across a `close()` must not resurrect the session behind the caller's back and then delete by a UID that now means something else. `Mailbox` counts sessions and a message from an older one is refused, which is also why the folder cache is dropped on close and connect.
- **Folder names travel in modified UTF-7** (`[Gmail]/Ko&AWE-`). The API speaks UTF-8 in both directions and converts at the edge via `mb_convert_encoding(…, 'UTF7-IMAP')`; that is why `ext-mbstring` is required, since `iconv` does not know this variant.

### Error handling

`DG\Imap\Exception` is a plain exception carrying the server's response text (or the socket error). There is no global error stack, nothing to drain, and no `imap_errors()` — the 1.x rule about tests having to flush it no longer applies.

Using a `Connection` after `disconnect()` throws `Exception: Connection is closed` rather than a `TypeError`. Using a `Message` whose `Mailbox` was closed throws the same way.

## Testing

Nette Tester with `.phpt` files in `tests/`. `bootstrap.php` enables `test()` and holds the two helpers every test leans on: `crlf()`, which turns a heredoc-written message into the CRLF line endings mail travels with, and `scriptedConnection()`, which returns a `Connection` reading from a canned server dialogue together with the server end of the socket. The server ends live in a static registry inside it; without one they are garbage collected and the socket closes mid-test.

Coverage is real and offline — a live mailbox is needed for almost nothing:

- **`Connection.phpt`** — the protocol against a scripted dialogue: literals (single and several per response), `NO`/`BAD`, tag sequencing, a truncated stream, a partial line, use after disconnect.
- **`MimeParser.phpt`**, **`Message.phpt`** — parsing and decoding through `Message::fromString()`, including every quirk listed above.
- **`Mailbox.phpt`** — mailbox specification parsing, `getFolders()` (UTF-7 names, a name sent as a literal) and a failed `connect()`, all against a scripted connection put in place with `Assert::with()`. The failed connect aims at port 0, which no platform will dial, so it costs nothing; a closed port like `127.0.0.1:1` looks equivalent but takes two seconds on Windows.
- **`connect.phpt`** — the only test that touches the internet: bogus credentials against Gmail must throw.

What the offline tests cannot reach: `connect()` closing the previous session on **success** needs a server to connect to, so only the failure path is pinned by a test.

When adding behavior, prefer a scripted dialogue or an `.eml` string over a live mailbox. Note that assertions inside `test()` are what make a `.phpt` count — a file that executes none fails with "This test forgets to execute an assertion".
