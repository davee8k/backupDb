# BackupDb

## Description

Simple database exporter for PHP

## Requirements

- PHP 7.1+

## Usage

Basic complete export to sql file

	$backup = new BackupDb($pdoConnection);
	$backup->create("backup");

Just selected table

	$backup = new BackupDb($pdoConnection, null, false);	// disables automatic loading of accessible tables and db procedures export
	$backup->setTable('table_name');
	$backup->create("backup");
