<?php
namespace Harmony\Installer;

use Composer\Installer;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Factory;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Harmony\Services\FileService;

/**
 * This class is in charge of handling the installation of the Harmony framework in composer.
 * Harmony has a special type "harmony-framework" in composer.json,
 * This class will be called to handle specific actions.
 * In particular, it will run composer on composer-harmony-core.json.
 *
 * @author David Négrier
 */
class HarmonyFrameworkInstaller extends LibraryInstaller {

	/**
	 * This variable is set to true if we are in the process of installing mouf, using the
	 * HarmonyFrameworkInstaller. This is useful to disable the install process for Harmony inner packages.
	 *
	 * @var bool
	 */
	private static $isRunningHarmonyFrameworkInstaller = false;

	/**
	 * {@inheritDoc}
	 */
	public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
	{
		parent::update($repo, $initial, $target);

		$this->installHarmony();
		$this->dumpPhpBinaryFile();
	}

	/**
	 * {@inheritDoc}
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
	{
		parent::install($repo, $package);

		$this->installHarmony();
	}

	private function installHarmony() {
		self::$isRunningHarmonyFrameworkInstaller = true;

		$oldWorkingDirectory = getcwd();
		chdir("vendor/harmony/harmony");

		// Now, let's try to run Composer recursively on composer-harmony.json...
		$composer = Factory::create($this->io, 'composer-harmony-core.json');
		$install = Installer::create($this->io, $composer);

		// Let's get some speed by optimizing Harmony's autoloader... always.
		$install->setOptimizeAutoloader(true);

		$result = $install->run();

		chdir($oldWorkingDirectory);

		self::$isRunningHarmonyFrameworkInstaller = false;

		// The $result value has changed in Composer during development.
		// In earlier version, "false" meant probleam
		// Now, 0 means "OK".
		// Check disabled because we cannot rely on Composer on this one.
		/*if (!$result) {
			throw new \Exception("An error occured while running Harmony2 installer.");
		}*/
	}

	/**
	 * Writes a "vendor/mouf/mouf/mouf/no_commit/php_binary.php" that will contain the path to the Harmony installer.
	 * @throws \Exception
	 * @throws HarmonyException
	 */
	private function dumpPhpBinaryFile() {
		if (!PHP_BINARY) {
			return;
		}

		$phpBinaryFile = 'vendor/harmony/harmony/harmony/no_commit/php_binary.php';

		FileService::writePhpExportFile($phpBinaryFile, PHP_BINARY);
	}

	/**
	 * {@inheritDoc}
	 */
	public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
	{
		parent::uninstall($repo, $package);
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType)
	{
		return 'harmony-framework' === $packageType;
	}

	/**
	 * Returns true if we are in the process of installing mouf, using the
	 * HarmonyFrameworkInstaller. This is useful to disable the install process for Harmony inner packages.
	 */
	public static function getIsRunningHarmonyFrameworkInstaller() {
		return self::$isRunningHarmonyFrameworkInstaller;
	}
}
