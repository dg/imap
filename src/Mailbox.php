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
		$connection->command('LOGIN ' . self::quote($this->username) . ' ' . self::quote($this->password));
		$connection->command('SELECT ' . self::quote($this->folder));
		$this->connection = $connection;
	}


	/**
	 * Fetches all messages from the mailbox; bodies are downloaded lazily.
	 * @return list<Message>
	 * @throws Exception
	 */
	public function getMessages(): array
	{
		if (!$this->connection) {
			$this->connect();
		}

		$connection = $this->connection ?? throw new \LogicException;
		$res = [];
		foreach ($connection->command('UID FETCH 1:* (UID BODY.PEEK[HEADER.FIELDS (SUBJECT FROM DATE)])') as $line) {
			$parsed = str_starts_with($line, '* ') ? Connection::parseLiteral($line) : null;
			if ($parsed && preg_match('~\bUID (\d+)~', $parsed[1], $m)) {
				$res[] = new Message($connection, (int) $m[1], MimeParser::parse($parsed[0])[0]);
			}
		}

		return $res;
	}


	/**
	 * Closes the connection to the mailbox. Messages marked for deletion are not expunged,
	 * so the CLOSE command, which would expunge them, is deliberately not used.
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
		}
	}


	private static function quote(string $value): string
	{
		if (preg_match('~[\r\n]~', $value)) {
			throw new Exception('Value must not contain line breaks');
		}

		return '"' . addcslashes($value, '"\\') . '"';
	}
}
