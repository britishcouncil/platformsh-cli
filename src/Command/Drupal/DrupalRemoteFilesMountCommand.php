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
         ->setDescription('Mount the remote file share from the designated environment (via SSHFS)');
    $this->addDirectoryArgument();
    $this->addExample('Mounts remote file share for a Drupal project via SSHFS', '/path/to/project');
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
      $command = sprintf('sshfs %s-%s@ssh.bc.platform.sh:/app/public/sites/default/files %s/shared/files -o allow_other -o workaround=all -o nonempty -o reconnect', $project->id, self::$config->get('local.deploy.backup_environment'), $sharedPath);
      $sshfs = new Process($command);
      $sshfs->setTimeout(self::$config->get('local.deploy.external_process_timeout'));
      try {
        $this->stdErr->write("<info>[*]</info> Mounting remote file share for <info>" . $project->getProperty('title') . "</info> ($project->id)...");
        $sshfs->mustRun();
        $this->stdErr->writeln("\t<info>[ok]</info>");
      }
      catch (ProcessFailedException $e) {
        echo $e->getMessage();
        return 1;
      }
    }
    else {
      $this->stdErr->writeln("<info>[*]</info> Remote file share already mounted for <info>" . $project->getProperty('title') . "</info> ($project->id)");
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
