<?php

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentEradicateCommand extends CommandBase {
  protected function configure() {
    $this->setName('environment:eradicate')
         ->setAliases(['eradicate'])
         ->setDescription('Delete an environment and the related branch without asking for confirmation')
         ->addArgument('url', InputArgument::IS_ARRAY, 'The URL of the environment to delete');
    $this->addExample('Delete the environments "test1" from project "refsklfzrwbvg"', 'http://test1-refsklfzrwbvg.bc.platform.sh/')
         ->addExample('Delete the environments "test3" from project "vetsk3f5ravsg"', 'https://test3-vetsk3f5ravsg.bc.platform.sh/');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $urls = $input->getArgument('url');
    foreach ($urls as $url) {
      if (preg_match("/http[s]{0,1}:\/\/(.+)-(\w{13}).\w+.platform.sh/", $url, $matches)) {
        try {
          $this->runOtherCommand('environment:delete', [
            '--environment' => $matches[1],
            '--project' => $matches[2],
            '--delete-branch' => TRUE,
            '--yes' => TRUE,
          ]);
        }
        catch (\RuntimeException $e) {
          $this->stdErr->writeln("Environment <info>$matches[1]</info> does not exist on project <info>$matches[2]</info>");
        }
      }
      else {
        if (strlen($url)) {
          throw new \InvalidArgumentException(sprintf('Invalid URL: %s. It must match this pattern: <info>http[s]{0,1}://(.+)-(\w{13}).\w.platform.sh</info>', $url));
        }
        else {
          throw new \InvalidArgumentException(sprintf('No URL provided. It must match this pattern: <info>http[s]{0,1}://(.+)-(\w{13}).\w.platform.sh</info>', $url));
        }
      }
    }
    return 0;
  }
}
