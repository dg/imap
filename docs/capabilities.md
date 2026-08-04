# Capabilities and Limits

The library is a deliberately small, zero-dependency IMAP client. Since version 2.0 it implements
the IMAP protocol in pure PHP (TLS socket + own MIME parser) and does not need the unmaintained
`imap` extension that was unbundled from PHP 8.4. It requires only the `iconv`, `mbstring`
and `openssl` extensions, which are enabled by default.

The guiding principle: cover the common "robot mailbox" workflow – connect, list messages,
inspect headers, download and decode what matches, delete what was processed – and nothing more.


## What it does

- **Connection**: IMAP over implicit TLS (default port 993) or plain TCP (port 143). The mailbox
  is specified in the traditional c-client syntax, e.g. `{imap.gmail.com:993/imap/ssl}INBOX`,
  so version 1.x configurations keep working. Unknown `/flags` are ignored, the folder defaults
  to `INBOX`.
- **Authentication**: `LOGIN` with username and password (quoted with proper escaping). Gmail
  works with an app password.
- **Listing**: `Mailbox::getMessages()` fetches UID + Subject/From/Date of all messages in one
  round trip. Message bodies are downloaded lazily, on the first call of `countParts()`,
  `getPart()`, `getParts()` or `getContents()` – messages whose headers do not match are never
  downloaded.
- **MIME**: the body is split into top-level parts; each part decodes its transfer encoding
  (quoted-printable, base64) and text parts convert their declared charset to UTF-8. Subject
  and From decode encoded-words (`=?utf-8?B?...?=`), including multi-chunk ones.
- **Real-world tolerance**, verified against messages from actual supplier systems:
  - `Content-Type: base64` (a transfer encoding sent as the content type) – decoding follows
    the `Content-Transfer-Encoding` header, not the content type,
  - `MULTIPART/mixed; BOUNDARY="..."` in uppercase,
  - `charset = "utf-8"` with spaces around `=`,
  - subject sent as raw 8-bit UTF-8 (returned as-is when encoded-word decoding fails),
  - LF-only line endings in reconstructed or archived messages,
  - a charset declared on a binary attachment is ignored; on a text part, an illegal byte
    sequence is retried with `//IGNORE` and an unknown charset falls back to the undecoded
    bytes, so one bad byte never costs the whole part,
  - a missing, empty or malformed `Date` header makes `getDate()` return `null` instead of
    a guess.
- **Getting rid of a message**, in four flavors, because IMAP is ambiguous here. `delete()`
  flags `\Deleted` and expunges at once — the server decides what that means, destroying the
  message on a plain IMAP server but archiving it on Gmail per the account's Auto-Expunge
  setting. `trash()`, `archive()` and `moveTo($folder)` move the message instead, which is
  predictable everywhere. The trash and archive folders are not guessed: they come from the
  server through the `SPECIAL-USE` extension, also readable via `Mailbox::getSpecialFolder()`,
  and the operation throws when the server advertises none. UIDs are used throughout, so
  removing messages while iterating cannot hit a renumbered one.
- **Folders**: `Mailbox::getFolders()` lists them with their attributes. Names are UTF-8 in
  both directions; the modified UTF-7 of the wire is converted at the edge.
- **Offline processing**: `Message::fromString()` creates a detached message from a raw
  RFC 5322 string (an `.eml` file). Everything except the removal operations works on it,
  which makes code that processes messages testable without a server.


## What it does not do

By design – the workflow above does not need it:

- **POP3 and NNTP** (version 1.x nominally accepted such c-client specs; the pure PHP
  implementation speaks only IMAP).
- **STARTTLS** (`/tls` flag). Use implicit TLS on port 993 instead.
- **OAuth 2.0 / XOAUTH2.** Password login only; for Gmail use an app password.
- **Server-side operations** beyond the above: no `SEARCH`, no creating or deleting folders,
  no flags other than `\Deleted`, no `APPEND`, no `IDLE`.
- **Partial body fetch.** A message body is always downloaded whole (`BODY.PEEK[]`); there is
  no `BODYSTRUCTURE` parsing and no per-part fetch, so a message with a huge attachment is
  transferred entirely even if only a small part is needed. In exchange, the nastiest part
  of the IMAP protocol is avoided completely.
- **Nested multipart expansion.** Only top-level parts are exposed; a child that is itself
  `multipart/*` stays a single part whose `getContents()` returns the raw inner body, exactly
  like `imap_fetchbody()` did for a section. The same applies to embedded `message/rfc822`.
- **RFC 2231 extended parameters** (`filename*=utf-8''...`) and other exotic header syntax.

Known assumptions, fine on Gmail and mainstream servers:

- FETCH body responses are expected to use `{n}` literals, not quoted strings (all real servers
  send literals for bodies).
- An empty mailbox needs no special handling: `UID FETCH 1:*` matches no UID and the server
  answers OK with no data, which RFC 3501 requires and Gmail was verified to do.
- A `Message` is only usable within the session that produced it. After the mailbox was closed,
  or reconnected, acting on one throws `DG\Imap\Exception` rather than silently opening a new
  session, because a UID means nothing outside the session it came from. A message that was
  already deleted or moved throws `LogicException`. Data downloaded before that stays readable.
- Calling `connect()` again replaces the session, but only once the new one is logged in and
  the folder selected. A connection or authentication failure therefore leaves the session you
  had untouched, instead of taking it down on the way to a server that turned out unreachable.


## What could be added someday

- **XOAUTH2**, when some mailbox stops accepting app passwords.
- **Per-part fetch** (`BODYSTRUCTURE` + `BODY.PEEK[n]`), if bandwidth on large attachments
  ever becomes a problem.
- **STARTTLS**, if a server without implicit TLS ever needs to be supported.
- **Search and flags API** (`SEARCH`, `\Seen`, ...), if a workflow other than
  "process and delete" appears.
