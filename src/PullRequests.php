<?php

namespace Hubph;

class PullRequests
{
    protected $prs = [];

    /**
     * PullRequests constructor
     */
    public function __construct()
    {
    }

    public function addSearchResults($searchResults)
    {
        //print "Search results with $vid: ($q)\n";
        //var_export($searchResults);
        //print "\n";

        $total = $searchResults['total_count'];
        $incomplete = $searchResults['incomplete_results'];

        foreach ($searchResults['items'] as $pr) {
            $this->add($pr);
        }
    }

    public function add($pr)
    {
        $this->prs[$pr['number']] = $pr;
    }

    public function titles()
    {
        $titles = array_map(
            function ($pr) {
                return $pr['title'];
            }, $this->prs
        );
        return $titles;
    }

    public function prNumbers()
    {
        return array_keys($this->prs);
    }

    public function isEmpty()
    {
        return empty($this->prs);
    }
}
