<?php
if (!defined('ABSPATH')) exit;

/**
 * Página admin "Días Reservas"
 * Muestra sesiones futuras con reservas y permite asignar asistentes de grupo.
 */

// Handler: guardar asistentes de grupo
add_action('admin_post_mr_save_group_attendees', 'mr_save_group_attendees');
function mr_save_group_attendees() {
  if (!current_user_can('manage_options')) wp_die('No autorizado.');

  $date = sanitize_text_field($_POST['slot_date'] ?? '');
  $time = sanitize_text_field($_POST['slot_time'] ?? '');
  $group = max(0, intval($_POST['group_attendees'] ?? 0));

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
    wp_die('Datos no válidos.');
  }

  check_admin_referer('mr_group_att_' . $date . '_' . $time);

  $s = mr_get_settings();

  // Calcular aforo configurado para esta sesión
  $dow = (string)date('w', strtotime($date));
  $day_cap = isset($s['capacity_by_day'][$dow]) ? $s['capacity_by_day'][$dow] : '';
  $has_day_cap = ($day_cap !== '' && $day_cap !== null && (string)$day_cap !== '' && (string)$day_cap !== '0' && intval($day_cap) > 0);
  $capacity = $has_day_cap ? intval($day_cap) : intval($s['capacity']);

  // Reservas online actuales
  $online = function_exists('mr_db_sum_attendees') ? (int)mr_db_sum_attendees($date, $time) : 0;

  // Validar que no exceda el aforo
  if (($online + $group) > $capacity) {
    $max_group = $capacity - $online;
    $notice = sprintf('No se puede: %d (online) + %d (grupo) = %d > %d (aforo). Máximo grupo: %d.',
      $online, $group, $online + $group, $capacity, max(0, $max_group));
    $redirect = add_query_arg([
      'page' => 'museo-reservas-dias',
      'mr_notice' => $notice,
      'mr_notice_type' => 'error',
    ], admin_url('admin.php'));
    wp_safe_redirect($redirect);
    exit;
  }

  // Guardar
  $data = get_option('mr_group_attendees', []);
  if (!is_array($data)) $data = [];

  $key = $date . '|' . $time;
  if ($group > 0) {
    $data[$key] = $group;
  } else {
    unset($data[$key]);
  }

  update_option('mr_group_attendees', $data);

  $notice = sprintf('Asistentes de grupo actualizados para %s a las %s: %d personas.',
    mr_admin_fmt_date_es($date), $time, $group);

  $redirect = add_query_arg([
    'page' => 'museo-reservas-dias',
    'mr_notice' => $notice,
    'mr_notice_type' => 'success',
  ], admin_url('admin.php'));

  wp_safe_redirect($redirect);
  exit;
}

/**
 * Renderiza la página "Días Reservas"
 */
function mr_admin_dias_page() {
  if (!current_user_can('manage_options')) {
    wp_die('No tienes permisos suficientes.');
  }

  global $wpdb;
  $table = function_exists('mr_db_table') ? mr_db_table() : $wpdb->prefix . 'mr_bookings';
  $s = mr_get_settings();

  // Sesiones futuras con reservas confirmadas
  $rows = $wpdb->get_results(
    "SELECT slot_date, slot_time, SUM(attendees) AS total_online
     FROM {$table}
     WHERE status='confirmed' AND slot_date >= CURDATE()
     GROUP BY slot_date, slot_time
     ORDER BY slot_date ASC, slot_time ASC",
    ARRAY_A
  );

  $group_data = get_option('mr_group_attendees', []);
  if (!is_array($group_data)) $group_data = [];

  $notice = isset($_GET['mr_notice']) ? sanitize_text_field($_GET['mr_notice']) : '';
  $notice_type = isset($_GET['mr_notice_type']) ? sanitize_text_field($_GET['mr_notice_type']) : 'success';

  ?>
  <div class="wrap">
    <h1>Días Reservas · Gestión de grupos</h1>
    <p class="description">Asigna asistentes de grupo (gestionados fuera de la plataforma) a sesiones concretas. Las plazas de grupo se restan del aforo disponible en el formulario público.</p>

    <?php if ($notice): ?>
      <div class="<?php echo esc_attr($notice_type === 'error' ? 'notice notice-error' : 'notice notice-success'); ?> is-dismissible">
        <p><?php echo esc_html($notice); ?></p>
      </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
      <p>No hay sesiones futuras con reservas.</p>
    <?php else: ?>
      <table class="widefat striped" style="max-width:1000px;">
        <thead>
          <tr>
            <th style="width:110px;">Fecha</th>
            <th style="width:70px;">Hora</th>
            <th style="width:90px;">Aforo</th>
            <th style="width:100px;">Online</th>
            <th style="width:130px;">Grupo</th>
            <th style="width:100px;">Restante</th>
            <th style="width:90px;">Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $date = $r['slot_date'];
            $time = $r['slot_time'];
            $online = (int)$r['total_online'];

            // Aforo configurado
            $dow = (string)date('w', strtotime($date));
            $day_cap = isset($s['capacity_by_day'][$dow]) ? $s['capacity_by_day'][$dow] : '';
            $has_day_cap = ($day_cap !== '' && $day_cap !== null && (string)$day_cap !== '' && (string)$day_cap !== '0' && intval($day_cap) > 0);
            $capacity = $has_day_cap ? intval($day_cap) : intval($s['capacity']);

            $key = $date . '|' . $time;
            $group = intval($group_data[$key] ?? 0);
            $remaining = max(0, $capacity - $online - $group);

            $nonce = wp_create_nonce('mr_group_att_' . $date . '_' . $time);
          ?>
            <tr>
              <td><strong><?php echo esc_html(mr_admin_fmt_date_es($date)); ?></strong></td>
              <td><?php echo esc_html($time); ?></td>
              <td><?php echo (int)$capacity; ?></td>
              <td><?php echo (int)$online; ?></td>
              <td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;align-items:center;">
                  <input type="hidden" name="action" value="mr_save_group_attendees">
                  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                  <input type="hidden" name="slot_date" value="<?php echo esc_attr($date); ?>">
                  <input type="hidden" name="slot_time" value="<?php echo esc_attr($time); ?>">
                  <input type="number" name="group_attendees" value="<?php echo (int)$group; ?>"
                    min="0" max="<?php echo max(0, $capacity - $online); ?>"
                    style="width:70px;">
              </td>
              <td>
                <?php
                  $color = $remaining <= 0 ? 'color:#b00;font-weight:700;' : '';
                ?>
                <span style="<?php echo esc_attr($color); ?>"><?php echo (int)$remaining; ?></span>
              </td>
              <td>
                  <button type="submit" class="button button-primary button-small">Aplicar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php
}
