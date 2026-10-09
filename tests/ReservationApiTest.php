<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la API POST /reservations contra el servidor real (HTTP + MySQL).
 * Cubren el Punto 8 de la prueba técnica: reserva correcta, stock insuficiente,
 * idempotencia y concurrencia (prueba crítica del Punto 7).
 */
final class ReservationApiTest extends TestCase
{
    private const PRODUCT_ID = 1;
    private const CONCURRENCY_ROUNDS = 10;
    private const BARRIER_TIMEOUT_SECONDS = 5.0;

    private static PDO $admin;

    public static function setUpBeforeClass(): void
    {
        // Conexión administrativa: solo para preparar datos (SPEC.md, sección 3).
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'),
            getenv('DB_PORT'),
            getenv('DB_DATABASE'),
        );
        self::$admin = new PDO($dsn, 'root', (string) getenv('DB_ROOT_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected function setUp(): void
    {
        $this->resetData(stock: 10);
    }

    public static function tearDownAfterClass(): void
    {
        // Deja la base como el estado inicial de database/schema.sql.
        self::$admin->exec('DELETE FROM reservations');
        self::$admin->prepare('UPDATE products SET stock = 10 WHERE id = ?')->execute([self::PRODUCT_ID]);
    }

    public function testReservaCorrectaDescuentaStock(): void
    {
        [$status, $body] = $this->post(['request_id' => 'T-OK-1', 'product_id' => self::PRODUCT_ID, 'quantity' => 3]);

        $this->assertSame(201, $status);
        $this->assertSame('confirmed', $body['status']);
        $this->assertSame(7, $body['remaining_stock']);
        $this->assertIsInt($body['reservation_id']);
        $this->assertSame(7, $this->stock());
        $this->assertSame(1, $this->reservationCount());
    }

    public function testStockInsuficienteSeRechazaSinModificarDatos(): void
    {
        [$status, $body] = $this->post(['request_id' => 'T-NOSTOCK-1', 'product_id' => self::PRODUCT_ID, 'quantity' => 11]);

        $this->assertSame(409, $status);
        $this->assertSame('INSUFFICIENT_STOCK', $body['error']['code']);
        $this->assertSame(10, $this->stock());
        $this->assertSame(0, $this->reservationCount());
    }

    public function testMismoRequestIdDevuelveLaMismaReservaSinDescontarDeNuevo(): void
    {
        $request = ['request_id' => 'T-IDEM-1', 'product_id' => self::PRODUCT_ID, 'quantity' => 3];

        [$firstStatus, $first] = $this->post($request);
        [$retryStatus, $retry] = $this->post($request);

        $this->assertSame(201, $firstStatus);
        $this->assertSame(200, $retryStatus);
        $this->assertSame($first, $retry, 'El reintento debe devolver exactamente la misma respuesta.');
        $this->assertSame(7, $this->stock(), 'El reintento no debe descontar stock.');
        $this->assertSame(1, $this->reservationCount());

        // Mismo request_id con otros datos: conflicto, sin efectos (decisión V1 de SPEC.md).
        [$conflictStatus, $conflict] = $this->post([...$request, 'quantity' => 2]);

        $this->assertSame(409, $conflictStatus);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict['error']['code']);
        $this->assertSame(7, $this->stock());
        $this->assertSame(1, $this->reservationCount());
    }

    /**
     * Prueba crítica (Punto 7): stock 1, dos solicitudes distintas y simultáneas de 1 unidad.
     * Usa una barrera (ver postConcurrently) que garantiza que ambas solicitudes están dentro
     * de MySQL al mismo tiempo; si el código tuviera el bug de sobreventa, ambas confirmarían.
     */
    public function testConcurrenciaSoloUnaReservaConfirmadaConStockUno(): void
    {
        for ($round = 1; $round <= self::CONCURRENCY_ROUNDS; $round++) {
            $this->resetData(stock: 1);

            $statuses = $this->postConcurrently([
                ['request_id' => "T-CONC-{$round}-A", 'product_id' => self::PRODUCT_ID, 'quantity' => 1],
                ['request_id' => "T-CONC-{$round}-B", 'product_id' => self::PRODUCT_ID, 'quantity' => 1],
            ]);
            sort($statuses);

            $this->assertSame([201, 409], $statuses, "Ronda {$round}: debe haber una reserva confirmada y una rechazada.");
            $this->assertSame(0, $this->stock(), "Ronda {$round}: el stock final debe ser 0.");
            $this->assertSame(1, $this->reservationCount(), "Ronda {$round}: debe existir una sola reserva.");
        }
    }

    /**
     * Idempotencia bajo concurrencia: el mismo request_id enviado varias veces a la vez
     * con la última unidad crea una sola reserva; el resto recibe esa misma reserva (200).
     */
    public function testConcurrenciaMismoRequestIdCreaUnaSolaReserva(): void
    {
        for ($round = 1; $round <= self::CONCURRENCY_ROUNDS; $round++) {
            $this->resetData(stock: 1);
            $request = ['request_id' => "T-CONC-IDEM-{$round}", 'product_id' => self::PRODUCT_ID, 'quantity' => 1];

            $statuses = $this->postConcurrently([$request, $request, $request]);
            sort($statuses);

            $this->assertSame([200, 200, 201], $statuses, "Ronda {$round}: una creación y dos reintentos.");
            $this->assertSame(0, $this->stock(), "Ronda {$round}: el stock final debe ser 0.");
            $this->assertSame(1, $this->reservationCount(), "Ronda {$round}: debe existir una sola reserva.");
        }
    }

    // ---------------------------------------------------------------- Auxiliares

    private function resetData(int $stock): void
    {
        self::$admin->exec('DELETE FROM reservations');
        self::$admin->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$stock, self::PRODUCT_ID]);
    }

    private function stock(): int
    {
        $stmt = self::$admin->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([self::PRODUCT_ID]);

        return (int) $stmt->fetchColumn();
    }

    private function reservationCount(): int
    {
        return (int) self::$admin->query('SELECT COUNT(*) FROM reservations')->fetchColumn();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(array $payload): array
    {
        $handle = $this->createHandle($payload);
        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $this->assertIsString($raw, 'La API no respondió. ¿Está corriendo el contenedor app?');

        return [$status, json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * Envía las solicitudes con una BARRERA que garantiza que compiten en el mismo instante:
     * 1. Una conexión administrativa bloquea la fila del producto (SELECT ... FOR UPDATE).
     * 2. Las solicitudes se envían una a una y cada una queda esperando ese bloqueo dentro de MySQL
     *    (se confirma en performance_schema.data_lock_waits antes de enviar la siguiente; así cada
     *    una cae en un worker distinto del servidor).
     * 3. Cuando TODAS están esperando a la vez, se libera el bloqueo y compiten por el stock.
     * El resultado no depende de la suerte: si el código tuviera el bug de sobreventa, fallaría.
     *
     * @param list<array<string, mixed>> $payloads
     * @return list<int> Códigos HTTP de cada solicitud.
     */
    private function postConcurrently(array $payloads): array
    {
        self::$admin->beginTransaction();
        $lock = self::$admin->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
        $lock->execute([self::PRODUCT_ID]);
        $lock->fetchAll();

        $multi = curl_multi_init();
        $handles = [];
        $allWaiting = true;
        foreach ($payloads as $payload) {
            $handle = $this->createHandle($payload);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;

            if (!$this->waitForLockWaits($multi, count($handles))) {
                $allWaiting = false;
                break;
            }
        }

        self::$admin->commit(); // Libera el bloqueo: todas compiten a la vez.

        do {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running > 0);

        $statuses = [];
        foreach ($handles as $handle) {
            $statuses[] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        $this->assertTrue(
            $allWaiting,
            'Las solicitudes no llegaron a coincidir dentro de MySQL: la prueba no demostraría concurrencia. '
            . '¿El servidor tiene PHP_CLI_SERVER_WORKERS configurado?'
        );

        return $statuses;
    }

    /**
     * Mantiene vivas las solicitudes HTTP hasta que haya $expected transacciones esperando el bloqueo.
     */
    private function waitForLockWaits(CurlMultiHandle $multi, int $expected): bool
    {
        $deadline = microtime(true) + self::BARRIER_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            curl_multi_exec($multi, $running);
            if ($this->transactionsWaitingForLock() >= $expected) {
                return true;
            }
            curl_multi_select($multi, 0.02);
        }

        return false;
    }

    /**
     * Transacciones que están esperando un bloqueo en este momento.
     * Se usa performance_schema porque refleja el estado en vivo; information_schema.innodb_trx
     * usa una caché que puede devolver datos de la ronda anterior (falso positivo de la barrera).
     */
    private function transactionsWaitingForLock(): int
    {
        return (int) self::$admin
            ->query('SELECT COUNT(DISTINCT REQUESTING_ENGINE_TRANSACTION_ID) FROM performance_schema.data_lock_waits')
            ->fetchColumn();
    }

    /** @param array<string, mixed> $payload */
    private function createHandle(array $payload): CurlHandle
    {
        $handle = curl_init((getenv('API_URL') ?: 'http://127.0.0.1:8080') . '/reservations');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        return $handle;
    }
}
