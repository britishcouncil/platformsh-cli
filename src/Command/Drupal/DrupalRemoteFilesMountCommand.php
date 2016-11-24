<?php

namespace Platformsh\Cli\Command\Drupal;

use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DrupalRemoteFilesMountCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:mount-files')
         ->setAliases(array('mount'))
         ->setDescription('Mount files from a remote environment (via SSHFS)');
    $this->addDirectoryArgument();
    $this->addEnvironmentOption();
    $this->addExample('Mounts remote file share for a Drupal project via SSHFS', '-e environmentID /path/to/project');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $project = $this->getSelectedProject();

    // Mount remote file share only if not mounted already.
    if (!$this->isMounted($project)) {
      // Account for Legacy projects CLI < 3.x
      if (!($sharedPath = $this->localProject->getLegacyProjectRoot())) {
        $sharedPath = $this->getProjectRoot() . '/.platform/local';
      }
      $command = sprintf('sshfs %s-%s@ssh.bc.platform.sh:/app/public/sites/default/files %s/shared/files -o allow_other -o workaround=all -o nonempty -o reconnect', $project->id, $input->getOption('environment'), $sharedPath);
      $sshfs = new Process($command);
      $sshfs->setTimeout(self::$config->get('local.deploy.external_process_timeout'));
      try {
        $this->stdErr->writeln("Mounting files from environment <info>" . $project->id . '-' . $input->getOption('environment') . "</info> to <info>" . $project->id . '-' . "local</info>.");
        $sshfs->mustRun();
      }
      catch (ProcessFailedException $e) {
        echo $e->getMessage();
        return 1;
      }
    }
    else {
      $this->stdErr->writeln("Project $project->id already has remote files mounted to <info>local</info>");
    }
    return 0;
  }

  /**
   * Check if the remote file share for the project is already mounted.
   * @param \Platformsh\Cli\Model\Project
   * @return bool
   */
  private function isMounted(Project $project) {
    $mounts = file('/proc/mounts');
    foreach ($mounts as $mount) {
      if (strpos($mount, $project->id) > -1) {
        return TRUE;
      }
    }
    return FALSE;
  }
}
