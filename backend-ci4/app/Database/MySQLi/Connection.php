<?php

namespace App\Database\MySQLi;

use DateTime;
use DateTimeZone;

/**
 * The framework's MySQLi connection, plus one thing: every new connection
 * sets its session time zone to the app's (Config\App::$appTimezone,
 * Asia/Manila = +08:00). So MySQL's NOW() / CURRENT_TIMESTAMP and PHP's
 * date() always agree, on any computer, whatever the server's own clock
 * zone is. Config\Database points every MySQLi group at this driver.
 */
class Connection extends \CodeIgniter\Database\MySQLi\Connection
{
    public function connect(bool $persistent = false)
    {
        $connection = parent::connect($persistent);

        if ($connection) {
            $connection->query("SET time_zone = '" . self::appOffset() . "'");
        }

        return $connection;
    }

    /**
     * Still "MySQLi" to the framework (Model and Session check this name);
     * only the class lives in the app.
     */
    public function getPlatform(): string
    {
        return 'MySQLi';
    }

    /** The app time zone as a UTC offset, e.g. "+08:00" (a fixed offset needs no MySQL time zone tables). */
    public static function appOffset(): string
    {
        return (new DateTime('now', new DateTimeZone(config('App')->appTimezone)))->format('P');
    }
}
