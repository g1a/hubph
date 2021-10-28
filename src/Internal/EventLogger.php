<?php

namespace Hubph\Internal;

use Hubph\EventLoggerInterface;

class EventLogger implements EventLoggerInterface
{
    protected $filename;

    /**
     * EventLogger constructor
     */
    public function __construct($filename)
    {
        $this->filename = $filename;
    }

    public function start()
    {
        if (file_exists($this->filename)) {
            @unlink($this->filename);
        }
    }

    public function stop()
    {
    }

    /**
     * Write an event into the event log
     * @param string $event_name
     * @param array $args
     * @param array $params
     * @param array $response
     */
    public function log($event_name, $args, $params, $response)
    {
        $this->writeHeader();
        $entry = $this->getEntry($event_name, $args, $params, $response);
        file_put_contents($this->filename, $entry, FILE_APPEND);
    }

    protected function writeHeader()
    {
        if (!file_exists($this->filename)) {
            file_put_contents($this->filename, $this->getHeader());
        }
    }

    protected function getHeader()
    {
        return <<<EOT
# Event log
# ---------

EOT;
    }

    protected function getEntry($event_name, $args, $params, $response)
    {
        $args_string = json_encode($args);
        $params_string = json_encode($params);
        $response_string = json_encode($response);

        return <<<EOT
name    : $event_name
args    : $args_string
params  : $params_string
response: $response_string
EOT;
    }
}
