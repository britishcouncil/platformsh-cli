<?php

namespace Platformsh\Cli\Command\Drupal;

use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Helper\DrushHelper;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DrupalSanitizeDbCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:db-sanitize')
         ->setAliases(array('db-sanitize'))
         ->setDescription('Sanitize the database')
         ->addOption('app', NULL, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Specify application(s) to sanitize the database for.');
    $this->addDirectoryArgument();
    $this->addExample("Sanitize database of a Drupal project", "/path/to/project");
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $apps = $input->getOption('app');

    // Do it for each Drupal application in the project.
    foreach (LocalApplication::getApplications($this->getProjectRoot(), self::$config) as $app) {
      if ($apps && !in_array($app->getId(), $apps)) {
        continue;
      }
      if ($app->getConfig()['build']['flavor'] == 'drupal') {
        $this->_execute($input, $app);
      }
    }
  }

  protected function _execute(InputInterface $input, LocalApplication $app) {
    $project = $this->getSelectedProject();

    // Work out the 'www' directory.
    $wwwRoot = ($this->localProject->getLegacyProjectRoot() !== FALSE) ?
      $this->localProject->getLegacyProjectRoot() . '/../www' :
      $this->getProjectRoot() . '/_www';
    $wwwRoot .= '/' . $app->getId();

    /* @var DrushHelper $dh */
    $dh = $this->getHelper('drush');

    $dh->ensureInstalled();
    try {
      $this->stdErr->writeln("Sanitizing database for <info>" . $project->id . '-' . $app->getId() . "</info>");
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
