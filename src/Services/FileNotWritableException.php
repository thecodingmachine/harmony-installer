<?php
namespace Harmony\Services;

use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Exception throw if a file is expected to be writable.
 *
 * @author David Negrier <david@mouf-php.com>
 */
class FileNotWritableException extends IOException
{
}
