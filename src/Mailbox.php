<?php

declare(strict_types=1);

namespace DG\Imap;
use IMAP\Connection;


/**
 * Represents an IMAP mailbox and provides functionality to interact with it.
 */
final class Mailbox
{
	private ?Connection $connection = null;


	public function __construct(
		private string $mailbox,
		private string $username,
		private string $password,
	) {
	}


	/**
	 * Establishes a connection to the mailbox.
	 * @throws Exception If connection fails.
	 */
	public function connect(): void
	{
		$this->connection = @imap_open($this->mailbox, $this->username, $this->password) ?: throw new Exception;
	}


	/**
	 * Fetches all messages from the mailbox.
	 * @return Message[]
	 * @throws Exception If message retrieval fails.
	 */
	public function getMessages(): array
	{
		if (!$this->connection) {
			$this->connect();
		}

		$status = @imap_check($this->connection) ?: throw new Exception;
		if (!$status->Nmsgs) {
			return [];
		}

		$messages = @imap_fetch_overview($this->connection, "1:{$status->Nmsgs}") ?: throw new Exception;
		$res = [];
		foreach ($messages as $message) {
			$res[] = new Message($this->connection, $message);
		}

		return $res;
	}


	/**
	 * Closes the connection to the mailbox.
	 */
	public function close(): void
	{
		if ($this->connection) {
			imap_close($this->connection);
			$this->connection = null;
		}
	}
}
