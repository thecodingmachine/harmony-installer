<?php
namespace Harmony\Services;

use Symfony\Component\Filesystem\Filesystem;

/**
 * This file is in charge of ensuring files can possibly be written.
 *
 * @author David Negrier <david@mouf-php.com>
 */
class FileService
{

    /**
     * Tests if a file can be written.
     * Will throw a FileNotWritableException if the file is not writable or the directory that might contain the file
     * is not writable.
     *
     * @param string $filename
     */
    public static function detectWriteIssues($filename)
    {
        $iterablefilename = $filename;

        do {
            if (file_exists($iterablefilename)) {
                if (!is_writable($iterablefilename)) {
                    $message = "File system error: ";
                    if (is_dir($iterablefilename)) {
                        $message .= "Directory ";
                    } else {
                        $message .= "File ";
                    }
                    $message .= "'$iterablefilename' is not writable.";

                    throw new FileNotWritableException($message, 0, null, $iterablefilename);
                } else {
                    return;
                }
            }
        } while ($iterablefilename = dirname($iterablefilename));
    }

    /**
     * Tests if a file can be created.
     * Will create the file's directory if needed with 775 rights.
     * Will throw an exception if file cannot be created.
     *
     * @param string $filename
     */
    public static function prepareDirectory($filename)
    {
        self::detectWriteIssues($filename);

        $fs = new Filesystem();

        $dirname = dirname($filename);

        if (!is_dir($dirname)) {
            $fs->mkdir($dirname, 0775);
        }
    }

    /**
     * Writes a file to the disk. Will throw an exception in case of write problems.
     * The file is first written to a temp file, then moved.
     *
     * @param string $filename
     * @param string $content
     */
    public static function writeFile($filename, $content) {
        self::prepareDirectory($filename);

        $fs = new Filesystem();
        $fs->dumpFile($filename, $content);
    }

    /**
     * Writes a PHP file that contains only a "return" statement with a variable.
     *
     * @param string $filename
     * @param mixed $variable Must be "var_export"able
     * @param string $comment Comment to add in the header of the file
     */
    public static function writePhpExportFile($filename, $variable, $comment = '') {
        $phpCode = "<?php\n";

        if ($comment) {
            $phpCode .= "/*\n".$comment."\n*/\n";
        }

        $phpCode .= "return ".var_export($variable, true);
        $phpCode .= "\n";

        self::writeFile($filename, $phpCode);
    }
}
