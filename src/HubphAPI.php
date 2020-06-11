<?php

namespace Hubph;

use Consolidation\Config\ConfigInterface;
use Hubph\Internal\EventLogger;

class HubphAPI
{
    protected $config;
    protected $token;
    protected $gitHubAPI;
    protected $eventLogger;
    protected $as = 'default';

    /**
     * HubphAPI constructor
     */
    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    public function startLogging($filename)
    {
        $this->stopLogging();
        $this->eventLogger = new EventLogger($filename);
        $this->eventLogger->start();
    }

    public function stopLogging()
    {
        if ($this->eventLogger) {
            $this->eventLogger->stop();
        }
        $this->eventLogger = null;
    }

    public function setAs($as)
    {
        if ($as != $this->as) {
            $this->as = $as;
            $this->token = false;
            $this->gitHubAPI = false;
        }
    }

    public function whoami()
    {
        $gitHubAPI = $this->gitHubAPI();
        $authenticated = $gitHubAPI->api('current_user')->show();
        return $authenticated;
    }

    public function prCreate($org, $project, $title, $body, $base, $head)
    {
        $params = [
            'title' => $title,
            'body' => $body,
            'base' => $base,
            'head' => $head,
        ];
        $response = $this->gitHubAPI()->api('pull_request')->create($org, $project, $params);
        $this->logEvent(__FUNCTION__, [$org, $project], $params, $response);
        return $this;
    }

    public function prClose($org, $project, PullRequests $prs)
    {
        foreach ($prs->prNumbers() as $n) {
            $gitHubAPI = $this->gitHubAPI();
            $gitHubAPI->api('pull_request')->update($org, $project, $n, ['state' => 'closed']);
        }
    }

    public function prMerge($org, $project, PullRequests $prs, $message, $mergeMethod = 'squash', $title = null)
    {
        // First, check to see if all of the pull requests can be merged,
        // and collect the sha hash of the head of the branch.
        $allClean = true;
        $shas = [];
        foreach ($prs->prNumbers() as $n) {
            $pullRequest = $this->gitHubAPI()->api('pull_request')->show($org, $project, $n);
            $is_clean = $pullRequest['mergeable'] && $pullRequest['mergeable_state'] == 'clean';
            if (!$is_clean) {
                return false;
            }
            $shas[$n] = $pullRequest['head']['sha'];
        }

        // Merge all of the pull requests
        foreach ($shas as $n => $sha) {
            $response = $this->gitHubAPI()->api('pull_request')->merge($org, $project, $n, $message, $sha, $mergeMethod, $title);
            $this->logEvent(__FUNCTION__, [$org, $project], [$n, $message, $sha, $mergeMethod, $title], $response);
        }
        return true;
    }

    /**
     * prCheck determines whether there are any open PRs that already exist
     * that satisfy any of the provided $vids.
     *
     * @param string $projectWithOrg org/project to check
     * @param VersionIdentifiers $vids
     * @return [int $status, PullRequests $prs] status of PRs, and a list of PR numbers
     *   - If $status is 0, then the caller should go ahead and create a new PR.
     *     The existing pull requests that would be superceded by the new PR are
     *     returned in the second parameter. These PRs could all be closed.
     *   - If $status is >0, then there is no need to create a new PR, as there
     *     are already existing PRs that are equivalent to the one that would
     *     be open. The equivalent PRs are returned in the second parameter.
     */
    public function prCheck($projectWithOrg, VersionIdentifiers $vids)
    {
        // Find all of the PRs that contain any vid
        $existingPRs = $this->existingPRs($projectWithOrg, $vids);

        // Check to see if there are PRs matching all of the vids/vvals.
        $titles = $existingPRs->titles();
        $status = $vids->allExist($titles);

        return [$status, $existingPRs];
    }

    public function prStatuses($projectWithOrg, $number)
    {
        list($org, $project) = explode('/', $projectWithOrg, 2);
        $pullRequestStatus = $this->gitHubAPI()->api('pull_request')->status($org, $project, $number);

        // Filter out the results based on 'target_url'
        $filteredResults = [];
        foreach (array_reverse($pullRequestStatus) as $id => $item) {
            $filteredResults[$item['target_url']] = $item;
        }
        $pullRequestStatus = [];
        foreach ($filteredResults as $target_url => $item) {
            $pullRequestStatus[$item['id']] = $item;
        }

        // Put the most recently updated statuses at the top of the list
        uasort(

            $pullRequestStatus,
            function ($lhs, $rhs) {
                return abs(strtotime($lhs['updated_at']) - strtotime($rhs['updated_at']));
            }
        );

        return $pullRequestStatus;
    }

    public function addTokenAuthentication($url)
    {
        $token = $this->gitHubToken();
        if (!$token) {
            return $url;
        }
        if (!preg_match('#github\.com[/:]#', $url)) {
            return $url;
        }
        $projectAndOrg = $this->projectAndOrgFromUrl($url);
        return "https://{$token}:x-oauth-basic@github.com/{$projectAndOrg}.git";
    }

    protected function projectAndOrgFromUrl($remote)
    {
        $remote = preg_replace('#^git@[^:]*:#', '', $remote);
        $remote = preg_replace('#^[^:]*://[^/]*/#', '', $remote);
        $remote = preg_replace('#\.git$#', '', $remote);

        return $remote;
    }

    protected function existingPRs($projectWithOrg, VersionIdentifiers $vids)
    {
        return $this->matchingPRs($projectWithOrg, $vids->getPreamble(), $vids->pattern());
    }

    public function matchingPRs($projectWithOrg, $preamble, $pattern = '')
    {
        $q = "repo:$projectWithOrg in:title is:pr state:open $preamble";
        $result = new PullRequests();
        $gitHubAPI = $this->gitHubAPI();
        $searchResults = $gitHubAPI->api('search')->issues($q);
        $result->addSearchResults($searchResults, $pattern);

        return $result;
    }

    public function allPRs($projectWithOrg)
    {
        $q = "repo:$projectWithOrg in:title is:pr state:open";
        $result = new PullRequests();
        $searchResults = $this->gitHubAPI()->api('search')->issues($q);
        $result->addSearchResults($searchResults);

        return $result;
    }

    /**
     * Pass an event of note to the event logger
     * @param string $event_name
     * @param array $args
     * @param array $params
     * @param array $response
     */
    protected function logEvent($event_name, $args, $params, $response)
    {
        if ($this->eventLogger) {
            $this->eventLogger->log($event_name, $args, $params, $response);
        }
    }

    /**
     * Authenticate and then return the gitHub API object.
     */
    public function gitHubAPI()
    {
        if (!$this->gitHubAPI) {
            $token = $this->gitHubToken();

            $this->gitHubAPI = new \Github\Client();
            $this->gitHubAPI->authenticate($token, null, \Github\Client::AUTH_HTTP_TOKEN);
        }
        return $this->gitHubAPI;
    }

    /**
     * Return a result pager object using our cached GitHub API client.
     */
    public function resultPager()
    {
        return new \Github\ResultPager($this->gitHubAPI());
    }

    /**
     * Look up the GitHub token set either via environment variable or in the
     * auth-token cache directory.
     */
    public function gitHubToken()
    {
        if (!$this->token) {
            $this->token = $this->getGitHubToken();
        }
        return $this->token;
    }

    protected function getGitHubToken()
    {
        $as = $this->as;
        $token = null;
        if ($as == 'default') {
            $as = $this->getConfig()->get("github.default-user");
        }

        // First preference: There is a 'path' component in preferences
        // pointing to a file containing the token.
        $github_token_cache = $this->getConfig()->get("github.personal-auth-token.$as.path");
        if (file_exists($github_token_cache)) {
            $token = trim(file_get_contents($github_token_cache));
        }

        // Second preference: There is an environment variable that begins
        // with an uppercased version of the 'as' string followed by '_TOKEN'
        if (!$token) {
            $env_name = strtoupper(str_replace('-', '_', $as)) . '_TOKEN';
            $token = getenv($env_name);
        }

        // If we read in a token from one of the preferred locations, then
        // set the GITHUB_TOKEN environment variable and return it.
        if ($token) {
            putenv("GITHUB_TOKEN=$token");
            return $token;
        }

        // Fallback: authenticate to whatever 'GITHUB_TOKEN' is already set to.
        return getenv('GITHUB_TOKEN');
    }

    protected function getConfig()
    {
        return $this->config;
    }
}
