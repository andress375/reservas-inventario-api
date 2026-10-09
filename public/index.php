<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\JsonResponse;
use App\ReservationService;
use App\ReservationValidator;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

try {
    if ($path !== '/reservations') {
        $response = JsonResponse::error(404, 'NOT_FOUND', 'Ruta no encontrada.');
    } elseif ($method !== 'POST') {
        header('Allow: POST');
        $response = JsonResponse::error(405, 'METHOD_NOT_ALLOWED', 'Método no permitido.');
    } else {
        try {
            $data = json_decode((string) file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
            $errors = ReservationValidator::validate($data);

            if ($errors !== []) {
                $response = JsonResponse::error(422, 'VALIDATION_ERROR', 'Datos inválidos.', $errors);
            } else {
                $service = new ReservationService(Database::connect());
                $response = $service->reserve($data['request_id'], $data['product_id'], $data['quantity']);
            }
        } catch (JsonException) {
            $response = JsonResponse::error(400, 'INVALID_JSON', 'El cuerpo no es un JSON válido.');
        }
    }
} catch (Throwable $e) {
    // Detalle completo solo en el log del contenedor; el cliente recibe un mensaje genérico.
    error_log((string) $e);
    $response = JsonResponse::error(500, 'INTERNAL_ERROR', 'Error interno.');
}

$response->send();
