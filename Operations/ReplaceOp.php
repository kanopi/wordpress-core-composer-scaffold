<?php

namespace WordPress\Composer\Plugin\Scaffold\Operations;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use WordPress\Composer\Plugin\Scaffold\ScaffoldFilePath;
use WordPress\Composer\Plugin\Scaffold\ScaffoldOptions;

/**
 * Scaffold operation to copy or symlink from source to destination.
 *
 * @internal
 */
class ReplaceOp extends AbstractOperation {

  /**
   * Identifies Replace operations.
   */
  const ID = 'replace';

  /**
   * The relative path to the source file.
   *
   * @var \WordPress\Composer\Plugin\Scaffold\ScaffoldFilePath
   */
  protected $source;

  /**
   * Whether to overwrite existing files.
   *
   * @var bool
   */
  protected $overwrite;

  /**
   * Constructs a ReplaceOp.
   *
   * @param \WordPress\Composer\Plugin\Scaffold\ScaffoldFilePath $sourcePath
   *   The relative path to the source file.
   * @param bool $overwrite
   *   Whether to allow this scaffold file to overwrite files already at
   *   the destination. Defaults to TRUE.
   */
  public function __construct(ScaffoldFilePath $sourcePath, $overwrite = TRUE) {
    $this->source = $sourcePath;
    $this->overwrite = $overwrite;
  }

  /**
   * {@inheritdoc}
   */
  protected function generateContents() {
    return file_get_contents($this->source->fullPath());
  }

  /**
   * {@inheritdoc}
   */
  public function process(ScaffoldFilePath $destination, IOInterface $io, ScaffoldOptions $options) {
    $fs = new Filesystem();
    $destination_path = $destination->fullPath();
    // Do nothing if overwrite is 'false' and a file already exists at the
    // destination.
    if ($this->overwrite === FALSE && file_exists($destination_path)) {
      $interpolator = $destination->getInterpolator();
      $io->write($interpolator->interpolate("  - Skip <info>[dest-rel-path]</info> because it already exists and overwrite is <comment>false</comment>."));
      return new ScaffoldResult($destination, FALSE);
    }

    // Get rid of the destination if it exists, and make sure that
    // the directory where it's going to be placed exists.
    $fs->remove($destination_path);
    $fs->ensureDirectoryExists(dirname($destination_path));
    if ($options->symlink()) {
      return $this->symlinkScaffold($destination, $io);
    }
    return $this->copyScaffold($destination, $io);
  }

  /**
   * Copies the scaffold file.
   *
   * @param \WordPress\Composer\Plugin\Scaffold\ScaffoldFilePath $destination
   *   Scaffold file to process.
   * @param \Composer\IO\IOInterface $io
   *   IOInterface to writing to.
   *
   * @return \WordPress\Composer\Plugin\Scaffold\Operations\ScaffoldResult
   *   The scaffold result.
   */
  protected function copyScaffold(ScaffoldFilePath $destination, IOInterface $io) {
    $interpolator = $destination->getInterpolator();
    $this->source->addInterpolationData($interpolator);
    $success = file_put_contents($destination->fullPath(), $this->contents());
    if (!$success) {
      throw new \RuntimeException($interpolator->interpolate("Could not copy source file <info>[src-rel-path]</info> to <info>[dest-rel-path]</info>!"));
    }
    $io->write($interpolator->interpolate("  - Copy <info>[dest-rel-path]</info> from <info>[src-rel-path]</info>"));
    return new ScaffoldResult($destination, $this->overwrite);
  }

  /**
   * Symlinks the scaffold file.
   *
   * @param \WordPress\Composer\Plugin\Scaffold\ScaffoldFilePath $destination
   *   Scaffold file to process.
   * @param \Composer\IO\IOInterface $io
   *   IOInterface to writing to.
   *
   * @return \WordPress\Composer\Plugin\Scaffold\Operations\ScaffoldResult
   *   The scaffold result.
   */
  protected function symlinkScaffold(ScaffoldFilePath $destination, IOInterface $io) {
    $interpolator = $destination->getInterpolator();
    try {
      $fs = new Filesystem();
      $fs->relativeSymlink($this->source->fullPath(), $destination->fullPath());
    }
    catch (\Exception $e) {
      throw new \RuntimeException($interpolator->interpolate("Could not symlink source file <info>[src-rel-path]</info> to <info>[dest-rel-path]</info>!"), [], $e);
    }
    $io->write($interpolator->interpolate("  - Link <info>[dest-rel-path]</info> from <info>[src-rel-path]</info>"));
    return new ScaffoldResult($destination, $this->overwrite);
  }

}
