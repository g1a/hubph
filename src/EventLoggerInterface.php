<?php

namespace Hubph;

interface EventLoggerInterface
{
    /**
     * start is called when logging starts
     */
    public function start();

    /**
     * stop is called when logging stops
     */
    public function stop();

    /**
     * Write an event into the event log
     * @param string $event_name
     * @param array $args
     * @param array $params
     * @param array $response
     */
    public function log($event_name, $args, $params, $response);
}
