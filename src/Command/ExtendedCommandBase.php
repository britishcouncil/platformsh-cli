<?php

namespace Platformsh\Cli\Command;

use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


abstract class ExtendedCommandBase extends CommandBase {

  protected $currentProject;
  protected $profilesRootDir;
  protected $sitesRootDir;

  /**
   * Extend 'validateInput' method.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param null $envNotRequired
   * @return int
   */
  protected function validateInput(InputInterface $input, $envNotRequired = NULL) {
    // Either title or --project.
    if ($input->hasArgument('title') && $input->getArgument('title') && $input->getOption('project')) {
      $this->stdErr->writeln("<error>You cannot specify both the option <info>--project</info> and the argument <info>title</info></error>.");
      return 1;
    }

    // If title was passed, try and get the PID.
    if ($input->hasArgument('title') && ($title = $input->getArgument('title'))) {
      if (($pid = $this->getPidFromTitle($title, $input, $this->output)) !== FALSE) {
        $input->setOption('project', $pid);
      }
    }

    if ($input->hasArgument('directory')) {
      if ($directory = $input->getArgument('directory')) {
        $this->setProjectRoot($directory);
      }
      if (!($project = $this->getCurrentProject())) {
        $this->stdErr->writeln("\n<error>No project found at " . (!empty($directory) ?
            $directory : getcwd()) . "</error>");
        return 1;
      }
    }

    parent::validateInput($input, $envNotRequired);

    // Some config.
    $this->profilesRootDir = $this->expandTilde(self::$config->get('local.drupal.profiles_dir'));
    $this->sitesRootDir = $this->expandTilde(self::$config->get('local.drupal.sites_dir'));
    $this->currentProject['internal_site_code'] = $this->selectEnvironment(self::$config->get('local.deploy.remote_environment'))->getVariable(self::$config->get('local.deploy.internal_site_code_variable'))->value;
  }

  /**
   * Select a project from an internal British Council code for the site.
   *
   * @param $title
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   * @return bool|mixed
   */
  protected function getPidFromTitle($title, InputInterface $input, OutputInterface $output) {
    $refresh = $input->hasOption('refresh') ? $input->getOption('refresh') : 1;

    // Filter the projects by title.
    $projects = array_filter($this->api()->getProjects($refresh ? TRUE :
      NULL), function (Project $project) use ($title) {
      return (stripos($project->title, $title) > -1);
    });

    switch (count($projects)) {
      case 0:
        return FALSE;
      case 1:
        return end($projects)->id;
      // More than 1 project matched.
      default:
        foreach ($projects as $pid => $project) {
          $projects[$pid] = $project->getProperty('title');
        }
        $qh = $this->getHelper('question');
        return $qh->choose($projects, 'More than one project matched. Please, choose the one you desire to deploy:', $input, $output);
    }
  }

  /**
   * Extend addProjectOption() so that the 'title' argument is present whenever
   * the '--project' option is.
   *
   * @return $this
   */
  protected function addProjectOption() {
    parent::addProjectOption();
    $this->addArgument('title', InputArgument::OPTIONAL, "The project title, or part of it.");
    return $this;
  }

  /**
   * Several of our bespoke commands rely on this argument.
   *
   * @return $this
   */
  protected function addDirectoryArgument() {
    $this->addArgument('directory', InputArgument::OPTIONAL, 'The directory where the project lives locally.');
    return $this;
  }

  /**
   * Check if GitHub integration is available by verifying the existence of the
   * expected GitHub repository.
   * @return bool|string
   */
  protected function gitHubIntegrationAvailable() {
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $git->setDefaultRepositoryDir($this->currentProject['repository_dir']);
    return $git->execute([
      'ls-remote',
      self::$config->get('local.integration.github_base_uri') . '/' . self::$config->get('local.integration.github_repo_prefix') . $this->currentProject['internal_site_code'] . '.git',
      'HEAD'
    ]);
  }

  /**
   * Check if Github integration is enabled by verifying the existence of the
   * expected marker file.
   * @return bool
   */
  public function gitHubIntegrationEnabled() {
    // The proper way to check for an active integration would be to use
    // P.sh integrations API. Unfortunately, that API is only accessible by
    // users with administrative permissions, so they cannot be invoked if
    // the user deploying locally is a normal developer.
    return file_exists($this->currentProject['root_dir'] . '/' . self::$config->get('local.integration.github_local_flag_file'));
  }

  /**
   * Enable GitHub integration locally for this project.
   */
  public function enableGitHubIntegration() {
    // Write file that indicates an integration is enabled.
    // Save original git URI in it.
    chdir($this->currentProject['repository_dir']);
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $git->setDefaultRepositoryDir($this->currentProject['repository_dir']);
    file_put_contents($this->currentProject['root_dir'] . '/' . self::$config->get('local.integration.github_local_flag_file'),
      $git->getConfig('remote.platform.url'));
    // Remove "platform" remote.
    $git->execute([
      'remote',
      'rm',
      'platform',
    ]);
    // Set the URL for "origin" remote to the GitHub repo.
    $git->execute([
      'remote',
      'set-url',
      'origin',
      self::$config->get('local.integration.github_base_uri') . '/' . self::$config->get('local.integration.github_repo_prefix') . $this->currentProject['internal_site_code'] . '.git',
    ]);
    // Fetch the remote.
    $git->execute(['fetch', 'origin']);
    // Change upstream for currently selected branch.
    $branch = $git->getCurrentBranch();
    $git->execute([
      'branch',
      $branch,
      '--set-upstream',
      "origin/$branch"
    ]);
    // Pull the latest.
    $git->execute(['pull']);
  }

  /**
   * Disable GitHub integration locally for this project.
   */
  public function disableGitHubIntegration($projectRepositoryDir) {
    chdir($this->currentProject['repository_dir']);
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $git->setDefaultRepositoryDir($this->currentProject['repository_dir']);
    // Retrieve original git URI.
    $originalGitUri = file_get_contents($this->currentProject['root_dir'] . '/' . self::$config->get('local.integration.github_local_flag_file'));
    // Remove file that indicates an integration is enabled.
    unlink($this->currentProject['root_dir'] . '/' . self::$config->get('local.integration.github_local_flag_file'));

    // Restore remote "origin" to original URI.
    $git->execute([
      'remote',
      'set-url',
      'origin',
      $originalGitUri
    ]);
    $git->execute([
      'remote',
      'add',
      'platform',
      $originalGitUri
    ]);
    // Fetch the remote.
    $git->execute(['fetch', 'platform']);
    // Restore upstream for currently selected branch.
    $branch = $git->getCurrentBranch();
    $git->execute([
      'branch',
      $branch,
      '--set-upstream',
      "platform/$branch"
    ]);
    // Pull the latest.
    $git->execute(['pull']);
  }

  private function expandTilde($path) {
    if (function_exists('posix_getuid') && strpos($path, '~') !== FALSE) {
      $info = posix_getpwuid(posix_getuid());
      $path = str_replace('~', $info['dir'], $path);
    }
    return $path;
  }
}
