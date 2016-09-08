<?php

namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LocalDropDatabasesCommand extends CommandBase {

  protected function configure() {
    $this->setName('local:drop-databases')
         ->setAliases(array('drop-dbs'))
         ->setDescription('Drops all the databases related to Platform projects');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    if ($connection = mysqli_connect($this::$config->get('local.stack.mysql_host'), $this::$config->get('local.stack.mysql_root_user'), $this::$config->get('local.stack.mysql_root_password'))) {
      $r = mysqli_query($connection, "SHOW DATABASES LIKE '" . $this::$config->get('local.stack.mysql_db_prefix') . "%'");
      $databases = mysqli_fetch_all($r);

      if (count($databases)) {
        foreach ($databases as $db) {
          $this->stdErr->writeln("* <info>$db[0]</info>");
        }

        $qh = $this->getHelper('question');
        if ($qh->confirm("Are you sure you want to delete all databases listed above?", $input, $this->stdErr, FALSE)) {
          foreach ($databases as $db) {
            $this->stdErr->writeln("Dropping <info>$db[0]</info>...");
            mysqli_query($connection, "DROP DATABASE IF EXISTS $db[0]");
          }
        }

        mysqli_close($connection);
        return 0;
      }
      else {
        $this->stdErr->writeln("There are no databases to drop.");
        return 0;
      }
    }
    else {
      $this->stdErr->writeln('<error>Could not connect to MySQL. Try again later.</error>');
      return 1;
    }
  }
}
