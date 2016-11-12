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
    $internal_site_code = $this->selectEnvironment(self::$config->get('local.deploy.backup_environment'))->getVariable(self::$config->get('local.deploy.internal_site_code_variable'))->value;

    /* @var DrushHelper $dh */
    $dh = $this->getHelper('drush');

    $dh->ensureInstalled();
    try {
      $this->stdErr->write("<info>[*]</info> Sanitizing database for <info>" . $project->getProperty('title') . "</info> (" . $project->id . ")...");
      $dh->execute([
        '-y',
        "@$internal_site_code._local",
        'sql-sanitize',
        '--sanitize-email="%name@example.com"'
      ], NULL, TRUE);
      $this->stdErr->writeln("\t<info>[ok]</info>");
    }
    catch (\Exception $e) {
      echo $e->getMessage();
      return 1;
    }
    return 0;
  }
}
