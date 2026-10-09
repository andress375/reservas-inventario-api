<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Crea la conexión PDO a MySQL con las credenciales de las variables de entorno.
 */
final class Database
{
    public static function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            self::env('DB_HOST'),
            self::env('DB_PORT'),
            self::env('DB_DATABASE'),
        );

        $pdo = new PDO($dsn, self::env('DB_USERNAME'), self::env('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Modo estricto en la sesión: no depende de la configuración del servidor (riesgo R18).
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new \RuntimeException("Falta la variable de entorno {$name}");
        }

        return $value;
    }
}
