<?php declare(strict_types=1);

namespace DG\Imap;

use function count;


/**
 * Represents an individual email message within an IMAP mailbox.
 */
final class Message
{
	private ?string $body = null;

	/** @var ?list<string>  raw top-level MIME parts */
	private ?array $parts = null;

	private bool $removed = false;


	/**
	 * @param  array<string, string>  $headers
	 * @internal
	 */
	public function __construct(
		private ?Mailbox $mailbox,
		private int $generation,
		private int $uid,
		private array $headers,
	) {
	}


	/**
	 * Creates a message from a raw RFC 5322 string, e.g. the contents of an .eml file.
	 * Such a message is not bound to a mailbox and cannot be deleted.
	 */
	public static function fromString(string $raw): self
	{
		[$headers, $body] = MimeParser::parse($raw);
		$message = new self(null, 0, 0, $headers);
		$message->processBody($body);
		return $message;
	}


	/**
	 * Returns the subject of the message, decoded for readability. Malformed input,
	 * such as a raw 8-bit subject some senders emit, is returned as-is.
	 */
	public function getSubject(): string
	{
		return self::decodeHeader($this->headers['subject'] ?? '');
	}


	/**
	 * Returns the sender's information of the message, decoded for readability.
	 */
	public function getFrom(): string
	{
		return self::decodeHeader($this->headers['from'] ?? '');
	}


	/**
	 * Returns the date of the message, or null when the Date header is missing, empty or malformed.
	 */
	public function getDate(): ?\DateTimeImmutable
	{
		$date = trim($this->headers['date'] ?? ''); // an empty value would parse as 'now'
		try {
			return $date === '' ? null : new \DateTimeImmutable($date);
		} catch (\Throwable) {
			return null;
		}
	}


	/**
	 * Returns the raw content of the message body: the first part for a multipart message,
	 * the whole body otherwise. Transfer encoding is not decoded.
	 */
	public function getContents(): string
	{
		$this->fetchBody();
		return $this->parts
			? MimeParser::parse($this->parts[0])[1]
			: ($this->body ?? '');
	}


	/**
	 * Removes the message from the mailbox right away. What that means is the server's policy:
	 * a plain IMAP server destroys it, Gmail applies the Auto-Expunge setting of the account.
	 * Use trash() or archive() when the outcome has to be the same everywhere.
	 */
	public function delete(): void
	{
		$this->requireMailbox()->deleteMessage($this->uid);
		$this->removed = true;
	}


	/**
	 * Moves the message to the folder the server designates as trash, so it is removed from
	 * the mailbox but stays recoverable until the server's retention period ends.
	 * @throws Exception  The server advertises no trash folder.
	 */
	public function trash(): void
	{
		$this->moveTo(
			$this->requireMailbox()->getSpecialFolder('\Trash')
			?? throw new Exception('The server advertises no trash folder, use moveTo() with an explicit name'),
		);
	}


	/**
	 * Moves the message to the folder the server designates as archive, so it disappears from
	 * the mailbox but is not deleted. On Gmail this is All Mail.
	 * @throws Exception  The server advertises no archive folder.
	 */
	public function archive(): void
	{
		$mailbox = $this->requireMailbox();
		$this->moveTo(
			$mailbox->getSpecialFolder('\Archive')
			?? $mailbox->getSpecialFolder('\All')
			?? throw new Exception('The server advertises no archive folder, use moveTo() with an explicit name'),
		);
	}


	/**
	 * Moves the message to the given folder, named in UTF-8.
	 */
	public function moveTo(string $folder): void
	{
		$this->requireMailbox()->moveMessage($this->uid, $folder);
		$this->removed = true;
	}


	/**
	 * Counts and returns the number of parts in the message.
	 */
	public function countParts(): int
	{
		$this->fetchBody();
		return count($this->parts ?? []);
	}


	/**
	 * Retrieves a specific part of the message.
	 */
	public function getPart(int $id): MessagePart
	{
		$this->fetchBody();
		$part = $this->parts[$id] ?? null;
		if ($part === null) {
			throw new \ValueError('Invalid part number');
		}

		return new MessagePart($part);
	}


	/**
	 * Returns an array of all parts of the message.
	 * @return list<MessagePart>
	 */
	public function getParts(): array
	{
		$this->fetchBody();
		$res = [];
		foreach ($this->parts ?? [] as $n => $foo) {
			$res[] = $this->getPart($n);
		}

		return $res;
	}


	/** Decodes encoded-words (=?utf-8?B?...?=); malformed input is returned as-is. */
	private static function decodeHeader(string $value): string
	{
		$decoded = @iconv_mime_decode($value); // @ - malformed input falls back to the raw value
		return $decoded === false ? $value : $decoded;
	}


	/**
	 * @throws \LogicException  The message is detached or no longer in the mailbox.
	 */
	private function requireMailbox(): Mailbox
	{
		if ($this->removed) {
			throw new \LogicException('Message is no longer in the mailbox');
		}

		$mailbox = $this->mailbox ?? throw new \LogicException('Message is not bound to a mailbox');
		if ($mailbox->getGeneration() !== $this->generation) {
			throw new Exception('Message comes from an earlier session, its UID is no longer valid');
		}

		return $mailbox;
	}


	/**
	 * Fetches and caches the body of the message. Once cached it stays readable even after the
	 * message was removed from the mailbox; only a fetch that still has to happen fails.
	 */
	private function fetchBody(): void
	{
		if ($this->parts !== null) {
			return;
		}

		$connection = $this->requireMailbox()->getConnection();
		foreach ($connection->command("UID FETCH {$this->uid} (BODY.PEEK[])") as $line) {
			$parsed = str_starts_with($line, '* ') ? Connection::parseLiteral($line) : null;
			if ($parsed) {
				[$headers, $body] = MimeParser::parse($parsed[0]);
				$this->headers += $headers;
				$this->processBody($body);
				return;
			}
		}

		throw new Exception('Cannot fetch message');
	}


	private function processBody(string $body): void
	{
		[$type, $params] = MimeParser::parseHeaderValue($this->headers['content-type'] ?? '');
		$boundary = $params['BOUNDARY'] ?? null;
		$this->parts = str_starts_with($type, 'multipart/') && $boundary !== null && $boundary !== ''
			? MimeParser::splitMultipart($body, $boundary)
			: [];
		$this->body = $body;
	}
}
