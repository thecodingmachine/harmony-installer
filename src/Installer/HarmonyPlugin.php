<?php
namespace Harmony\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Plugin\PluginInterface;
use Composer\Package\CompletePackage;
use Composer\Package\RootPackage;
use Composer\Json\JsonFile;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\EventSubscriberInterface;
use Harmony\Services\ClassExplorer;
use Harmony\Services\ClassMapService;
use Harmony\Services\FileService;
use Harmony\Services\ReflectionExporter;
use Seld\JsonLint\ParsingException;

/**
 * RootContainer Installer for Composer.
 * (based on RobLoach's code for ComponentInstaller)
 */
class HarmonyPlugin implements PluginInterface, EventSubscriberInterface
{

    public function activate(Composer $composer, IOInterface $io)
    {
        $harmonyFrameworkInstaller = new HarmonyFrameworkInstaller($io, $composer);
        $composer->getInstallationManager()
                ->addInstaller($harmonyFrameworkInstaller);
    }

    /**
     * Let's register the harmony dependencies update events.
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => array(
                array('postInstall', 0),
            ),
            ScriptEvents::POST_UPDATE_CMD => array(
                array('postUpdate', 0),
            ),
            ScriptEvents::POST_AUTOLOAD_DUMP => array(
                array('postAutoloadDump', 0),
            ),
        );
    }

    /**
     * Script callback; Acted on after install.
     */
    public function postInstall(Event $event)
    {
        self::processHarmonyDependencies($event, 'install');
    }

    /**
     * Script callback; Acted on after update.
     */
    public function postUpdate(Event $event)
    {
        self::processHarmonyDependencies($event, 'update');
    }

    /**
     * Script callback; Acted on after dumpautoload.
     */
    public function postAutoloadDump(Event $event)
    {
        self::generateClassMapCache($event);
    }

    /**
     * Script callback; Acted on after the autoloader is dumped.
     *
     * @param  Event      $event
     * @param  string     $action update or install
     * @throws \Exception
     */
    private static function processHarmonyDependencies(Event $event, $action)
    {
        if (!is_dir('vendor/harmony/harmony')) {
            // If the vendor/harmony/harmony directory does not exist, it means we are
            // running composer-harmony.sh update
            // Let's ignore this process.
            return;
        }

        // Let's trigger EmbeddedComposer.
        $composer = $event->getComposer();
        $io = $event->getIO();
        $io->write('');
        $io->write('Updating Harmony dependencies');
        $io->write('=============================');

        // Let's start by scanning all packages for a composer-harmony.json file.
        $packagesList = $composer->getRepositoryManager()->getLocalRepository()
                ->getCanonicalPackages();
        $packagesList[] = $composer->getPackage();

        $globalHarmonyComposer = [];

        foreach ($packagesList as $package) {
            /* @var $package PackageInterface */
            if ($package instanceof CompletePackage) {
                if ($package instanceof RootPackage) {
                    $targetDir = "";
                } else {
                    $targetDir = "vendor/".$package->getName()."/";
                }
                if ($package->getTargetDir()) {
                    $targetDir .= $package->getTargetDir()."/";
                }

                $composerFile = $targetDir."composer-harmony.json";
                if (file_exists($composerFile) && is_readable($composerFile)) {
                    $harmonyData = self::loadComposerHarmonyFile(
                            $composerFile, '../../../'.$targetDir);
                    $globalHarmonyComposer = array_merge_recursive(
                            $globalHarmonyComposer, $harmonyData);
                }
            }
        }

        // Finally, let's merge the extra.container-interop section of the composer-harmony-core.json file
        if (file_exists("vendor/harmony/harmony/composer-harmony-core.json")) {
            $composerHarmony = self::loadComposerHarmonyFile("vendor/harmony/harmony/composer-harmony-core.json", "");
            $composerHarmonySection = [ "extra" => [ "framework-interop" => $composerHarmony['extra']['framework-interop'] ] ];

            // Let's also add a dependency to framework-interop autoinstaller plugin.
            $composerHarmonySection['require']["framework-interop/module-autoinstaller"] = "~1.0";

            $globalHarmonyComposer = array_merge_recursive(
                    $globalHarmonyComposer, $composerHarmonySection);
        }

        $targetHarmonyFile = 'composer-harmony-dependencies.json';

        if (file_exists($targetHarmonyFile) && !is_writable($targetHarmonyFile)) {
            $io
                    ->write(
                            "<error>Error, unable to write file '"
                                    .$targetHarmonyFile
                                    ."'. Please check file-permissions.</error>");

            return;
        }

        if (!file_exists($targetHarmonyFile)
                && !is_writable(dirname($targetHarmonyFile))) {
            $io
                    ->write(
                            "<error>Error, unable to write a file in directory '"
                                    .dirname($targetHarmonyFile)
                                    ."'. Please check file-permissions.</error>");

            return;
        }

        if ($globalHarmonyComposer) {
            $result = file_put_contents($targetHarmonyFile,
                    json_encode($globalHarmonyComposer,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($result == false) {
                throw new \Exception(
                        "An error occured while writing file '"
                                .$targetHarmonyFile."'");
            }

            // Run command
            $oldCwd = getcwd();
            chdir('vendor/harmony/harmony');
            $commandLine = PHP_BINARY." console.php composer:$action";
            passthru($commandLine);
            chdir($oldCwd);
        } else {
            $io->write("<info>No harmony dependencies to $action</info>");
            if (file_exists($targetHarmonyFile)) {
                $result = unlink($targetHarmonyFile);
                if ($result == false) {
                    throw new \Exception(
                            "An error occured while deleting file '"
                            .$targetHarmonyFile."'");
                }
            }
            $lockFile = substr($targetHarmonyFile, 0 -4).".lock";
            if (file_exists($lockFile)) {
                $result = unlink($lockFile);
                if ($result == false) {
                    throw new \Exception(
                            "An error occured while deleting file '"
                            .$lockFile."'");
                }
            }
        }
    }

    /**
     * Loads a harmony file, returns the array, with autoloads modified to fit the directory.
     *
     * @param string $composerHarmonyFile
     */
    private static function loadComposerHarmonyFile($composerHarmonyFile,
            $targetDir)
    {
        $configJsonFile = new JsonFile($composerHarmonyFile);

        try {
            $configJsonFile->validateSchema(JsonFile::LAX_SCHEMA);
            $localConfig = $configJsonFile->read();
        } catch (ParsingException $e) {
            throw new \Exception(
                    "Error while parsing file '".$composerHarmonyFile."'",
                    0, $e);
        }

        foreach (['autoload', 'autoload-dev'] as $autoloadType) {
            foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $mode) {
                if (isset($localConfig[$autoloadType][$mode])) {
                    $localConfig[$autoloadType][$mode] = array_map(
                            function ($path) use ($targetDir) {
                                if (!is_array($path)) {
                                    $path = [$path];
                                }

                                return array_map(
                                        function ($pathItem) use ($targetDir) {
                                            return $targetDir.$pathItem;
                                        }, $path);
                            }, $localConfig[$autoloadType][$mode]);
                }
            }
        }

        // Let's wrap all container-factory sections into arrays so they get correctly merged.
        if (isset($localConfig["extra"]["container-interop"]["container-factory"])) {
            $factorySection = $localConfig["extra"]["container-interop"]["container-factory"];
            if (!is_array($factorySection) || self::isAssoc($factorySection)) {
                $localConfig["extra"]["container-interop"]["container-factory"] = [ $factorySection ];
            }
        }

        // Let's wrap all framework-factory sections into arrays so they get correctly merged.
        if (isset($localConfig["extra"]["framework-interop"]["module-factory"])) {
            $factorySection = $localConfig["extra"]["framework-interop"]["module-factory"];
            if (!is_array($factorySection) || self::isAssoc($factorySection)) {
                $localConfig["extra"]["framework-interop"]["module-factory"] = [ $factorySection ];
            }
        }

        return $localConfig;
    }

    /**
     * Returns if an array is associative or not.
     *
     * @param  array   $arr
     * @return boolean
     */
    private static function isAssoc($arr)
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Generates the cache containing the class map and errors including files for all dependencies.
     *
     * @param Event $event
     */
    private static function generateClassMapCache(Event $event)
    {
        // Let's get the autoload file path
        $config = $event->getComposer()->getConfig();
        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));

        // Let's get started by requiring the project's autoload (to get access to Symfony filesystem)
        require_once $vendorPath.'/autoload.php';

        $io = $event->getIO();
        $io->write('');
        $io->write('Generating Harmony classmap');

        if (file_exists($vendorPath.'/harmony/harmony/generated/vendorClassMap.php')) {
            $oldClassMap = include $vendorPath.'/harmony/harmony/generated/vendorClassMap.php';
            $oldClassMap = $oldClassMap['classMap'];
        } else {
            $oldClassMap = array();
        }

        // Let's get all classes
        $classMapService = new ClassMapService($event->getComposer());
        $classMap = $classMapService->getClassMap(ClassMapService::MODE_DEPENDENCIES_CLASSES, $oldClassMap);

        if ($io->isVerbose()) {
            $io->write("  Analyzing classes to filter autoloadable classes.");
        }

        // Let's filter these classes
        $classExplorer = new ClassExplorer();
        $results = $classExplorer->analyze($classMap, $vendorPath.'/autoload.php');

        if ($io->isVerbose()) {
            $io->write("  Analysis finished.");
        }

        FileService::writePhpExportFile($vendorPath.'/harmony/harmony/generated/vendorClassMap.php', $results);

        if ($io->isVerbose()) {
            $io->write("  Dumping reflection data.");
        }

        $reflectionExporter = new ReflectionExporter();
        $reflectionData = $reflectionExporter->getReflectionData($results['classMap'], $vendorPath.'/autoload.php');

        FileService::writePhpExportFile($vendorPath.'/harmony/harmony/generated/vendorReflectionData.php', $reflectionData);

        if ($io->isVerbose()) {
            $io->write("  Process finished.");
        }
    }
}
