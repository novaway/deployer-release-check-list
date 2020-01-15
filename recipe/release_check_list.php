<?php

namespace Deployer;

use Deployer\Task\Context;
use Deployer\Utility\Httpie;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;

set('rcl_gitlab_host', '');
set('rcl_gitlab_api_key', '');
set('rcl_gitlab_project_id', null);
set('rcl_gitlab_label', 'Release check-list');

task('rcl:check', function() {
    $releaseVersion = get('rcl_release_version', input()->getOption('tag'));

    if (!$releaseVersion) {
        return false;
    }

    $decomposedTag = explode('.', $releaseVersion);
    $majorVersion = $decomposedTag[0];
    $minorVersion = $decomposedTag[1];

    $titlePattern = sprintf('[%d.%d.x]', $majorVersion, $minorVersion);

    $queryParams = [
        'labels' => get('rcl_gitlab_label'),
        'search' => $titlePattern,
        'in' => 'title',
    ];

    $issues = Httpie::get(sprintf('%s/api/v4/projects/%d/issues?%s',
        get('rcl_gitlab_host'),
        get('rcl_gitlab_project_id'),
        http_build_query($queryParams)
    ))
        ->header(sprintf('PRIVATE-TOKEN: %s', get('rcl_gitlab_api_key')))
        ->send()
    ;
    $issues = json_decode($issues, true);

    if (count($issues) === 0) {
        return false;
    }

    $matchingIssue = $issues[0];

    if ($matchingIssue['has_tasks']) {
        $host = Context::get()->getHost()->getHostname();

        preg_match_all('/^- \[( |x)\] (.+)(?: \[(.+)\])?$/imU', $matchingIssue['description'], $tasksMatches);

        $blockingTasks = [];
        $pendingPostReleaseTasks = [];

        $rows = array_filter(array_map(function($status, $desc, $tagsAsString) use ($host, &$blockingTasks, &$pendingPostReleaseTasks) {
            $isPostReleaseTask = false;
            if (trim($tagsAsString) !== '') {
                $tags = array_map('trim', explode(',', $tagsAsString));
                $isPostReleaseTask = in_array('post-release', $tags);

                if (!in_array($host, $tags)) {
                    return null;
                }
            }

            $taskDone = $status === 'x';
            $status = $taskDone ? '<fg=green>✔</>' : '<fg='.($isPostReleaseTask ? 'yellow' : 'red').'>✘</>';

            if (!$taskDone) {
                if (!$isPostReleaseTask) {
                    $blockingTasks[] = $desc;
                } else {
                    $pendingPostReleaseTasks[] = [$status, $desc];
                }
            }

            return [$status, $desc, $isPostReleaseTask ? 'post-release' : 'mandatory'];
        }, $tasksMatches[1], $tasksMatches[2], $tasksMatches[3]));

        set('rcl_pending_post_release_tasks', $pendingPostReleaseTasks);

        if (count($rows) === 0) {
            return false;
        }

        $io = new SymfonyStyle(input(), output());
        $io->section($matchingIssue['title'].' for "'.$host.'"');
        $io->writeln('> '.$matchingIssue['web_url'].PHP_EOL);

        $table = new Table(output());
        $table
            ->setHeaders(['', 'Task', 'Type'])
            ->setRows($rows);
        $table->render();

        if (count($blockingTasks) > 0) {
            throw new \Exception(implode("\n", $blockingTasks));
        }
    }
});

task('rcl:post-release-reminder', function() {
    $pendingPostReleaseTasks = get('rcl_pending_post_release_tasks', []);
    if (count($pendingPostReleaseTasks) > 0) {
        $io = new SymfonyStyle(input(), output());
        $io->section('Do not forget to complete the following tasks:');

        $table = new Table(output());
        $table
            ->setHeaders(['', 'Task'])
            ->setRows($pendingPostReleaseTasks);
        $table->render();
    }
});

before('deploy', 'rcl:check');
after('success', 'rcl:post-release-reminder');
