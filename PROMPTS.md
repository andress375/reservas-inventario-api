# PROMPTS.md

Prompts principales usados durante el desarrollo, copiados **exactamente como fueron escritos**.
Las respuestas de la IA no se copian completas; solo se resume qué se hizo con ellas.

- **Asistente:** Claude (claude-opus-5-5), en chat, con acceso a la carpeta del repositorio.
- **Intento:** 1 (aprendizaje). Los prompts de este intento fueron redactados con apoyo del asistente en rol de tutor y revisados por mí. En el intento 2 serán de redacción propia y se consolidarán en 3 a 8 prompts.

## Plan

| # | Propósito | Estado |
|---|---|---|
| P1 | Contexto y análisis (sin código) | ✅ |
| P2 | Base de datos | ✅ |
| P3 | Lógica de reserva + endpoint | ✅ |
| P4 | Pruebas (incluida concurrencia) | ✅ |
| P5 | Auditoría crítica (Punto 9) | ✅ |
| P6 | Correcciones precisas | ✅ |

---

## P1 · Contexto y análisis

```text
Actúa como ingeniero backend senior PHP/MySQL con experiencia en sistemas transaccionales de inventario.

Reglas para toda la sesión:
- No crees ni modifiques archivos sin mi aprobación explícita.
- Cada decisión que propongas debe indicar su consecuencia.
- No agregues frameworks ni librerías sin justificarlos; prefiero la solución mínima que cumpla.

Contexto:
- Lee SPEC.md en la raíz del repositorio. Contiene el problema, el entorno (PHP 8.3, MySQL 8.4 LTS en Docker, PHPUnit) y las reglas con sus criterios de aceptación.
- MySQL está configurado con InnoDB, aislamiento REPEATABLE-READ y modo estricto.

Tarea:
Analiza el problema y propón el diseño necesario para completar las secciones marcadas "(pendiente P1)" en SPEC.md.

Restricciones:
- No escribas código PHP ni crees archivos en esta respuesta.
- Solo puedes usar fragmentos SQL de máximo 5 líneas cuando sean necesarios para explicar una restricción o alternativa.
- Extensión máxima: 2 páginas. Prioriza tablas sobre párrafos.

Formato de salida, en este orden:
1. Riesgos: tabla con riesgo, impacto y mitigación. Incluye como mínimo concurrencia, idempotencia, integridad de datos y seguridad.
2. Modelo de datos (SPEC.md sección 3): restricciones y ampliaciones propuestas, cada una con su justificación.
3. Contrato de la API (sección 4): código HTTP y cuerpo de respuesta para el éxito, el reintento idempotente y cada validación de la regla R4.
4. Concurrencia e idempotencia (sección 5): al menos dos alternativas para cada una, con ventajas, desventajas y tu recomendación.
5. Vacíos del enunciado: casos que la especificación no define (por ejemplo, el mismo request_id con datos distintos). Dame opciones, pero no decidas por mí.
6. Fuera de alcance (sección 7).
7. Preguntas que debo responder antes de diseñar la base de datos.

Criterio de aceptación de tu respuesta:
- Cada sección "(pendiente P1)" de SPEC.md tiene una propuesta.
- Las decisiones de concurrencia e idempotencia traen alternativas comparadas, no una sola solución.
- Todo supuesto que hagas queda marcado explícitamente como "Supuesto".
```

**Qué hice con la respuesta:**

- La IA entregó riesgos, modelo de datos, contrato HTTP, dos o más alternativas para concurrencia e idempotencia, y seis vacíos del enunciado sin decidirlos.
- Hallazgo relevante del análisis: insertar la reserva antes de descontar el stock puede causar *deadlock* por el bloqueo de la clave foránea. Se fijó el orden `UPDATE` → `INSERT`.
- Decisiones (intento 1: delegadas al asistente con justificación y revisadas por mí): descuento atómico condicional, consulta previa + `UNIQUE`, 409 ante `request_id` con datos distintos, reevaluar rechazos, guardar `remaining_stock`, 200 en el reintento, `request_id` con *collation* binario y PHP puro.
- Las decisiones quedaron registradas en SPEC.md (secciones 2 a 7).

---

## P2 · Base de datos

```text
Continuamos con las reglas de la sesión definidas en P1. El contrato es SPEC.md (secciones 3 y 5).

Tarea:
Crea el script de base de datos en database/schema.sql. Te autorizo a crear solo ese archivo.

Requisitos del script:
- Tablas products y reservations exactamente como define SPEC.md sección 3 (tipos, restricciones, InnoDB y utf8mb4).
- Producto de ejemplo: id 1, "Producto Demo", stock 10.
- Debe poder ejecutarse varias veces y dejar siempre la base en su estado inicial.
- No debe contener contraseñas ni crear usuarios: el usuario reservas_app ya lo crea Docker con la clave del .env.

Además, sin crear más archivos:
1. Propón cómo ejecutar el script en Docker con un solo comando. Si requiere cambiar docker-compose.yml, muéstrame solo las líneas que cambian y espera mi aprobación.
2. Propón cómo dejar al usuario reservas_app con los permisos mínimos de SPEC.md, considerando que las pruebas automatizadas solo reiniciarán los datos (no la estructura). Dame opciones con su consecuencia.
3. Dame las consultas para comprobar que MySQL rechaza: stock negativo, quantity 0, request_id duplicado y product_id inexistente, indicando el error esperado en cada caso.

Formato: el SQL completo y luego los puntos 1 a 3. Máximo 1 página fuera del SQL.
```

**Qué hice con la respuesta:**

- Acepté `database/schema.sql`. Lo ejecuté en MySQL 8.4 (Docker) y comprobé el estado inicial (Producto Demo, stock 10).
- Acepté montar `./database` en `/docker-entrypoint-initdb.d` (cambio de una línea en `docker-compose.yml`): el script se aplica solo al crear el volumen.
- Permisos: elegí la opción b (app con `SELECT, INSERT, UPDATE`; las pruebas reinician datos con una conexión administrativa). Se creó `database/permissions.sql`.
- Validé que MySQL rechaza stock negativo (3819), quantity 0 (3819), request_id duplicado (1062) y producto inexistente (1452), y que la app no puede borrar (1142).
- **Error de la IA detectado:** los comandos propuestos usaban comillas dobles dentro de `sh -c`; PowerShell 5.1 las elimina al llamar a Docker y MySQL recibió una consulta incompleta (error 1064). Se corrigió usando solo comillas simples.

---

## P3 · Lógica de reserva + endpoint

```text
Continuamos con las reglas de la sesión. El contrato es SPEC.md (secciones 2, 4 y 5); sigue el "Flujo de una reserva" paso a paso, sin cambiar su orden.

Tarea:
Implementa el endpoint POST /reservations.

Te autorizo a crear:
- public/index.php (único punto de entrada).
- Hasta 4 archivos en src/ (por ejemplo: conexión, validación, servicio de reserva y respuestas JSON).

Si necesitas cambiar composer.json o docker-compose.yml, muéstrame solo las líneas que cambian y espera mi aprobación.

Requisitos:
- declare(strict_types=1) en todos los archivos.
- PDO con ERRMODE_EXCEPTION, EMULATE_PREPARES en false y modo estricto de MySQL activado al conectar. Credenciales solo desde variables de entorno.
- Validación estricta de tipos según SPEC.md sección 4.
- Respuestas y códigos HTTP exactamente como la tabla de SPEC.md sección 4.
- En errores 500, no expongas detalles internos al cliente; regístralos en el log del contenedor.
- No implementes pruebas automatizadas todavía.

Formato de respuesta:
1. Árbol de archivos con la responsabilidad de cada uno (una línea por archivo).
2. El código.
3. La forma más simple de probar manualmente cada caso de la tabla de SPEC.md sección 4 desde PowerShell 5.1, mostrando el código HTTP y el cuerpo de la respuesta también en los errores. No uses comillas dobles anidadas dentro de comandos de Docker.

Máximo 1 página de explicación fuera del código.
```

**Qué hice con la respuesta:**

- Acepté los 5 archivos (`public/index.php` y 4 clases en `src/`) y los cambios propuestos como diff en `composer.json` (autoload PSR-4) y `docker-compose.yml` (servidor PHP con 4 workers en el puerto 8080).
- Probé manualmente los 11 casos de la tabla de SPEC.md sección 4 en mi entorno: todos devolvieron el código esperado (201, 200, 409, 400, 422 ×3, 404, 409, 405, 404).
- **Hallazgo de la IA aceptado:** dos solicitudes con el mismo `request_id` y la última unidad podían devolver 409 por stock en vez de la reserva existente. Se agregó una nueva búsqueda del `request_id` antes de rechazar (verificado con 10 solicitudes idénticas en paralelo: 1 × 201 + 9 × 200).
- **Inconsistencia detectada por la IA y corregida:** `composer.json` declaraba PHP 8.2, pero PHPUnit 12.5 exige 8.3.
- **Error de la IA detectado:** la función de pruebas manuales asumía PowerShell 5.1; en la terminal de VS Code (PowerShell 7) falló al mostrar las respuestas de error. Se corrigió para funcionar en ambas versiones.

---

## P4 · Pruebas

```text
Continuamos con las reglas de la sesión. El contrato es SPEC.md (secciones 4 y 5). El código está en src/ y public/index.php.

Tarea:
Implementa las pruebas automatizadas con PHPUnit que exige la prueba técnica:
1. Reserva correcta.
2. Stock insuficiente.
3. Idempotencia de request_id: el mismo pedido devuelve la misma reserva sin cambiar el stock; el mismo request_id con otros datos devuelve 409.
4. Concurrencia: stock 1 y dos solicitudes simultáneas de quantity 1 → exactamente una 201, una 409 y stock final 0.

Te autorizo a crear solo archivos dentro de tests/. Si necesitas cambiar phpunit.xml, composer.json o docker-compose.yml, muéstrame solo las líneas que cambian y espera mi aprobación.

Requisitos:
- Las pruebas llaman a la API real por HTTP (http://127.0.0.1:8080 desde el contenedor app) para validar el flujo completo.
- Antes de cada prueba, reinicia solo los datos con una conexión administrativa (root), como define SPEC.md sección 3. La aplicación no recibe permisos nuevos.
- La prueba de concurrencia debe enviar las solicitudes realmente en paralelo y repetirse varias veces, para que un resultado correcto no sea casualidad.
- Pocas pruebas relevantes; nada trivial.

Antes del código, explica en máximo 5 líneas: cómo garantiza la prueba de concurrencia que las solicitudes se solapan de verdad, y qué haría fallar la prueba si el código tuviera el bug de sobreventa.

Formato:
1. Esa explicación.
2. El código.
3. El comando para ejecutar las pruebas y el comando para guardar el resultado en un archivo (es un entregable).
```

**Qué hice con la respuesta:**

- Acepté `tests/ReservationApiTest.php`: 5 pruebas por HTTP contra la API real (reserva correcta, stock insuficiente, idempotencia, concurrencia con stock 1 e idempotencia bajo concurrencia).
- La prueba de concurrencia usa una **barrera**: bloquea la fila del producto, espera a que todas las solicitudes estén detenidas dentro de MySQL y solo entonces las libera. Así el solapamiento está garantizado y no depende de la suerte.
- **Validación de la prueba (mutación):** con el bug "consultar y luego descontar" introducido a propósito, la prueba crítica falla en la primera ronda; además, la restricción `CHECK (stock >= 0)` impidió que el stock quedara negativo.
- **Errores de la IA detectados en el camino:** la primera versión no garantizaba el solapamiento; la barrera inicial leía `information_schema.innodb_trx`, que usa caché y podía abrirse antes de tiempo (se cambió a `performance_schema.data_lock_waits`); y el envío simultáneo podía hacer que un mismo worker atendiera dos solicitudes en fila (ahora se envían una a una).
- Ejecuté la suite en mi entorno (PHP 8.3, MySQL 8.4, PHPUnit 12.5): 6 de 6 pruebas en verde. Resultado guardado en `resultados-pruebas.txt`.

---

## P5 · Auditoría crítica

```text
Continuamos con las reglas de la sesión. Cambia de rol: ahora eres un auditor técnico independiente. Tu objetivo es encontrar problemas, no defender la solución.

Contexto: SPEC.md es el contrato. Revisa src/, public/index.php, database/, docker-compose.yml y tests/.

Tarea:
Audita la solución buscando problemas de:
1. Concurrencia.
2. Transacciones.
3. Idempotencia.
4. Integridad de datos.
5. Seguridad.

Restricciones:
- No modifiques ni crees archivos: solo análisis.
- Cada hallazgo debe citar archivo y línea, o el comando con que se reproduce. Si no puedes demostrarlo, márcalo como "Hipótesis".
- Máximo 10 hallazgos, ordenados por severidad. Nada de recomendaciones de estilo.

Formato: una tabla con #, área, hallazgo, evidencia, severidad (alta/media/baja), recomendación y costo de aplicarla (bajo/medio/alto). Al final, en máximo 3 líneas: lo que consideras correcto y no necesita cambios.
```

### Decisiones sobre las recomendaciones de la IA

**Recomendación 1 (Idempotencia / Integridad):** La validación de `request_id` acepta un salto de línea final (`$` en la expresión regular): `"REQ-NL"` y `"REQ-NL\n"` crean dos reservas. Reproducido.
Decisión: Aceptada
Motivo: `request_id` es la llave de idempotencia (Regla 2): si dos textos que el cliente considera iguales generan llaves distintas, un reintento descuenta stock dos veces. La validación debe aceptar exactamente el formato definido en SPEC.md. El hallazgo se reprodujo y la corrección cuesta un carácter (`\z`).

**Recomendación 2 (Seguridad):** Los puertos 3307 (MySQL) y 8080 (API) están publicados en todas las interfaces de red.
Decisión: Aceptada
Motivo: Principio de mínima exposición: la base de datos y la API solo necesitan ser accesibles desde este equipo. Publicarlas en `127.0.0.1` elimina el acceso desde la red local sin afectar el funcionamiento ni las pruebas.

**Recomendación 3 (Seguridad):** El contenedor `app` recibe todo el `.env`, incluida la clave de root.
Decisión: Aceptada
Motivo: Principio de mínimo privilegio: la API ya usa un usuario sin permiso de borrado, pero tener la clave de root en su entorno anula esa protección si el proceso se compromete. Esa clave solo la necesitan las pruebas, para preparar datos.

**Recomendación 4 (Seguridad / Robustez):** El servidor integrado de PHP no está diseñado para producción.
Decisión: Rechazada
Motivo: La prueba pide una API local y una solución mínima (R14). Cambiarlo por Nginx + PHP-FPM no altera la corrección del sistema: las garantías de stock, idempotencia y concurrencia viven en MySQL (descuento atómico, `CHECK`, `UNIQUE`), no en el servidor web. Su costo es alto; queda documentado en el README como mejora para producción.

**Recomendación 5 (Concurrencia / Transacciones):** Un deadlock (1213) o un tiempo de espera de bloqueo agotado (1205) responden 500 sin reintento. Hipótesis no reproducida.
Decisión: Rechazada
Motivo: Un deadlock requiere que dos transacciones esperen recursos en orden cruzado. En este flujo todas bloquean primero la fila del producto y después insertan la reserva (orden fijo `UPDATE` → `INSERT`), lo que evita ese ciclo. No se reprodujo, y la prueba de concurrencia con barrera tampoco lo produjo. Agregar reintentos sería código sin un caso demostrado; si ocurriera un caso extremo, el sistema responde 500 sin corromper datos porque la transacción se revierte.

**Recomendación 6 (Seguridad):** La cabecera `X-Powered-By` revela la versión exacta de PHP. Reproducido.
Decisión: Aceptada
Motivo: Reducir la exposición de información (fingerprinting): conocer la versión exacta permite buscar vulnerabilidades publicadas para esa versión. Ocultarla no corrige vulnerabilidades por sí sola, pero suma una capa de defensa en profundidad a cambio de una línea de configuración.

**Recomendación 7 (Seguridad):** El healthcheck pasa la clave de root como argumento (`-p...`), visible en la lista de procesos del contenedor `db`.
Decisión: Aceptada
Motivo: Los secretos no deben pasarse como argumentos de línea de comandos: cualquier proceso del contenedor puede leerlos en la lista de procesos. Pasarla por la variable `MYSQL_PWD` evita esa exposición sin cambiar el funcionamiento del healthcheck.

**Recomendación 8 (Integridad / Robustez):** No se valida el `Content-Type` ni el tamaño del cuerpo de la solicitud.
Decisión: Aceptada
Motivo: Validación en el borde y robustez: el contrato de la API es JSON, así que rechazar otros tipos con 415 y limitar el tamaño del cuerpo evita procesar entradas inesperadas y protege contra solicitudes enormes que consumen memoria (denegación de servicio). Implica agregar el 415 al contrato de SPEC.md.

---

## P6 · Correcciones precisas

```text
Continuamos con las reglas de la sesión. Aplica solo las recomendaciones de P5 marcadas como "Aceptada" en PROMPTS.md (1, 2, 3, 6, 7 y 8). No cambies nada más.

Te autorizo a modificar: src/, public/index.php, docker-compose.yml, docker/php/Dockerfile, tests/ y SPEC.md.

Para cada recomendación:
- Muéstrame solo las líneas que cambian (diff), indicando el archivo.
- Si cambia el contrato de la API o la forma de ejecutar el proyecto, actualiza SPEC.md y dímelo.
- Agrega una prueba automatizada cuando el cambio sea verificable así (como mínimo para la 1 y la 8).

Al final: los comandos para reconstruir el entorno y ejecutar todas las pruebas, y el resultado esperado.
```

**Qué hice con la respuesta:**

- Acepté los cambios, mostrados como diff, de las 6 recomendaciones aceptadas en P5; las rechazadas (4 y 5) no se tocaron.
- SPEC.md se actualizó porque cambió el contrato: nuevos códigos 415 y 413, `request_id` sin caracteres adicionales, puertos solo en `127.0.0.1` y pruebas en un servicio aparte.
- Dos pruebas nuevas cubren las recomendaciones 1, 6 y 8. Ambas **fallan con el código anterior** y pasan con el corregido, lo que demuestra que detectan el problema.
- Verificación en mi entorno: puertos `127.0.0.1:8080` y `127.0.0.1:3307` (rec. 2); `printenv DB_ROOT_PASSWORD` en el contenedor de la API no devuelve nada (rec. 3); MySQL `healthy` con el nuevo healthcheck (rec. 7); 8 de 8 pruebas en verde con `docker compose run --rm tests`.
