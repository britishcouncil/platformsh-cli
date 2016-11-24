<?php

namespace Platformsh\Cli\Command\Drupal;

use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Helper\DrushHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DrupalSanitizeDbCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:db-sanitize')
         ->setAliases(array('db-sanitize'))
         ->setDescription('Sanitize the database');
    $this->addDirectoryArgument();
    $this->addExample("Sanitize database of a Drupal project", "/path/to/project");
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $project = $this->getSelectedProject();
    $wwwRoot = ($this->localProject->getLegacyProjectRoot() !== false) ? $this->localProject->getLegacyProjectRoot() . '/../www' : $this->getProjectRoot() . '/_www';

    /* @var DrushHelper $dh */
    $dh = $this->getHelper('drush');

    $dh->ensureInstalled();
    try {
      $this->stdErr->writeln("Sanitizing database for <info>" . $project->id . "</info> (" . $project->getProperty('title') . ")");
      $dh->execute([
        '-y',
        'sql-sanitize',
        '--sanitize-email="%name@example.com"'
      ], $wwwRoot, TRUE);
    }
    catch (\Exception $e) {
      echo $e->getMessage();
      return 1;
    }
    return 0;
  }
}
