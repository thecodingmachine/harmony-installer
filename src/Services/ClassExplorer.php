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
 * This class is in charge of exploring a list of classes and returning what classes can be
 * included, and what classes can't be included (because they contain errors or are not PSR-1 compliant).
 *
 * @author David Negrier
 */
class ClassExplorer
{

    /**
     * @param  array            $classMap     ClassMap to anaylze. Keys = class name, Value = file name.
     * @param  string           $autoloadFile Path to the autoload file (usually vendor/autoload.php)
     * @throws HarmonyException
     * @throws \Exception
     */
    public function analyze(array $classMap, $autoloadFile)
    {
        $forbiddenClasses = [];

        do {
            $notYetAnalysedClassMap = $classMap;
            $nbRun = 0;
            while (!empty($notYetAnalysedClassMap)) {
                $this->analysisResponse = $this->runAnalyzeIncludes($notYetAnalysedClassMap, $autoloadFile);
                //$this->analysisResponse = MoufReflectionProxy::analyzeIncludes2($this->selfEdit, $notYetAnalysedClassMap);
                $nbRun++;
                $startupPos = strpos($this->analysisResponse, "FDSFZEREZ_STARTUP\n");
                if ($startupPos === false) {
                    // It seems there is a problem running the script, let's throw an exception
                    throw new HarmonyException("Error while running classes analysis: ".$this->analysisResponse);
                }

                $this->analysisResponse = substr($this->analysisResponse, $startupPos+18);

                while (true) {
                    $beginMarker = $this->trimLine();
                    if ($beginMarker == "SQDSG4FDSE3234JK_ENDFILE") {
                        // We are finished analysing the file! Yeah!
                        break;
                    } elseif ($beginMarker != "X4EVDX4SEVX5_BEFOREINCLUDE") {
                        throw new \Exception("Strange behaviour while importing classes. Begin marker: ".$beginMarker);
                    }

                    $analyzedClassName = $this->trimLine();

                    // Now, let's see if the end marker is right after the begin marker...
                    $endMarkerPos = strpos($this->analysisResponse, "DSQRZREZRZER__AFTERINCLUDE\n");
                    if ($endMarkerPos !== 0) {
                        // There is a problem...
                        if ($endMarkerPos === false) {
                            // An error occured:
                            $forbiddenClasses[$analyzedClassName] = $this->analysisResponse;
                            unset($notYetAnalysedClassMap[$analyzedClassName]);
                            break;
                        } else {
                            $forbiddenClasses[$analyzedClassName] = substr($this->analysisResponse, 0, $endMarkerPos);
                            $this->analysisResponse = substr($this->analysisResponse, $endMarkerPos);
                        }
                    }
                    $this->trimLine();

                    unset($notYetAnalysedClassMap[$analyzedClassName]);
                }
            }

            foreach ($forbiddenClasses as $badClass => $errorMessage) {
                unset($classMap[$badClass]);
            }

            if ($nbRun <= 1) {
                break;
            }

            // If we arrive here, we managed to detect a number of files to exclude.
            // BUT, the complete list of file has never been tested together.
            // and sometimes, a class included can trigger errors if another class is included at the same time
            // (most of the time, when a require is performed on a file already loaded, triggering a "class already defined" error.
        } while (true);

        $result = [
            'classMap' => $classMap,
            'errors' => $forbiddenClasses,
        ];

        return $result;
    }

    /**
     * The text response from the analysis script.
     * @var string
     */
    private $analysisResponse;

    /**
     * Trim the first line from $analysisResponse and returns it.
     */
    private function trimLine()
    {
        $newLinePos = strpos($this->analysisResponse, "\n");

        if ($newLinePos === false) {
            throw new \Exception("End of file reached!");
        }

        $line = substr($this->analysisResponse, 0, $newLinePos);
        $this->analysisResponse = substr($this->analysisResponse, $newLinePos + 1);

        return $line;
    }

    /**
     * @param array  $classMap     ClassMap to anaylze. Keys = class name, Value = file name.
     * @param string $autoloadFile Path to the autoload file (usually vendor/autoload.php)
     */
    private function runAnalyzeIncludes(array $classMap, $autoloadFile)
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

			echo "FDSFZEREZ_STARTUP\n";
			if (is_array($classMap)) {
				foreach ($classMap as $className => $fileName) {
					//if (!isset($forbiddenClasses[$className])) {
						echo "X4EVDX4SEVX5_BEFOREINCLUDE\n";
					echo $className."\n";
						//$refClass = new MoufReflectionClass($className);
						$refClass = new \ReflectionClass($className);

						// Let\'s also serialize to check all the parameters, fields, etc...
						// Note: disabled for optimization purposes
						//$refClass->toJson();

						// If we manage to get here, there has been no error loading $className. Youhou, let\'s output an encoded "OK"
						echo "DSQRZREZRZER__AFTERINCLUDE\n";
					//}
				}
			}

			// Another line breaker to mark the end of class loading. If we make it here, everything went according to plan.
			echo "SQDSG4FDSE3234JK_ENDFILE\n";
			';

        $process = new PhpProcess($code);

        // Let's increase the performance as much as possible by disabling xdebug.
        // Also, let's set opcache.revalidate_freq to 0 to avoid caching issues with generated files.
        // Finally, let's redirect STDERR to STDOUT
        $process->setPhpBinary(PHP_BINARY." -d xdebug.remote_autostart=0 -d xdebug.remote_enable=0 -d opcache.revalidate_freq=0 2>&1");
        $process->run();

        $output = $process->getOutput();

        return $output;
    }
}
