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

        // Go ahead and create the requested PR

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
        $this->alterPRTables($result);

        return $result;
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

        $pullRequests = $api->gitHubAPI()->api('pull_request')->show($org, $project, $number);

        $result = new PropertyList($pullRequests);
        $this->alterPRTables($result);

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
        $this->alterPRTables($result);

        return $result;
    }

    protected function alterPRTables($data)
    {
        $data->addRendererFunction(
            function ($key, $cellData, FormatterOptions $options, $rowData) {
                if (is_array($cellData)) {
                    if (empty($cellData)) {
                        return '';
                    }
                    foreach (['login', 'label'] as $k) {
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

    /**
     * @hook alter @filter-output
     * @option $filter Filter output based on provided expression
     * @default $filter ''
     */
    public function filterOutput($result, CommandData $commandData)
    {
        $expr = $commandData->input()->getOption('filter');
        if (!empty($expr)) {
            $factory = LogicalOpFactory::get();
            $op = $factory->evaluate($expr);
            $filter = new FilterOutputData();
            $result = $filter->filter($result, $op);
        }

        return $result;
    }

    protected function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }
}
