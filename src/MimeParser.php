<?php declare(strict_types=1);

namespace DG\Imap;


/**
 * Minimal parser of RFC 5322 messages and MIME multipart bodies.
 * @internal
 */
final class MimeParser
{
	/**
	 * Splits a raw message or message part into unfolded headers and body.
	 * Header names are lowercased; for repeated headers the first occurrence wins.
	 * @return array{array<string, string>, string}
	 */
	public static function parse(string $raw): array
	{
		$split = preg_split('~\r?\n\r?\n~', $raw, 2) ?: [$raw];
		$headers = [];
		foreach (preg_split('~\r?\n(?![ \t])~', $split[0]) ?: [] as $field) {
			if (preg_match('~^([\w-]+):\s*(.*)$~sD', $field, $m)) {
				$headers[strtolower($m[1])] ??= trim(preg_replace('~\s*\r?\n[ \t]+~', ' ', $m[2]));
			}
		}

		return [$headers, $split[1] ?? ''];
	}


	/**
	 * Parses a structured header value like 'multipart/mixed; boundary="..."' into the lowercased
	 * value itself and its parameters with uppercased names.
	 * @return array{string, array<string, string>}
	 */
	public static function parseHeaderValue(string $header): array
	{
		$value = strtolower(trim(explode(';', $header, 2)[0]));
		$params = [];
		preg_match_all('~;\s*([\w-]+)\s*=\s*(?:"([^"]*)"|([^;\s]+))~', $header, $matches, PREG_SET_ORDER);
		foreach ($matches as $m) {
			$params[strtoupper($m[1])] ??= $m[3] ?? $m[2] ?? '';
		}

		return [$value, $params];
	}


	/**
	 * Splits a multipart body into raw top-level parts, each with its own headers and body.
	 * Nested multipart children are kept unexpanded as a single part.
	 * @return list<string>
	 */
	public static function splitMultipart(string $body, string $boundary): array
	{
		$chunks = preg_split('~\r?\n--' . preg_quote($boundary, '~') . '(?=--|[ \t]*\r?\n|\z)~', "\n" . $body) ?: [];
		array_shift($chunks); // preamble
		$parts = [];
		foreach ($chunks as $chunk) {
			if (str_starts_with($chunk, '--')) { // closing delimiter
				break;
			}
			$parts[] = preg_replace('~^[ \t]*\r?\n~', '', $chunk);
		}

		return $parts;
	}
}
