<?php

declare(strict_types=1);

namespace App;

/**
 * Respuesta HTTP con cuerpo JSON (formatos de SPEC.md, sección 4).
 */
final class JsonResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
    }

    /** @param array<string, string>|null $fields */
    public static function error(int $status, string $code, string $message, ?array $fields = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return new self($status, ['error' => $error]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
