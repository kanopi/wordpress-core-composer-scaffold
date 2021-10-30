<?php

namespace WordPress\Composer\Plugin\Scaffold;

use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use WordPress\Composer\Plugin\Scaffold\Operations\OperationData;
use WordPress\Composer\Plugin\Scaffold\Operations\OperationFactory;
use WordPress\Composer\Plugin\Scaffold\Operations\ScaffoldFileCollection;

/**
 * Core class of the plugin.
 *
 * Contains the primary logic which determines the files to be fetched and
 * processed.
 *
 * @internal
 */
class Handler {

  /**
   * Composer hook called before scaffolding begins.
   */
  const PRE_WORDPRESS_SCAFFOLD_CMD = 'pre-wordpress-scaffold-cmd';

  /**
   * Composer hook called after scaffolding completes.
   */
  const POST_WORDPRESS_SCAFFOLD_CMD = 'post-wordpress-scaffold-cmd';

  /**
   * The Composer service.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * Composer's I/O service.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * The scaffold options in the top-level composer.json's 'extra' section.
   *
   * @var \WordPress\Composer\Plugin\Scaffold\ManageOptions
   */
  protected $manageOptions;

  /**
   * The manager that keeps track of which packages are allowed to scaffold.
   *
   * @var \WordPress\Composer\Plugin\Scaffold\AllowedPackages
   */
  protected $manageAllowedPackages;

  /**
   * The list of listeners that are notified after a package event.
   *
   * @var \WordPress\Composer\Plugin\Scaffold\PostPackageEventListenerInterface[]
   */
  protected $postPackageListeners = [];

  /**
   * Handler constructor.
   *
   * @param \Composer\Composer $composer
   *   The Composer service.
   * @param \Composer\IO\IOInterface $io
   *   The Composer I/O service.
   */
  public function __construct(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
    $this->manageOptions = new ManageOptions($composer);
    $this->manageAllowedPackages = new AllowedPackages($composer, $io, $this->manageOptions);
  }

  /**
   * Registers post-package events if the 'require' command was called.
   */
  public function requireWasCalled() {
    // In order to differentiate between post-package events called after
    // 'composer require' vs. the same events called at other times, we will
    // only install our handler when a 'require' event is detected.
    $this->postPackageListeners[] = $this->manageAllowedPackages;
  }

  /**
   * Posts package command event.
   *
   * We want to detect packages 'require'd that have scaffold files, but are not
   * yet allowed in the top-level composer.json file.
   *
   * @param \Composer\Installer\PackageEvent $event
   *   Composer package event sent on install/update/remove.
   */
  public function onPostPackageEvent(PackageEvent $event) {
    foreach ($this->postPackageListeners as $listener) {
      $listener->event($event);
    }
  }

  /**
   * Creates scaffold operation objects for all items in the file mappings.
   *
   * @param \Composer\Package\PackageInterface $package
   *   The package that relative paths will be relative from.
   * @param array $package_file_mappings
   *   The package file mappings array keyed by destination path and the values
   *   are operation metadata arrays.
   *
   * @return \WordPress\Composer\Plugin\Scaffold\Operations\OperationInterface[]
   *   A list of scaffolding operation objects
   */
  protected function createScaffoldOperations(PackageInterface $package, array $package_file_mappings) {
    $scaffold_op_factory = new OperationFactory($this->composer);
    $scaffold_ops = [];
    foreach ($package_file_mappings as $dest_rel_path => $data) {
      $operation_data = new OperationData($dest_rel_path, $data);
      $scaffold_ops[$dest_rel_path] = $scaffold_op_factory->create($package, $operation_data);
    }
    return $scaffold_ops;
  }

  /**
   * Copies all scaffold files from source to destination.
   */
  public function scaffold() {
    // Recursively get the list of allowed packages. Only allowed packages
    // may declare scaffold files. Note that the top-level composer.json file
    // is implicitly allowed.
    $allowed_packages = $this->manageAllowedPackages->getAllowedPackages();
    if (empty($allowed_packages)) {
      $this->io->write("Nothing scaffolded because no packages are allowed in the top-level composer.json file.");
      return;
    }

    // Call any pre-scaffold scripts that may be defined.
    $dispatcher = new EventDispatcher($this->composer, $this->io);
    $dispatcher->dispatch(self::PRE_WORDPRESS_SCAFFOLD_CMD);

    // Fetch the list of file mappings from each allowed package and normalize
    // them.
    $file_mappings = $this->getFileMappingsFromPackages($allowed_packages);

    $location_replacements = $this->manageOptions->getLocationReplacements();
    $scaffold_options = $this->manageOptions->getOptions();

    // Create a collection of scaffolded files to process. This determines which
    // take priority and which are combined.
    $scaffold_files = new ScaffoldFileCollection($file_mappings, $location_replacements);

    // Get the scaffold files whose contents on disk match what we are about to
    // write. We can remove these from consideration, as rewriting would be a
    // no-op.
    $unchanged = $scaffold_files->checkUnchanged();
    $scaffold_files->filterFiles($unchanged);

    // Process the list of scaffolded files.
    $scaffold_results = $scaffold_files->processScaffoldFiles($this->io, $scaffold_options);

    // Generate an autoload file in the document root that includes the
    // autoload.php file in the vendor directory, wherever that is. WordPress
    // requires this in order to easily locate relocated vendor dirs.
    $web_root = $this->manageOptions->getOptions()->getLocation('web-root');
    if (!GenerateAutoloadReferenceFile::autoloadFileCommitted($this->io, $this->rootPackageName(), $web_root)) {
      $scaffold_results[] = GenerateAutoloadReferenceFile::generateAutoload($this->io, $this->rootPackageName(), $web_root, $this->getVendorPath());
    }

    // Add the managed scaffold files to .gitignore if applicable.
    $gitIgnoreManager = new ManageGitIgnore($this->io, getcwd());
    $gitIgnoreManager->manageIgnored($scaffold_results, $scaffold_options);

    // Call post-scaffold scripts.
    $dispatcher->dispatch(self::POST_WORDPRESS_SCAFFOLD_CMD);
  }

  /**
   * Gets the path to the 'vendor' directory.
   *
   * @return string
   *   The file path of the vendor directory.
   */
  protected function getVendorPath() {
    $vendor_dir = $this->composer->getConfig()->get('vendor-dir');
    $filesystem = new Filesystem();
    return $filesystem->normalizePath(realpath($vendor_dir));
  }

  /**
   * Gets a consolidated list of file mappings from all allowed packages.
   *
   * @param \Composer\Package\Package[] $allowed_packages
   *   A multidimensional array of file mappings, as returned by
   *   self::getAllowedPackages().
   *
   * @return \WordPress\Composer\Plugin\Scaffold\Operations\OperationInterface[][]
   *   An array of destination paths => scaffold operation objects.
   */
  protected function getFileMappingsFromPackages(array $allowed_packages) {
    $file_mappings = [];
    foreach ($allowed_packages as $package_name => $package) {
      $file_mappings[$package_name] = $this->getPackageFileMappings($package);
    }
    return $file_mappings;
  }

  /**
   * Gets the array of file mappings provided by a given package.
   *
   * @param \Composer\Package\PackageInterface $package
   *   The Composer package from which to get the file mappings.
   *
   * @return \WordPress\Composer\Plugin\Scaffold\Operations\OperationInterface[]
   *   An array of destination paths => scaffold operation objects.
   */
  protected function getPackageFileMappings(PackageInterface $package) {
    $options = $this->manageOptions->packageOptions($package);
    if ($options->hasFileMapping()) {
      return $this->createScaffoldOperations($package, $options->fileMapping());
    }

    if ($package->getName() == 'kanopi/wordpress-core') {
      // Create Default setup.
      return $this->createScaffoldOperations($package, $this->createPackageFileMappings($package));
    }

    // Warn the user if they allow a package that does not have any scaffold
    // files. We will ignore wordpress/core, though, as it is implicitly allowed,
    // but might not have scaffold files.
    if (!$options->hasAllowedPackages() && ($package->getName() != 'wordpress/core')) {
      $this->io->writeError("The allowed package {$package->getName()} does not provide a file mapping for Composer Scaffold.");
    }
    return [];
  }

  /**
   * Get all of the files for the provided directory.
   * 
   * @param string $dir
   *   The path to search for files.
   * @param string[] $results
   *   The results array used for storing the files recursivley.
   * 
   * @return string[]
   *   The files for the provided directory.
   */
  protected function getDirContents($dir, &$results = []) {
    $files = scandir($dir);
    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
            $results[] = $path;
        } else if ($value != "." && $value != "..") {
            $this->getDirContents($path, $results);
        }
    }
    return $results;
  }

  /**
   * Generate the default file mappings for the provided packages.
   * 
   * @param \Composer\Package\PackageInterface $package
   *   The Composer package from which to get the file mappings.
   * 
   * @return string[]
   *   The files returned in an array.
   */
  protected function createPackageFileMappings(PackageInterface $package) {
    $files = $this->getPackageFiles($package);

    $mappings = [];

    // Assume everything should be defaulted to the [web-root].
    foreach ($files AS $file) {
      $mappings['[web-root]' . DIRECTORY_SEPARATOR . $file] = $file;
    }

    return $mappings;
  }

  /** 
   * Return the path for the provided package.
   * 
   * @param \Composer\Package\PackageInterface $package
   *   The Composer package from which to get the file mappings.
   * 
   * @return string
   *   The path of the installed package.
   */
  protected function getPackagePath(PackageInterface $package) {
    $installationManager = $this->composer->getInstallationManager();
    return $installationManager->getInstallPath($package);
  }

  /**
   * Return all of the files for the provided package.
   * 
   * @param \Composer\Package\PackageInterface $package
   *   The Composer package from which to get the file mappings.
   * 
   * @return string[]
   *   The files for the provided directory.
   */
  protected function getPackageFiles(PackageInterface $package) {
    $files = $this->getDirContents($this->getPackagePath($package));

    array_walk($files, function(&$item, $key, $packagePath) {
      $item = str_ireplace($packagePath . DIRECTORY_SEPARATOR, '', $item);
    }, $this->getPackagePath($package));

    return $files;
  }

  /**
   * Gets the root package name.
   *
   * @return string
   *   The package name of the root project
   */
  protected function rootPackageName() {
    $root_package = $this->composer->getPackage();
    return $root_package->getName();
  }

}
