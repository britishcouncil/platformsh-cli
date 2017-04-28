<?php

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\ExtendedCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectDisableGithubIntegrationCommand extends ExtendedCommandBase {

  protected function configure() {
    $this->setName('project:disabled-github-integration')
         ->setAliases(array('dgi'))
         ->setDescription('Disable GitHub integration locally for a project.');
    $this->addExample("Disable integration locaòly for a project", "/path/to/project");
    $this->addDirectoryArgument();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->validateInput($input);
    $project = $this->getSelectedProject();

    $qh = $this->getService('question_helper');
    if ($qh->confirm("Are you sure you want to disable GitHub integration for <info>$project->id</info>?", $input, $this->stdErr, FALSE)) {
      $this->disableGitHubIntegration();
    }
  }

}
