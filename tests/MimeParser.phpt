<?php declare(strict_types=1);

use DG\Imap\MimeParser;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('parses headers and body', function () {
	[$headers, $body] = MimeParser::parse(crlf(<<<'XX'
		From: "Someone" <someone@example.com>
		Subject: Hello
		X-Folded: first
			second
		Repeated: one
		Repeated: two

		Line 1
		Line 2
		XX));
	Assert::same('"Someone" <someone@example.com>', $headers['from']);
	Assert::same('Hello', $headers['subject']);
	Assert::same('first second', $headers['x-folded']);
	Assert::same('one', $headers['repeated']);
	Assert::same("Line 1\r\nLine 2", $body);
});


test('tolerates LF line endings and missing body', function () {
	[$headers, $body] = MimeParser::parse("Subject: Hi\nX-Test: y\n\nBody");
	Assert::same('Hi', $headers['subject']);
	Assert::same('y', $headers['x-test']);
	Assert::same('Body', $body);

	[$headers, $body] = MimeParser::parse("Subject: Hi\r\n");
	Assert::same('Hi', $headers['subject']);
	Assert::same('', $body);
});


test('parses header value with parameters', function () {
	[$value, $params] = MimeParser::parseHeaderValue('multipart/mixed; boundary="----MIME delimiter for sendEmail-139736.539990739"');
	Assert::same('multipart/mixed', $value);
	Assert::same('----MIME delimiter for sendEmail-139736.539990739', $params['BOUNDARY']);

	// uppercased value and parameter names occur in the wild
	[$value, $params] = MimeParser::parseHeaderValue('MULTIPART/mixed; BOUNDARY="1565348563-1146809180-1630868137=:7902"');
	Assert::same('multipart/mixed', $value);
	Assert::same('1565348563-1146809180-1630868137=:7902', $params['BOUNDARY']);

	// spaces around '=' occur in the wild
	[$value, $params] = MimeParser::parseHeaderValue('text/html; charset = "utf-8"');
	Assert::same('text/html', $value);
	Assert::same('utf-8', $params['CHARSET']);

	[$value, $params] = MimeParser::parseHeaderValue('text/plain; charset=iso-8859-2; format=flowed');
	Assert::same('iso-8859-2', $params['CHARSET']);
	Assert::same('flowed', $params['FORMAT']);

	// senders emit even a transfer encoding as the content type
	[$value, $params] = MimeParser::parseHeaderValue('base64; name="10563113.DOD"');
	Assert::same('base64', $value);
	Assert::same('10563113.DOD', $params['NAME']);

	Assert::same(['', []], MimeParser::parseHeaderValue(''));
});


test('splits multipart body', function () {
	$body = crlf(<<<'XX'
		preamble
		--BOUND
		Content-Type: text/plain

		first
		--BOUND
		Content-Type: text/html

		<b>second</b>
		--BOUND--
		epilogue
		XX);
	$parts = MimeParser::splitMultipart($body, 'BOUND');
	Assert::count(2, $parts);
	Assert::same("Content-Type: text/plain\r\n\r\nfirst", $parts[0]);
	Assert::same("Content-Type: text/html\r\n\r\n<b>second</b>", $parts[1]);
});


test('splits multipart body with LF line endings and no preamble', function () {
	$parts = MimeParser::splitMultipart("--B\nH: v\n\ndata\n--B--\n", 'B');
	Assert::count(1, $parts);
	Assert::same("H: v\n\ndata", $parts[0]);
});


test('boundary that is a prefix of another line does not split', function () {
	$body = crlf(<<<'XX'
		--B
		H: v

		--BX is ordinary content
		--B--
		XX);
	$parts = MimeParser::splitMultipart($body, 'B');
	Assert::count(1, $parts);
	Assert::same("H: v\r\n\r\n--BX is ordinary content", $parts[0]);
});
