<?php

namespace Hubph;

use PHPUnit\Framework\TestCase;

class VersionIdentifierTest extends TestCase
{
    /**
     * Data provider for testAddVidsFromMessage.
     */
    public function addVidsFromMessageTestValues()
    {
        return [
            ['WordPress 4.9.8', 'WordPress 4.9.8', '', ''],
            ['WordPress 4.9.8', 'Update to WordPress 4.9.8; for more information, see https://example.com/info', '', ''],
            ['Update to WordPress 4.9.8', 'Update to WordPress 4.9.8; for more information, see https://example.com/info', 'Update to WordPress ', ''],
            ['php-7.2.8', 'Update to php-5.6.37, php-7.0.31, php-7.1.20, and php-7.2.8', 'php-', ''],
            ['php-5.6.37, php-7.0.31, php-7.1.20, and php-7.2.8', 'Update to php-5.6.37, php-7.0.31, php-7.1.20, and php-7.2.8', 'php-#.#.', '#'],
        ];
    }

    //        ['WordPress 4.9.8', 'WordPress 4.9.8', 'Update to WordPress ', ''],

    /**
     * Test our example class. Each time this function is called, it will
     * be passed data from the data provider function idendified by the
     * dataProvider annotation.
     *
     * @dataProvider addVidsFromMessageTestValues
     */
    public function testAddVidsFromMessage($expected, $message, $vidPattern, $vvalPattern)
    {
        $vids = new VersionIdentifiers();
        $vids->setVidPattern($vidPattern);
        $vids->setVvalPattern($vvalPattern);
        $vids->addVidsFromMessage($message);
        $actual = (string) $vids;
        $this->assertEquals($expected, $actual);
    }
}
