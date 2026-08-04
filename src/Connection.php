<?php declare(strict_types=1);

namespace DG\Imap;

use function strlen;


/**
 * Low-level IMAP protocol client over a TCP/TLS socket.
 * @internal
 */
final class Connection
{
	/** @var ?resource */
	private $stream;
	private int $tagCount = 0;


	/**
	 * @param  resource  $stream  connected socket, positioned before the server greeting
	 */
	private function __construct($stream)
	{
		$this->stream = $stream;
		$this->readResponse(); // server greeting
	}


	/**
	 * @throws Exception  Failed to connect to the server.
	 */
	public static function connect(string $host, int $port, bool $ssl, float $timeout = 30.0): self
	{
		$stream = @stream_socket_client( // @ - failure is throwing exception
			($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port,
			$errorCode,
			$errorMessage,
			$timeout,
		);
		if (!$stream) {
			throw new Exception("Cannot connect to $host:$port: $errorMessage");
		}

		stream_set_timeout($stream, (int) ceil($timeout));
		return new self($stream);
	}


	/**
	 * Creates a connection over an already opened stream, for testing against a scripted dialogue.
	 * @param  resource  $stream
	 */
	public static function fromStream($stream): self
	{
		return new self($stream);
	}


	/**
	 * Sends a tagged command and returns the untagged response lines, with literals inlined.
	 * @return list<string>
	 * @throws Exception  The server rejected the command.
	 */
	public function command(string $command): array
	{
		$tag = 'T' . ++$this->tagCount;
		$this->write("$tag $command\r\n");
		$lines = [];
		while (true) {
			$line = $this->readResponse();
			if (str_starts_with($line, "$tag ")) {
				return str_starts_with($line, "$tag OK")
					? $lines
					: throw new Exception('Command failed: ' . trim(substr($line, strlen($tag) + 1)));
			}
			$lines[] = $line;
		}
	}


	/**
	 * Extracts the first {n} literal from a response line. Returns [literal, line without the literal],
	 * or null if the line contains no literal.
	 * @return ?array{string, string}
	 */
	public static function parseLiteral(string $line): ?array
	{
		if (!preg_match('~\{(\d+)\}\r\n~', $line, $m, PREG_OFFSET_CAPTURE)) {
			return null;
		}

		$offset = $m[0][1] + strlen($m[0][0]);
		$length = (int) $m[1][0];
		return [
			substr($line, $offset, $length),
			substr($line, 0, $m[0][1]) . substr($line, $offset + $length),
		];
	}


	public function disconnect(): void
	{
		if ($this->stream) {
			fclose($this->stream);
			$this->stream = null;
		}
	}


	/**
	 * @return resource
	 */
	private function getStream()
	{
		return $this->stream ?? throw new Exception('Connection is closed');
	}


	private function write(string $data): void
	{
		if (@fwrite($this->getStream(), $data) !== strlen($data)) { // @ - failure is throwing exception
			throw new Exception('Cannot write to server');
		}
	}


	/**
	 * Reads one logical response line; a {n} literal at the end of a line is followed
	 * by n bytes of data and a continuation of the same logical line.
	 */
	private function readResponse(): string
	{
		$line = $this->readLine();
		while (preg_match('~\{(\d+)\}\r\n$~', $line, $m)) {
			$line .= $this->readBytes((int) $m[1]) . $this->readLine();
		}
		return $line;
	}


	private function readLine(): string
	{
		$line = @fgets($this->getStream()); // @ - failure is throwing exception
		if ($line === false || !str_ends_with($line, "\n")) { // a partial line means timeout or EOF mid-line
			throw new Exception('Cannot read from server (connection closed or timed out)');
		}
		return $line;
	}


	private function readBytes(int $count): string
	{
		$data = '';
		while (($remaining = $count - strlen($data)) > 0) {
			$chunk = @fread($this->getStream(), $remaining); // @ - failure is throwing exception
			if ($chunk === false || $chunk === '') {
				throw new Exception('Cannot read from server (connection closed or timed out)');
			}
			$data .= $chunk;
		}

		return $data;
	}
}
