<?php

namespace Hubph\Cli;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\Filter\FilterOutputData;
use Consolidation\Filter\LogicalOpFactory;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Consolidation\AnnotatedCommand\CommandError;
use Hubph\HubphAPI;
use Hubph\VersionIdentifiers;
use Hubph\PullRequests;
use Hubph\Git\WorkingCopy;
use Hubph\Git\Remote;

class ExtraCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;

    /**
     * @command repo:convert-data
     */
    public function repoConvertData($path, $options = ['format' => 'json'])
    {
        $repos = json_decode(file_get_contents($path), true);

        $reposResult = [];
        foreach ($repos as $key => $spec) {
            list($org, $project) = explode('/', $spec['full_name'], 2);
            $spec['org'] = $org;

            $repo = [
                'kind' => 'repository',
                'metadata' => [
                    'name' => $project,
                ],
                'spec' => $spec,
            ];

            $reposResult[] = $repo;
        }

        return $reposResult;
    }

    /**
     * @command project:update-info
     * @param $project The github project to update info for.
     *
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function projectUpdateInfo($project, $options = [
        'as' => 'default',
        'codeowners' => '',
        'support-level-badge' => '',
        'branch-name' => 'project-update-info',
        'commit-message' => 'Update project information.',
        'pr-title' => 'Update project information.',
        'pr-body' => '',
        'base-branch' => 'master',
    ])
    {
        if (count(explode('/', $project)) != 2) {
            throw new \Exception("Invalid project name: $project");
        }
        if (empty($options['codeowners']) && empty($options['support-level-badge'])) {
            throw new \Exception("Must specify at least one of --codeowners or --support-level-badge");
        }
        $url = "git@github.com:$project.git";
        $remote = new Remote($url);
        $api = $this->api($options['as']);
        $dir = sys_get_temp_dir() . '/hubph/' . $remote->project();
        $baseBranch = $options['base-branch'];
        $workingCopy = WorkingCopy::cloneBranch($url, $dir, $baseBranch, $api);

        // Set logger for workingCopy, is this correct?
        if ($this->logger) {
            $workingCopy->setLogger($this->logger);
        }

        $branchName = $options['branch-name'];
        $workingCopy->createBranch($branchName);
        $workingCopy->switchBranch($branchName);

        if (!empty($options['codeowners'])) {
            $codeowners = $options['codeowners'];
            // Append given CODEOWNERS line.
            file_put_contents("$dir/CODEOWNERS", '* ' . $codeowners . "\n", FILE_APPEND);
            $workingCopy->add("$dir/CODEOWNERS");
        }

        if (!empty($options['support-level-badge'])) {
            $support_level_badge = $options['support-level-badge'];
            $badge_contents = $this->getSupportLevelBadge($support_level_badge);
            if (!$badge_contents) {
                throw new \Exception("Invalid support level badge: $support_level_badge");
            }
            if (file_exists("$dir/README.md")) {
                $readme_contents = file_get_contents("$dir/README.md");
            }
            else {
                $readme_contents = '';
            }
            $lines = explode("\n", $readme_contents);
            $number_of_lines_to_search = 5;
            $first_empty_line = -1;
            $last_badge_line = -1;
            $badge_insert_line = -1;
            foreach ($lines as $line_number => $line) {
                if ($first_empty_line == -1 && empty(trim($line))) {
                    $first_empty_line = $line_number;
                }
                // Is this line a badge?
                if (preg_match('/\[\!\[[A-Za-z0-9\s]+\]\(.*\)/', $line)) {
                    $last_badge_line = $line_number;
                    // Is this line the License badge?
                    if (preg_match('/\[\!\[License]\(.*\)/', $line)) {
                        if ($line_number) {
                            $badge_insert_line = $line_number - 1;
                        } else {
                            $badge_insert_line = 0;
                        }
                    }
                } else {
                    if ($last_badge_line != -1) {
                        // We already found the badges, exit foreach.
                        break;
                    } elseif ($line_number > $number_of_lines_to_search) {
                        // We've searched enough lines, exit foreach.
                        break;
                    }
                }
            }
            if ($badge_insert_line === -1) {
                if ($last_badge_line !== -1) {
                    // If we found badges, we'll insert this badge after the last badge.
                    $badge_insert_line = $last_badge_line + 1;
                } elseif ($first_empty_line !== -1) {
                    // If we didn't find any badges, we'll insert this badge at the first empty line.
                    $badge_insert_line = $first_empty_line + 1;
                } else {
                    // Final fallback: insert badge in the second line of the file.
                    $badge_insert_line = 1;
                }
            }
            // Insert badge contents and empty line after it.
            array_splice($lines, $badge_insert_line, 0, [$badge_contents, '']);
            $readme_contents = implode("\n", $lines);
            file_put_contents("$dir/README.md", $readme_contents);
            $workingCopy->add("$dir/README.md");
        }

        $commit_message = $options['commit-message'];
        $workingCopy->commit($commit_message);
        $workingCopy->push('origin', $branchName);
        $message = $options['pr-title'];
        $body = $options['pr-body'];
        $workingCopy->pr($message, $body, $baseBranch, $branchName);
    }

    /**
     * Get right badge markdown.
     */
    protected function getSupportLevelBadge($level)
    {
        $badges = [
            'ea' => '[![Early Access](https://img.shields.io/badge/pantheon-EARLY_ACCESS-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/early-access?q=org%3Apantheon-systems)',
            'la' => '[![Limited Availability](https://img.shields.io/badge/pantheon-LIMITED_AVAILABILTY-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/limited-availability?q=org%3Apantheon-systems)',
            'actively-supported' => '[![Actively Maintained](https://img.shields.io/badge/pantheon-actively_maintained-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/actively-maintained?q=org%3Apantheon-systems)',
            'minimally-supported' => '[![Minimal Support](https://img.shields.io/badge/pantheon-minimal_support-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/minimal-support?q=org%3Apantheon-systems)',
            'unsupported' => '[![Unsupported](https://img.shields.io/badge/pantheon-unsupported-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/unsupported?q=org%3Apantheon-systems)',
            'unofficial' => '[![Unofficial](https://img.shields.io/badge/pantheon-unofficial-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/unofficial?q=org%3Apantheon-systems)',
            'deprecated' => '[![Deprecated](https://img.shields.io/badge/pantheon-deprecated-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/unofficial?q=org%3Apantheon-systems)',
        ];
        return $badges[$level] ?? '';
    }

    /**
     * @command org:analyze
     * @param $org The org to list
     * @filter-output
     * @field-labels
     *   url: Url
     *   id: ID
     *   owner: Owner
     *   name: Shortname
     *   full_name: Name
     *   private: Private
     *   fork: Fork
     *   created_at: Created
     *   updated_at: Updated
     *   pushed_at: Pushed
     *   git_url: Git URL
     *   ssh_url: SSH URL
     *   svn_url: SVN URL
     *   homepage: Homepage
     *   size: Size
     *   stargazers_count: Stargazers
     *   watchers_count: Watchers
     *   language: Language
     *   has_issues: Has Issues
     *   has_projects: Has Projects
     *   has_downloads: Has Downloads
     *   has_wiki: Has Wiki
     *   has_pages: Has Pages
     *   forks_count: Forks
     *   archived: Archived
     *   disabled: Disabled
     *   open_issues_count: Open Issues
     *   default_branch: Default Branch
     *   license: License
     *   permissions: Permissions
     *   codeowners: Code Owners
     *   owners_src: Owners Source
     *   ownerTeam: Owning Team
     * @default-fields full_name,codeowners,owners_src
     * @default-string-field full_name
     *
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function orgAnalyze($org, $options = ['as' => 'default', 'format' => 'table'])
    {
        $api = $this->api($options['as']);
        $pager = $api->resultPager();

        $repoApi = $api->gitHubAPI()->api('organization');
        $repos = $pager->fetchAll($repoApi, 'repositories', [$org]);

        // Remove archived repositories from consideration
        $repos = array_filter($repos, function ($repo) {
            return empty($repo['archived']);
        });

        // TEMPORARY: only do the first 20
        // $repos = array_splice($repos, 0, 20);

        // Add CODEOWNER information to repository data
        $reposResult = [];
        foreach ($repos as $key => $repo) {
            $resultKey = $repo['id'];
            $codeowners = [];
            $ownerSource = '';

            try {
                $data = $api->gitHubAPI()->api('repo')->contents()->show($org, $repo['name'], 'CODEOWNERS');
                if (!empty($data['content'])) {
                    $content = base64_decode($data['content']);
                    $ownerSource = 'file';
                    $codeowners = static::filterGlobalCodeOwners($content);
                }
            } catch (\Exception $e) {
            }

            list($codeowners, $ownerSource) = static::inferOwners($api, $org, $repo['name'], $codeowners, $ownerSource);

            $repo['codeowners'] = $codeowners;
            $repo['owners_src'] = $ownerSource;

            if (empty($codeowners)) {
                $repo['ownerTeam'] = 'n/a';
            } else {
                $repo['ownerTeam'] = str_replace("@$org/", "", $codeowners[0]);
            }

            $reposResult[$resultKey] = $repo;
        }

        $data = new \Consolidation\OutputFormatters\StructuredData\RowsOfFields($reposResult);
        $this->addTableRenderFunction($data);

        return $data;
    }

    protected static function inferOwners($api, $org, $project, $codeowners, $ownerSource)
    {
        $owningTeams = array_filter($codeowners, function ($owner) use ($org) {
            // @pantheon-systems/sig-go is in the default CODEOWNERS file for the go-demo-service
            if (($owner == '@pantheon-systems/sig-go') || ($owner == '@pantheon-systems/upstream-maintenance')) {
                return false;
            }
            return preg_match("/^@$org/", $owner);
        });

        // Our standard is that only TEAMS should be global code owners, but we
        // do have some examples with teams and individuals. For now we are
        // stripping out the individuals.
        if (!empty($owningTeams)) {
            return [$owningTeams, $ownerSource];
        }

        $teams = [];

        try {
            // Use the API to look up teams that have access to the repo and might be owners
            $teamsWithAccess = $api->gitHubAPI()->api('repo')->teams($org, $project);
            $teamsWithAdmin = [];
            $teamsWithWrite = [];
            foreach ($teamsWithAccess as $team) {
                if ($team['permissions']['admin']) {
                    $teamsWithAdmin[] = $team['slug'];
                } elseif ($team['permissions']['push']) {
                    $teamsWithWrite[] = $team['slug'];
                }
            }

            // If there are any teams with admin, use them. Otherwise fall back to teams with write.
            $teams = empty($teamsWithAdmin) ? $teamsWithWrite : $teamsWithAdmin;
        } catch (\Exception $e) {
        }

        // Convert from team slug to @org/slug
        $teams = array_map(function ($team) use ($org) {
            return "@$org/$team";
        }, $teams);

        if (!empty($teams)) {
            return [$teams, 'api'];
        }

        // Infer some owners
        $inferences = [
            'autopilot' => '@pantheon-systems/otto',
            'terminus' => '@pantheon-systems/cms-ecosystem',
            'drupal' => '@pantheon-systems/cms-ecosystem',
            'wordpress' => '@pantheon-systems/cms-ecosystem',
            'wp-' => '@pantheon-systems/cms-ecosystem',
            'cos-' => '@pantheon-systems/platform',
            'fastly' => '@pantheon-systems/platform-edge-routing',
        ];
        foreach ($inferences as $pat => $owner) {
            if (strpos($project, $pat) !== false) {
                return [[$owner], 'guess'];
            }
        }

        return [[], ''];
    }

    protected static function filterGlobalCodeOwners($content)
    {
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\*[ \t]/', $line)) {
                $globalOwners = str_replace("\t", " ", trim(ltrim($line, '*')));
                return explode(' ', $globalOwners);
            }
        }
        return [];
    }

    protected function addTableRenderFunction($data)
    {
        $data->addRendererFunction(
            function ($key, $cellData, FormatterOptions $options, $rowData) {
                if (empty($cellData)) {
                    return '';
                }
                if (is_array($cellData)) {
                    if ($key == 'permissions') {
                        return implode(',', array_filter(array_keys($cellData)));
                    }
                    if ($key == 'codeowners') {
                        return implode(' ', array_filter($cellData));
                    }
                    foreach (['login', 'label', 'name'] as $k) {
                        if (isset($cellData[$k])) {
                            return $cellData[$k];
                        }
                    }
                    // TODO: simplify
                    //   assignees
                    //   requested_reviewers
                    //   requested_teams
                    //   labels
                    //   _links
                    return json_encode($cellData, true);
                }
                if (!is_string($cellData)) {
                    return var_export($cellData, true);
                }
                return $cellData;
            }
        );
    }

    protected function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }
}
