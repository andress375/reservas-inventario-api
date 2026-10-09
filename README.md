# API de reservas de inventario

API REST que reserva unidades de un producto garantizando que **nunca haya sobreventa**, que **un reintento no descuente stock dos veces** (idempotencia) y que **dos solicitudes simultáneas por la última unidad no la obtengan ambas** (concurrencia).

**Tecnologías:** PHP 8.3 (sin framework, PDO) · MySQL 8.4 LTS · PHPUnit 12.5 · Docker Compose.

## Cómo ejecutar

Requisito: Docker con Docker Compose v2.

```bash
git clone https://github.com/andress375/reservas-inventario-api.git
cd reservas-inventario-api
cp .env.example .env          # En PowerShell: Copy-Item .env.example .env
```

Edita `.env` y cambia las dos claves **antes del primer arranque** (MySQL las toma al crear su volumen). Luego:

```bash
docker compose up -d --build
docker compose exec app composer install
```

La API queda en `http://localhost:8080`. Ejemplo:

```bash
curl -i -X POST http://localhost:8080/reservations \
  -H "Content-Type: application/json" \
  -d '{"request_id":"REQ-2026-0001","product_id":1,"quantity":3}'
```

Respuesta: `201` con `{"reservation_id":1,"status":"confirmed","remaining_stock":7}`. Repetir la misma solicitud devuelve `200` con la misma reserva, sin descontar stock. Todas las respuestas y códigos de error están en [SPEC.md](SPEC.md), sección 4.

> En Windows usa PowerShell 7 o Git Bash para el ejemplo con `curl`.

## Cómo crear la base de datos

Se crea **automáticamente** en el primer `docker compose up`: MySQL ejecuta los scripts de `database/`:

- `schema.sql`: tablas `products` y `reservations`, restricciones y el producto de ejemplo (id 1, stock 10).
- `permissions.sql`: el usuario de la API solo puede consultar, insertar y actualizar.

Para dejar la base en su estado inicial (Producto Demo con stock 10 y sin reservas), ejecuta este comando. **Advertencia:** elimina todas las reservas existentes.

```bash
docker compose exec db sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot $MYSQL_DATABASE < /docker-entrypoint-initdb.d/schema.sql'
```

Para empezar desde cero, incluidos usuarios y claves: `docker compose down -v` y luego `docker compose up -d`.

## Cómo ejecutar las pruebas

```bash
docker compose run --rm tests
```

Resultado esperado: `OK (8 tests, 120 assertions)`. Cubren reserva correcta, stock insuficiente, idempotencia, validaciones de borde y **concurrencia**. Para regenerar [resultados-pruebas.txt](resultados-pruebas.txt):

```bash
docker compose run --rm tests vendor/bin/phpunit --testdox --testdox-text resultados-pruebas.txt
```

**Prueba crítica de concurrencia:** con stock 1, una conexión de administrador bloquea la fila del producto, envía las solicitudes y espera a que **todas estén detenidas dentro de MySQL** antes de liberar el bloqueo. Así compiten en el mismo instante y el resultado no depende de la suerte. Se repite 10 veces y exige siempre una `201`, una `409` y stock final 0. Se validó introduciendo a propósito el bug de sobreventa: la prueba falla en la primera ronda.

## Decisiones técnicas principales

| Decisión | Por qué |
|---|---|
| Descuento atómico: `UPDATE ... SET stock = stock - ? WHERE id = ? AND stock >= ?` | Leer y descontar ocurren en una sola sentencia: no existe el hueco donde nace la sobreventa |
| `CHECK (stock >= 0)` en MySQL | Última defensa: aunque el código fallara, la base rechaza un stock negativo |
| `UNIQUE(request_id)` + consulta previa | La idempotencia la garantiza MySQL, no solo PHP. Los reintentos responden sin tocar el stock |
| Orden `UPDATE` → `INSERT` dentro de la transacción | Evita el *deadlock* que causaría insertar primero (bloqueo de la clave foránea) |
| Mismo `request_id` con otros datos → `409` | Ignorarlo en silencio ocultaría un error del cliente |
| Mínimo privilegio | La API no puede borrar ni cambiar la estructura; la clave de root solo llega al servicio de pruebas |
| Validación en el borde | Solo JSON (`415`), máximo 1 KB (`413`), enteros estrictos y `request_id` con formato exacto (`422`) |
| PHP sin framework | Es un solo endpoint: menos código que mantener y sustentar |
| Docker | El evaluador obtiene el mismo entorno con un comando |

## Limitaciones conocidas

- Se usa el servidor integrado de PHP, que es de desarrollo. Las garantías de stock, idempotencia y concurrencia viven en MySQL, así que no dependen de él. Para producción se recomienda Nginx + PHP-FPM.
- Fuera de alcance: autenticación, cancelación o expiración de reservas, CRUD de productos y reservas de varios productos.

## Documentos del repositorio

- [SPEC.md](SPEC.md): especificación (contrato) de la solución.
- [PROMPTS.md](PROMPTS.md): prompts usados con la IA, auditoría y decisiones Aceptada / Rechazada.
- [resultados-pruebas.txt](resultados-pruebas.txt): resultado de las pruebas automatizadas.
