<?php

namespace WordPress\Composer\Plugin\Scaffold;

use Composer\Installer\PackageEvent;

/**
 * Interface for post package event listeners.
 *
 * @see \WordPress\Composer\Plugin\Scaffold\Handler::onPostPackageEvent
 *
 * @internal
 */
interface PostPackageEventListenerInterface {

  /**
   * Handles package events during a 'composer require' operation.
   *
   * @param \Composer\Installer\PackageEvent $event
   *   Composer package event sent on install/update/remove.
   */
  public function event(PackageEvent $event);

}
