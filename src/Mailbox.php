<?php declare(strict_types=1);

namespace DG\Imap;


/**
 * Represents an IMAP mailbox and provides functionality to interact with it.
 */
final class Mailbox
{
	private ?Connection $connection = null;
	private string $host;
	private int $port;
	private bool $ssl;
	private string $folder;

	/** @var ?array<string, list<string>>  folder name in UTF-8 => its attributes */
	private ?array $folders = null;

	/** counts sessions, so a message cannot act on a UID from a previous one */
	private int $generation = 0;


	/**
	 * The mailbox specification uses the traditional c-client syntax, e.g. '{imap.gmail.com:993/imap/ssl}'.
	 */
	public function __construct(
		string $mailbox,
		private string $username,
		private string $password,
	) {
		if (!preg_match('~^\{([^:/}]+)(?::(\d+))?((?:/[\w.-]+)*)\}(.*)$~D', $mailbox, $m)) {
			throw new Exception("Invalid mailbox specification '$mailbox'");
		}

		if (str_contains($m[3], '/tls')) {
			throw new Exception('STARTTLS is not supported, use implicit TLS via /ssl');
		}

		$this->host = $m[1];
		$this->ssl = str_contains($m[3], '/ssl');
		$this->port = $m[2] === '' ? ($this->ssl ? 993 : 143) : (int) $m[2]; // a port of 0 is falsy, but given
		$this->folder = $m[4] === '' ? 'INBOX' : $m[4];
	}


	/**
	 * Establishes a connection to the server and selects the folder.
	 * @throws Exception  Connection or authentication failed.
	 */
	public function connect(): void
	{
		$connection = Connection::connect($this->host, $this->port, $this->ssl);
		$connection->command('LOGIN ' . Connection::quote($this->username) . ' ' . Connection::quote($this->password));
		$connection->command('SELECT ' . Connection::quote(self::encodeName($this->folder)));
		$this->close(); // only once the new session stands, so a failure does not cost the old one
		$this->connection = $connection;
		$this->generation++;
	}


	/**
	 * Fetches all messages from the mailbox; bodies are downloaded lazily.
	 * @return list<Message>
	 * @throws Exception
	 */
	public function getMessages(): array
	{
		$connection = $this->connectIfNeeded();
		$res = [];
		foreach ($connection->command('UID FETCH 1:* (UID BODY.PEEK[HEADER.FIELDS (SUBJECT FROM DATE)])') as $line) {
			$parsed = str_starts_with($line, '* ') ? Connection::parseLiteral($line) : null;
			if ($parsed && preg_match('~\bUID (\d+)~', $parsed[1], $m)) {
				$res[] = new Message($this, $this->generation, (int) $m[1], MimeParser::parse($parsed[0])[0]);
			}
		}

		return $res;
	}


	/**
	 * Returns all folders on the server as name => attributes, with names in UTF-8.
	 * @return array<string, list<string>>
	 */
	public function getFolders(): array
	{
		if ($this->folders === null) {
			$quotedPattern = '~^\* LIST \(([^)]*)\) (?:"(?:[^"\\\]|\\\.)*"|NIL) (?:"((?:[^"\\\]|\\\.)*)"|(\S+))~';
			$folders = [];
			foreach ($this->connectIfNeeded()->command('LIST "" "*"') as $line) {
				$parsed = Connection::parseLiteral($line); // a name with special characters travels as a literal
				if ($parsed && preg_match('~^\* LIST \(([^)]*)\)~', $parsed[1], $m)) {
					$name = $parsed[0];
				} elseif (!$parsed && preg_match($quotedPattern, $line, $m)) {
					$name = stripslashes(($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
				} else {
					continue;
				}

				$folders[self::decodeName($name)] = preg_split('~\s+~', $m[1], -1, PREG_SPLIT_NO_EMPTY);
			}

			$this->folders = $folders; // cached only once the listing completed, so a failure can be retried
		}

		return $this->folders;
	}


	/**
	 * Returns the folder the server designates for the given purpose (RFC 6154), e.g. '\Trash'
	 * or '\Archive', or null when the server advertises none.
	 */
	public function getSpecialFolder(string $attribute): ?string
	{
		$attribute = strtolower($attribute);
		foreach ($this->getFolders() as $name => $attributes) {
			foreach ($attributes as $candidate) {
				if (strtolower($candidate) === $attribute) {
					return $name;
				}
			}
		}

		return null;
	}


	/**
	 * Closes the connection to the mailbox.
	 */
	public function close(): void
	{
		if ($this->connection) {
			try {
				$this->connection->command('LOGOUT');
			} catch (Exception) {
				// closing must not fail
			}
			$this->connection->disconnect();
			$this->connection = null;
			$this->folders = null;
		}
	}


	/**
	 * Permanently removes the message from the mailbox.
	 * @internal
	 */
	public function deleteMessage(int $uid): void
	{
		$connection = $this->getConnection();
		$connection->command("UID STORE $uid +FLAGS.SILENT (\\Deleted)");
		$this->expunge($uid);
	}


	/**
	 * Moves the message to another folder, named in UTF-8.
	 * @internal
	 */
	public function moveMessage(int $uid, string $folder): void
	{
		$connection = $this->getConnection();
		$target = Connection::quote(self::encodeName($folder));
		if ($connection->hasCapability('MOVE')) {
			$connection->command("UID MOVE $uid $target");
		} else {
			$connection->command("UID COPY $uid $target");
			$connection->command("UID STORE $uid +FLAGS.SILENT (\\Deleted)");
			$this->expunge($uid);
		}
	}


	/**
	 * Returns the current session number. UIDs are only meaningful within one session,
	 * so a message from an older one must not be acted upon.
	 * @internal
	 */
	public function getGeneration(): int
	{
		return $this->generation;
	}


	/**
	 * Returns the open connection. Unlike the entry points on the mailbox itself it never
	 * reconnects: silently opening a new session would resurrect UIDs that no longer mean
	 * anything, and this is what the destructive operations run on.
	 * @internal
	 */
	public function getConnection(): Connection
	{
		return $this->connection ?? throw new Exception('The mailbox is closed');
	}


	private function connectIfNeeded(): Connection
	{
		if (!$this->connection) {
			$this->connect();
		}

		return $this->connection ?? throw new \LogicException;
	}


	/**
	 * Removes messages flagged as deleted. Without the UIDPLUS extension the server can only
	 * expunge the whole folder, which also drops messages flagged by somebody else.
	 */
	private function expunge(int $uid): void
	{
		$connection = $this->getConnection();
		$connection->command($connection->hasCapability('UIDPLUS') ? "UID EXPUNGE $uid" : 'EXPUNGE');
	}


	/** Converts a folder name from UTF-8 to the modified UTF-7 used on the wire. */
	private static function encodeName(string $name): string
	{
		return mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
	}


	/** Converts a folder name from the modified UTF-7 used on the wire to UTF-8. */
	private static function decodeName(string $name): string
	{
		return mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
	}
}
