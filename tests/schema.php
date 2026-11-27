<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Returns a connection only if the server has the fixture's private temporary datadir. */
function fixture_connection(string $manifest, bool $selectDatabase = true): PDO
{
    $config = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
    $folder = realpath($config['directory']);
    $temporary = realpath(sys_get_temp_dir());
    if (!$folder || !$temporary || strcasecmp(dirname($folder), $temporary) !== 0
        || !preg_match('/^celco-qa-[a-f0-9]{32}$/D', basename($folder))
        || realpath($manifest) !== realpath($folder . '/fixture.json')
        || $config['database'] !== 'celco_ims_test' || $config['host'] !== '127.0.0.1'
        || !is_int($config['port']) || $config['port'] === 3306 || $config['port'] < 1024 || $config['port'] > 65535) {
        throw new RuntimeException('Invalid isolated test fixture manifest.');
    }
    $dsn = 'mysql:host=127.0.0.1;port=' . $config['port'] . ';charset=utf8mb4';
    $connection = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $serverPath = realpath($connection->query('SELECT @@datadir')->fetchColumn());
    if (!$serverPath || strcasecmp($serverPath, realpath($folder . '/data') ?: '-') !== 0) {
        throw new RuntimeException('Refusing to use a database server outside the temporary fixture.');
    }
    if ($selectDatabase) {
        $connection->exec('USE celco_ims_test');
    }
    return $connection;
}

function seed_fixture(PDO $target): void
{
    // Only this connection reads CELCO. The read-only transaction prevents accidental DML.
    require dirname(__DIR__) . '/config/db.php';
    if (!isset($pdo) || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'celco_ims') {
        throw new RuntimeException('The source must select the existing celco_ims database.');
    }
    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        if ($pdo->query('SHOW TRIGGERS')->fetch()) {
            throw new RuntimeException('Inspect the new source triggers before refreshing the fixture.');
        }
        $definitions = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $target->exec('CREATE DATABASE celco_ims_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $target->exec('USE celco_ims_test');
        $target->exec('SET FOREIGN_KEY_CHECKS = 0');
        $views = [];
        foreach ($definitions as [$name, $kind]) {
            $identifier = '`' . str_replace('`', '``', $name) . '`';
            $ddl = $pdo->query('SHOW CREATE TABLE ' . $identifier)->fetch(PDO::FETCH_NUM)[1];
            if ($kind === 'VIEW') {
                $views[$name] = preg_replace('/DEFINER=`[^`]*`@`[^`]*` /', '', $ddl, 1);
            } else {
                $target->exec($ddl);
            }
        }
        foreach (['roles', 'permissions', 'role_permissions', 'units', 'categories'] as $table) {
            $data = $pdo->query("SELECT * FROM `$table` ORDER BY id");
            $insert = null;
            foreach ($data as $row) {
                if (!$insert) {
                    $columns = implode('`,`', array_keys($row));
                    $parameters = implode(',', array_fill(0, count($row), '?'));
                    $insert = $target->prepare("INSERT INTO `$table` (`$columns`) VALUES ($parameters)");
                }
                $insert->execute(array_values($row));
            }
        }
        $target->exec('SET FOREIGN_KEY_CHECKS = 1');
        while ($views) {
            $prior = count($views);
            foreach ($views as $name => $ddl) {
                try {
                    $target->exec($ddl);
                    unset($views[$name]);
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? null) !== 1146) {
                        throw $e;
                    }
                }
            }
            if (count($views) === $prior) {
                throw new RuntimeException('Unresolved view dependencies: ' . implode(', ', array_keys($views)));
            }
        }
    } finally {
        $pdo->rollBack();
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        [$script, $action, $manifest] = $argv + [1 => '', 2 => ''];
        if (!in_array($action, ['initialize', 'shutdown'], true)) {
            throw new RuntimeException('Specify initialize or shutdown and a temporary fixture manifest.');
        }
        for ($attempt = 0; ; $attempt++) {
            try {
                $target = fixture_connection($manifest, false);
                break;
            } catch (PDOException $e) {
                if ($action !== 'initialize' || $attempt >= 30 || ($e->errorInfo[1] ?? null) !== 2002) {
                    throw $e;
                }
                usleep(250000);
            }
        }
        if ($action === 'shutdown') {
            $target->exec('SHUTDOWN');
        } else {
            seed_fixture($target);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
