<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Reserva de inventario según el "Flujo de una reserva" de SPEC.md, sección 5.
 */
final class ReservationService
{
    private const MYSQL_DUPLICATE_KEY = 1062;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reserve(string $requestId, int $productId, int $quantity): JsonResponse
    {
        // Paso 2: si el request_id ya existe, responder sin tocar el stock (idempotencia).
        $existing = $this->findExisting($requestId, $productId, $quantity);
        if ($existing !== null) {
            return $existing;
        }

        try {
            // Paso 3.
            $this->pdo->beginTransaction();

            // Paso 4: descuento atómico. MySQL solo descuenta si alcanza el stock.
            // Se hace ANTES del INSERT para evitar el deadlock por la clave foránea.
            $update = $this->pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            $update->execute([$quantity, $productId, $quantity]);

            // Paso 5: no se descontó nada → producto inexistente o sin stock suficiente.
            if ($update->rowCount() === 0) {
                $this->pdo->rollBack();

                return $this->rejection($requestId, $productId, $quantity);
            }

            // Paso 6: la fila del producto está bloqueada por esta transacción; se lee su stock actualizado.
            $select = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
            $select->execute([$productId]);
            $remainingStock = (int) $select->fetchColumn();

            $insert = $this->pdo->prepare(
                'INSERT INTO reservations (request_id, product_id, quantity, remaining_stock) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$requestId, $productId, $quantity, $remainingStock]);
            $reservationId = (int) $this->pdo->lastInsertId();

            // Paso 7.
            $this->pdo->commit();

            return new JsonResponse(201, [
                'reservation_id' => $reservationId,
                'status' => 'confirmed',
                'remaining_stock' => $remainingStock,
            ]);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Paso 8: otra solicitud con el mismo request_id ganó la carrera.
            // El rollback ya revirtió el descuento; se responde con la reserva existente.
            if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_KEY) {
                $existing = $this->findExisting($requestId, $productId, $quantity);
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /**
     * Devuelve la respuesta de una reserva ya existente con ese request_id, o null si no existe.
     * Se ejecuta siempre fuera de una transacción, para leer el estado ya confirmado.
     */
    private function findExisting(string $requestId, int $productId, int $quantity): ?JsonResponse
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, product_id, quantity, remaining_stock FROM reservations WHERE request_id = ?'
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        if ((int) $row['product_id'] !== $productId || (int) $row['quantity'] !== $quantity) {
            return JsonResponse::error(
                409,
                'IDEMPOTENCY_CONFLICT',
                'El request_id ya fue usado con otros datos.'
            );
        }

        return new JsonResponse(200, [
            'reservation_id' => (int) $row['id'],
            'status' => 'confirmed',
            'remaining_stock' => (int) $row['remaining_stock'],
        ]);
    }

    private function rejection(string $requestId, int $productId, int $quantity): JsonResponse
    {
        // Si el mismo request_id se confirmó mientras esta solicitud esperaba el bloqueo,
        // se devuelve esa reserva en lugar de un rechazo por stock.
        $existing = $this->findExisting($requestId, $productId, $quantity);
        if ($existing !== null) {
            return $existing;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM products WHERE id = ?');
        $stmt->execute([$productId]);

        if ($stmt->fetchColumn() === false) {
            return JsonResponse::error(404, 'PRODUCT_NOT_FOUND', 'El producto no existe.');
        }

        return JsonResponse::error(409, 'INSUFFICIENT_STOCK', 'No hay stock suficiente.');
    }
}
