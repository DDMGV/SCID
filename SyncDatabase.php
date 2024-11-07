<?php
declare(strict_types=1);

class SyncDatabase
{
    private $develop_db;
    private $production_db;

    public function __construct($dev_db_connect, $prod_db_connect)
    {
        $this->develop_db = new PDO(
            "mysql:host={$dev_db_connect['host']};dbname={$dev_db_connect['dbname']};charset=utf8",
            $dev_db_connect['username'],
            $dev_db_connect['password']
        );

        $this->production_db = new PDO(
            "mysql:host={$prod_db_connect['host']};dbname={$prod_db_connect['dbname']};charset=utf8",
            $prod_db_connect['username'],
            $prod_db_connect['password']
        );

        $this->develop_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->production_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function syncDatabase(): void
    {
        $tables = $this->getAllTables($this->develop_db);

        foreach ($tables as $table) {
            echo "Синхронизация таблицы $table...\n";
            $this->syncTableStructure($table);
        }
    }

    private function getAllTables($db): array
    {
        $stmt = $db->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function syncTableStructure($table_name): void
    {
        $dev_db_columns = $this->getTableColumns($this->develop_db, $table_name);
        $prod_db_columns = $this->getTableColumns($this->production_db, $table_name);

        foreach ($dev_db_columns as $column => $specification) {
            if (!isset($prod_db_columns[$column])) {
                $this->addColumn($table_name, $column, $specification);
            } elseif ($this->columnNeedsUpdate($prod_db_columns[$column], $specification)) {
                $this->modifyColumn($table_name, $column, $specification);
            }
        }

        foreach ($prod_db_columns as $column => $specification) {
            if (!isset($dev_db_columns[$column])) {
                $this->dropColumn($table_name, $column);
            }
        }
    }

    private function getTableColumns($db, $table_name): array
    {
        $stmt = $db->query("SHOW COLUMNS FROM $table_name");
        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['Field']] = [
                'Type' => $column['Type'],
                'Null' => $column['Null'],
                'Key' => $column['Key'],
                'Default' => $column['Default'],
                'Extra' => $column['Extra']
            ];
        }

        return $columns;
    }

    private function columnNeedsUpdate($prod_db_specification, $dev_db_specification): bool
    {
        return $prod_db_specification['Type'] !== $dev_db_specification['Type'] ||
            $prod_db_specification['Null'] !== $dev_db_specification['Null'] ||
            $prod_db_specification['Key'] !== $dev_db_specification['Key'] ||
            $prod_db_specification['Default'] !== $dev_db_specification['Default'] ||
            $prod_db_specification['Extra'] !== $dev_db_specification['Extra'];
    }

    private function addColumn($table_name, $column, $specification): void
    {
        $sql = "ALTER TABLE $table_name ADD COLUMN $column {$specification['Type']} ";
        $sql .= ($specification['Null'] === 'NO') ? 'NOT NULL ' : 'NULL ';
        $sql .= ($specification['Default'] !== null) ? "DEFAULT '{$specification['Default']}' " : '';
        $sql .= $specification['Extra'];

        $this->production_db->exec($sql);
        echo "Добавлена колонка $column в таблицу $table_name.\n";
    }

    private function modifyColumn($table_name, $column, $specification): void
    {
        $sql = "ALTER TABLE $table_name MODIFY COLUMN $column {$specification['Type']} ";
        $sql .= ($specification['Null'] === 'NO') ? 'NOT NULL ' : 'NULL ';
        $sql .= ($specification['Default'] !== null) ? "DEFAULT '{$specification['Default']}' " : '';
        $sql .= $specification['Extra'];

        $this->production_db->exec($sql);
        echo "Изменена колонка $column в таблице $table_name.\n";
    }

    private function dropColumn($table_name, $column): void
    {
        $sql = "ALTER TABLE $table_name DROP COLUMN $column";
        $this->production_db->exec($sql);
        echo "Удалена колонка $column из таблицы $table_name.\n";
    }
}

$dev_db_connect = [
    'host' => 'localhost',
    'dbname' => 'dev',
    'username' => 'root',
    'password' => ''
];

$prod_db_connect = [
    'host' => 'localhost',
    'dbname' => 'prod',
    'username' => 'root',
    'password' => ''
];

$dbSync = new SyncDatabase($dev_db_connect, $prod_db_connect);
$dbSync->syncDatabase();
