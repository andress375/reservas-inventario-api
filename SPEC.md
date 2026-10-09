# SPEC.md · API de reservas de inventario

Contrato de la solución. Los prompts a la IA hacen referencia a este archivo en lugar de repetir el contexto.
Decisiones tomadas a partir del análisis del prompt P1 (ver PROMPTS.md).

## 1. Problema

API que permite reservar unidades de un producto descontando su stock, garantizando que:
nunca haya stock negativo, un reintento con el mismo `request_id` no descuente dos veces,
y dos solicitudes simultáneas por la última unidad no la obtengan ambas.

## 2. Entorno

- PHP 8.3 (contenedor `app`), MySQL 8.4 LTS (contenedor `db`, puerto 3307 en el host).
- Composer y PHPUnit dentro del contenedor.
- **PHP puro, sin framework.** Solo PDO. Un único punto de entrada: `public/index.php`.
- Servidor integrado de PHP con `PHP_CLI_SERVER_WORKERS` (4) para atender solicitudes en paralelo. La API queda en `http://localhost:8080`.

## 3. Modelo de datos

| Tabla | Columna | Definición | Motivo |
|---|---|---|---|
| `products` | `id` | `INT UNSIGNED` PK autoincremental | Exigido |
| | `name` | `VARCHAR(150) NOT NULL` | Exigido |
| | `stock` | `INT NOT NULL`, `CHECK (stock >= 0)` | R1: la base de datos rechaza la sobreventa aunque falle el código |
| `reservations` | `id` | `BIGINT UNSIGNED` PK autoincremental | Exigido |
| | `request_id` | `VARCHAR(64)`, `ascii` / `ascii_bin`, `NOT NULL`, `UNIQUE` | R2: idempotencia garantizada por MySQL, no solo por PHP. Binario: "req-1" ≠ "REQ-1" |
| | `product_id` | `INT UNSIGNED NOT NULL`, FK → `products(id)` | Integridad referencial |
| | `quantity` | `INT NOT NULL`, `CHECK (quantity > 0)` | Segunda defensa de R4 |
| | `remaining_stock` | `INT NOT NULL` **(ampliación)** | R2: el reintento devuelve exactamente la misma respuesta |
| | `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` | Exigido |

- Tablas con `ENGINE=InnoDB` y `utf8mb4` declarados explícitamente.
- El usuario de la aplicación solo tiene `SELECT, INSERT, UPDATE` sobre la base `reservas`.
- Scripts: `database/schema.sql` (crea o recrea las tablas y el estado inicial; **borra los datos**) y `database/permissions.sql` (permisos mínimos). Ambos se ejecutan como root y se aplican automáticamente al crear el volumen de MySQL por primera vez.
- Las pruebas automatizadas reinician **solo los datos** usando una conexión administrativa (root); la aplicación nunca recibe permiso de borrado.

## 4. Contrato de la API

`POST /reservations`

```json
{ "request_id": "REQ-2026-0001", "product_id": 1, "quantity": 3 }
```

Validación de entrada:

- `request_id`: texto de 1 a 64 caracteres, solo letras, números, `-` y `_`.
- `product_id` y `quantity`: enteros JSON (no se aceptan `"3"`, `3.5` ni `true`). `product_id > 0` y `quantity > 0` (si no, 422). Sin tope de cantidad.

| Caso | HTTP | Cuerpo |
|---|---|---|
| Reserva nueva | 201 | `{"reservation_id": 15, "status": "confirmed", "remaining_stock": 7}` |
| Reintento idempotente (mismos datos) | 200 | El mismo cuerpo de la reserva original |
| Mismo `request_id`, datos distintos | 409 | `IDEMPOTENCY_CONFLICT` |
| JSON malformado | 400 | `INVALID_JSON` |
| Campo faltante o tipo inválido | 422 | `VALIDATION_ERROR` + detalle por campo |
| `quantity <= 0` | 422 | `VALIDATION_ERROR` |
| Producto inexistente | 404 | `PRODUCT_NOT_FOUND` |
| Stock insuficiente | 409 | `INSUFFICIENT_STOCK` |
| Ruta / método no soportado | 404 / 405 | `NOT_FOUND` / `METHOD_NOT_ALLOWED` |
| Error interno | 500 | `INTERNAL_ERROR`, sin detalles internos |

Formato de error: `{"error": {"code": "...", "message": "...", "fields": {...}}}` (`fields` solo en 422).

## 5. Reglas y criterios de aceptación

| Regla | Criterio de aceptación | Cómo se cumple |
|---|---|---|
| R1 · Stock | Nunca `stock < 0` | `UPDATE` condicional + `CHECK (stock >= 0)` |
| R2 · Idempotencia | Mismo `request_id` → devuelve la reserva existente y no descuenta stock | Consulta previa + `UNIQUE(request_id)` + `remaining_stock` guardado |
| R3 · Concurrencia | Stock 1 + dos solicitudes simultáneas de 1 → una confirmada, una rechazada, stock final 0 | Descuento atómico dentro de una transacción |
| R4 · Validaciones | Producto inexistente, cantidad ≤ 0, stock insuficiente, `request_id` duplicado, datos faltantes | Tabla de la sección 4 |

### Flujo de una reserva

1. Validar la entrada (400 / 422).
2. Buscar la reserva por `request_id`. Si existe: mismos datos → 200 con la respuesta guardada; datos distintos → 409.
3. `BEGIN`.
4. `UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?`.
5. Si afectó 0 filas: `ROLLBACK`. Buscar de nuevo el `request_id` (pudo confirmarse mientras esta solicitud esperaba el bloqueo); si existe, responder como en el paso 2. Si no, consultar si el producto existe → 404 o 409.
6. Leer el stock resultante e `INSERT` de la reserva con `remaining_stock`.
7. `COMMIT` → 201.
8. Si el `INSERT` falla por duplicado (error 1062): `ROLLBACK` (se revierte el descuento) y repetir el paso 2 **fuera** de la transacción.

**Orden `UPDATE` → `INSERT`:** insertar primero la reserva toma un bloqueo compartido sobre el producto por la clave foránea; dos solicitudes simultáneas pedirían luego el bloqueo exclusivo y se bloquearían mutuamente (*deadlock*).

### Casos no definidos por el enunciado (decididos)

| Caso | Decisión |
|---|---|
| Mismo `request_id` con datos distintos | 409 `IDEMPOTENCY_CONFLICT` |
| Reintento de una solicitud rechazada | Se reevalúa (los rechazos no se guardan) |
| `remaining_stock` en el reintento | El valor del momento de la reserva |
| Código HTTP del reintento | 200 |

## 6. Decisiones técnicas

| Decisión | Alternativa descartada | Motivo |
|---|---|---|
| Descuento atómico condicional | `SELECT ... FOR UPDATE` | Una sola sentencia, sin hueco entre leer y escribir, bloqueo más corto |
| Consulta previa + `UNIQUE` | Solo capturar el error de duplicado | Los reintentos no tocan el stock ni toman bloqueos |
| PHP puro + PDO | Framework | Un endpoint; menos código que sustentar |
| `ascii_bin` en `request_id` | *Collation* por defecto | Evita colisiones por mayúsculas y minúsculas |
| Consultas preparadas reales (`ATTR_EMULATE_PREPARES = false`) | Preparación emulada | Seguridad y tipos correctos |

## 7. Fuera de alcance

Autenticación, cancelación y expiración de reservas, CRUD de productos, varios productos por solicitud,
listado de reservas, *rate limiting* y framework.
