<?php

namespace Platformsh\Cli\Command\Drupal;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Helper\ShellHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DrupalDbSyncCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:dbsync')
         ->setAliases(array('dbsync'))
         ->setDescription('Synchronize local database with designated remote')
         ->addOption('no-sanitize', 'S', InputOption::VALUE_NONE, 'Do not perform database sanitization.');
    $this->addDirectoryArgument();
    $this->addExample('Synchronize database of a Solas project from daily backup', '-p myproject123')
         ->addExample('Synchronize database of a Solas project from daily backup; do not sanitize it', '-p myproject123 -no-sanitize');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $project = $this->getSelectedProject();
    $slugify = new Slugify();
    $slugifiedTitle = $project->title ? $slugify->slugify($project->title) : $project->id;
    $backupPath = self::$config->get('local.deploy.db_backup_local_cache') . "/" . date('Y-m-d') . '-' . $slugifiedTitle . '.sql';

    $this->stdErr->writeln("<info>[*]</info> Importing live database backup for <info>" . $project->getProperty('title') . "</info> (" . $project->id . ")...");

    // Get a fresh SQL dump if necessary.
    if (!file_exists($backupPath)) {
//      $commandLine = "scp " . $project->id . "-" . self::$config->get('local.deploy.remote_environment') . "@ssh." . $project->getProperty('region') . ".platform.sh:~/private/" . $project->id . ".sql.gz $backupPath.gz && gunzip $backupPath.gz";
//      $sh = new ShellHelper();
//      $sh->executeSimple($commandLine);
//
//      if (!file_exists($backupPath)) {
        $this->runOtherCommand('environment:sql-dump', [
          '--project' => $project->id,
          '--environment' => self::$config->get('local.deploy.remote_environment'),
          '--file' => $backupPath
        ]);
//      }
    }
    else {
      $this->stdErr->writeln("Retrieving backup from the cache: <info>$backupPath</info>");
    }

    $dbName = self::$config->get('local.stack.mysql_db_prefix') . str_replace('-', '_', $slugifiedTitle);

    // If dump is present and sound.
    if (file_exists($backupPath) && filesize($backupPath) > 0) {
      // Use PHP MySQL APIs for these simple queries.
      $queries = array(
        "DROP DATABASE IF EXISTS $dbName",
        "CREATE DATABASE IF NOT EXISTS $dbName",
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON $dbName.* TO '" . self::$config->get('local.stack.mysql_user') . "'@'" . self::$config->get('local.stack.mysql_host') . "' IDENTIFIED BY '" . self::$config->get('local.stack.mysql_password') . "';"
      );
      if ($connection = mysqli_connect(self::$config->get('local.stack.mysql_host'), self::$config->get('local.stack.mysql_root_user'), self::$config->get('local.stack.mysql_root_password'))) {
        foreach ($queries as $q) {
          mysqli_query($connection, $q);
        }
        mysqli_close($connection);
      }
      else {
        $this->stdErr->writeln('<error>Could not connect to MySQL. Try again later.</error>');
        return 1;
      }

      // Use mysql CLI for importing the SQL dump, as it's much more efficient.
      $cmd = sprintf("cat %s | mysql -h%s -u%s -p%s --database %s", $backupPath, self::$config->get('local.stack.mysql_host'), self::$config->get('local.stack.mysql_root_user'), self::$config->get('local.stack.mysql_root_password'), $dbName);
      $p = new Process($cmd);
      $p->setTimeout(self::$config->get('local.deploy.external_process_timeout'));
      try {
        $p->mustRun();
      }
      catch (ProcessFailedException $e) {
        echo $e->getMessage();
        return 1;
      }

      // Sanitise, if requested.
      if (!$input->getOption('no-sanitize')) {
        $this->runOtherCommand('drupal:db-sanitize', ["directory" => $this->getProjectRoot()]);
        return 1;
      }
      return 0;
    }
    else {
      $this->stdErr->writeln('<error>Backup could not be downloaded. Try again later.</error>');
      return 1;
    }
  }
}
