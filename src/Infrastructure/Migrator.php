<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

final class Migrator
{
    public function __construct(private Database $db, private string $directory) {}

    public function migrate(): array
    {
        $version = (string) $this->db->execute('SELECT VERSION()')->fetchColumn();
        if (!str_starts_with($version, '8.') || stripos($version, 'mariadb') !== false) {
            throw new \RuntimeException('Las migraciones requieren MySQL 8; MariaDB no es el motor de validación.');
        }
        $lockName = 'aibid-migrations-' . substr(hash('sha256', (string) $this->db->execute('SELECT DATABASE()')->fetchColumn()), 0, 40);
        if ((int) $this->db->execute('SELECT GET_LOCK(?,10)', [$lockName])->fetchColumn() !== 1) {
            throw new \RuntimeException('Hay otra migración en ejecución.');
        }
        try {
            $this->db->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, checksum CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, applied_at DATETIME(6) NULL) ENGINE=InnoDB");
            $applied = [];
            $files = glob($this->directory . '/*.sql');
            sort($files);
            foreach ($files as $path) {
                $name = basename($path);
                $sql = file_get_contents($path);
                $checksum = hash('sha256', $sql);
                $existing = $this->db->one('SELECT * FROM schema_migrations WHERE name=?', [$name]);
                if ($existing) {
                    if ($existing['state'] !== 'complete' || !hash_equals($existing['checksum'], $checksum)) {
                        throw new \RuntimeException('Migración incompleta o modificada: ' . $name . '. Revisa el estado antes de continuar.');
                    }
                    continue;
                }
                $this->db->execute("INSERT INTO schema_migrations(name,checksum,state) VALUES (?,?,'pending')", [$name, $checksum]);
                // Each file contains plain DDL/DML statements, without stored procedures.
                foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
                    if (trim($statement) !== '') {
                        $this->db->pdo->exec($statement);
                    }
                }
                $this->db->execute("UPDATE schema_migrations SET state='complete', applied_at=UTC_TIMESTAMP(6) WHERE name=?", [$name]);
                $applied[] = $name;
            }
            return $applied;
        } finally {
            $this->db->execute('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
}
