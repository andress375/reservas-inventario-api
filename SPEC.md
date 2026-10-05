# SPEC.md · API de reservas de inventario

Contrato de la solución. Los prompts a la IA hacen referencia a este archivo en lugar de repetir el contexto.
Las secciones marcadas *(pendiente P1)* se completan con las decisiones tomadas tras el análisis del prompt P1.

## 1. Problema

API que permite reservar unidades de un producto descontando su stock, garantizando que:
nunca haya stock negativo, un reintento con el mismo `request_id` no descuente dos veces,
y dos solicitudes simultáneas por la última unidad no la obtengan ambas.

## 2. Entorno

- PHP 8.3 (contenedor `app`), MySQL 8.4 LTS (contenedor `db`, puerto 3307 en el host).
- Composer y PHPUnit dentro del contenedor.
- Sin framework *(confirmar en P1)*.

## 3. Modelo de datos

Mínimo exigido:

- `products`: `id`, `name`, `stock`
- `reservations`: `id`, `request_id`, `product_id`, `quantity`, `created_at`

Restricciones y ampliaciones: *(pendiente P1)*

## 4. Contrato de la API

`POST /reservations`

```json
{ "request_id": "REQ-2026-0001", "product_id": 1, "quantity": 3 }
```

Respuesta exitosa (ejemplo del enunciado):

```json
{ "reservation_id": 15, "status": "confirmed", "remaining_stock": 7 }
```

Códigos HTTP y respuestas de error: *(pendiente P1)*

## 5. Reglas y criterios de aceptación

| Regla | Criterio de aceptación |
|---|---|
| R1 · Stock | Nunca `stock < 0` |
| R2 · Idempotencia | Mismo `request_id` → devuelve la reserva existente y no descuenta stock |
| R3 · Concurrencia | Stock 1 + dos solicitudes simultáneas de 1 → una confirmada, una rechazada, stock final 0 |
| R4 · Validaciones | Producto inexistente, cantidad ≤ 0, stock insuficiente, `request_id` duplicado, datos faltantes |

Estrategia de concurrencia e idempotencia: *(pendiente P1)*

Caso abierto: mismo `request_id` con datos distintos → *(pendiente P1)*

## 6. Decisiones técnicas

*(pendiente P1)*

## 7. Fuera de alcance

*(pendiente P1)*
