<?php

namespace WordPress\Composer\Plugin\Scaffold;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The "wordpress:scaffold" command class.
 *
 * Manually run the scaffold operation that normally happens after
 * 'composer install'.
 *
 * @internal
 */
class ComposerScaffoldCommand extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('wordpress:scaffold')
      ->setAliases(['scaffold'])
      ->setDescription('Update the WordPress scaffold files.')
      ->setHelp(
        <<<EOT
The <info>wordpress:scaffold</info> command places the scaffold files in their
respective locations according to the layout stipulated in the composer.json
file.

<info>php composer.phar wordpress:scaffold</info>

It is usually not necessary to call <info>wordpress:scaffold</info> manually,
because it is called automatically as needed, e.g. after an <info>install</info>
or <info>update</info> command. Note, though, that only packages explicitly
allowed to scaffold in the top-level composer.json will be processed by this
command.

For more information, see https://www.wordpress.org/docs/develop/using-composer/using-wordpresss-composer-scaffold.
EOT
            );

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $handler = new Handler($this->getComposer(), $this->getIO());
    $handler->scaffold();
    return 0;
  }

}
