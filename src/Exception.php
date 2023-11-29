<?php

declare(strict_types=1);

namespace DG\Imap;


class Exception extends \Exception
{
	public function __construct()
	{
		$errors = imap_errors();
		parent::__construct(end($errors) ?: 'Unknown error');
	}
}
