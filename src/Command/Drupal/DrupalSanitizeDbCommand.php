<?php

namespace Platformsh\Cli\Command\Drupal;

use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DrupalSanitizeDbCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:db-sanitize')
         ->setAliases(array('db-sanitize'))
         ->setDescription('Sanitize the database');
    $this->addAppOption();
    $this->addDirectoryArgument();
    $this->addExample("Sanitize the database of a Drupal project", "/path/to/project");
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $apps = $input->getOption('app');

    // Do it for each Drupal application in the project.
    foreach (LocalApplication::getApplications($this->getProjectRoot(), $this->config()) as $app) {
      // If --app was specified, only allow those apps.
      if ($apps && !in_array($app->getId(), $apps)) {
        continue;
      }
      // Also, only allow Drupal apps.
      if ($app->getConfig()['build']['flavor'] == 'drupal') {
        $this->_execute($input, $app);
      }
    }
  }

  /**
   * Helper function.
   */
  protected function _execute(InputInterface $input, LocalApplication $app) {
    $project = $this->getSelectedProject();

    // Work out the 'www' directory.
    $wwwRoot = ($this->getService('local.project')->getLegacyProjectRoot() !== FALSE) ?
      $this->getService('local.project')->getLegacyProjectRoot() . '/../www' :
      $this->getProjectRoot() . '/_www';
    $wwwRoot .= '/' . $app->getId();

    $dh = $this->getService('drush');

    $dh->ensureInstalled();
    try {
      $this->stdErr->writeln("Sanitizing local database for <info>" . $project->id . '-' . $app->getId() . "</info>");
      $dh->execute([
        '-y',
        'sql-sanitize',
        '--sanitize-email="%name@example.com"',
        '--sanitize-password=password'
      ], $wwwRoot, TRUE);
    }
    catch (\Exception $e) {
      echo $e->getMessage();
      return 1;
    }
    return 0;
  }
}
