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

class HubphCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;

    /**
     * Report who we have authenticated as
     *
     * @command whoami
     */
    public function whoami($options = ['as' => 'default'])
    {
        $api = $this->api($options['as']);
        $authenticated = $api->whoami();
        $authenticatedUser = $authenticated['login'];

        $this->say("Authenticated as $authenticatedUser.");
    }

    /**
     * @command pr:close
     */
    public function prClose($projectWithOrg = '', $number = '', $options = ['as' => 'default'])
    {
        if (empty($number) && preg_match('#^[0-9]*$#', $projectWithOrg)) {
            $number = $projectWithOrg;
            $projectWithOrg = '';
        }
        $projectWithOrg = $this->projectWithOrg($projectWithOrg);
        list($org, $project) = explode('/', $projectWithOrg, 2);

        $api = $this->api($options['as']);
        $api->prClose($org, $project, $number);
    }

    /*
     * hubph pr:check --vid=php-7.0./31 --vid=php-7.1./20
     *
     * status 0 and csv with PR numbers to close
     *
     *  - or -
     *
     * status 1 if all vid/vvals exist and nothing more needs to be done
     */

    /**
     * @command pr:check
     */
    public function prCheck(
        $options = [
            'message|m' => '',
            'file|F' => '',
            'base' => '',
            'head' => '',
            'as' => 'default',
            'format' => 'yaml',
            'idempotent' => false
        ]
    ) {
        $projectWithOrg = $this->projectWithOrg();

        // Get the commit message from --message or --file
        $message = $this->getMessage($options);

        // Determine all of the vid/vval pairs if idempotent
        $vids = $this->getVids($options, $message);

        $api = $this->api($options['as']);
        list($status, $result) = $api->prCheck($projectWithOrg, $vids);

        if ($status) {
            return new CommandError($result, $status);
        }
        if (is_string($result)) {
            $this->logger->notice("No open pull requests that need to be closed.");
            return;
        }
        return implode(',', (array)$result);
    }

    /**
     * @command pr:create
     * @aliases pull-request
     */
    public function prCreate(
        $options = [
            'message|m' => '',
            'body' => '',
            'file|F' => '',
            'base' => 'master',
            'head' => '',
            'as' => 'default',
            'format' => 'yaml',
            'idempotent' => false
        ]
    ) {
        $projectWithOrg = $this->projectWithOrg();

        // Get the commit message from --message or --file
        $message = $this->getMessage($options);
        list($org, $project) = explode('/', $projectWithOrg, 2);

        // Determine all of the vid/vval pairs if idempotent
        $vids = $this->getVids($options, $message);

        $api = $this->api($options['as']);
        list($status, $result) = $api->prCheck($projectWithOrg, $vids);

        if ($status) {
            return new CommandError($result, $status);
        }

        // TODO: We could look up 'head' if it is not provided.
        if (empty($options['head'])) {
            throw new \Exceptions('Must provide --head');
        }

        // Create the requested PR
        $api->prCreate($org, $project, $message, $options['body'], $options['base'], $options['head']);

        // If $result is an array, it will contain
        // all of the pull request numbers to close.
        // TODO: We should make a wrapper object for $result
        if (is_array($result)) {
            list($org, $project) = explode('/', $projectWithOrg, 2);
            $api->prClose($org, $project, $result);
        }
    }

    protected function getMessage($options)
    {
        if (!empty($options['message'])) {
            return $options['message'];
        }
        if (!empty($options['file'])) {
            return file_get_contents($options['file']);
        }
        return '';
    }

    protected function getVids($options, $message)
    {
        $vids = new VersionIdentifiers();

        //if (empty($options['idempotent'])) {
        //    return $vids;
        //}

        // Allow the caller to define more specific vid / vval patterns
        if (!empty($options['vid'])) {
            $vids->setVidPattern($options['vid']);
        }
        if (!empty($options['vval'])) {
            $vids->setVvalPattern($options['vval']);
        }

        $vids->addVidsFromMessage($message);
        return $vids;
    }

    protected function projectWithOrg($projectWithOrg = '')
    {
        if (!empty($projectWithOrg)) {
            return $projectWithOrg;
        }

        return $this->getProjectWithOrgFromRemote();
    }

    protected function getProjectWithOrgFromRemote($remote = 'origin', $cwd = '')
    {
        $remote = $this->getRemote($remote, $cwd);

        return $this->getProjectWithOrfFromUrl($remote);
    }

    protected function getProjectWithOrfFromUrl($remote)
    {
        $remote = preg_replace('#^git@[^:]*:#', '', $remote);
        $remote = preg_replace('#^[^:]*://[^/]/#', '', $remote);
        $remote = preg_replace('#\.git$#', '', $remote);

        return $remote;
    }

    protected function getRemote($remote = 'origin', $cwd = '')
    {
        if (!empty($cwd)) {
            $cwd = "-C $cwd";
        }
        return exec("git {$cwd} config --get remote.{$remote}.url");
    }

    /**
     * @command pr:find
     * @param $projectWithOrg The project to work on, e.g. org/project
     * @option $q Query term
     * @filter-output
     * @field-labels
     *   url: Url
     *   id: ID
     *   node_id: Node ID
     *   html_url: HTML Url
     *   diff_url: Diff Url
     *   patch_url: Patch Url
     *   issue_url: Issue Url
     *   number: Number
     *   state: State
     *   locked: Locked
     *   title: Title
     *   user: User
     *   body: Boday
     *   created_at: Created
     *   updated_at: Updated
     *   closed_at: Closed
     *   merged_at: Merged
     *   merge_commit_sha: Merge Commit
     *   assignee: Assignee
     *   assignees: Assignees
     *   requested_reviewers: Requested Reviewers
     *   requested_teams: Requested Teams
     *   labels: Labels
     *   milestone: Milestone
     *   commits_url: Commit Url
     *   review_comments_url: Review Comments Url
     *   review_comment_url: Review Comment Url
     *   comments_url: Comments Url
     *   statuses_url: Statuses Url
     *   head: Head
     *   base: Base
     *   _links: Links
     * @default-fields number,user,title
     * @default-string-field number
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
    */
    public function prFind($projectWithOrg = '', $options = ['as' => 'default', 'format' => 'yaml', 'q' => ''])
    {
        $api = $this->api($options['as']);
        $projectWithOrg = $this->projectWithOrg($projectWithOrg);
        $q = $options['q'];

        if (!empty($q)) {
            $q = $q . ' ';
        }
        $q = $q . 'repo:' . $projectWithOrg;
        $searchResults = $api->gitHubAPI()->api('search')->issues($q);
        $pullRequests = $searchResults['items'];

        $pullRequests = $this->keyById($pullRequests, 'number');
        $result = new RowsOfFields($pullRequests);
        $this->addTableRenderFunction($result);

        return $result;
    }

    /**
     * @command org:repos
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
     * @default-fields full_name,language,default_branch
     * @default-string-field full_name
     *
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function orgRepos($org, $options = ['as' => 'default', 'format' => 'table'])
    {
        $api = $this->api($options['as']);
        $pager = $api->resultPager();

        $repoApi = $api->gitHubAPI()->api('organization');
        $repos = $pager->fetchAll($repoApi, 'repositories', [$org]);

        $data = new \Consolidation\OutputFormatters\StructuredData\RowsOfFields($repos);
        $this->addTableRenderFunction($data);

        return $data;
    }

    /**
     * @command repo:cat
     */
    public function repoCat($projectWithOrgAndPath = '', $options = ['as' => 'default', 'format' => 'string'])
    {
        $api = $this->api($options['as']);

        $argParts = explode('/', $projectWithOrgAndPath, 3);
        if (count($argParts) != 3) {
            return new CommandError('First argument must include the org/owner, the project, and the path, all separated with "/"');
        }

        list($org, $project, $path) = $argParts;
        $data = $api->gitHubAPI()->api('repo')->contents()->show($org, $project, $path);

        // If the requested format is string, then return only the decoded
        // contents. In all other instances, return the entire structured
        // metadata structure, which includes the base64-encoded contents.
        if ($options['format'] == 'string') {
            $data = base64_decode($data['content']);
        }

        return $data;
    }

    /**
     * @command repo:teams
     */
    public function repoTeams($projectWithOrg = '', $options = ['as' => 'default', 'format' => 'yaml'])
    {
        $api = $this->api($options['as']);

        $projectWithOrg = $this->projectWithOrg($projectWithOrg);
        list($org, $project) = explode('/', $projectWithOrg, 2);

        $teams = $api->gitHubAPI()->api('repo')->teams($org, $project);

        $data = new \Consolidation\OutputFormatters\StructuredData\PropertyList($teams);
        $this->addTableRenderFunction($data);

        return $data;
    }

    /**
     * @command repo:info
     * @param $projectWithOrg The project to work on, e.g. org/project
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
     *
     * @return Consolidation\OutputFormatters\StructuredData\PropertyList
     */
    public function repoInfo($projectWithOrg = '', $options = ['as' => 'default', 'format' => 'table'])
    {
        $api = $this->api($options['as']);

        $projectWithOrg = $this->projectWithOrg($projectWithOrg);
        list($org, $project) = explode('/', $projectWithOrg, 2);

        $info = $api->gitHubAPI()->api('repo')->show($org, $project);

        $data = new \Consolidation\OutputFormatters\StructuredData\PropertyList($info);
        $this->addTableRenderFunction($data);

        return $data;
    }

    /**
     * @command repo:statuses
     * @field-labels
     *   url: Url
     *   avatar_url: Avatar Url
     *   id: ID
     *   state: State
     *   description: Description
     *   target_url: Target Url
     *   context: Context
     *   created_at: Created
     *   updated_at: Updated
     *   creator: Creator
     * @default-fields context,state,description
     * @default-string-field context
     */
    public function repoStatuses($projectWithOrg = '', $options = ['as' => 'default', 'format' => 'table'])
    {
        $api = $this->api($options['as']);

        $projectWithOrg = $this->projectWithOrg($projectWithOrg);
        list($org, $project) = explode('/', $projectWithOrg, 2);

        $info = $api->gitHubAPI()->api('repo')->show($org, $project);
        $statuses = $api->gitHubAPI()->api('repo')->statuses()->show($org, $project, $info['default_branch']);

        $statusResults = [];
        foreach (array_reverse($statuses) as $status) {
            $key = $status['context'];
            $statusResults[$key] = $status;
        }

        $data = new \Consolidation\OutputFormatters\StructuredData\RowsOfFields(array_values($statusResults));
        $this->addTableRenderFunction($data);

        return $data;
    }

    /**
     * @command repo:default-branch:switch
     * @aliases switch-default
     */
    public function switchDefaultBranch($projectWithOrg = '', $options = ['as' => 'default', 'branch' => 'main'])
    {
        $api = $this->api($options['as']);

        $projectWithOrg = $this->projectWithOrg($projectWithOrg);
        list($org, $project) = explode('/', $projectWithOrg, 2);

        $repoApi = $api->gitHubAPI()->api('repo');
        $info = $repoApi->show($org, $project);
        $currentDefault = $info['default_branch'];
        $newDefault = $options['branch'];

        if ($currentDefault == $newDefault) {
            $this->logger->notice("Default branch is already {default}.", ['default' => $currentDefault]);
            return;
        }

        $referencesApi = $api->gitHubAPI()->api('gitData')->references();

        // Get the sha of the current HEAD of the current default
        $currentDefaultInfo = $referencesApi->show($org, $project, "heads/$currentDefault");
        $currentHeadSha = $currentDefaultInfo['object']['sha'];

        // TODO: We could pass $api here, but that would modify the remote url of 'origin'.
        // For now we'll use whatever auth is set up for the project at the cwd.
        $workingCopy = WorkingCopy::fromDir(getcwd());

        if ($workingCopy->valid() && ($workingCopy->projectWithOrg() == $projectWithOrg)) {
            $this->configureDefaultWithWorkingCopy($workingCopy, $currentDefault, $newDefault, $currentHeadSha);
        } else {
            // Create a new branch for the new default. If it's already there,
            // then we'll assume it's at the desired SHA.
            try {
                $referencesApi->create($org, $project, ['ref' => "refs/heads/$newDefault", 'sha' => $currentHeadSha]);
            } catch (\Exception $e) {
                $this->logger->notice("Branch {new} already exists; using it as-is.", ['new' => $newDefault]);
            }
        }

        $result = $repoApi->update($org, $project, ['default_branch' => $newDefault]);
        $this->logger->notice("Set default branch to {new}.", ['new' => $newDefault]);
    }

    protected function configureDefaultWithWorkingCopy($workingCopy, $currentDefault, $newDefault, $currentHeadSha)
    {
        $statusResult = $workingCopy->status();
        if (!empty($statusResult)) {
            throw new \Exception('Working copy not clean; commit, reset or ignore all modified files.');
        }

        // TODO: Check to see if new branch already exists, as we do for the API case?
        $workingCopy->createBranch($newDefault, $currentHeadSha);

        $fixupList = ['README.md', '.travis.yml', 'composer.json', '.circleci/config.yml'];
        $alteredList = [];
        foreach ($fixupList as $file) {
            if (file_exists($file)) {
                $contents = file_get_contents($file);
                $altered = str_replace($currentDefault, $newDefault, $contents);
                if ($altered != $contents) {
                    file_put_contents($file, $altered);
                    $alteredList[] = $file;
                }
            }
        }

        $statusResult = $workingCopy->status();
        if (!empty($statusResult)) {
            passthru('git diff');

            // If we modified composer.json, update composer.lock
            if (in_array('composer.json', $alteredList)) {
                passthru('composer update');
            }

            $workingCopy->add('.');

            $workingCopy->commit("Change references to old default branch '$currentDefault' to new default branch '$newDefault'");
        }

        $workingCopy->push('origin', $newDefault);
    }

    /**
     * @command pr:show
     * @field-labels
     *   url: Url
     *   id: ID
     *   node_id: Node ID
     *   html_url: HTML Url
     *   diff_url: Diff Url
     *   patch_url: Patch Url
     *   issue_url: Issue Url
     *   number: Number
     *   state: State
     *   locked: Locked
     *   title: Title
     *   user: User
     *   body: Boday
     *   created_at: Created
     *   updated_at: Updated
     *   closed_at: Closed
     *   mergeable: Mergeable
     *   mergeable_state: Mergable State
     *   merged_at: Merged
     *   merge_commit_sha: Merge Commit
     *   assignee: Assignee
     *   assignees: Assignees
     *   requested_reviewers: Requested Reviewers
     *   requested_teams: Requested Teams
     *   labels: Labels
     *   milestone: Milestone
     *   commits_url: Commit Url
     *   review_comments_url: Review Comments Url
     *   review_comment_url: Review Comment Url
     *   comments_url: Comments Url
     *   statuses_url: Statuses Url
     *   head: Head
     *   base: Base
     *   _links: Links
     * @return Consolidation\OutputFormatters\StructuredData\PropertyList
     */
    public function prShow($projectWithOrg = '', $number = '', $options = ['as' => 'default', 'format' => 'table'])
    {
        if (empty($number) && preg_match('#^[0-9]*$#', $projectWithOrg)) {
            $number = $projectWithOrg;
            $projectWithOrg = '';
        }
        $api = $this->api($options['as']);
        $projectWithOrg = $this->projectWithOrg($projectWithOrg);

        list($org, $project) = explode('/', $projectWithOrg, 2);

        $pullRequest = $api->gitHubAPI()->api('pull_request')->show($org, $project, $number);

        $result = new PropertyList($pullRequest);
        $this->addTableRenderFunction($result);

        return $result;
    }

    /**
     * @command pr:statuses
     * @field-labels
     *   url: Url
     *   id: ID
     *   state: State
     *   description: Description
     *   node_id: Node ID
     *   context: Context
     *   avatar_url: Avatar URL
     *   target_url: Target URL
     *   creator: Creator
     *   created_at: Created
     *   updated_at: Updated
     * @default-fields id,creator,state,description
     * @default-string-field description
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function prStatuses($projectWithOrg = '', $number = '', $options = ['as' => 'default', 'format' => 'yaml'])
    {
        if (empty($number) && preg_match('#^[0-9]*$#', $projectWithOrg)) {
            $number = $projectWithOrg;
            $projectWithOrg = '';
        }
        $api = $this->api($options['as']);
        $projectWithOrg = $this->projectWithOrg($projectWithOrg);

        $pullRequestStatus = $api->prStatuses($projectWithOrg, $number);

        $result = new RowsOfFields($pullRequestStatus);
        $this->addTableRenderFunction($result);

        return $result;
    }

    /**
     * @command pr:list
     * @param $projectWithOrg The project to work on, e.g. org/project
     * @filter-output
     * @field-labels
     *   url: Url
     *   id: ID
     *   node_id: Node ID
     *   html_url: HTML Url
     *   diff_url: Diff Url
     *   patch_url: Patch Url
     *   issue_url: Issue Url
     *   number: Number
     *   state: State
     *   locked: Locked
     *   title: Title
     *   user: User
     *   body: Boday
     *   created_at: Created
     *   updated_at: Updated
     *   closed_at: Closed
     *   merged_at: Merged
     *   merge_commit_sha: Merge Commit
     *   assignee: Assignee
     *   assignees: Assignees
     *   requested_reviewers: Requested Reviewers
     *   requested_teams: Requested Teams
     *   labels: Labels
     *   milestone: Milestone
     *   commits_url: Commit Url
     *   review_comments_url: Review Comments Url
     *   review_comment_url: Review Comment Url
     *   comments_url: Comments Url
     *   statuses_url: Statuses Url
     *   head: Head
     *   base: Base
     *   _links: Links
     * @default-fields number,user,title
     * @default-string-field number
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function prList($projectWithOrg = '', $options = ['state' => 'open', 'as' => 'default', 'format' => 'table'])
    {
        $api = $this->api($options['as']);
        $projectWithOrg = $this->projectWithOrg($projectWithOrg);

        list($org, $project) = explode('/', $projectWithOrg, 2);

        $pullRequests = $api->gitHubAPI()->api('pull_request')->all($org, $project, ['state' => $options['state']]);

        $pullRequests = $this->keyById($pullRequests, 'number');

        $result = new RowsOfFields($pullRequests);
        $this->addTableRenderFunction($result);

        return $result;
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

    protected function keyById($data, $field)
    {
        return
            array_column(
                array_map(
                    function ($k) use ($data, $field) {
                        return [$data[$k][$field], $data[$k]];
                    },
                    array_keys($data)
                ),
                1,
                0
            );
    }

    protected function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }
}
