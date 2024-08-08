<?php

declare(strict_types=1);

namespace BackupDb;

use InvalidArgumentException;
use PDO;

/**
 * Simple database export to sql
 *
 * @author DaVee8k
 * @license https://unlicense.org/
 * @version 0.87dev
 */
class BackupDb
{
	/** @var float */
	public const VERSION = 0.87;

	/** @var int	Number of rows in one insert */
	public static $maxRows = 1000;
	/** @var PDO */
	protected $pdo;
	/** @var bool */
	protected $procedures;
	/** @var array<string, bool> */
	protected $tables = [];
	/** @var bool */
	protected $enableBuffer = false;

	/**
	 * @param PDO $pdo
	 * @param string|null $loadPrefix	Null for disable table load -> insert tables manually
	 * @param bool $procedures
	 */
	public function __construct(PDO $pdo, ?string $loadPrefix = '', bool $procedures = true)
	{
		$this->pdo = $pdo;
		$this->procedures = $procedures;
		if ($loadPrefix !== null) {
			$this->loadTables($loadPrefix);
		}
	}

	/**
	 * Get list of tables to export
	 * @return array<string, bool>
	 */
	public function getTables(): array
	{
		return $this->tables;
	}

	/**
	 * Set list of tables to export
	 * @param array<string, bool> $tables
	 * @throws InvalidArgumentException
	 */
	public function setTables(array $tables): void
	{
		if (count($tables) !== count($tables, COUNT_RECURSIVE)) {
			throw new InvalidArgumentException('List of tables must be an one-dimensional array.');
		}
		$this->tables = $tables;
	}

	/**
	 * Add table to export
	 * @param string $table		table name
	 * @param bool $data		should export table data
	 */
	public function setTable(string $table, bool $data = true): void
	{
		$this->tables[$table] = $data;
	}

	/**
	 * Export selected tables to sql format
	 * @param string|null $fileName		export as downloadable file or return as string
	 * @param bool $compress		should be output compressed (gzip)
	 * @return string|void
	 */
	public function create(?string $fileName = null, bool $compress = false)
	{
		$this->disableBuffer();

		$fnCompress = null;
		if ($compress) {
			if (!is_callable('gzencode')) {
				throw new InvalidArgumentException('Missing gzip support');
			}

			$fnCompress = function ($string) {
				return gzencode($string, -1, FORCE_GZIP);
			};
		}

		ob_start($fnCompress, $fileName ? 1048576 : 0);

		if ($fileName) {
			header('Content-type: application/'.($compress ? 'x-gzip' : 'sql'));
			header('Content-Disposition: attachment; filename='.$fileName.'.sql'.($compress ? '.gz' : ''));
		}

		echo "-- Database Backup ".BackupDb::VERSION."\n\n";
		$this->exportData();

		$this->enableBuffer();

		if (!$fileName) {
			$txt = ob_get_contents();
			ob_end_clean();
			return (string) $txt;
		}

		ob_end_flush();
	}

	/**
	 * Disable buffer for mysql to lower memory consumption
	 */
	protected function disableBuffer(): void
	{
		if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) == 'mysql') {
			if ($this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) == true) {
				$this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
				$this->enableBuffer = true;
			}
		}
	}

	/**
	 * Re-enable disabled buffer
	 */
	protected function enableBuffer(): void
	{
		if ($this->enableBuffer) {
			if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) == 'mysql') {
				$this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
			}
		}
	}

	/**
	 * Load list of tables with selected prefix
	 * @param string $prefix
	 */
	protected function loadTables(string $prefix): void
	{
		$req = $this->pdo->query('SHOW TABLES', PDO::FETCH_COLUMN);
		if ($req) {
			foreach ($req->fetchAll() as $tab) {
				if (!$prefix || stripos($tab, $prefix) === 0) {
					$this->tables[(string) $tab] = true;
				}
			}
		}
	}

	/**
	 * Start exporting data from database
	 */
	protected function exportData(): void
	{
		echo "SET NAMES utf8;\nSET foreign_key_checks = 0;\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
		// table export
		foreach ($this->tables as $table => $data) {
			echo "DROP TABLE IF EXISTS `".$table."`;\n";
			$req = $this->pdo->query('SHOW CREATE TABLE `'.$table.'`', PDO::FETCH_NUM);
			if ($req) {
				// unbuffered queries
				while ($row = $req->fetch()) {
					echo $row[1].";\n";
				}
			}
			// data export
			if ($data) {
				$this->printData($table);
			}
		}

		// procedures
		if ($this->procedures) {
			$req = $this->pdo->query("SELECT database()");
			if ($req) {
				foreach ($req->fetchAll(PDO::FETCH_COLUMN) as $database) {
					$backUp = $this->getProcedures($database);
					if ($backUp) {
						echo "DELIMITER ;;\n\n".$backUp."DELIMITER ;\n\n";
					}
				}
			}
		}
	}

	/**
	 * Export data from table
	 * @param string $table
	 */
	protected function printData(string $table): void
	{
		$escape = $this->getEscapeRules($table);

		$req = $this->pdo->query('SELECT * FROM `'.$table.'`', PDO::FETCH_NUM);
		if ($req !== false) {
			$rows = 0;
			while ($row = $req->fetch()) {
				echo $rows++ % self::$maxRows == 0 ? ($rows == 1 ? '' : ';')."\nINSERT INTO `".$table."` VALUES (" : ",\n(";
				$this->printDataRow($row, $escape);
				echo ")";
			}
			echo $rows > 0 ? ";\n\n" : "\n";
		} else {
			throw new InvalidArgumentException("Nonexistent table");
		}
	}

	/**
	 * Export row from database
	 * @param array<int, mixed> $data
	 * @param array<int, bool> $escape
	 */
	protected function printDataRow(array $data, array $escape): void
	{
		foreach ($data as $i => $val) {
			if ($i !== 0) {
				echo ",";
			}

			if ($val === null) {
				echo "null";
			} elseif ($val === '') {
				echo "''";
			} else {
				echo $escape[$i] ? $this->pdo->quote($val) : $val;
			}
		}
	}

	/**
	 * Get database functions and procedures
	 * @param string $database
	 * @return string
	 */
	protected function getProcedures(string $database): string
	{
		$txt = '';
		foreach (["FUNCTION", "PROCEDURE"] as $type) {
			$req = $this->pdo->query('SHOW '.$type.' STATUS WHERE DB = '.$this->pdo->quote($database));
			if ($req) {
				foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $row) {
					$reqColumn = $this->pdo->query("SHOW CREATE ".$type." `".$row['Name']."`");
					if ($reqColumn) {
						$function = (string) $reqColumn->fetchColumn(2);
						if ($function) {
							$txt .= "DROP ".$type." IF EXISTS `".$row['Name']."`;;\n";
							$txt .= preg_replace('/^CREATE (\S+) '.$type.'/', 'CREATE '.$type, $function, 1).";;\n\n";
						}
					}
				}
			}
		}
		return $txt;
	}

	/**
	 * Get which values should be escaped based on table columns
	 * @param string $table
	 * @return array<int, bool>
	 */
	protected function getEscapeRules(string $table): array
	{
		$escape = [];
		$req = $this->pdo->query("SHOW FULL COLUMNS FROM `".$table."`", PDO::FETCH_ASSOC);
		if ($req) {
			foreach ($req->fetchAll() as $i => $col) {
				preg_match('/^([^( ]+)(?:\\((.+)\\))?/', $col['Type'], $match);
				$escape[$i] = !in_array($match[1], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'real', 'year']);
			}
		}
		return $escape;
	}
}
