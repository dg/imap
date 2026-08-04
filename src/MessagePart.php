<?php declare(strict_types=1);

namespace DG\Imap;


/**
 * Represents a part of an IMAP email message, such as an attachment or a text segment.
 */
final class MessagePart
{
	/** @var array<string, string> */
	private array $headers;
	private string $body;
	private string $type;

	/** @var array<string, string>  parameters of the Content-Type header, with uppercased names */
	private array $params;


	/** @internal */
	public function __construct(string $raw)
	{
		[$this->headers, $this->body] = MimeParser::parse($raw);
		[$this->type, $this->params] = MimeParser::parseHeaderValue($this->headers['content-type'] ?? '');
	}


	/**
	 * Returns the content of the message part with transfer encoding decoded. A text part
	 * declaring a charset is converted to UTF-8; an illegal byte costs at most itself, an
	 * unknown charset falls back to the undecoded bytes, and a charset wrongly declared on
	 * a binary part is ignored.
	 */
	public function getContents(): string
	{
		$content = match (MimeParser::parseHeaderValue($this->headers['content-transfer-encoding'] ?? '')[0]) {
			'quoted-printable' => quoted_printable_decode($this->body),
			'base64' => base64_decode($this->body, strict: true),
			default => $this->body,
		};
		if ($content === false) {
			throw new Exception('Failed to decode content of message part');
		}

		$charset = $this->getParameter('CHARSET');
		return $charset && str_starts_with($this->type, 'text/')
			? self::convert($content, $charset)
			: $content;
	}


	/**
	 * Converts to UTF-8. An illegal byte sequence is retried with //IGNORE to salvage the readable
	 * text; how much that salvages is up to the iconv implementation, and the undecoded bytes are
	 * returned when it refuses too, so never depend on the exact output for such input.
	 */
	private static function convert(string $content, string $charset): string
	{
		$converted = @iconv($charset, 'UTF-8', $content); // @ - an illegal byte is retried below
		if ($converted === false) {
			$converted = @iconv($charset, 'UTF-8//IGNORE', $content); // @ - an unknown charset falls back
		}

		return $converted === false ? $content : $converted;
	}


	/**
	 * Returns a specific parameter of the message part; the name is case-insensitive.
	 */
	public function getParameter(string $name): ?string
	{
		return $this->params[strtoupper($name)] ?? null;
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
