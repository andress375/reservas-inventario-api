# PROMPTS.md

Prompts principales usados durante el desarrollo, copiados **exactamente como fueron escritos**.
Las respuestas de la IA no se copian completas; solo se resume qué se hizo con ellas.

- **Asistente:** Claude (claude-opus-5-5), en chat, con acceso a la carpeta del repositorio.
- **Intento:** 1 (aprendizaje). Los prompts de este intento fueron redactados con apoyo del asistente en rol de tutor y revisados por mí. En el intento 2 serán de redacción propia y se consolidarán en 3 a 8 prompts.

## Plan

| # | Propósito | Estado |
|---|---|---|
| P1 | Contexto y análisis (sin código) | ✅ |
| P2 | Base de datos | ⏳ |
| P3 | Lógica de reserva + endpoint | ⏳ |
| P4 | Pruebas (incluida concurrencia) | ⏳ |
| P5 | Auditoría crítica (Punto 9) | ⏳ |
| P6 | Correcciones precisas | ⏳ |

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
(pendiente)
```

**Qué hice con la respuesta:** (pendiente)

---

## P3 · Lógica de reserva + endpoint

```text
(pendiente)
```

**Qué hice con la respuesta:** (pendiente)

---

## P4 · Pruebas

```text
(pendiente)
```

**Qué hice con la respuesta:** (pendiente)

---

## P5 · Auditoría crítica

```text
(pendiente)
```

### Decisiones sobre las recomendaciones de la IA

**Recomendación 1:** (pendiente)
Decisión: Aceptada / Rechazada
Motivo: ...

---

## P6 · Correcciones precisas

```text
(pendiente)
```

**Qué hice con la respuesta:** (pendiente)
