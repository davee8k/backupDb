<?php

declare(strict_types=1);

namespace Tests;

use BackupDb\BackupDb;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use LogicException;
use PDOStatement;
use PDO;

class BackupDbTest extends TestCase
{
	protected PDO $mockDb;

	protected function setUp(): void
	{
		$this->mockDb = $this->createMock(PDO::class);
	}

	public function testLoadTablesAll(): void
	{
		$req = $this->createMock(PDOStatement::class);
		$req->expects($this->once())
				->method('fetchAll')
				->willReturn(['test', 'prefix_test']);

		$this->mockDb->expects($this->once())
				->method('query')
				->with("SHOW TABLES")
				->willReturn($req);

		$backup = new BackupDb($this->mockDb);

		$this->assertEquals(['test' => true, 'prefix_test' => true], $backup->getTables());
	}

	public function testLoadTablesPrefix(): void
	{
		$req = $this->createMock(PDOStatement::class);
		$req->expects($this->once())
				->method('fetchAll')
				->willReturn(['test', 'prefix_test']);

		$this->mockDb->expects($this->once())
				->method('query')
				->with("SHOW TABLES")
				->willReturn($req);

		$backup = new BackupDb($this->mockDb, 'prefix');

		$this->assertEquals(['prefix_test' => true], $backup->getTables());
	}

	#[RunInSeparateProcess()]
	public function testBasicExport(): void
	{
		$headers = ["Content-type: application/sql", "Content-Disposition: attachment; filename=test.sql"];

		$txt = '-- Database Backup 0.87

SET NAMES utf8;
SET foreign_key_checks = 0;
SET sql_mode = \'NO_AUTO_VALUE_ON_ZERO\';

';

		$reqTables = $this->createMock(PDOStatement::class);
		$reqTables->expects($this->once())
				->method('fetchAll')
				->willReturn([]);

		$reqDb = $this->createMock(PDOStatement::class);
		$reqDb->expects($this->once())
				->method('fetchAll')
				->willReturn(['database']);

		$reqFunction = $this->createMock(PDOStatement::class);
		$reqFunction->expects($this->once())
				->method('fetchAll')
				->willReturn([]);

		$reqProcedure = $this->createMock(PDOStatement::class);
		$reqProcedure->expects($this->once())
				->method('fetchAll')
				->willReturn([]);

		$this->mockDb->method('query')
				->willReturnCallback(fn (string $property) => match ($property) {
					"SHOW TABLES" => $reqTables,
					"SELECT database()" => $reqDb,
					'SHOW FUNCTION STATUS WHERE DB = "database"' => $reqFunction,
					'SHOW PROCEDURE STATUS WHERE DB = "database"' => $reqProcedure,
					default => throw new LogicException("Unknown query: ".$property)
				});

		$this->mockDb->method('quote')
				->willReturnCallback(fn (string $property) => '"'.$property.'"');

		$backup = new BackupDb($this->mockDb);

		$this->expectOutputString($txt);
		$backup->create('test', false);

		$this->assertEquals($headers, xdebug_get_headers());
	}

	public function testFullExport(): void
	{
		$txt = '-- Database Backup 0.87

SET NAMES utf8;
SET foreign_key_checks = 0;
SET sql_mode = \'NO_AUTO_VALUE_ON_ZERO\';

DROP TABLE IF EXISTS `test`;
CREATE TABLE `test` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(10) COLLATE utf8mb4_czech_ci NOT NULL,
  `name_too` varchar(10) COLLATE utf8mb4_czech_ci NULL,
  `default` int unsigned NOT NULL DEFAULT \'11\' COMMENT \'Comment\',
  `empty` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

INSERT INTO `test` VALUES (1,\'\',null,12,null),
(1,"test","too",12,9);

DROP TABLE IF EXISTS `prefix_test`;
CREATE TABLE `prefix_test` (
  `id` int unsigned NOT NULL AUTO_INCREMENT
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

DELIMITER ;;

DROP PROCEDURE IF EXISTS `procedure`;;
CREATE PROCEDURE `procedure`(IN prefix CHAR(10))
BEGIN
  DECLARE tabname CHAR(20) DEFAULT \'test\';
END;;

DELIMITER ;

';

		$reqTables = $this->createMock(PDOStatement::class);
		$reqTables->expects($this->once())
				->method('fetchAll')
				->willReturn(['test', 'prefix_test']);

		$reqDb = $this->createMock(PDOStatement::class);
		$reqDb->expects($this->once())
				->method('fetchAll')
				->willReturn(['database']);

		$reqFunction = $this->createMock(PDOStatement::class);
		$reqFunction->expects($this->once())
				->method('fetchAll')
				->willReturn([]);

		$reqProcedure = $this->createMock(PDOStatement::class);
		$reqProcedure->expects($this->once())
				->method('fetchAll')
				->willReturn([
					['Db' => 'database', 'Name' => 'procedure', 'Type' => 'PROCEDURE']
				]);

		$reqCreateProcedure = $this->createMock(PDOStatement::class);
		$reqCreateProcedure->expects($this->once())
				->method('fetchColumn')
				->willReturn('CREATE DEFINER=`user`@`localhost` PROCEDURE `procedure`(IN prefix CHAR(10))
BEGIN
  DECLARE tabname CHAR(20) DEFAULT \'test\';
END');

		$reqCreateTest = $this->createMock(PDOStatement::class);
		$reqCreateTest->expects($this->atMost(2))
				->method('fetch')
				->willReturnOnConsecutiveCalls(['test', 'CREATE TABLE `test` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(10) COLLATE utf8mb4_czech_ci NOT NULL,
  `name_too` varchar(10) COLLATE utf8mb4_czech_ci NULL,
  `default` int unsigned NOT NULL DEFAULT \'11\' COMMENT \'Comment\',
  `empty` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci'], false);

		$reqCreatePrefix = $this->createMock(PDOStatement::class);
		$reqCreatePrefix->expects($this->atMost(2))
				->method('fetch')
				->willReturnOnConsecutiveCalls(['prefix_test', 'CREATE TABLE `prefix_test` (
  `id` int unsigned NOT NULL AUTO_INCREMENT
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci'], false);

		$reqColumnTest = $this->createMock(PDOStatement::class);
		$reqColumnTest->expects($this->once())
				->method('fetchAll')
				->willReturn([
						['Field' => 'id', 'Type' => 'int unsigned', 'Collation' => null, 'Null' => 'NO', 'PRI', null, 'auto_increment', 'select,insert,update,references', null],
						['Field' => 'name', 'Type' => 'varchar(10)', 'Collation' => 'utf8mb4_czech_ci', 'Null' => 'NO', null, 'select,insert,update,references', null],
						['Field' => 'name_too', 'Type' => 'varchar(10)', 'Collation' => 'utf8mb4_czech_ci', 'Null' => 'YES', null, 'select,insert,update,references', null],
						['Field' => 'default', 'Type' => 'int unsigned', 'Collation' => null, 'Null' => 'NO', 11, 'select,insert,update,references', 'Comment'],
						['Field' => 'empty', 'Type' => 'int', 'Collation' => null, 'Null' => 'YES', null, 'select,insert,update,references', null],
					]);

		$reqColumnPrefix = $this->createMock(PDOStatement::class);
		$reqColumnPrefix->expects($this->once())
				->method('fetchAll')
				->willReturn([
						['Field' => 'id', 'Type' => 'int unsigned', 'Collation' => null, 'Null' => 'NO', 'PRI', null, 'auto_increment', 'select,insert,update,references', null],
					]);

		$reqShowTest = $this->createMock(PDOStatement::class);
		$reqShowTest->expects($this->atMost(3))
				->method('fetch')
				->willReturnOnConsecutiveCalls(
					[1, '', null, 12, null],
					[1, 'test', 'too', 12, 9],
					false
				);

		$reqShowPrefix = $this->createMock(PDOStatement::class);
		$reqShowPrefix->expects($this->once())
				->method('fetch')
				->willReturn(false);

		$this->mockDb->method('query')
				->willReturnCallback(fn (string $property) => match ($property) {
					"SHOW TABLES" => $reqTables,
					"SELECT database()" => $reqDb,
					'SHOW FUNCTION STATUS WHERE DB = "database"' => $reqFunction,
					'SHOW PROCEDURE STATUS WHERE DB = "database"' => $reqProcedure,
					'SHOW CREATE PROCEDURE `procedure`' => $reqCreateProcedure,
					"SHOW CREATE TABLE `test`" => $reqCreateTest,
					"SHOW CREATE TABLE `prefix_test`" => $reqCreatePrefix,
					"SHOW FULL COLUMNS FROM `test`" => $reqColumnTest,
					"SHOW FULL COLUMNS FROM `prefix_test`" => $reqColumnPrefix,
					"SELECT * FROM `test`" => $reqShowTest,
					"SELECT * FROM `prefix_test`" => $reqShowPrefix,
					default => throw new LogicException("Unknown query: ".$property)
				});

		$this->mockDb->method('quote')
				->willReturnCallback(fn (string $property) => '"'.$property.'"');

		$backup = new BackupDb($this->mockDb);

		$this->assertEquals($txt, $backup->create(null, false));
	}

	public function testSetTablesFail(): void
	{
		$backup = new BackupDb($this->mockDb, null);

		$this->expectException('InvalidArgumentException');
		$this->expectExceptionMessage('List of tables must be an one-dimensional array.');

		$backup->setTables(['fail' => ['now']]);
	}

	public function testExportTableFail(): void
	{
		$backup = new BackupDb($this->mockDb, null);
		$backup->setTable('fail');

		$this->expectException('InvalidArgumentException');
		$this->expectExceptionMessage('Nonexistent table');

		$backup->create(null, false);
	}

	public function testBufferOnOff(): void
	{
		$txt = '-- Database Backup 0.87

SET NAMES utf8;
SET foreign_key_checks = 0;
SET sql_mode = \'NO_AUTO_VALUE_ON_ZERO\';

DROP TABLE IF EXISTS `prefix_test`;
CREATE TABLE `prefix_test` (
  `id` int unsigned NOT NULL AUTO_INCREMENT
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
';

		$reqDb = $this->createMock(PDOStatement::class);
		$reqDb->expects($this->once())
				->method('fetchAll')
				->willReturn(['database']);

		$reqFunction = $this->createMock(PDOStatement::class);
		$reqFunction->expects($this->once())
				->method('fetchAll')
				->willReturn([]);

		$reqProcedure = $this->createMock(PDOStatement::class);
		$reqProcedure->expects($this->once())
				->method('fetchAll')
				->willReturn([]);

		$reqCreatePrefix = $this->createMock(PDOStatement::class);
		$reqCreatePrefix->expects($this->atMost(2))
				->method('fetch')
				->willReturnOnConsecutiveCalls(['prefix_test', 'CREATE TABLE `prefix_test` (
  `id` int unsigned NOT NULL AUTO_INCREMENT
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci'], false);

		$this->mockDb->method('quote')
				->willReturnCallback(fn (string $property) => '"'.$property.'"');

		$this->mockDb->method('getAttribute')
				->willReturnCallback(fn (int $property) => match ($property) {
					PDO::ATTR_DRIVER_NAME => 'mysql',
					PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
				});

		$this->mockDb->method('setAttribute');

		$this->mockDb->method('query')
				->willReturnCallback(fn (string $property) => match ($property) {
					"SELECT database()" => $reqDb,
					'SHOW FUNCTION STATUS WHERE DB = "database"' => $reqFunction,
					'SHOW PROCEDURE STATUS WHERE DB = "database"' => $reqProcedure,
					"SHOW CREATE TABLE `prefix_test`" => $reqCreatePrefix,
					default => throw new LogicException("Unknown query: ".$property)
				});

		$backup = new BackupDb($this->mockDb, null);
		$backup->setTables(['prefix_test' => false]);

		$this->assertEquals($txt, $backup->create(null, false));
	}
}
