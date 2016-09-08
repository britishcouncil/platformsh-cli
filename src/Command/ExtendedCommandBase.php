<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


abstract class ExtendedCommandBase extends CommandBase {

  public function __construct($name = NULL) {
    parent::__construct($name);
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
    $projects = array_filter($this->api()->getProjects($refresh ? TRUE : NULL), function (Project $project) use ($title) {
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
  }

  protected function addProjectOption() {
    parent::addProjectOption();
    $this->addTitleArgument();
    return $this;
  }

  protected function addDirectoryArgument() {
    $this->addArgument('directory', InputArgument::OPTIONAL, 'The directory where the project lives locally.');
    return $this;
  }

  private function addTitleArgument() {
    $this->addArgument('title', InputArgument::OPTIONAL, "The project title, or part of it.");
    return $this;
  }

  private function expandTilde($path) {
    if (function_exists('posix_getuid') && strpos($path, '~') !== FALSE) {
      $info = posix_getpwuid(posix_getuid());
      $path = str_replace('~', $info['dir'], $path);
    }
    return $path;
  }
}
