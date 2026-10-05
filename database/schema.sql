-- Esquema de la API de reservas de inventario (ver SPEC.md, sección 3).
-- Crea o recrea las tablas y deja el estado inicial.
-- ADVERTENCIA: borra todos los datos existentes. Solo para desarrollo y pruebas.
-- Se ejecuta sobre la base de datos ya seleccionada (no contiene USE, usuarios ni contraseñas).

SET NAMES utf8mb4;

DROP TABLE IF EXISTS reservations;
DROP TABLE IF EXISTS products;

CREATE TABLE products (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name  VARCHAR(150) NOT NULL,
    stock INT NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_products_stock CHECK (stock >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reservations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    quantity        INT NOT NULL,
    remaining_stock INT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reservations_request_id (request_id),
    KEY idx_reservations_product_id (product_id),
    CONSTRAINT fk_reservations_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_reservations_quantity CHECK (quantity > 0),
    CONSTRAINT chk_reservations_remaining_stock CHECK (remaining_stock >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO products (id, name, stock) VALUES (1, 'Producto Demo', 10);
