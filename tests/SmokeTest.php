<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpSeConectaAMySql84(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')
        );
        $pdo = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $version = $pdo->query('SELECT VERSION()')->fetchColumn();

        $this->assertStringStartsWith('8.4', $version);
    }
}
