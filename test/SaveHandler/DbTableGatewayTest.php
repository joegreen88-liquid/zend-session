<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace ZendTest\Session\SaveHandler;

use Zend\Session\SaveHandler\DbTableGateway;
use Zend\Session\SaveHandler\DbTableGatewayOptions;
use Zend\Db\Adapter\Adapter;
use Zend\Db\TableGateway\TableGateway;
use ZendTest\Session\TestAsset\TestDbTableGatewaySaveHandler;

/**
 * Unit testing for DbTableGateway include all tests for
 * regular session handling
 *
 * @group      Zend_Session
 * @group      Zend_Db_Table
 * @covers Zend\Session\SaveHandler\DbTableGateway
 */
class DbTableGatewayTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * @var TableGateway
     */
    protected $tableGateway;

    /**
     * @var DbTableGatewayOptions
     */
    protected $options;

    /**
     * Array to collect used DbTableGateway objects, so they are not
     * destroyed before all tests are done and session is not closed
     *
     * @var array
     */
    protected $usedSaveHandlers = [];

    /**
     * Test data container.
     *
     * @var array
     */
    private $testArray;

    /**
     * Setup performed prior to each test method
     *
     * @return void
     */
    public function setUp()
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Zend\Session\SaveHandler\DbTableGateway tests are not enabled due to missing PDO_Sqlite extension');
        }

        $this->options = new DbTableGatewayOptions([
            'nameColumn' => 'name',
            'idColumn'   => 'id',
            'dataColumn' => 'data',
            'modifiedColumn' => 'modified',
            'lifetimeColumn' => 'lifetime',
        ]);

        $this->setupDb($this->options);
        $this->testArray = ['foo' => 'bar', 'bar' => ['foo' => 'bar']];
    }

    /**
     * Tear-down operations performed after each test method
     *
     * @return void
     */
    public function tearDown()
    {
        if ($this->adapter) {
            $this->dropTable();
        }
    }

    public function testReadWrite()
    {
        $this->usedSaveHandlers[] = $saveHandler = new DbTableGateway($this->tableGateway, $this->options);
        $saveHandler->open('savepath', 'sessionname');

        $id = '242';

        $this->assertTrue($saveHandler->write($id, serialize($this->testArray)));

        $data = unserialize($saveHandler->read($id));
        $this->assertEquals($this->testArray, $data, 'Expected ' . var_export($this->testArray, 1) . "\nbut got: " . var_export($data, 1));
    }

    public function testReadWriteComplex()
    {
        $this->usedSaveHandlers[] = $saveHandler = new DbTableGateway($this->tableGateway, $this->options);
        $saveHandler->open('savepath', 'sessionname');

        $id = '242';

        $this->assertTrue($saveHandler->write($id, serialize($this->testArray)));

        $this->assertEquals($this->testArray, unserialize($saveHandler->read($id)));
    }

    public function testReadWriteTwice()
    {
        $this->usedSaveHandlers[] = $saveHandler = new DbTableGateway($this->tableGateway, $this->options);
        $saveHandler->open('savepath', 'sessionname');

        $id = '242';

        $this->assertTrue($saveHandler->write($id, serialize($this->testArray)));

        $this->assertEquals($this->testArray, unserialize($saveHandler->read($id)));

        $this->assertTrue($saveHandler->write($id, serialize($this->testArray)));

        $this->assertEquals($this->testArray, unserialize($saveHandler->read($id)));
    }

    public function testReadShouldAlwaysReturnString()
    {
        $this->usedSaveHandlers[] = $saveHandler = new DbTableGateway($this->tableGateway, $this->options);
        $saveHandler->open('savepath', 'sessionname');

        $id = '242';

        $data = $saveHandler->read($id);

        $this->assertTrue(is_string($data));
    }

    public function testDestroyReturnsTrueEvenWhenSessionDoesNotExist()
    {
        $this->usedSaveHandlers[] = $saveHandler = new DbTableGateway($this->tableGateway, $this->options);
        $saveHandler->open('savepath', 'sessionname');

        $id = '242';

        $result = $saveHandler->destroy($id);

        $this->assertTrue($result);
    }

    public function testDestroyReturnsTrueWhenSessionIsDeleted()
    {
        $this->usedSaveHandlers[] = $saveHandler = new DbTableGateway($this->tableGateway, $this->options);
        $saveHandler->open('savepath', 'sessionname');

        $id = '242';

        $this->assertTrue($saveHandler->write($id, serialize($this->testArray)));

        $result = $saveHandler->destroy($id);

        $this->assertTrue($result);
    }
    
    public function testExpiredSessionDoesNotCauseARecursion()
    {
        // puts an expired session in the db
        $query = "
INSERT INTO `sessions` (
    `{$this->options->getIdColumn()}`,
    `{$this->options->getNameColumn()}`,
    `{$this->options->getModifiedColumn()}`,
    `{$this->options->getLifetimeColumn()}`,
    `{$this->options->getDataColumn()}`
) VALUES (
    '123', 'zend-session-test', ".(time() - 31).", 30, 'foobar'
);
";
        $this->adapter->query($query, Adapter::QUERY_MODE_EXECUTE);

        $this->usedSaveHandlers[] = $saveHandler = new TestDbTableGatewaySaveHandler($this->tableGateway, $this->options);
        $saveHandler->open('savepath', 'zend-session-test');

        $this->assertSame(0, $saveHandler->getNumReadCalls());
        $this->assertSame(0, $saveHandler->getNumDestroyCalls());

        $success = (boolean) $saveHandler->read('123');
        $this->assertFalse($success);

        $this->assertSame(2, $saveHandler->getNumReadCalls());
        $this->assertSame(1, $saveHandler->getNumDestroyCalls());

        // cleans the test record from the db
        $this->adapter->query("DELETE FROM `sessions` WHERE `{$this->options->getIdColumn()}` = '123';");
    }

    /**
     * Sets up the database connection and creates the table for session data
     *
     * @param  \Zend\Session\SaveHandler\DbTableGatewayOptions $options
     * @return void
     */
    protected function setupDb(DbTableGatewayOptions $options)
    {
        $this->adapter = new Adapter([
            'driver' => 'pdo_sqlite',
            'database' => ':memory:',
        ]);


        $query = <<<EOD
CREATE TABLE `sessions` (
    `{$options->getIdColumn()}` text NOT NULL,
    `{$options->getNameColumn()}` text NOT NULL,
    `{$options->getModifiedColumn()}` int(11) default NULL,
    `{$options->getLifetimeColumn()}` int(11) default NULL,
    `{$options->getDataColumn()}` text,
    PRIMARY KEY (`{$options->getIdColumn()}`, `{$options->getNameColumn()}`)
);
EOD;
        $this->adapter->query($query, Adapter::QUERY_MODE_EXECUTE);
        $this->tableGateway = new TableGateway('sessions', $this->adapter);
    }

    /**
     * Drops the database table for session data
     *
     * @return void
     */
    protected function dropTable()
    {
        if (!$this->adapter) {
            return;
        }
        $this->adapter->query('DROP TABLE sessions', Adapter::QUERY_MODE_EXECUTE);
    }
}
