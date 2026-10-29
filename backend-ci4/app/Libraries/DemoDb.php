<?php

namespace App\Libraries;

/**
 * Database plumbing for the demo commands (snapshot, reset). Nothing here runs
 * unless the caller passes guard(): DEMO_MODE must be true and the database name
 * must contain "demo", so these can never touch the live database.
 *
 * Credentials reach mysqldump/mysql through a temporary --defaults-extra-file
 * (mode 600, deleted afterwards), never on the command line.
 */
class DemoDb
{
    public static function snapshotPath(): string
    {
        return WRITEPATH . 'demo' . DIRECTORY_SEPARATOR . 'snapshot.sql';
    }

    /** Null when it is safe to go on, otherwise the reason the command must refuse. */
    public static function guard(): ?string
    {
        helper('demo');

        if (! demo_mode()) {
            return 'Demo mode is off. Set DEMO_MODE = true in this copy\'s .env to use the demo commands.';
        }
        if (! demo_db_ok()) {
            return 'Refusing to run: the database name "' . self::database() . '" does not contain "demo".';
        }

        return null;
    }

    public static function database(): string
    {
        return (string) config('Database')->default['database'];
    }

    public static function log(string $message): void
    {
        $dir = WRITEPATH . 'logs';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'demo.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }

    /** Dumps the demo database to $file with DEFINER clauses removed. Throws on failure. */
    public static function dump(string $file): void
    {
        $dir = dirname($file);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $file . '.part';

        self::withDefaultsFile(static function (string $defaults) use ($tmp) {
            $cmd = [
                self::binary('MYSQLDUMP', 'mysqldump'),
                '--defaults-extra-file=' . $defaults,
                '--single-transaction', '--routines', '--triggers', '--no-tablespaces',
                self::database(),
            ];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($proc)) {
                throw new \RuntimeException('Could not start mysqldump.');
            }

            $out = fopen($tmp, 'wb');
            while (($line = fgets($pipes[1])) !== false) {
                fwrite($out, self::stripDefiner($line));
            }
            fclose($out);
            $err  = stream_get_contents($pipes[2]);
            $code = proc_close($proc);

            if ($code !== 0) {
                @unlink($tmp);
                throw new \RuntimeException('mysqldump failed: ' . trim($err));
            }
        });

        rename($tmp, $file);
    }

    /** Loads a dump file into the demo database. Throws on failure. */
    public static function restore(string $file): void
    {
        if (! is_file($file)) {
            throw new \RuntimeException('No snapshot found at ' . $file . '. Run php spark demo:snapshot first.');
        }

        self::withDefaultsFile(static function (string $defaults) use ($file) {
            $cmd  = [self::binary('MYSQL', 'mysql'), '--defaults-extra-file=' . $defaults, self::database()];
            $proc = proc_open($cmd, [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($proc)) {
                throw new \RuntimeException('Could not start mysql.');
            }

            stream_get_contents($pipes[1]);
            $err  = stream_get_contents($pipes[2]);
            $code = proc_close($proc);

            if ($code !== 0) {
                throw new \RuntimeException('mysql restore failed: ' . trim($err));
            }
        });
    }

    /** Removes DEFINER=`user`@`host` (and the versioned-comment form) so the dump loads under any account. */
    public static function stripDefiner(string $line): string
    {
        $line = preg_replace('/\/\*![0-9]* DEFINER=[^*]*\*\/ ?/', '', $line);

        return preg_replace('/ ?DEFINER=`[^`]*`@`[^`]*`/', '', $line);
    }

    /** Logs everyone out: the session files (or table) plus the cache that holds rate limits. */
    public static function clearSessions(): void
    {
        self::clearSessionFiles();
        cache()->clean();
    }

    /** Removes only the session files (named after the session cookie), never anything else in that folder. */
    public static function clearSessionFiles(): void
    {
        $session = config('Session');

        if (str_contains(strtolower($session->driver), 'database')) {
            db_connect()->table($session->savePath)->truncate();

            return;
        }

        $pattern = rtrim($session->savePath, '/' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $session->cookieName . '*';
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private static function binary(string $key, string $default): string
    {
        return demo_setting($key . '_PATH', $default);
    }

    private static function withDefaultsFile(callable $fn): void
    {
        $db   = config('Database')->default;
        $dir  = WRITEPATH . 'demo';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . '.my-' . bin2hex(random_bytes(6)) . '.cnf';

        $bs    = chr(92);
        $quote = static fn (string $v) => '"' . str_replace([$bs, '"'], [$bs . $bs, $bs . '"'], $v) . '"';
        $cnf   = "[client]\nuser=" . $quote((string) $db['username']) . "\npassword=" . $quote((string) $db['password'])
            . "\nhost=" . $quote((string) $db['hostname']) . "\nport=" . (int) ($db['port'] ?? 3306) . "\n";

        touch($path);
        chmod($path, 0600);
        file_put_contents($path, $cnf);

        try {
            $fn($path);
        } finally {
            @unlink($path);
        }
    }
}
