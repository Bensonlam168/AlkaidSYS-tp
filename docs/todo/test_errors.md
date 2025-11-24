% cd /Users/Benson/Code/AlkaidSYS-tp && php vendor/bin/phpunit
PHPUnit 11.5.44 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.28
Configuration: /Users/Benson/Code/AlkaidSYS-tp/phpunit.xml

...III...........EEEEEEEEEEEEEEEE............EEEEEE........EEEE   63 / 63 (100%)

Time: 00:00.220, Memory: 12.00 MB

There were 26 errors:

1) Tests\Unit\Lowcode\FormDesigner\FormDataManagerTest::testSaveNewData
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:64
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:51

2) Tests\Unit\Lowcode\FormDesigner\FormDataManagerTest::testUpdateData
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:64
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:51

3) Tests\Unit\Lowcode\FormDesigner\FormDataManagerTest::testGetData
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:64
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:51

4) Tests\Unit\Lowcode\FormDesigner\FormDataManagerTest::testDeleteData
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:64
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:51

5) Tests\Unit\Lowcode\FormDesigner\FormDataManagerTest::testListData
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:64
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:51

6) Tests\Unit\Lowcode\FormDesigner\FormDataManagerTest::testValidationFailure
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:64
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormDataManagerTest.php:51

7) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testCreateFormSchema
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

8) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testGetFormSchemaByName
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

9) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testCaching
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

10) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testUpdateFormSchema
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

11) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testDeleteFormSchema
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

12) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testListForms
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

13) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testDuplicateForm
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

14) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testSchemaValidation
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

15) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testRequiredFieldsValidation
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

16) Tests\Unit\Lowcode\FormDesigner\FormSchemaManagerTest::testTenantIsolation
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/connector/Mysql.php:68
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:442
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:399
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:361
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:428
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:480
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/Query.php:327
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/concern/WhereQuery.php:43
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Lowcode/FormDesigner/FormSchemaManagerTest.php:35

17) Tests\Unit\Schema\SchemaBuilderTest::testCreateTable
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:688
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/infrastructure/Schema/SchemaBuilder.php:110
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Schema/SchemaBuilderTest.php:24

18) Tests\Unit\Schema\SchemaBuilderTest::testHasTable
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:688
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/infrastructure/Schema/SchemaBuilder.php:110
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Schema/SchemaBuilderTest.php:24

19) Tests\Unit\Schema\SchemaBuilderTest::testAddColumn
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:688
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/infrastructure/Schema/SchemaBuilder.php:110
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Schema/SchemaBuilderTest.php:24

20) Tests\Unit\Schema\SchemaBuilderTest::testDropColumn
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:688
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/infrastructure/Schema/SchemaBuilder.php:110
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Schema/SchemaBuilderTest.php:24

21) Tests\Unit\Schema\SchemaBuilderTest::testDropTable
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:688
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/infrastructure/Schema/SchemaBuilder.php:110
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Schema/SchemaBuilderTest.php:24

22) Tests\Unit\Schema\SchemaBuilderTest::testHasColumn
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:688
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/infrastructure/Schema/SchemaBuilder.php:110
/Users/Benson/Code/AlkaidSYS-tp/tests/Unit/Schema/SchemaBuilderTest.php:24

23) Tests\Feature\Lowcode\FormApiTest::testControllerInstantiation
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Feature/Lowcode/FormApiTest.php:32

24) Tests\Feature\Lowcode\FormApiTest::testManagerInstantiation
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Feature/Lowcode/FormApiTest.php:32

25) Tests\Feature\Lowcode\FormApiTest::testFormSchemaCrud
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Feature/Lowcode/FormApiTest.php:32

26) Tests\Feature\Lowcode\FormApiTest::testFormDataApi
think\db\exception\PDOException: SQLSTATE[HY000] [1049] Unknown database 'alkaid_sys'

/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:861
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/db/PDOConnection.php:704
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-orm/src/DbManager.php:398
/Users/Benson/Code/AlkaidSYS-tp/vendor/topthink/think-container/src/Facade.php:97
/Users/Benson/Code/AlkaidSYS-tp/tests/Feature/Lowcode/FormApiTest.php:32

ERRORS!
Tests: 63, Assertions: 94, Errors: 26, PHPUnit Deprecations: 1, Incomplete: 3.