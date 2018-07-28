<?php

namespace Hubph;

use Consolidation\Config\ConfigInterface;

class HubphAPI
{
    protected $config;
    protected $token;
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
        $this->gitHubAPI()->api('pull_request')->create($org, $project, $params);
        return $this;
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
        // Find all of the PRs that contain any vid
        $existingPRs = $this->existingPRs($projectWithOrg, $vids);

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

    public function addTokenAuthentication($url)
    {
        $token = $this->gitHubToken();
        if (!$token) {
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

    protected function existingPRs($projectWithOrg, $vids)
    {
        $preamble = $vids->getPreamble();
        $q = "repo:$projectWithOrg in:title is:pr state:open $preamble";
        $result = new PullRequests();
        $gitHubAPI = $this->gitHubAPI();
        $searchResults = $gitHubAPI->api('search')->issues($q);
        $result->addSearchResults($searchResults, $vids->pattern());

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
        if (!$this->token) {
            $this->token = $this->getGitHubToken();
        }
        return $this->token;
    }

    protected function getGitHubToken()
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
