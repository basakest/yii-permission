<?php

namespace yii\permission\tests;

use PHPUnit\Framework\TestCase;
use yii\web\Application;
use Yii;
use yii\permission\models\CasbinRule;
use Casbin\Persist\Adapters\Filter;
use Casbin\Exceptions\InvalidFilterTypeException;
use yii\db\ActiveQueryInterface;

class AdapterTest extends TestCase
{
    protected $app;

    public function testEnforce()
    {
        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data1', 'read'));

        $this->assertFalse(Yii::$app->permission->enforce('bob', 'data1', 'read'));
        $this->assertTrue(Yii::$app->permission->enforce('bob', 'data2', 'write'));

        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data2', 'read'));
        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data2', 'write'));
    }

    public function testAddPolicy()
    {
        $this->assertFalse(Yii::$app->permission->enforce('eve', 'data3', 'read'));
        Yii::$app->permission->addPermissionForUser('eve', 'data3', 'read');
        $this->assertTrue(Yii::$app->permission->enforce('eve', 'data3', 'read'));
    }

    public function testAddPolicies()
    {
        $policies = [
            ['u1', 'd1', 'read'],
            ['u2', 'd2', 'read'],
            ['u3', 'd3', 'read'],
        ];
        Yii::$app->permission->clearPolicy();
        $this->assertEquals([], Yii::$app->permission->getPolicy());
        Yii::$app->permission->addPolicies($policies);
        $this->assertEquals($policies, Yii::$app->permission->getPolicy());
    }

    public function testSavePolicy()
    {
        $this->assertFalse(Yii::$app->permission->enforce('alice', 'data4', 'read'));

        $model = Yii::$app->permission->getModel();
        $model->clearPolicy();
        $model->addPolicy('p', 'p', ['alice', 'data4', 'read']);

        $adapter = Yii::$app->permission->getAdapter();
        $adapter->savePolicy($model);
        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data4', 'read'));
    }

    public function testRemovePolicy()
    {
        $this->assertFalse(Yii::$app->permission->enforce('alice', 'data5', 'read'));

        Yii::$app->permission->addPermissionForUser('alice', 'data5', 'read');
        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data5', 'read'));

        Yii::$app->permission->deletePermissionForUser('alice', 'data5', 'read');
        $this->assertFalse(Yii::$app->permission->enforce('alice', 'data5', 'read'));
    }

    public function testRemovePolicies()
    {
        $this->assertEquals([
            ['alice', 'data1', 'read'],
            ['bob', 'data2', 'write'],
            ['data2_admin', 'data2', 'read'],
            ['data2_admin', 'data2', 'write'],
        ], Yii::$app->permission->getPolicy());

        Yii::$app->permission->removePolicies([
            ['data2_admin', 'data2', 'read'],
            ['data2_admin', 'data2', 'write'],
        ]);

        $this->assertEquals([
            ['alice', 'data1', 'read'],
            ['bob', 'data2', 'write']
        ], Yii::$app->permission->getPolicy());
    }

    public function testRemoveFilteredPolicy()
    {
        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data1', 'read'));
        Yii::$app->permission->removeFilteredPolicy(1, 'data1');
        $this->assertFalse(Yii::$app->permission->enforce('alice', 'data1', 'read'));
        $this->assertTrue(Yii::$app->permission->enforce('bob', 'data2', 'write'));
        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data2', 'read'));
        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data2', 'write'));
        Yii::$app->permission->removeFilteredPolicy(1, 'data2', 'read');
        $this->assertTrue(Yii::$app->permission->enforce('bob', 'data2', 'write'));
        $this->assertFalse(Yii::$app->permission->enforce('alice', 'data2', 'read'));
        $this->assertTrue(Yii::$app->permission->enforce('alice', 'data2', 'write'));
        Yii::$app->permission->removeFilteredPolicy(2, 'write');
        $this->assertFalse(Yii::$app->permission->enforce('bob', 'data2', 'write'));
        $this->assertFalse(Yii::$app->permission->enforce('alice', 'data2', 'write'));
    }

    public function testLoadFilteredPolicy()
    {
        Yii::$app->permission->clearPolicy();
        $adapter = Yii::$app->permission->getAdapter();
        $adapter->setFiltered(true);
        $this->assertEquals([], Yii::$app->permission->getPolicy());

        // invalid filter type
        try {
            $filter = ['alice', 'data1', 'read'];
            Yii::$app->permission->loadFilteredPolicy($filter);
            $exception = InvalidFilterTypeException::class;
            $this->fail("Expected exception $exception not thrown");
        } catch (InvalidFilterTypeException $exception) {
            $this->assertEquals("invalid filter type", $exception->getMessage());
        }

        // string
        $filter = "v0 = 'bob'";
        Yii::$app->permission->loadFilteredPolicy($filter);
        $this->assertEquals([
            ['bob', 'data2', 'write']
        ], Yii::$app->permission->getPolicy());

        // Filter
        $filter = new Filter(['v2'], ['read']);
        Yii::$app->permission->loadFilteredPolicy($filter);
        $this->assertEquals([
            ['alice', 'data1', 'read'],
            ['data2_admin', 'data2', 'read'],
        ], Yii::$app->permission->getPolicy());

        // Closure
        Yii::$app->permission->loadFilteredPolicy(function (ActiveQueryInterface &$entity) {
            $entity->where(['v1' => 'data1']);
        });

        $this->assertEquals([
            ['alice', 'data1', 'read'],
        ], Yii::$app->permission->getPolicy());
    }

    public function createApplication()
    {
        $config = require __DIR__ . '/../vendor/yiisoft/yii2-app-basic/config/web.php';
        $config['components']['permission'] = require __DIR__ . '/../config/permission.php';

        $config['components']['db']['dsn'] = 'mysql:host=' . $this->env('DB_HOST', '127.0.0.1') . ';port=' . $this->env('DB_PORT', '3306') . ';dbname=' . $this->env('DB_DATABASE', 'casbin');
        $config['components']['db']['username'] = $this->env('DB_USERNAME', 'root');
        $config['components']['db']['password'] = $this->env('DB_PASSWORD', '');

        return new Application($config);
    }

    /**
     * init table.
     */
    protected function initTable()
    {
        $db = CasbinRule::getDb();
        $tableName = CasbinRule::tableName();
        $table = $db->getTableSchema($tableName);
        if ($table) {
            $db->createCommand()->dropTable($tableName)->execute();
        }

        Yii::$app->permission->init();

        Yii::$app->db->createCommand()->batchInsert(
            $tableName,
            ['ptype', 'v0', 'v1', 'v2'],
            [
                ['p', 'alice', 'data1', 'read'],
                ['p', 'bob', 'data2', 'write'],
                ['p', 'data2_admin', 'data2', 'read'],
                ['p', 'data2_admin', 'data2', 'write'],
                ['g', 'alice', 'data2_admin', null],
            ]
        )->execute();
    }

    /**
     * Refresh the application instance.
     */
    protected function refreshApplication()
    {
        $this->app = $this->createApplication();
    }

    /**
     * This method is called before each test.
     */
    protected function setUp()/* The :void return type declaration that should be here would cause a BC issue */
    {
        if (!$this->app) {
            $this->refreshApplication();
        }

        $this->initTable();
    }

    /**
     * This method is called after each test.
     */
    protected function tearDown()/* The :void return type declaration that should be here would cause a BC issue */
    {
    }

    protected function env($key, $default = null)
    {
        $value = getenv($key);
        if (is_null($default)) {
            return $value;
        }

        return false === $value ? $default : $value;
    }
}
