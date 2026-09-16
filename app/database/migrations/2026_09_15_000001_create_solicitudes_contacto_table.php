<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea la base `eorder_contactos` y la tabla `solicitudes_contacto` que
 * respalda el formulario público de contacto (ruta POST /api/contacto).
 *
 * Vive en una base aparte de la del restaurante, por eso usa la conexión
 * `contactos` (ver config/database.php). El `folio` (UUID) lo genera un
 * trigger en la propia base. La migración es idempotente: si la tabla ya
 * existe no la toca ni pierde datos.
 */
return new class extends Migration
{
    private const CONNECTION = 'contactos';

    private const TRIGGER = 'solicitudes_contacto_folio';

    public function up(): void
    {
        $connection = DB::connection(self::CONNECTION);
        $database = $connection->getDatabaseName();

        $connection->statement(
            "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        $connection->statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS solicitudes_contacto (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  folio CHAR(36) NULL,
  nombre VARCHAR(120) NOT NULL,
  negocio VARCHAR(150) NULL,
  email VARCHAR(190) NOT NULL,
  telefono VARCHAR(25) NOT NULL,
  ciudad VARCHAR(120) NULL,
  tipo_contacto ENUM('distribuidor','representante','indistinto') NOT NULL,
  medio_preferido ENUM('telefono','whatsapp','email') NOT NULL DEFAULT 'telefono',
  horario_preferido ENUM('cualquiera','manana','tarde') NOT NULL DEFAULT 'cualquiera',
  mensaje TEXT NOT NULL,
  estatus ENUM('nuevo','asignado','contactado','calificado','descartado','convertido') NOT NULL DEFAULT 'nuevo',
  asignado_a VARCHAR(120) NULL,
  notas_internas TEXT NULL,
  acepta_privacidad TINYINT(1) NOT NULL DEFAULT 0,
  ip_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(500) NULL,
  origen VARCHAR(100) NOT NULL DEFAULT 'contactanos.html',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  contactado_en DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_solicitudes_folio (folio),
  KEY idx_solicitudes_estatus_creado (estatus, creado_en),
  KEY idx_solicitudes_email (email),
  KEY idx_solicitudes_ip_creado (ip_hash, creado_en),
  KEY idx_solicitudes_asignado (asignado_a)
) ENGINE=InnoDB
SQL
        );

        // Folio (UUID) automático para cada solicitud nueva.
        $connection->unprepared('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        $connection->unprepared(<<<'SQL'
CREATE TRIGGER solicitudes_contacto_folio
BEFORE INSERT ON solicitudes_contacto
FOR EACH ROW
BEGIN
  IF NEW.folio IS NULL OR NEW.folio = '' THEN
    SET NEW.folio = UUID();
  END IF;
END
SQL
        );
    }

    /**
     * No se elimina la tabla ni sus datos: son solicitudes reales de clientes
     * y el formulario público seguiría escribiendo en ella. Sólo se revierte
     * el trigger para que la estructura quede en el estado previo.
     */
    public function down(): void
    {
        DB::connection(self::CONNECTION)->unprepared('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
    }
};
