<?php

declare(strict_types=1);

namespace App;

/**
 * Validación estricta de la solicitud (SPEC.md, sección 4).
 * Solo acepta enteros JSON reales: "3", 3.5 o true se rechazan.
 */
final class ReservationValidator
{
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @return array<string, string> Errores por campo; vacío si la solicitud es válida.
     */
    public static function validate(mixed $data): array
    {
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            return ['body' => 'El cuerpo debe ser un objeto JSON.'];
        }

        $errors = [];

        if (!array_key_exists('request_id', $data)) {
            $errors['request_id'] = 'Campo obligatorio.';
        } elseif (!is_string($data['request_id']) || preg_match(self::REQUEST_ID_PATTERN, $data['request_id']) !== 1) {
            $errors['request_id'] = 'Debe tener de 1 a 64 caracteres: letras, números, "-" o "_".';
        }

        if (!array_key_exists('product_id', $data)) {
            $errors['product_id'] = 'Campo obligatorio.';
        } elseif (!is_int($data['product_id']) || $data['product_id'] < 1) {
            $errors['product_id'] = 'Debe ser un entero mayor que 0.';
        }

        if (!array_key_exists('quantity', $data)) {
            $errors['quantity'] = 'Campo obligatorio.';
        } elseif (!is_int($data['quantity']) || $data['quantity'] <= 0) {
            $errors['quantity'] = 'Debe ser un entero mayor que 0.';
        }

        return $errors;
    }
}
