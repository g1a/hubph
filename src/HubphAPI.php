<?php

namespace Hubph;

use Consolidation\Config\ConfigInterface;

class HubphAPI
{
    protected $config;
    protected $gitHubAPI;
    protected $as = 'default';

    /**
     * HubphAPI constructor
     */
    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    public function setAs($as)
    {
        if ($as != $this->as) {
            $this->as = $as;
            $this->gitHubAPI = false;
        }
    }

    public function whoami()
    {
        $gitHubAPI = $this->gitHubAPI();
        $authenticated = $gitHubAPI->api('current_user')->show();
        return $authenticated;
    }

    public function prClose($org, $project, $number)
    {
        foreach ((array)$number as $n) {
            $gitHubAPI = $this->gitHubAPI();
            $gitHubAPI->api('pull_request')->update($org, $project, $n, ['state' => 'closed']);
        }
    }

    public function prCheck($projectWithOrg, $vids)
    {
        $gitHubAPI = $this->gitHubAPI();

        // Find all of the PRs that contain any vid
        $existingPRs = $this->existingPRs($gitHubAPI, $projectWithOrg, $vids);

        // Check to see if there are PRs matching all of the vids/vvals.
        // If so, exit with a message and do nothing.
        $titles = $existingPRs->titles();
        if ($vids->allExist($titles)) {
            return [2, "Pull requests already exist; nothing more to do."];
        }

        // Check to see if there are PRs matching SOME of the vids (with
        // or without the matching vvals).  If so, close all that match.
        if ($existingPRs->isEmpty()) {
            return [0, "No open pull requests that need to be closed."];
        }

        return [0, $existingPRs->prNumbers()];
    }

    protected function existingPRs($gitHubAPI, $projectWithOrg, $vids)
    {
        $base_q = "repo:$projectWithOrg in:title is:pr state:open";
        $result = new PullRequests();

        foreach ($vids->ids() as $vid) {
            // TODO: we could exit early if $result already contains all $vid/$vval values

            $q = "$base_q $vid";
            $searchResults = $gitHubAPI->api('search')->issues($q);

            $result->addSearchResults($searchResults);
        }

        return $result;
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
     * Look up the GitHub token set either via environment variable or in the
     * auth-token cache directory.
     */
    public function gitHubToken()
    {
        $as = $this->as;
        if ($as == 'default') {
            $as = $this->getConfig()->get("github.default-user");
        }
        $github_token_cache = $this->getConfig()->get("github.personal-auth-token.$as.path");
        if (file_exists($github_token_cache)) {
            $token = trim(file_get_contents($github_token_cache));
            putenv("GITHUB_TOKEN=$token");
        } else {
            $token = getenv('GITHUB_TOKEN');
        }

        return $token;
    }

    protected function getConfig()
    {
        return $this->config;
    }
}
