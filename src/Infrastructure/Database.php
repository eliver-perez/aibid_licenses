<?php
declare(strict_types=1);
namespace Aibid\Infrastructure;

use Aibid\Config;
use PDO;

final class Database
{
    public function __construct(public readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    public static function connect(Config $config): self
    {
        return new self(new PDO($config->get('DB_DSN'), $config->get('DB_USER'), $config->get('DB_PASSWORD')));
    }

    public function execute(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(is_int($name) ? $name + 1 : $name, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $statement->execute();
        return $statement;
    }

    public function one(string $sql, array $parameters = []): ?array
    {
        return $this->execute($sql, $parameters)->fetch() ?: null;
    }

    public function all(string $sql, array $parameters = []): array
    {
        return $this->execute($sql, $parameters)->fetchAll();
    }

    public function transaction(callable $operation, int $retries = 0): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Transaction boundaries belong to the application service.');
        }
        for ($attempt = 0; ; ++$attempt) {
            $this->pdo->beginTransaction();
            try {
                $result = $operation();
                $this->pdo->commit();
                return $result;
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                // Only known lock failures are safe to retry after rollback.
                // A connection loss/ambiguous COMMIT must be resolved by request ID.
                if ($attempt < $retries && $error instanceof \PDOException && in_array((int)($error->errorInfo[1] ?? 0), [1205,1213], true)) {
                    usleep(random_int(10000,40000));
                    continue;
                }
                throw $error;
            }
        }
    }
}
