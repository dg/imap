<?php

declare(strict_types=1);

namespace DG\Imap;
use IMAP\Connection;


/**
 * Represents an individual email message within an IMAP mailbox.
 */
final class Message
{
	private \stdClass $struct;


	/** @internal */
	public function __construct(
		private Connection $connection,
		private \stdClass $message,
	) {
	}


	/**
	 * Returns the subject of the message, decoded for readability.
	 */
	public function getSubject(): string
	{
		return iconv_mime_decode($this->message->subject);
	}


	/**
	 * Returns the sender's information of the message.
	 */
	public function getFrom(): string
	{
		return $this->message->from;
	}


	/**
	 * Returns the date of the message as a DateTimeImmutable object.
	 */
	public function getDate(): \DateTimeImmutable
	{
		return new \DateTimeImmutable($this->message->date);
	}


	/**
	 * Returns the content of the message body.
	 */
	public function getContents(): string
	{
		$content = @imap_fetchbody($this->connection, $this->message->msgno, '1', FT_PEEK);
		return is_string($content)
			? $content
			: throw new Exception;

	}


	/**
	 * Marks the message for deletion from the mailbox.
	 */
	public function delete(): void
	{
		imap_delete($this->connection, (string) $this->message->msgno);
	}


	/**
	 * Counts and returns the number of parts in the message.
	 */
	public function countParts(): int
	{
		$this->fetchStructure();
		return count($this->struct->parts ?? []);
	}


	/**
	 * Retrieves a specific part of the message.
	 */
	public function getPart(int $id): MessagePart
	{
		$this->fetchStructure();
		$part = $this->struct->parts[$id] ?? null;
		if (!$part) {
			throw new \ValueError('Invalid part number');
		}
		return new MessagePart($this->connection, $this->message->msgno, (string) ($id + 1), $part);
	}


	/**
	 * Returns an array of all parts of the message.
	 * @return MessagePart[]
	 */
	public function getParts(): array
	{
		$this->fetchStructure();
		$res = [];
		foreach (($this->struct->parts ?? []) as $n => $foo) {
			$res[] = $this->getPart($n);
		}

		return $res;
	}


	/**
	 * Fetches and caches the structure of the message.
	 */
	private function fetchStructure(): void
	{
		$this->struct ??= @imap_fetchstructure($this->connection, $this->message->msgno) ?: throw new Exception;
	}
}
