<?php 
namespace Harmony\Services;

use Composer\Autoload\ClassMapGenerator;
use Composer\EventDispatcher\EventDispatcher;

use Composer\IO\NullIO;
use Composer\Package\Link;

use Symfony\Component\Console\Application as BaseApplication;
use Composer\Repository\PlatformRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Composer\Command;
use Composer\Command\Helper\DialogHelper;
use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;
use Composer\Util\ErrorHandler;
use Composer\Repository\CompositeRepository;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Filesystem;
use Composer\Package\CompletePackageInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Installer;

// TODO: change namespace to Harmony\Services

/**
 * A service in charge of generating a class map.
 * 
 * @author David Négrier
 */
class ClassMapService {

	const MODE_ALL_CLASSES = 1;
	const MODE_APPLICATION_CLASSES = 2;
	const MODE_DEPENDENCIES_CLASSES = 3;

	/**
	 * @var Composer
	 */
	protected $composer;
	
	protected $selfEdit;
	
	public function __construct(Composer $composer) {
		$this->composer = $composer;
	}

	/**
	 * Returns the classmap array.
	 * This map associates the name of the classes and the PHP file they are declared in.
	 *
	 * @param int $mode One of ClassMapService::MODE_ALL_CLASSES, ClassMapService::MODE_APPLICATION_CLASSES or ClassMapService::MODE_DEPENDENCIES_CLASSES
	 * @return array <string, string>
	 */
	public function getClassMap($mode) {
		$time_start = microtime(true);

		$dispatcher = new EventDispatcher($this->composer, new NullIO());
		$autoloadGenerator = new \Composer\Autoload\AutoloadGenerator($dispatcher);

		if ($mode === self::MODE_ALL_CLASSES || $mode === self::MODE_DEPENDENCIES_CLASSES) {
			$localRepos = new CompositeRepository(array($this->composer->getRepositoryManager()->getLocalRepository()));
			$packages = $localRepos->getPackages();
		} else {
			$packages = [];
		}

		$installationManager = $this->composer->getInstallationManager();

		$package = $this->composer->getPackage();
		$config = $this->composer->getConfig();
		
		$packageMap = $autoloadGenerator->buildPackageMap($installationManager, $package, $packages);

		if ($mode === self::MODE_DEPENDENCIES_CLASSES) {
			// Remove first element from packageMap (it is the local package)
			array_shift($packageMap);
		}

		$autoloads = $autoloadGenerator->parseAutoloads($packageMap, $package);

		
		$targetDir = "composer";
		
		$filesystem = new Filesystem();
		$filesystem->ensureDirectoryExists($config->get('vendor-dir'));
		$vendorPath = strtr(realpath($config->get('vendor-dir')), '\\', '/');
		$targetDir = $vendorPath.'/'.$targetDir;
		$filesystem->ensureDirectoryExists($targetDir);
		$basePath = $filesystem->normalizePath(realpath(getcwd()));

		// flatten array
		$classMap = array();

		// TODO: performance could be improved with a cache system that tracks file modification time and that
		// only explores files that have not yet been explored....

		// Scan the PSR-0/4 directories for class files, and add them to the class map
		foreach (array('psr-0', 'psr-4') as $psrType) {
			foreach ($autoloads[$psrType] as $namespace => $paths) {
				foreach ($paths as $dir) {
					$dir = $filesystem->normalizePath($filesystem->isAbsolutePath($dir) ? $dir : $basePath.'/'.$dir);
					if (!is_dir($dir)) {
						continue;
					}
					$whitelist = sprintf(
							'{%s/%s.+(?<!(?<!/)Test\.php)$}',
							preg_quote($dir),
							($psrType === 'psr-0' && strpos($namespace, '_') === false) ? preg_quote(strtr($namespace, '\\', '/')) : ''
					);
					foreach (ClassMapGenerator::createMap($dir, $whitelist) as $class => $path) {
						if ('' === $namespace || 0 === strpos($class, $namespace)) {
							if (!isset($classMap[$class])) {
								$classMap[$class] = $path;
							}
						}
					}
				}
			}
		}
		
		
			
		$autoloads['classmap'] = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($autoloads['classmap']));
		foreach ($autoloads['classmap'] as $dir) {
			$dir = $filesystem->normalizePath($filesystem->isAbsolutePath($dir) ? $dir : $basePath.'/'.$dir);
			foreach (ClassMapGenerator::createMap($dir) as $class => $path) {
				$classMap[$class] = $path;
			}
		}
		
		// FIXME: $autoloads['files'] seems ignored

		$time_end = microtime(true);
		echo "Autoload time: ".($time_end-$time_start)." s\n";

		return $classMap;
	}
}
