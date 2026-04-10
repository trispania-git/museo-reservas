<?php
if (!defined('ABSPATH')) exit;

function mr_db_table() {
  global $wpdb;
  return $wpdb->prefix . 'mr_bookings';
}

function mr_db_install() {
  global $wpdb;
  $table = mr_db_table();
  $charset_collate = $wpdb->get_charset_collate();

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  // Tabla (dbDelta crea o ajusta)
  $sql = "CREATE TABLE {$table} (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_code VARCHAR(40) NULL,
    slot_date DATE NOT NULL,
    slot_time VARCHAR(5) NOT NULL,
    capacity INT NOT NULL,
    attendees INT NOT NULL,
    req_first_name VARCHAR(100) NOT NULL,
    req_last_name VARCHAR(120) NOT NULL,
    req_dni VARCHAR(20) NOT NULL,
    req_phone VARCHAR(30) NOT NULL,
    req_email VARCHAR(190) NOT NULL,
    companions_json LONGTEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
    created_at DATETIME NOT NULL,
    privacy_accepted TINYINT(1) NOT NULL DEFAULT 0,
    privacy_ip VARCHAR(45) NULL,
    privacy_at DATETIME NULL,
    PRIMARY KEY  (id),
    KEY slot_idx (slot_date, slot_time),
    UNIQUE KEY booking_code_uq (booking_code)
  ) {$charset_collate};";

  dbDelta($sql);

  // ✅ Migración segura: si no existe booking_code, la añadimos (y su índice)
  $has_col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'booking_code'));
  if (!$has_col) {
    $wpdb->query("ALTER TABLE {$table} ADD COLUMN booking_code VARCHAR(40) NULL");
    $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", 'booking_code_uq'));
    if (!$idx) {
      $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY booking_code_uq (booking_code)");
    }
  }

  // ✅ Migración segura: columnas privacidad
  $p1 = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'privacy_accepted'));
  if (!$p1) $wpdb->query("ALTER TABLE {$table} ADD COLUMN privacy_accepted TINYINT(1) NOT NULL DEFAULT 0");

  $p2 = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'privacy_ip'));
  if (!$p2) $wpdb->query("ALTER TABLE {$table} ADD COLUMN privacy_ip VARCHAR(45) NULL");

  $p3 = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'privacy_at'));
  if (!$p3) $wpdb->query("ALTER TABLE {$table} ADD COLUMN privacy_at DATETIME NULL");
}

/**
 * Suma de asistentes confirmados en una sesión (fecha + hora).
 */
function mr_db_sum_attendees($date, $time) {
  global $wpdb;
  $table = mr_db_table();

  $sum = $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(attendees),0)
     FROM {$table}
     WHERE slot_date=%s AND slot_time=%s AND status='confirmed'",
    $date, $time
  ));
  return intval($sum);
}

/**
 * Cuenta reservas confirmadas para una sesión (fecha + hora).
 * Lo usamos para generar el sufijo 01/02/03...
 */
function mr_db_count_bookings_for_slot($date, $time) {
  global $wpdb;
  $table = mr_db_table();

  $n = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*)
     FROM {$table}
     WHERE slot_date=%s AND slot_time=%s AND status='confirmed'",
    $date, $time
  ));
  return intval($n);
}

/**
 * Máximo de asistentes confirmados en un solo slot para un día de la semana (fechas futuras).
 * $dow: 0=Dom, 1=Lun, ..., 6=Sáb (misma convención que PHP date('w'))
 */
function mr_db_max_attendees_for_weekday($dow) {
  global $wpdb;
  $table = mr_db_table();
  $dow = intval($dow);

  // MySQL DAYOFWEEK: 1=Dom..7=Sáb → restamos 1 para alinear con PHP (0=Dom..6=Sáb)
  $mysql_dow = $dow + 1;

  $val = $wpdb->get_var($wpdb->prepare(
    "SELECT MAX(slot_total) FROM (
       SELECT SUM(attendees) AS slot_total
       FROM {$table}
       WHERE status='confirmed' AND slot_date >= CURDATE() AND DAYOFWEEK(slot_date) = %d
       GROUP BY slot_date, slot_time
     ) t",
    $mysql_dow
  ));

  return intval($val);
}

/**
 * Inserta una reserva y devuelve el ID.
 */
function mr_db_insert_booking($data) {
  global $wpdb;
  $table = mr_db_table();

  $ok = $wpdb->insert($table, [
    'booking_code' => $data['booking_code'] ?? null,
    'slot_date' => $data['slot_date'],
    'slot_time' => $data['slot_time'],
    'capacity'  => $data['capacity'],
    'attendees' => $data['attendees'],
    'req_first_name' => $data['req_first_name'],
    'req_last_name'  => $data['req_last_name'],
    'req_dni'        => $data['req_dni'],
    'req_phone'      => $data['req_phone'],
    'req_email'      => $data['req_email'],
    'companions_json'=> $data['companions_json'],
    'status'         => $data['status'],
    'created_at'     => $data['created_at'],
    'privacy_accepted' => isset($data['privacy_accepted']) ? (int)$data['privacy_accepted'] : 0,
    'privacy_ip'       => $data['privacy_ip'] ?? null,
    'privacy_at'       => $data['privacy_at'] ?? null,
  ], [
    '%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s'
  ]);

  if (!$ok) return false;
  return intval($wpdb->insert_id);
}

/**
 * Obtener una reserva por ID (array) o null.
 */
function mr_db_get_booking($id) {
  global $wpdb;
  $table = mr_db_table();
  $id = intval($id);
  if ($id <= 0) return null;

  $row = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id),
    ARRAY_A
  );
  return is_array($row) ? $row : null;
}

/**
 * Cancelar reserva (soft delete): status = 'cancelled'.
 */
function mr_db_cancel_booking($id) {
  global $wpdb;
  $table = mr_db_table();
  $id = intval($id);
  if ($id <= 0) return false;

  $ok = $wpdb->update(
    $table,
    ['status' => 'cancelled'],
    ['id' => $id],
    ['%s'],
    ['%d']
  );

  return ($ok !== false);
}

/**
 * Eliminar definitivamente una reserva.
 */
function mr_db_delete_booking($id) {
  global $wpdb;
  $table = mr_db_table();
  $id = intval($id);
  if ($id <= 0) return false;

  $ok = $wpdb->delete($table, ['id' => $id], ['%d']);
  return ($ok !== false);
}
