<?php
if (!defined('ABSPATH')) exit;

/**
 * Helpers formato ES (solo visual)
 */
function mr_admin_fmt_date_es($iso) {
  $iso = (string)$iso;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
    [$y,$m,$d] = explode('-', $iso);
    return $d . '-' . $m . '-' . $y;
  }
  return $iso;
}

function mr_admin_fmt_datetime_es($dt) {
  $dt = (string)$dt;
  // expected: YYYY-MM-DD HH:MM:SS (o similar)
  if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/', $dt, $m)) {
    return mr_admin_fmt_date_es($m[1]) . ' ' . $m[2];
  }
  // fallback: si viene sin hora, intentamos solo fecha
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
    return mr_admin_fmt_date_es($dt);
  }
  return $dt;
}

/**
 * Pantalla de listado de reservas + filtros + export CSV
 * (NO registra menús; eso se hace en museo-reservas.php)
 */
function mr_admin_bookings_page() {
  if (!current_user_can('manage_options')) {
    wp_die('No tienes permisos suficientes.');
  }

  global $wpdb;
  $table = function_exists('mr_db_table') ? mr_db_table() : $wpdb->prefix . 'mr_bookings';

  $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
  $date_to   = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

  // Filtro de sesión (fecha + hora)
  $session_filter = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';

  // ✅ Nuevo: filtro de estado
  // confirmed (por defecto), cancelled, all
  $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'confirmed';
  if (!in_array($status_filter, ['confirmed','cancelled','all'], true)) $status_filter = 'confirmed';

  // Normalizar a ISO si el helper existe
  if (function_exists('mr_norm_date_to_iso')) {
    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = mr_norm_date_to_iso($date_from);
    if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = mr_norm_date_to_iso($date_to);
  }

  // Obtener sesiones únicas (fecha + hora) para el desplegable
  $sessions_sql = "SELECT DISTINCT slot_date, slot_time FROM {$table} ORDER BY slot_date DESC, slot_time ASC";
  $sessions_rows = $wpdb->get_results($sessions_sql, ARRAY_A);

  $where = "WHERE 1=1";
  $params = [];

  // Filtro de sesión tiene prioridad sobre fecha desde/hasta
  if ($session_filter && preg_match('/^(\d{4}-\d{2}-\d{2})\|(.+)$/', $session_filter, $sm)) {
    $where .= " AND slot_date = %s AND slot_time = %s";
    $params[] = $sm[1];
    $params[] = $sm[2];
  } else {
    if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
      $where .= " AND slot_date >= %s";
      $params[] = $date_from;
    }
    if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
      $where .= " AND slot_date <= %s";
      $params[] = $date_to;
    }
  }

  // ✅ Aplicar filtro estado
  if ($status_filter === 'confirmed' || $status_filter === 'cancelled') {
    $where .= " AND status = %s";
    $params[] = $status_filter;
  }

  $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
  $per_page = 25;
  $offset = ($page - 1) * $per_page;

  $sql_total = "SELECT COUNT(*) FROM {$table} {$where}";
  $total = $params ? (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params)) : (int)$wpdb->get_var($sql_total);

  $sql = "SELECT * FROM {$table} {$where}
          ORDER BY slot_date DESC, slot_time DESC, id DESC
          LIMIT %d OFFSET %d";

  $params_rows = array_merge($params, [$per_page, $offset]);
  $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_rows), ARRAY_A);

  $base_url = admin_url('admin.php?page=museo-reservas-bookings');

  // Mensaje tras acción
  $notice = isset($_GET['mr_notice']) ? sanitize_text_field($_GET['mr_notice']) : '';
  $notice_type = isset($_GET['mr_notice_type']) ? sanitize_text_field($_GET['mr_notice_type']) : 'success';

  $nonce = wp_create_nonce('mr_export_csv');

  // ✅ Export respetando filtros (incluye status y sesión)
  $export_url = admin_url('admin-post.php?action=mr_export_csv')
    . '&_wpnonce=' . urlencode($nonce)
    . '&date_from=' . urlencode($date_from)
    . '&date_to=' . urlencode($date_to)
    . '&status=' . urlencode($status_filter)
    . '&session=' . urlencode($session_filter);

  ?>
  <div class="wrap">
    <h1>Reservas · Museo Reservas</h1>

    <?php if ($notice): ?>
      <div class="<?php echo esc_attr($notice_type === 'error' ? 'notice notice-error' : 'notice notice-success'); ?> is-dismissible">
        <p><?php echo esc_html($notice); ?></p>
      </div>
    <?php endif; ?>

    <form method="get" style="margin:12px 0;">
      <input type="hidden" name="page" value="museo-reservas-bookings">

      <label>Desde:
        <input type="text" name="date_from" value="<?php echo esc_attr($date_from ? mr_admin_fmt_date_es($date_from) : ''); ?>"
               placeholder="DD-MM-YYYY" style="width:190px;">
      </label>

      <label style="margin-left:10px;">Hasta:
        <input type="text" name="date_to" value="<?php echo esc_attr($date_to ? mr_admin_fmt_date_es($date_to) : ''); ?>"
               placeholder="DD-MM-YYYY" style="width:190px;">
      </label>

      <!-- Filtro por sesión -->
      <label style="margin-left:10px;">Sesión:
        <select name="session">
          <option value="">— Todas —</option>
          <?php foreach ($sessions_rows as $sr):
            $val = $sr['slot_date'] . '|' . $sr['slot_time'];
            $label = mr_admin_fmt_date_es($sr['slot_date']) . ' / ' . $sr['slot_time'];
          ?>
            <option value="<?php echo esc_attr($val); ?>" <?php selected($session_filter, $val); ?>>
              <?php echo esc_html($label); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <!-- ✅ Nuevo selector de estado -->
      <label style="margin-left:10px;">Estado:
        <select name="status">
          <option value="confirmed" <?php selected($status_filter, 'confirmed'); ?>>Confirmadas</option>
          <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>Canceladas</option>
          <option value="all" <?php selected($status_filter, 'all'); ?>>Todas</option>
        </select>
      </label>

      <button class="button button-primary" style="margin-left:10px;">Filtrar</button>
      <a class="button" href="<?php echo esc_url($base_url); ?>" style="margin-left:6px;">Limpiar</a>
      <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>" style="margin-left:10px;">
        Exportar CSV
      </a>
    </form>

    <p class="description">Total: <strong><?php echo (int)$total; ?></strong> reservas</p>

    <table class="widefat striped">
      <thead>
        <tr>
          <th style="width:170px;">Código / ID</th>
          <th style="width:110px;">Fecha</th>
          <th style="width:80px;">Hora</th>
          <th style="width:70px;">Asist.</th>
          <th>Solicitante</th>
          <th>Acompañantes</th>
          <th style="width:170px;">Creada</th>
          <th style="width:160px;">Estado / Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8">No hay reservas con esos filtros.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $code = !empty($r['booking_code']) ? $r['booking_code'] : ('#' . $r['id']);

              $companions = [];
              if (!empty($r['companions_json'])) {
                $decoded = json_decode($r['companions_json'], true);
                if (is_array($decoded)) $companions = $decoded;
              }

              $comp_summary = '—';
              if (!empty($companions)) {
                $parts = [];
                foreach ($companions as $c) {
                  $parts[] = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))
                           . ' (' . ($c['dni'] ?? '') . ')';
                }
                $comp_summary = implode('; ', $parts);
              }

              $req = trim(($r['req_first_name'] ?? '') . ' ' . ($r['req_last_name'] ?? ''));
              $req_line = esc_html($req)
                . '<br><small>DNI: ' . esc_html($r['req_dni'] ?? '') . ' · Tel: ' . esc_html($r['req_phone'] ?? '') . '</small>'
                . '<br><small>Email: ' . esc_html($r['req_email'] ?? '') . '</small>';

              $status = $r['status'] ?? 'confirmed';
              $status_label = ($status === 'cancelled') ? 'Cancelada' : 'Confirmada';

              // Volver al mismo listado con filtros/página
              $return_args = [
                'page' => 'museo-reservas-bookings',
                'date_from' => $date_from,
                'date_to' => $date_to,
                'session' => $session_filter,
                'status' => $status_filter,
                'paged' => $page,
              ];

              $cancel_nonce = wp_create_nonce('mr_cancel_booking_' . intval($r['id']));
              $delete_nonce = wp_create_nonce('mr_delete_booking_' . intval($r['id']));

              $cancel_url = add_query_arg(array_merge($return_args, [
                'action' => 'mr_cancel_booking',
                'booking_id' => intval($r['id']),
                '_wpnonce' => $cancel_nonce,
              ]), admin_url('admin-post.php'));

              $delete_url = add_query_arg(array_merge($return_args, [
                'action' => 'mr_delete_booking',
                'booking_id' => intval($r['id']),
                '_wpnonce' => $delete_nonce,
              ]), admin_url('admin-post.php'));
            ?>
            <tr>
              <td><strong><?php echo esc_html($code); ?></strong></td>

              <!-- ✅ Fecha en formato ES -->
              <td><?php echo esc_html(mr_admin_fmt_date_es($r['slot_date'])); ?></td>

              <td><?php echo esc_html($r['slot_time']); ?></td>
              <td><?php echo (int)$r['attendees']; ?></td>
              <td><?php echo $req_line; ?></td>
              <td style="max-width:420px;word-break:break-word;"><?php echo esc_html($comp_summary); ?></td>

              <!-- ✅ Creada en formato ES -->
              <td><?php echo esc_html(mr_admin_fmt_datetime_es($r['created_at'])); ?></td>

              <td>
                <div><strong><?php echo esc_html($status_label); ?></strong></div>

                <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
                  <?php if ($status !== 'cancelled'): ?>
                    <a class="button" href="<?php echo esc_url($cancel_url); ?>"
                       onclick="return confirm('¿Cancelar esta reserva? Esto recuperará el aforo de la sesión.');">
                      Cancelar
                    </a>
                  <?php endif; ?>

                  <a class="button button-link-delete" href="<?php echo esc_url($delete_url); ?>"
                     onclick="return confirm('¿Eliminar definitivamente esta reserva? Esta acción no se puede deshacer.');">
                    Eliminar
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <?php
      $total_pages = (int)ceil($total / $per_page);
      if ($total_pages > 1):
        $qs = [
          'page' => 'museo-reservas-bookings',
          'date_from' => $date_from,
          'date_to' => $date_to,
          'session' => $session_filter,
          'status' => $status_filter,
        ];
    ?>
      <div style="margin-top:12px;">
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
          <?php
            $qs['paged'] = $p;
            $url = add_query_arg($qs, admin_url('admin.php'));
            $style = $p === $page ? 'font-weight:700;text-decoration:underline;' : '';
          ?>
          <a href="<?php echo esc_url($url); ?>" style="margin-right:8px;<?php echo esc_attr($style); ?>">
            <?php echo $p; ?>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

  </div>
  <?php
}

/**
 * ✅ Acción: Cancelar reserva (soft delete)
 */
add_action('admin_post_mr_cancel_booking', 'mr_admin_cancel_booking');
function mr_admin_cancel_booking() {
  if (!current_user_can('manage_options')) wp_die('No autorizado.');

  $id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
  if ($id <= 0) wp_die('ID no válido.');

  $nonce = $_GET['_wpnonce'] ?? '';
  if (!wp_verify_nonce($nonce, 'mr_cancel_booking_' . $id)) wp_die('Nonce inválido.');

  $ok = function_exists('mr_db_cancel_booking') ? mr_db_cancel_booking($id) : false;

  $notice = $ok ? 'Reserva cancelada. El aforo se ha recuperado.' : 'No se pudo cancelar la reserva.';
  $type = $ok ? 'success' : 'error';

  $redirect = admin_url('admin.php');
  $redirect = add_query_arg([
    'page' => 'museo-reservas-bookings',
    'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
    'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
    'session' => sanitize_text_field($_GET['session'] ?? ''),
    'status' => sanitize_text_field($_GET['status'] ?? 'confirmed'),
    'paged' => max(1, intval($_GET['paged'] ?? 1)),
    'mr_notice' => $notice,
    'mr_notice_type' => $type,
  ], $redirect);

  wp_safe_redirect($redirect);
  exit;
}

/**
 * ✅ Acción: Eliminar reserva (hard delete)
 */
add_action('admin_post_mr_delete_booking', 'mr_admin_delete_booking');
function mr_admin_delete_booking() {
  if (!current_user_can('manage_options')) wp_die('No autorizado.');

  $id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
  if ($id <= 0) wp_die('ID no válido.');

  $nonce = $_GET['_wpnonce'] ?? '';
  if (!wp_verify_nonce($nonce, 'mr_delete_booking_' . $id)) wp_die('Nonce inválido.');

  $ok = function_exists('mr_db_delete_booking') ? mr_db_delete_booking($id) : false;

  $notice = $ok ? 'Reserva eliminada definitivamente.' : 'No se pudo eliminar la reserva.';
  $type = $ok ? 'success' : 'error';

  $redirect = admin_url('admin.php');
  $redirect = add_query_arg([
    'page' => 'museo-reservas-bookings',
    'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
    'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
    'session' => sanitize_text_field($_GET['session'] ?? ''),
    'status' => sanitize_text_field($_GET['status'] ?? 'confirmed'),
    'paged' => max(1, intval($_GET['paged'] ?? 1)),
    'mr_notice' => $notice,
    'mr_notice_type' => $type,
  ], $redirect);

  wp_safe_redirect($redirect);
  exit;
}

/**
 * Exportación CSV
 */
add_action('admin_post_mr_export_csv', 'mr_export_csv');
function mr_export_csv() {
  if (!current_user_can('manage_options')) wp_die('No autorizado.');
  check_admin_referer('mr_export_csv');

  global $wpdb;
  $table = function_exists('mr_db_table') ? mr_db_table() : $wpdb->prefix . 'mr_bookings';

  $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
  $date_to   = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
  $session_filter = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
  $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'confirmed';
  if (!in_array($status_filter, ['confirmed','cancelled','all'], true)) $status_filter = 'confirmed';

  if (function_exists('mr_norm_date_to_iso')) {
    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = mr_norm_date_to_iso($date_from);
    if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = mr_norm_date_to_iso($date_to);
  }

  $where = "WHERE 1=1";
  $params = [];

  if ($session_filter && preg_match('/^(\d{4}-\d{2}-\d{2})\|(.+)$/', $session_filter, $sm)) {
    $where .= " AND slot_date = %s AND slot_time = %s";
    $params[] = $sm[1];
    $params[] = $sm[2];
  } else {
    if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
      $where .= " AND slot_date >= %s";
      $params[] = $date_from;
    }
    if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
      $where .= " AND slot_date <= %s";
      $params[] = $date_to;
    }
  }

  if ($status_filter === 'confirmed' || $status_filter === 'cancelled') {
    $where .= " AND status = %s";
    $params[] = $status_filter;
  }

  $sql = "SELECT * FROM {$table} {$where} ORDER BY slot_date DESC, slot_time DESC, id DESC";
  $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

  $filename = 'reservas_museo_' . date('Ymd_His') . '.csv';

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename=' . $filename);
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');

  fputcsv($out, [
    'codigo','id','fecha','hora','asistentes',
    'solicitante_nombre','solicitante_dni','solicitante_telefono','solicitante_email',
    'acompanantes','created_at','status'
  ], ';');

  foreach ($rows as $r) {
    $code = !empty($r['booking_code']) ? $r['booking_code'] : ('#' . $r['id']);

    $companions = [];
    if (!empty($r['companions_json'])) {
      $decoded = json_decode($r['companions_json'], true);
      if (is_array($decoded)) $companions = $decoded;
    }

    $comp_summary = '';
    if (!empty($companions)) {
      $parts = [];
      foreach ($companions as $c) {
        $parts[] = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))
                 . ' (' . ($c['dni'] ?? '') . ')';
      }
      $comp_summary = implode(' | ', $parts);
    }

    $req = trim(($r['req_first_name'] ?? '') . ' ' . ($r['req_last_name'] ?? ''));

    fputcsv($out, [
      $code,
      $r['id'],
      $r['slot_date'],   // en CSV lo dejamos ISO (más interoperable)
      $r['slot_time'],
      $r['attendees'],
      $req,
      $r['req_dni'] ?? '',
      $r['req_phone'] ?? '',
      $r['req_email'] ?? '',
      $comp_summary,
      $r['created_at'] ?? '',
      $r['status'] ?? '',
    ], ';');
  }

  fclose($out);
  exit;
}