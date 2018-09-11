<?php

namespace Hubph\Internal;

use PHPUnit\Framework\TestCase;

class EventLoggerTest extends TestCase
{
    public function eventLoggerTestParameters()
    {
        return [
            [
                '# Event log
# ---------
name    : event name
args    : ["arg1","arg2"]
params  : ["param1","param2"]
response: ["response"]',
                'event name',
                ['arg1', 'arg2'],
                ['param1', 'param2'],
                ['response'],
            ],
        ];
    }
    /**
     * @dataProvider eventLoggerTestParameters
     */
    public function testEventLogger($expected, $event_name, $args, $params, $response)
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'test_event_logger');
        $logger = new EventLogger($outputFile);
        $logger->start();
        $logger->log($event_name, $args, $params, $response);
        $logger->stop();

        $this->assertFileExists($outputFile);
        $actual = file_get_contents($outputFile);
        @unlink($outputFile);

        $this->assertEquals($expected, $actual);
    }
}
