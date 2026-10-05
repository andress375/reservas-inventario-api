-- Permisos mínimos del usuario de la aplicación (ver SPEC.md, sección 3).
-- Se ejecuta como root. No crea usuarios ni contiene contraseñas:
-- el usuario reservas_app lo crea Docker con la clave del .env.
-- La aplicación puede consultar, crear y actualizar; nunca borrar ni cambiar la estructura.

REVOKE ALL PRIVILEGES ON reservas.* FROM 'reservas_app'@'%';
GRANT SELECT, INSERT, UPDATE ON reservas.* TO 'reservas_app'@'%';
