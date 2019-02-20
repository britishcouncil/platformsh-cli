<?php

namespace Platformsh\Cli\Command\Drupal;

use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DrupalUncleanFeaturesCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:unclean-features')
         ->setAliases(array('unclean-features'))
         ->setDescription('Show a list of unclean features.');
    $this->addAppOption();
    $this->addDirectoryArgument();
    $this->addExample('Shows all the unclean features on a given Drupal project', '/path/to/project');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $apps = $input->getOption('app');
    // The output for 'drush fl' goes beyond the 90 columns.
    // For some reasons, commands run via Process (proc_open) will assume
    // a narrow terminal output. This messes the output, hence our filtering
    // on it. This is why we have this additional variable put into the ENV
    // before running the process.
    putenv('COLUMNS=512');
    foreach (LocalApplication::getApplications($this->getProjectRoot(), NULL) as $app) {
      // If --app was specified, only allow those apps.
      if ($apps && !in_array($app->getId(), $apps)) {
        continue;
      }
      // Also, only allow Drupal apps.
      if ($app->getConfig()['build']['flavor'] == 'drupal') {
        $this->_execute($input, $app);
      }
    }
    // Reset.
    putenv('COLUMNS');
  }

  /**
   * Helper function.
   */
  private function _execute(InputInterface $input, LocalApplication $app) {
    $project = $this->getSelectedProject();
    $alias = basename(realpath($input->getArgument('directory')));
    $p = new Process("drush @$alias._local--" . $app->getId() . " fl --status=enabled");
    $p->setTimeout($this->config()->get('local.deploy.external_process_timeout'));
    try {
      $p->mustRun();
      $output = array_filter(explode("\n", $p->getOutput()), function ($value) {
        return (preg_match('/(overridden|needs review)/i', $value) === 1);
      });
      if ($count = count($output)) {
        $heading = sprintf('<info>[*]</info> %d unclean features found for <info>%s</info> (%s)', $count, $project->getProperty('title'), $project->id);
        $this->stdErr->writeln($heading);
        $this->stdErr->writeln($output);
      }
      else {
        $heading = sprintf('<info>[*]</info> No unclean features found for <info>%s</info> (%s).', $project->getProperty('title'), $project->id);
        $this->stdErr->writeln($heading);
      }
      return 0;
    }
    catch (ProcessFailedException $e) {
      $this->stdErr->writeln($e->getMessage());
      return 1;
    }
  }
}

