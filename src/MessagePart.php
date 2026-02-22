<?php declare(strict_types=1);

namespace DG\Imap;
use IMAP\Connection;
use function is_string;


/**
 * Represents a part of an IMAP email message, such as an attachment or a text segment.
 */
final class MessagePart
{
	/** @var string[] */
	private array $params = [];


	/** @internal */
	public function __construct(
		private Connection $connection,
		private int $messageNo,
		private string $partNo,
		private \stdClass $info,
	) {
		foreach ($info->parameters ?? [] as $pair) {
			$this->params[$pair->attribute] = $pair->value;
		}
	}


	/**
	 * Returns the content of the message part.
	 */
	public function getContents(): string
	{
		$content = @imap_fetchbody($this->connection, $this->messageNo, $this->partNo, FT_PEEK);
		return is_string($content)
			? $this->decodePart($content)
			: throw new Exception;

	}


	/**
	 * Decodes the message part content based on its encoding.
	 */
	private function decodePart(string $content): string
	{
		$content = match ($this->info->encoding) {
			ENCQUOTEDPRINTABLE => quoted_printable_decode($content),
			ENCBASE64 => base64_decode($content, true),
			default => $content,
		};
		if ($content === false) {
			throw new \RuntimeException;
		}

		$charset = $this->getParameter('CHARSET');
		if ($charset) {
			$content = iconv($charset, 'UTF-8', $content);
			if ($content === false) {
				throw new \RuntimeException('Failed to convert charset of message part');
			}
		}

		return $content;
	}


	/**
	 * Returns a specific parameter of the message part.
	 */
	public function getParameter(string $name): ?string
	{
		return $this->params[$name] ?? null;
	}


	/**
	 * Returns all parameters of the message part.
	 * @return array<string, string>
	 */
	public function getParameters(): array
	{
		return $this->params;
	}
}
