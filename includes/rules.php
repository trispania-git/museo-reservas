<?php
if (!defined('ABSPATH')) exit;

/**
 * Normaliza una fecha en formato:
 * - YYYY-MM-DD
 * - DD-MM-YYYY
 * Devuelve YYYY-MM-DD o '' si no es válida.
 */
function mr_normalize_date($raw) {
  $raw = trim((string)$raw);
  if ($raw === '') return '';

  // YYYY-MM-DD
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
    $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    if (checkdate($mo, $d, $y)) {
      return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    return '';
  }

  // DD-MM-YYYY
  if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) {
    $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
    if (checkdate($mo, $d, $y)) {
      return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    return '';
  }

  return '';
}

/**
 * Normaliza un listado (textarea) para guardar en ajustes.
 * Acepta:
 * - YYYY-MM-DD
 * - DD-MM-YYYY
 * - opcionalmente "YYYY-MM-DD HH:MM" / "DD-MM-YYYY HH:MM"
 *
 * Devuelve líneas en:
 * - YYYY-MM-DD
 * - YYYY-MM-DD HH:MM (si venía con hora válida)
 */
function mr_normalize_lines_dates($text) {
  $text = preg_replace("/\r\n|\r/", "\n", (string)$text);
  $lines = preg_split("/\n/", trim($text));
  $out = [];

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    // ¿Trae hora?
    if (preg_match('/^(.+?)\s+(\d{2}:\d{2})$/', $line, $m)) {
      $d = mr_normalize_date($m[1]);
      $t = trim($m[2]);
      if ($d && preg_match('/^\d{2}:\d{2}$/', $t)) {
        $out[] = $d . ' ' . $t;
      }
      continue;
    }

    $d = mr_normalize_date($line);
    if ($d) $out[] = $d;
  }

  $out = array_values(array_unique($out));
  return implode("\n", $out);
}

/**
 * Devuelve true si $date (YYYY-MM-DD) está incluido en una lista (closures/extra_open).
 * La lista puede contener:
 * - YYYY-MM-DD
 * - YYYY-MM-DD HH:MM  (se cuenta como que la fecha está presente)
 */
function mr_list_has_date($listText, $date) {
  $listText = preg_replace("/\r\n|\r/", "\n", (string)$listText);
  $lines = preg_split("/\n/", trim($listText));
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    if ($line === $date) return true;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+\d{2}:\d{2}$/', $line, $m)) {
      if ($m[1] === $date) return true;
    }
  }
  return false;
}

/**
 * Parse de horas para un día (0 dom .. 6 sáb).
 * Lee $s['times_by_day'][$dow] como líneas HH:MM.
 */
function mr_times_for_weekday($dow, $s) {
  $dow = (string)intval($dow);
  $txt = (string)($s['times_by_day'][$dow] ?? '');
  $txt = preg_replace("/\r\n|\r/", "\n", trim($txt));
  if ($txt === '') return [];

  $lines = preg_split("/\n/", $txt);
  $times = [];
  foreach ($lines as $t) {
    $t = trim($t);
    if ($t === '') continue;
    if (preg_match('/^\d{2}:\d{2}$/', $t)) $times[] = $t;
  }

  $times = array_values(array_unique($times));
  sort($times);
  return $times;
}

/**
 * ✅ Opción A:
 * Un día está abierto si:
 * - NO está en cierres (closures)
 * y además:
 *   - está en extra_open, o
 *   - su día de la semana está en days_open
 */
function mr_is_date_open($date, $s) {
  $date = trim((string)$date);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;

  // cierres por fecha (manda siempre)
  if (mr_list_has_date($s['closures'] ?? '', $date)) return false;

  // extra_open abre siempre la fecha
  if (mr_list_has_date($s['extra_open'] ?? '', $date)) return true;

  // si no es extra, aplica regla semanal
  $dow = (int)date('w', strtotime($date)); // 0 dom .. 6 sáb
  $openDays = array_map('strval', (array)($s['days_open'] ?? []));
  return in_array((string)$dow, $openDays, true);
}

/**
 * ✅ Opción A:
 * Si el día está abierto (por semanal o por extra_open),
 * las horas salen de "Horas por día" del weekday correspondiente,
 * aunque ese weekday NO esté marcado como abierto.
 */
function mr_times_for_date($date, $s) {
  $date = trim((string)$date);
  if (!mr_is_date_open($date, $s)) return [];

  $dow = (int)date('w', strtotime($date)); // 0 dom .. 6 sáb
  return mr_times_for_weekday($dow, $s);
}

/**
 * Cierre de sesión:
 * - si en closures hay "YYYY-MM-DD" => cerrado todo el día
 * - si hay "YYYY-MM-DD HH:MM" => cerrado ese pase
 *
 * (Aunque ya no lo mostremos en la UI, lo respetamos por compatibilidad)
 */
function mr_slot_is_closed($date, $time, $s) {
  $date = trim((string)$date);
  $time = trim((string)$time);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return true;
  if (!preg_match('/^\d{2}:\d{2}$/', $time)) return true;

  $closures = preg_replace("/\r\n|\r/", "\n", (string)($s['closures'] ?? ''));
  $lines = preg_split("/\n/", trim($closures));

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    if ($line === $date) return true;
    if ($line === ($date . ' ' . $time)) return true;
  }

  return false;
}

/**
 * Comprueba si una fecha está bloqueada (no se permiten nuevas reservas).
 */
function mr_date_is_blocked($date, $s) {
  $date = trim((string)$date);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;

  $blocked = preg_replace("/\r\n|\r/", "\n", (string)($s['blocked_dates'] ?? ''));
  $lines = preg_split("/\n/", trim($blocked));

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === $date) return true;
  }

  return false;
}

/**
 * ✅ Función que faltaba (y causaba el 500):
 * Plazas restantes para un pase = capacity - suma asistentes confirmados.
 * Además respeta min_notice_hours: si el pase está "demasiado cerca", devuelve 0.
 */
function mr_remaining_for_slot($date, $time, $s) {
  $date = trim((string)$date);
  $time = trim((string)$time);

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 0;
  if (!preg_match('/^\d{2}:\d{2}$/', $time)) return 0;

  // Si ese pase está cerrado, no hay plazas
  if (mr_slot_is_closed($date, $time, $s)) return 0;

  // Si la fecha está bloqueada, no se permiten más reservas
  if (mr_date_is_blocked($date, $s)) return 0;

  // Antelación mínima
  $min_notice = intval($s['min_notice_hours'] ?? 0);
  if ($min_notice > 0) {
    try {
      $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
      $slot = new DateTime($date . ' ' . $time . ':00', $tz);
      $now_ts = (int) current_time('timestamp'); // respeta timezone WP
      $min_ts = $now_ts + ($min_notice * 3600);
      if ($slot->getTimestamp() < $min_ts) return 0;
    } catch (Throwable $e) {
      // si falla DateTime, no bloqueamos por antelación (pero nunca debe pasar)
    }
  }

  // Aforo: usar el del día si existe, si no el global
  $dow = (string)date('w', strtotime($date));
  $day_cap = $s['capacity_by_day'][$dow] ?? '';
  $capacity = ($day_cap !== '' && $day_cap !== null && (string)$day_cap !== '')
    ? intval($day_cap)
    : intval($s['capacity'] ?? 0);
  if ($capacity <= 0) return 0;

  $used = function_exists('mr_db_sum_attendees') ? (int) mr_db_sum_attendees($date, $time) : 0;
  $remaining = $capacity - $used;
  return max(0, (int)$remaining);
}

/**
 * Devuelve array de fechas YYYY-MM-DD a partir de un textarea.
 * Sirve para pasar a JS (calendario), ignorando horas si las hubiera.
 */
function mr_parse_dates_list($text) {
  $text = preg_replace("/\r\n|\r/", "\n", (string)$text);
  $lines = preg_split("/\n/", trim($text));
  $out = [];

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    // YYYY-MM-DD (con o sin hora)
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+\d{2}:\d{2})?$/', $line, $m)) {
      $out[] = $m[1];
      continue;
    }

    // DD-MM-YYYY (con o sin hora)
    if (preg_match('/^(\d{2}-\d{2}-\d{4})(?:\s+\d{2}:\d{2})?$/', $line, $m)) {
      $d = mr_normalize_date($m[1]);
      if ($d) $out[] = $d;
      continue;
    }
  }

  $out = array_values(array_unique($out));
  sort($out);
  return $out;
}
