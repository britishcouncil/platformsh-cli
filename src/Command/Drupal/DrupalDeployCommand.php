<?php

namespace Platformsh\Cli\Command\Drupal;

use Cocur\Slugify\Slugify;
use Composer\Config;
use Drush\Make\Parser\ParserIni;
use Platformsh\Cli\Command\ExtendedCommandBase;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DrupalDeployCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('drupal:deploy')
         ->setAliases(array('deploy'))
         ->setDescription('Deploy a Drupal Site locally')
         ->addOption('db-sync', 'd', InputOption::VALUE_NONE, "Sync project's database with the daily live backup.")
         ->addOption('core-branch', 'c', InputOption::VALUE_REQUIRED, "The core profile's branch to use during deployment")
         ->addOption('no-archive', 'A', InputOption::VALUE_NONE, 'Do not create or use a build archive. Run \'platform help build\' for more info.')
         ->addOption('no-deploy-hooks', 'D', InputOption::VALUE_NONE, 'Do not run deployment hooks (drush commands).')
         ->addOption('no-git-pull', 'G', InputOption::VALUE_NONE, 'Do not fetch updates for Git repositories. ')
         ->addOption('no-reindex', 'I', InputOption::VALUE_NONE, 'Do not reindex the content after creating indices for the first time during deployment.')
         ->addOption('no-sanitize', 'S', InputOption::VALUE_NONE, 'Do not perform database sanitization.')
         ->addOption('no-unclean-features', 'U', InputOption::VALUE_NONE, 'Shows a list of unclean features (if any) at the end of the deployment.');
    $this->addExample('Deploy Drupal project', '-p myproject123')
         ->addExample('Deploy Drupal project refreshing the database from the backup', '-d -p myproject123')
         ->addExample('Deploy Drupal project rereshing the database from the backup but skipping sanitization', '-d -S -p myproject123');
    $this->addProjectOption();
  }


  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $project = $this->getSelectedProject();

    $siteJustFetched = FALSE;
    $profileJustFetched = FALSE;

    $this->stdErr->writeln("<info>[*]</info> Deployment started for <info>" . $project->getProperty('title') . "</info> ($project->id)");

    // If this directory does not exist, create it.
    if (!is_dir($this->profilesRootDir)) {
      mkdir($this->profilesRootDir);
    }

    // If this directory does not exist, create it.
    if (!is_dir($this->sitesRootDir)) {
      mkdir($this->sitesRootDir);
    }

    $this->currentProject['root_dir'] = $this->sitesRootDir . '/' . $this->currentProject['internal_site_code'];

    // If the project was never deployed before.
    if (!is_dir($this->currentProject['root_dir'] . '/www') && !is_dir($this->currentProject['root_dir'] . '/_www')) {
      $this->fetchSite($project);
      // DB sync is required the first time the project is deployed.
      $input->setOption('db-sync', InputOption::VALUE_NONE);
      $siteJustFetched = TRUE;
    }

    $this->setProjectRoot($this->currentProject['root_dir']);
    $this->currentProject['legacy'] = $this->localProject->getLegacyProjectRoot() !== FALSE;
    $this->currentProject['repository'] = $this->currentProject['legacy'] ? $this->currentProject['root_dir'] . '/repository' : $this->currentProject['root_dir'];
    $this->currentProject['www_dir'] = $this->currentProject['legacy'] ? $this->currentProject['root_dir'] . '/www' : $this->currentProject['root_dir'] . '/_www';

    // Check for profile info.
    $profile = $this->getProfileInfo();

    // If the project refers to a profile and this was never fetched before.
    if (is_array($profile) && (!is_dir($this->profilesRootDir . "/" . $profile['name']))) {
      $this->fetchProfile($profile);
      $profileJustFetched = TRUE;
    }

    // Update repositories, if not requested otherwise.
    if (!$input->getOption('no-git-pull')) {
      // If we are not to fetch from a remote core branch.
      if (is_array($profile) && !$profileJustFetched && !$input->getOption('core-branch')) {
        $this->updateRepository($this->profilesRootDir . "/" . $profile['name']);
      }
      else {
        $this->stdErr->writeln('<info>[*]</info> Ignoring local profile repository. Using remote with branch <info>' . $input->getOption('core-branch') . '</info>');
      }
      // Update site repo only if the site has not just been fetched
      // for the first time.
      if (!$siteJustFetched) {
        // GitHub integration can be activated any time, so we must be ready
        // to apply it.
        $this->checkGitHubIntegration();
        // Update the repo.
        $this->updateRepository($this->currentProject['repository']);
      }
    }

    // DB import.
    if ($input->getOption('db-sync')) {
      $this->runOtherCommand('drupal:dbsync', [
        '--no-sanitize' => TRUE,
        'directory' => $this->currentProject['root_dir'],
      ]);
    }

    // Perform platform build.
    $this->build($project, $profile, $input->getOption('no-archive'), $input->getOption('core-branch'));

    // DB sync and sanitize.
    if ($input->getOption('db-sync') && !$input->getOption('no-sanitize')) {
      $this->runOtherCommand('drupal:db-sanitize', ['directory' => $this->currentProject['root_dir']]);
    }

    // Run deployment hooks, if not requested otherwise.
    if (!$input->getOption('no-deploy-hooks')) {
      $this->runDeployHooks($project);
    }

    // Mount remote file share.
    $this->runOtherCommand('drupal:mount', ['directory' => $this->currentProject['root_dir']]);

    // Show unclean features, if not requested otherwise.
    if (!$input->getOption('no-unclean-features')) {
      $this->stdErr->writeln("<info>[*]</info> Checking the status of features...");
      $this->runOtherCommand('drupal:unclean-features', ['directory' => $this->currentProject['root_dir']]);
    }

    // Clean up builds.
    $this->cleanBuilds();

    // End of deployment.
    $this->stdErr->writeln("<info>[*]</info> Deployment finished.\n\tGo to <info>http://" . $this->currentProject['internal_site_code'] . ".local.solas.britishcouncil.digital</info> to view the site.\n\tThe password for all users is <info>password</info>.");
  }

  /**
   * Build project.
   * @param $project
   * @param array $profile
   * @param $noArchive
   * @param $noDeployHooks
   * @param $coreBranch
   */
  private function build(Project $project, $profile, $noArchive, $coreBranch = NULL) {
    $this->stdErr->writeln('<info>[*]</info> Building <info>' . $project->getProperty('title') . '</info> (' . $project->id . ')...');

    // Account for projects that do not use external distro profiles.
    if (is_array($profile)) {
      // Temporarily override the project.make to use the local checkout of the
      // core profile, which must be on on the branch one desires to deploy.
      $originalMakefile = file_get_contents($this->currentProject['repository'] . '/project.make');
      if ($coreBranch) {
        $tempMakefile = preg_replace('/(projects\[' . $profile['name'] . '\]\[download\]\[branch\] =) (.*)/', "$1 $coreBranch", $originalMakefile);
      }
      else {
        $tempMakefile = preg_replace('/projects\[' . $profile['name'] . '\]\[download\]\[branch\] = .*/', "", $originalMakefile);
        $tempMakefile = preg_replace('/(projects\[' . $profile['name'] . '\]\[download\]\[url\] =) (.*)/', "$1 " . $this->profilesRootDir . '/' . $profile['name'], $tempMakefile);
        $tempMakefile = preg_replace('/(projects\[' . $profile['name'] . '\]\[download\]\[type\] =) (.*)/', "$1 copy", $tempMakefile);
      }
      file_put_contents($this->currentProject['repository'] . '/project.make', $tempMakefile);
    }

    // Build.
    $localBuildOptions['--yes'] = TRUE;
    $localBuildOptions['--source'] = $this->currentProject['root_dir'];
    if ($noArchive) {
      $localBuildOptions['--no-archive'] = TRUE;
    }
    $this->runOtherCommand('local:build', $localBuildOptions);

    // Make sure settings.local.php is up to date for the project.
    $slugify = new Slugify();
    $slugifiedProjectTitle = str_replace('-', '_', $project->title ? $slugify->slugify($project->title) : $project->id);
    $settings_local_php = sprintf(file_get_contents("/vagrant/etc/cli/resources/drupal/settings.local.php"), $this::$config->get('local.stack.mysql_db_prefix') . $slugifiedProjectTitle, $this::$config->get('local.stack.mysql_user'), $this::$config->get('local.stack.mysql_password'), $this::$config->get('local.stack.mysql_host'), $this::$config->get('local.stack.mysql_port'));
    // Account for Legacy projects CLI < 3.x
    if (!($sharedPath = $this->localProject->getLegacyProjectRoot())) {
      $sharedPath = $this->getProjectRoot() . '/.platform/local';
    }
    file_put_contents($sharedPath . "/shared/settings.local.php", $settings_local_php);

    // Account for projects that do not use external distro profiles.
    if (is_array($profile)) {
      // Restore original project.make so that the site repo is clean.
      file_put_contents($this->currentProject['repository'] . '/project.make', $originalMakefile);

      // Skip this step if $coreBranch is not NULL.
      if ($coreBranch == NULL) {
        $fs = new Filesystem();

        // Remove the .git directory that we got from the "build" of type "copy".
        $fs->remove($this->currentProject['root_dir'] . '/www/profiles/' . $profile['name'] . '/.git');

        // Obtain symlinks map for profile.
        $linkMap = $this->mapProfileSymlinks($profile);

        // Remove all files represented by $linkMap's keys.
        $fs->remove(array_keys($linkMap));

        // Replace them with required symlinks.
        foreach ($linkMap as $original => $link) {
          $symlink = rtrim($fs->makePathRelative($link, realpath(dirname($original))), '/');
          $fs->symlink($symlink, $original);
        }
      }
    }
  }

  /**
   * Fetch the Solas profile for the first time.
   * @throws \Exception
   */
  private function fetchProfile($profile) {
    /** @var $git GitHelper */
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $this->stdErr->writeln("<info>[*]</info> Checking out <info>" . $profile['name'] . "</info> for the first time...");

    $profileCheckoutDir = $this->profilesRootDir . "/" . $profile['name'];

    $git->cloneRepo($profile['url'], $profileCheckoutDir, array(
      '--branch',
      isset($profile['branch']) ? $profile['branch'] : 'master',
    ));

    if (isset($profile['tag'])) {
      $git->checkOut($profile['tag'], $profileCheckoutDir, TRUE);
    }

    copy('/vagrant/etc/config/pre-push', $profileCheckoutDir . '/.git/hooks/pre-push');
  }

  /**
   * Fetches a site for the first time.
   * @param $project
   * @throws \Exception
   */
  private function fetchSite(Project $project) {
    /** @var $git GitHelper */
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $this->stdErr->writeln("<info>[*]</info> Fetching <info>" . $project->getProperty('title') . "</info> (" . $project->id . ") for the first time...");
    $this->runOtherCommand('project:get', [
      '--yes' => TRUE,
      '--environment' => $this::$config->get('local.deploy.git_default_branch'),
      'id' => $project->id,
      'directory' => $this->currentProject['root_dir'],
    ]);
    copy('/vagrant/etc/config/pre-push', $this->currentProject['repository'] . '/.git/hooks/pre-push');
  }

  /**
   * Check for GitHub integration and act accordingly.
   * @param $project
   */
  private function checkGitHubIntegration() {
    if ($this->gitHubIntegrationAvailable() && !$this->gitHubIntegrationEnabled()) {
      $this->stdErr->writeln("\n<info>Found GitHub integration</info>: the remote <info>origin</info> will now be pointed at Github...");
      $this->stdErr->writeln("The remote <info>platform</info> will continue to point at Platform.sh");
      $this->enableGitHubIntegration();
    }
  }

  /**
   * Update a repository.
   * @param $dir
   *  Directory where the checked out repo lives.
   * @throws \Exception
   */
  private function updateRepository($dir) {
    /** @var $git GitHelper */
    $git = $this->getHelper('git');
    $git->ensureInstalled();
    $git->setDefaultRepositoryDir($dir);
    $this->stdErr->write(sprintf("<info>[*]</info> Updating <info>%s/%s</info>...", basename(str_ireplace(':', '/', $git->getConfig('remote.origin.url')), '.git'), $git->getCurrentBranch()));
    $git->execute(array('pull'));
    $git->execute(array(
      'pull',
      'origin',
      $this::$config->get('local.deploy.git_default_branch'),
    ));
    $this->stdErr->writeln("\t<info>[ok]</info>");
  }

  /**
   * Execute the deployment hooks.
   * @param $project
   */
  private function runDeployHooks(Project $project) {
    $localApplication = new LocalApplication($this->currentProject['repository']);
    $appConfig = $localApplication->getConfig();

    $this->stdErr->writeln("<info>[*]</info> Executing deployment hooks for <info>$project->title</info> ($project->id)...");

    $p = new Process('');
    $p->setWorkingDirectory($this->currentProject['www_dir']);
    foreach (explode("\n", $appConfig['hooks']['deploy']) as $hook) {
      if ($hook != "cd public") {
        $p->setCommandLine($hook);
        $p->setTimeout(self::$config->get('local.deploy.external_process_timeout'));
        try {
          $this->stdErr->writeln("Running <info>$hook</info>");
          if (stripos($hook, 'updb')) {
            $p->mustRun(function ($type, $buffer) {
              echo $buffer;
            });
          }
          else {
            $p->mustRun();
          }
        }
        catch (ProcessFailedException $e) {
          echo $e->getMessage();
        }
      }
    }
  }

  /**
   * Clean up old builds for the project and keep only the latest.
   */
  private function cleanBuilds() {
    $this->stdErr->writeln('<info>[*]</info> Deleting old builds...');
    chdir($this->currentProject['root_dir']);
    $this->runOtherCommand('local:clean', [
      '--keep' => 1,
    ]);
  }

  /**
   * Get information on the profile used by the project, if one is specified.
   */
  private function getProfileInfo() {
    $makefile = $this->currentProject['repository'] . '/project.make';
    if (file_exists($makefile)) {
      $ini = new ParserIni();
      $makeData = $ini->parse(file_get_contents($makefile));
      foreach ($makeData['projects'] as $projectName => $projectInfo) {
        if (($projectInfo['type'] == 'profile') && ($projectInfo['download']['type'] == 'git')) {
          $p = $projectInfo['download'];
          $p['name'] = $projectName;
          unset($p['type']);
          return $p;
        }
      }
    }
    return NULL;
  }

  /**
   * Build symlinks map for a given profile.
   *
   * @param $profile
   * @return array
   */
  private function mapProfileSymlinks($profile) {
    $profileName = $profile['name'];
    $profileDir = $this->profilesRootDir . "/" . $profileName;
    $wwwDir = $this->currentProject['www_dir'];

    // The keys of the $linkMap array are the files to remove.
    // The values are the files to symlink to, in place of the removed files.
    $linkMap = array();

    // Symlink profile files.
    foreach (array('.info', '.profile', '.install', '.make') as $ext) {
      $linkMap[$wwwDir . '/profiles/' . $profileName . '/' . $profileName . $ext] = $profileDir . '/' . $profileName . $ext;
    }

    // This will symlink things like: modules/custom, modules/features, // etc.
    if ($modules = scandir($profileDir . "/modules")) {
      foreach ($modules as $module_dir) {
        if (($module_dir != '.') && ($module_dir != '..') && ($module_dir != 'contrib')) {
          $linkMap[$wwwDir . '/profiles/' . $profileName . '/modules/' . $module_dir] = $profileDir . '/modules/' . $module_dir;
        }
      }
    }

    // This will symlink the themes.
    if ($themes = scandir($profileDir . "/themes")) {
      foreach ($themes as $theme_dir) {
        if (($theme_dir != '.') && ($theme_dir != '..') && ($theme_dir != 'contrib')) {
          $linkMap[$wwwDir . '/profiles/' . $profileName . '/themes/' . $theme_dir] = $profileDir . '/themes/' . $theme_dir;
        }
      }
    }

    if ($profileName != 'solas2') {
      // Symlink various resources.
      $linkMap[$wwwDir . '/profiles/' . $profileName . '/resources/settings'] = $profileDir . '/resources/settings';
      $linkMap[$wwwDir . '/profiles/' . $profileName . '/resources/sureroute-test-object.html'] = $profileDir . '/resources/sureroute-test-object.html';
      $linkMap[$wwwDir . '/sureroute-test-object.html'] = $profileDir . '/resources/sureroute-test-object.html';
      $linkMap[$wwwDir . '/profiles/' . $profileName . '/resources/humans.txt'] = $profileDir . '/resources/humans.txt';
      $linkMap[$wwwDir . '/humans.txt'] = $profileDir . '/resources/humans.txt';
    }
    else {
      $linkMap += array(
        $wwwDir . '/profiles/solas2/settings' => $profileDir . '/settings',
        $wwwDir . '/sites/all/drush/commands/ccall.drush.inc' => $profileDir . '/sites/all/drush/commands/ccall.drush.inc',
        $wwwDir . '/sureroute-test-object.html' => $profileDir . '/sureroute-test-object.html',
        $wwwDir . '/humans.txt' => $profileDir . '/humans.txt',
        $wwwDir . '/profiles/solas2/sureroute-test-object.html' => $profileDir . '/sureroute-test-object.html',
        $wwwDir . '/profiles/solas2/humans.txt' => $profileDir . '/humans.txt',
      );
    }

    return $linkMap;
  }
}
