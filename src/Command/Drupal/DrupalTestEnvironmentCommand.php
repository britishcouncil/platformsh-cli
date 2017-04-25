<?php


namespace Platformsh\Cli\Command\Drupal;

use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\QuestionHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class DrupalTestEnvironmentCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:test-environment')
         ->setAliases(array('te'))
         ->addOption('name', NULL, InputOption::VALUE_REQUIRED, 'Override automatically generated name for the test environment')
         ->addOption('refresh', 'r', InputOption::VALUE_NONE, 'Refresh an already existing testing environment')
         ->addArgument('core-branch', InputArgument::REQUIRED, 'Solas profile branch to test')
         ->setDescription('Create an environment for testing a branch of the core Solas profile');
    $this->addProjectOption();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);

    $testEnv = NULL;
    $ticket = NULL;

    // If core-branch was specified.
    if ($coreBranch = $input->getArgument('core-branch')) {
      preg_match("/(.*)-([0-9]+)(.*)/i", $coreBranch, $ticket);
      $ticket = is_numeric($ticket[2]) ? $ticket[2] : $coreBranch;
      // If the environment name wasn't specified.
      if (!($testEnv = $input->getOption('name'))) {
        // Generate it automatically.
        $testEnv = 'test-' . $ticket;
      }
    }


    // If environment already exists, but --refresh has not been specified,
    // throw an exception and advise accordingly.
    if (($e = $this->getSelectedProject()
                   ->getEnvironment($testEnv)) && !$input->getOption('refresh')
    ) {
      $qh = $this->getHelper('question');
      if ($qh->confirm("Environment <info>$testEnv</info> already exists. Did you mean to refresh it?")) {
        $input->setOption('refresh', TRUE);
      }
      else {
        throw new EnvironmentStateException("Environment <info>$testEnv</info> already exists.\nAdd [-r | --refresh] if you would like to update an existing test environment.", $e);
      }
    }

    $project = $this->getSelectedProject();
    $pid = $project->getProperty('id');
    $projectTitle = $project->getProperty('title');
    $siteDir = "/tmp/" . $pid;
    // Start fresh each time.
    if (file_exists($siteDir)) {
      $fs = new Filesystem();
      $fs->remove($siteDir);
    }

    // The environment to get: 'qa' if we are creating the test environment,
    // or the test environment's name if we are refreshing a pre-existing
    // environment for testing.
    $getEnv = $input->getOption('refresh') ? $testEnv :
      $this->config()->get('local.deploy.git_default_branch');

    $this->stdErr->write("<info>[*]</info> Obtaining project <info>$projectTitle</info> ($pid)...");
    $ret = $this->runOtherCommand('project:get', [
      '--yes' => TRUE,
      '--environment' => $getEnv,
      'id' => $pid,
      'directory' => $siteDir,
    ], new NullOutput());
    $this->stdErr->writeln("<info>[ok]</info>");

    // Throw exception on failure to get the project.
    if ($ret) {
      throw new \Exception(sprintf('The project %s could not be obtained.', $pid));
    }

    // Set project root.
    $this->extCurrentProject['root_dir'] = $siteDir;
    $this->setProjectRoot($this->extCurrentProject['root_dir']);

    // Set more info about the current project.
    $this->extCurrentProject['legacy'] = $this->getService('local.project')->getLegacyProjectRoot() !== FALSE;
    $this->extCurrentProject['repository_dir'] = $this->extCurrentProject['legacy'] ?
      $this->extCurrentProject['root_dir'] . '/repository' :
      $this->extCurrentProject['root_dir'];
    $this->extCurrentProject['www_dir'] = $this->extCurrentProject['legacy'] ?
      $this->extCurrentProject['root_dir'] . '/www' :
      $this->extCurrentProject['root_dir'] . '/_www';

    $timestamp = date('Y-m-d H:i:s');

    // If we are creating a new environment.
    if (!$input->getOption('refresh')) {
      $this->stdErr->write("<info>[*]</info> Creating environment <info>$testEnv</info> on <info>$projectTitle</info> ($pid)...");
      $ret = $this->runOtherCommand('environment:branch', [
        'id' => $testEnv,
        'parent' => $this->config()->get('local.deploy.git_default_branch'),
        '--project' => $pid,
      ], new NullOutput());
      $this->stdErr->writeln("<info>[ok]</info>");

      // Throw exception on failure to create the environment.
      if ($ret) {
        throw new \Exception(sprintf('The environment %s could not be created.', $testEnv));
      }

      // Update the makefile with the core branch to test.
      $makefile = file_get_contents($this->extCurrentProject['repository_dir'] . '/project.make');
      $makefile = preg_replace('/(projects\[.*\]\[download\]\[branch\] =) (.*)/', "$1 $coreBranch", $makefile);

      // Throw exception on failure to update the makefile.
      if (file_put_contents($this->extCurrentProject['repository_dir'] . '/project.make', $makefile) === FALSE) {
        throw new \Exception('Cannot edit the makefile for testing.');
      }
    }
    // If we are refreshing a pre-existing test environment.
    else {
      $this->stdErr->write("<info>[*]</info> Updating environment <info>$testEnv</info> on <info>$projectTitle</info> ($pid)...");
      if (file_put_contents($this->extCurrentProject['repository_dir'] . '/CHANGELOG-SOLAS-' . $ticket, "Refresh $testEnv: " . $timestamp) === FALSE) {
        throw new \Exception('Cannot edit the changelog.');
      }
      $this->stdErr->writeln("<info>[ok]</info>");
    }

    $git = new GitHelper(new ShellHelper($this->output));
    $git->ensureInstalled();
    // Add modified files.
    $git->execute([
      'add',
      '-A'
    ], $this->extCurrentProject['repository_dir'], TRUE);
    // Commit.
    $git->execute([
      'commit',
      '-am',
      "\"$timestamp $coreBranch\"",
    ], $this->extCurrentProject['repository_dir'], TRUE);
    // Deploy.
    $this->stdErr->writeln("<info>[*]</info> Deploying environment <info>$testEnv</info> for <info>$projectTitle</info> ($pid)...");
    $git->execute([
      'push',
      'origin',
      $testEnv
    ], $this->extCurrentProject['repository_dir'], TRUE, FALSE);

    if ($this->gitHubIntegrationEnabled()) {
      $this->stdErr->writeln("\n<error>GitHub integration is enabled on this project, so the environment just created is inactive.\nPlease go to </error><info>https://github.com/britishcouncil/solas_ " . $project->internalCode . "</info><error> and issue a pull request from </error><info>$testEnv</info><error> to </error><info>qa</info><error> in order to create an active test environment. The pull request can then be safely closed once testing is complete.</error>");
    }
    return 0;
  }
}
