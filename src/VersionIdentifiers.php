<?php

namespace Hubph;

class VersionIdentifiers
{
    protected $data = [];
    protected $vidPattern;
    protected $vvalPattern;
    protected $preamble = 'Update to ';

    const DEFAULT_VID = '[A-Za-z_-]+ ?';
    const DEFAULT_VVAL = '#.#.#';
    const EXTRA = '(?:(stable|beta|b|RC|alpha|a|patch|pl|p)((?:[.-]?\d+)*+)?)?([.-]?dev)?';
    const NUMBER = '[0-9]+';
    const OPTIONAL_NUMBER = '[0-9]*';

    /**
     * VersionIdentifiers constructor
     */
    public function __construct()
    {
        $this->vidPattern = '';
        $this->vvalPattern = '';
    }

    public function getPreamble()
    {
        return $this->preamble;
    }

    public function setPreamble($preamble)
    {
        $this->preamble = $preamble;
    }

    public function getVidPattern()
    {
        return empty($this->vidPattern) ? self::DEFAULT_VID : $this->vidPattern;
    }

    public function setVidPattern($vidPattern)
    {
        $this->vidPattern = $vidPattern;
    }

    public function getVvalPattern()
    {
        return empty($this->vvalPattern) ? self::DEFAULT_VVAL : $this->vvalPattern;
    }

    public function setVvalPattern($vvalPattern)
    {
        $this->vvalPattern = $vvalPattern;
    }

    /**
     * Multiple provided value with our multiplier
     *
     * @param $value multiplicand
     * @return integer product of multiplier and multiplicand
     */
    public function add($vid, $vval)
    {
        $this->data[$vid] = $vval;
    }

    public function pattern()
    {
        $vidPattern = $this->getVidPattern();
        $vvalPattern = $this->getVvalPattern();

        $vid_vval_regex = "({$vidPattern})({$vvalPattern}[._-]?)" . self::EXTRA;

        $vid_vval_regex = str_replace('.-', '\\.?' . self::OPTIONAL_NUMBER, $vid_vval_regex);
        $vid_vval_regex = str_replace('.#', '\\.#', $vid_vval_regex);
        $vid_vval_regex = str_replace('#', self::NUMBER, $vid_vval_regex);

        return $vid_vval_regex;
    }

    /**
     * Given a simple message like "Update to WordPress 4.9.8", return the
     * correct vid/vval pair. In this case, the vid is "WordPress " and the
     * vval is "4.9.8".
     *
     * @param string $message a commit message
     */
    public function addVidsFromMessage($message)
    {
        $vid_vval_regex = $this->pattern();

        if (!preg_match_all("#$vid_vval_regex#", $message, $matches, PREG_SET_ORDER)) {
            throw new \Exception('Message does not contain a semver release identifier, e.g.: Update to myproject-1.2.3');
        }
        foreach ($matches as $matchset) {
            array_shift($matchset);
            $vid = array_shift($matchset);
            $vval = implode('', $matchset);

            // Trim trailing punctuation.
            $vval = preg_replace('#[^0-9a-zA-Z]*$#', '', $vval);

            $this->add($vid, $vval);
        }
    }

    /**
     * Check to see if a list of PR titles collectively contain all of the
     * vids with vvals.
     *
     * @param string[] $titles
     */
    public function allExist($titles)
    {
        // If we are empty do we return 'true' or 'false'? Maybe throw.
        if ($this->isEmpty()) {
            return false;
        }

        foreach ($this->all() as $value) {
            if (!$this->someTitleContains($titles, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     *  foreach ($existingPRs as $pr) {
     *    if (strpos($pr['title'], $value) !== false) {
     */
    protected function someTitleContains($titles, $value)
    {
        foreach ($titles as $title) {
            if (strpos($title, $value) !== false) {
                return true;
            }
        }
        return false;
    }

    public function isEmpty()
    {
        return empty($this->data);
    }

    public function all()
    {
        $result = [];
        foreach ($this->data as $vid => $vval) {
            $result[] = "{$vid}{$vval}";
        }
        return $result;
    }

    public function ids()
    {
        return array_keys($this->data);
    }

    public function __toString()
    {
        if ($this->isEmpty()) {
            return '';
        }

        $all = $this->all();

        $last = array_pop($all);

        if (empty($all)) {
            return $last;
        }

        return implode(', ', $all) . ", and $last";
    }
}
