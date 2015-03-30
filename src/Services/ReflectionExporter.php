<?php
/*
 * This file is part of the Mouf core package.
 *
 * (c) 2012 David Negrier <david@mouf-php.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */
namespace Harmony\Services;

use Harmony\HarmonyException;
use Mouf\Reflection\MoufReflectionProxy;
use Symfony\Component\Process\PhpProcess;

/**
 * This class is in charge of exporting Reflection data to a file that can be later used to access reflection data
 * from another scope (where the classes analyzed are no more available).
 *
 * @author David Negrier
 */
class ReflectionExporter
{

    public function getReflectionData(array $classMap, $autoloadFile)
    {
        return $this->runReflectionExporter($classMap, $autoloadFile);
    }

    /**
     * @param array  $classMap     ClassMap to anaylze. Keys = class name, Value = file name.
     * @param string $autoloadFile Path to the autoload file (usually vendor/autoload.php)
     */
    private function runReflectionExporter(array $classMap, $autoloadFile)
    {
        $autoloadFileFullPath = realpath($autoloadFile);

        $code = '<?php
            require_once "'.$autoloadFileFullPath.'";

			// Disable output buffering
			while (ob_get_level() != 0) {
				ob_end_clean();
			}


			ini_set("display_errors", 1);
			// Add E_ERROR to error reporting if it is not already set
			error_reporting(E_ERROR | error_reporting());

			$classMap = '.var_export($classMap, true).';

			$reflectionData = [];
            foreach ($classMap as $className => $arr) {
                $reflectionClass = new \ReflectionClass($className);
                $interfaces = $reflectionClass->getInterfaceNames();
                $parentClasses = [];
                $parentClass = $reflectionClass;
                while ($parentClass = $parentClass->getParentClass()) {
                    $parentClasses[] = $parentClass->getName();
                }

                $reflectionData[$className] = [
                    "parents" => $parentClasses,
                    "interfaces" => $interfaces
                ];
            }

            echo json_encode($reflectionData);
			';

        $process = new PhpProcess($code);

        // Let's increase the performance as much as possible by disabling xdebug.
        // Also, let's set opcache.revalidate_freq to 0 to avoid caching issues with generated files.
        // Finally, let's redirect STDERR to STDOUT
        $process->setPhpBinary(PHP_BINARY." -d xdebug.remote_autostart=0 -d xdebug.remote_enable=0 -d opcache.revalidate_freq=0 2>&1");
        $process->run();

        $output = $process->getOutput();

        $errorOutput = $process->getErrorOutput();

        if ($errorOutput) {
            throw new \RuntimeException('Error triggered while exporting reflection data: '.$errorOutput);
        }

        $result = json_decode($output, true);
        if ($result === false) {
            throw new \RuntimeException('Error triggered while exporting reflection data: '.$output);
        }

        return $result;
    }
}
