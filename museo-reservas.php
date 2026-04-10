<?php
/**
 * Plugin Name: Museo Reservas
 * Plugin URI: https://welowmarketing.com/
 * Description: Reservas Sala Hisrírica Guardia Real 
 * Version: 1.0.0
 * Author: Welow Marketing
 * Author URI: https://welowmarketing.com/
 * License: GPLv2 or later
 * Text Domain: museo-reservas
 */

if (!defined('ABSPATH')) exit;

define('MR_VERSION', '1.0.0');
define('MR_PATH', plugin_dir_path(__FILE__));
define('MR_URL', plugin_dir_url(__FILE__));
define('MR_OPT', 'mr_settings');

// Includes (orden importante)
require_once MR_PATH . 'includes/db.php';
require_once MR_PATH . 'includes/rules.php';
require_once MR_PATH . 'includes/emails.php';
require_once MR_PATH . 'includes/public.php';
require_once MR_PATH . 'includes/admin-bookings.php';

/**
 * Settings getter central
 */
function mr_get_settings() {
  $defaults = [
    'capacity' => 30,
    'max_attendees' => 5,
    'group_email' => get_option('admin_email'),
    'days_open' => ['3'], // Mié por defecto
    'times_by_day' => [
      '0' => "",
      '1' => "",
      '2' => "",
      '3' => "11:30",
      '4' => "",
      '5' => "",
      '6' => "",
    ],
    'min_notice_hours' => 0,
    'closures' => "",
    'extra_open' => "",
    'recaptcha_site_key' => "",
    'recaptcha_secret_key' => "",
    'capacity_by_day' => ['0'=>'','1'=>'','2'=>'','3'=>'','4'=>'','5'=>'','6'=>''],
    'box_bg_color' => '#f5f5f5',
  ];

  $s = get_option(MR_OPT, []);
  if (!is_array($s)) $s = [];

  $s = array_merge($defaults, $s);

  if (!is_array($s['days_open'])) $s['days_open'] = $defaults['days_open'];
  if (!is_array($s['times_by_day'])) $s['times_by_day'] = $defaults['times_by_day'];
  if (!is_array($s['capacity_by_day'])) $s['capacity_by_day'] = $defaults['capacity_by_day'];

  for ($i=0; $i<=6; $i++) {
    $k = (string)$i;
    if (!array_key_exists($k, $s['times_by_day'])) $s['times_by_day'][$k] = "";
    if (!array_key_exists($k, $s['capacity_by_day'])) $s['capacity_by_day'][$k] = "";
  }

  return $s;
}

register_activation_hook(__FILE__, 'mr_activate');
function mr_activate() {
  mr_db_install();
  if (!get_option(MR_OPT)) {
    update_option(MR_OPT, mr_get_settings());
  }
}

/**
 * ✅ Menú unificado
 */
add_action('admin_menu', function() {

  add_menu_page(
    'Museo Reservas',
    'Museo Reservas',
    'manage_options',
    'museo-reservas',
    'mr_settings_page',
    'dashicons-tickets',
    56
  );

  add_submenu_page(
    'museo-reservas',
    'Ajustes',
    'Ajustes',
    'manage_options',
    'museo-reservas',
    'mr_settings_page'
  );

  add_submenu_page(
    'museo-reservas',
    'Reservas',
    'Reservas',
    'manage_options',
    'museo-reservas-bookings',
    'mr_admin_bookings_page'
  );

}, 9);

add_action('admin_init', function() {
  register_setting('mr_group', MR_OPT, 'mr_sanitize_settings');
});

function mr_sanitize_settings($in) {
  $out = [];
  $out['capacity'] = max(1, intval($in['capacity'] ?? 30));
  $out['max_attendees'] = 5; // fijo
  $out['group_email'] = sanitize_email($in['group_email'] ?? get_option('admin_email'));
  $out['min_notice_hours'] = max(0, intval($in['min_notice_hours'] ?? 0));

  $days = (array)($in['days_open'] ?? []);
  $out['days_open'] = array_values(array_filter(array_map('strval', $days), 'strlen'));

  $tbd = (array)($in['times_by_day'] ?? []);
  $clean = [];
  for ($i=0; $i<=6; $i++) {
    $txt = (string)($tbd[(string)$i] ?? '');
    $txt = preg_replace("/\r\n|\r/", "\n", trim($txt));
    $clean[(string)$i] = $txt;
  }
  $out['times_by_day'] = $clean;

  $closures = (string)($in['closures'] ?? '');
  $closures = preg_replace("/\r\n|\r/", "\n", trim($closures));
  $out['closures'] = function_exists('mr_normalize_lines_dates') ? mr_normalize_lines_dates($closures) : $closures;

  $extra = (string)($in['extra_open'] ?? '');
  $extra = preg_replace("/\r\n|\r/", "\n", trim($extra));
  $out['extra_open'] = function_exists('mr_normalize_lines_dates') ? mr_normalize_lines_dates($extra) : $extra;

  $out['recaptcha_site_key'] = sanitize_text_field($in['recaptcha_site_key'] ?? '');
  $out['recaptcha_secret_key'] = sanitize_text_field($in['recaptcha_secret_key'] ?? '');

  // Color de fondo del shortcode
  $box_bg = sanitize_hex_color($in['box_bg_color'] ?? '#f5f5f5');
  $out['box_bg_color'] = $box_bg ?: '#f5f5f5';

  // Aforo por día de la semana
  $cbd_in = (array)($in['capacity_by_day'] ?? []);
  $cbd_old = [];
  $old_settings = get_option(MR_OPT, []);
  if (is_array($old_settings) && isset($old_settings['capacity_by_day'])) {
    $cbd_old = (array)$old_settings['capacity_by_day'];
  }

  $cbd = [];
  $days_names = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
  for ($i=0; $i<=6; $i++) {
    $k = (string)$i;
    $raw = trim((string)($cbd_in[$k] ?? ''));
    if ($raw === '') {
      $cbd[$k] = '';
      continue;
    }
    $val = max(1, intval($raw));

    // Validar que no se reduce por debajo de reservas existentes
    if (function_exists('mr_db_max_attendees_for_weekday')) {
      $max_booked = mr_db_max_attendees_for_weekday($i);
      if ($max_booked > 0 && $val < $max_booked) {
        $cbd[$k] = $cbd_old[$k] ?? '';
        add_settings_error('mr_group', 'capacity_day_' . $i,
          sprintf('No se puede establecer aforo %d para %s: ya hay un slot con %d asistentes confirmados.',
            $val, $days_names[$i], $max_booked),
          'error'
        );
        continue;
      }
    }
    $cbd[$k] = $val;
  }
  $out['capacity_by_day'] = $cbd;

  return $out;
}

/**
 * ✅ Enqueue frontend + localize (MR.ajax y MR.nonce)
 */
add_action('wp_enqueue_scripts', function() {
  if (!is_singular()) return;
  global $post;
  if (!$post || !has_shortcode($post->post_content, 'museo_reservas')) return;

  $s = mr_get_settings();

  $handle = 'mr-booking';
  if (!wp_script_is($handle, 'enqueued')) {
    wp_enqueue_script($handle, MR_URL . 'assets/booking.js', [], MR_VERSION, true);
  }

  // ✅ listas para JS (solo YYYY-MM-DD)
  $closedDates = function_exists('mr_parse_dates_list') ? array_values(mr_parse_dates_list($s['closures'])) : [];
  $extraDates  = function_exists('mr_parse_dates_list') ? array_values(mr_parse_dates_list($s['extra_open'])) : [];

  $mr_data = [
    'ajax'           => admin_url('admin-ajax.php'),
    'nonce'          => wp_create_nonce('mr_nonce'),
    'maxAtt'         => (string)($s['max_attendees'] ?? 5),
    'openDays'       => array_values((array)$s['days_open']),

    // cierres
    'closedDates'    => $closedDates,

    // ✅ compat: antes era "extraOpen", ahora también enviamos "extraOpenDates"
    'extraOpen'      => $extraDates,
    'extraOpenDates' => $extraDates,
  ];

  // reCAPTCHA v3
  $rc_site_key = trim($s['recaptcha_site_key'] ?? '');
  if ($rc_site_key !== '') {
    $mr_data['recaptchaSiteKey'] = $rc_site_key;
    wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . urlencode($rc_site_key), [], null, true);
  }

  wp_localize_script($handle, 'MR', $mr_data);
}, 20);

/**
 * ✅ AJAX Hooks (logueado + no logueado)
 */
add_action('wp_ajax_mr_get_times', 'mr_ajax_get_times');
add_action('wp_ajax_nopriv_mr_get_times', 'mr_ajax_get_times');

add_action('wp_ajax_mr_make_booking', 'mr_ajax_make_booking');
add_action('wp_ajax_nopriv_mr_make_booking', 'mr_ajax_make_booking');

/**
 * ✅ Handler AJAX: obtener horas + plazas restantes para una fecha
 */
if (!function_exists('mr_ajax_get_times')) {
  function mr_ajax_get_times() {
    check_ajax_referer('mr_nonce', 'nonce');

    $s = mr_get_settings();
    $date = sanitize_text_field($_POST['date'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      wp_send_json_error(['message' => 'Fecha no válida.']);
    }

    if (function_exists('mr_is_date_open') && !mr_is_date_open($date, $s)) {
      wp_send_json_error(['message' => 'La fecha seleccionada no está disponible.']);
    }

    $times = function_exists('mr_times_for_date') ? (array) mr_times_for_date($date, $s) : [];
    if (empty($times)) {
      wp_send_json_error(['message' => 'No hay sesiones configuradas para ese día.']);
    }

    $out = [];
    foreach ($times as $t) {
      $t = sanitize_text_field($t);
      if (!preg_match('/^\d{2}:\d{2}$/', $t)) continue;

      if (function_exists('mr_slot_is_closed') && mr_slot_is_closed($date, $t, $s)) {
        continue;
      }

      $remaining = function_exists('mr_remaining_for_slot')
        ? (int) mr_remaining_for_slot($date, $t, $s)
        : (int) ($s['capacity'] ?? 0);

      $out[] = [
        'time' => $t,
        'remaining' => max(0, $remaining),
      ];
    }

    if (empty($out)) {
      wp_send_json_error(['message' => 'No hay sesiones disponibles para esa fecha.']);
    }

    wp_send_json_success(['times' => $out]);
  }
}

/**
 * Admin: Ajustes
 */
function mr_settings_page() {
  if (!current_user_can('manage_options')) return;
  $s = mr_get_settings();
  $days = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
  ?>
  <div class="wrap">
    <h1>Museo Reservas · Ajustes</h1>

    <form method="post" action="options.php">
      <?php settings_fields('mr_group'); ?>

      <h2>General</h2>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Aforo por sesión</th>
          <td><input type="number" min="1" name="<?php echo esc_attr(MR_OPT); ?>[capacity]" value="<?php echo esc_attr($s['capacity']); ?>"></td>
        </tr>
        <tr>
          <th scope="row">Color de fondo del formulario</th>
          <td><input type="text" name="<?php echo esc_attr(MR_OPT); ?>[box_bg_color]" value="<?php echo esc_attr($s['box_bg_color'] ?? '#f5f5f5'); ?>" placeholder="#f5f5f5" style="width:120px;"> <p class="description">Código HEX (ej: #f5f5f5). Vacío = gris claro.</p></td>
        </tr>
        <tr>
          <th scope="row">Máximo asistentes por reserva</th>
          <td><input type="number" value="5" disabled> <p class="description">Fijado a 5.</p></td>
        </tr>
        <tr>
          <th scope="row">Email aviso interno</th>
          <td><input type="email" name="<?php echo esc_attr(MR_OPT); ?>[group_email]" value="<?php echo esc_attr($s['group_email']); ?>"></td>
        </tr>
        <tr>
          <th scope="row">Antelación mínima (horas)</th>
          <td><input type="number" min="0" name="<?php echo esc_attr(MR_OPT); ?>[min_notice_hours]" value="<?php echo esc_attr($s['min_notice_hours']); ?>"></td>
        </tr>
      </table>

      <h2>Apertura semanal</h2>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Días abiertos</th>
          <td>
            <?php for ($i=0; $i<=6; $i++): ?>
              <?php $checked = in_array((string)$i, (array)$s['days_open'], true) ? 'checked' : ''; ?>
              <label style="margin-right:14px;">
                <input type="checkbox" name="<?php echo esc_attr(MR_OPT); ?>[days_open][]" value="<?php echo esc_attr($i); ?>" <?php echo $checked; ?>>
                <?php echo esc_html($days[$i]); ?>
              </label>
            <?php endfor; ?>
          </td>
        </tr>
      </table>

      <table class="widefat striped" style="max-width:900px;">
        <thead><tr><th>Día</th><th>Horas (HH:MM, una por línea)</th><th style="width:120px;">Aforo</th></tr></thead>
        <tbody>
          <?php for ($i=0; $i<=6; $i++): ?>
            <tr>
              <td style="width:120px;"><strong><?php echo esc_html($days[$i]); ?></strong></td>
              <td>
                <textarea rows="3" style="width:100%;max-width:650px;"
                  name="<?php echo esc_attr(MR_OPT); ?>[times_by_day][<?php echo esc_attr($i); ?>]"><?php
                    echo esc_textarea($s['times_by_day'][(string)$i] ?? '');
                  ?></textarea>
              </td>
              <td>
                <?php $day_cap = $s['capacity_by_day'][(string)$i] ?? ''; ?>
                <input type="number" min="1" style="width:80px;"
                  name="<?php echo esc_attr(MR_OPT); ?>[capacity_by_day][<?php echo esc_attr($i); ?>]"
                  value="<?php echo esc_attr($day_cap); ?>"
                  placeholder="<?php echo esc_attr($s['capacity']); ?>">
              </td>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
      <p class="description">Deja vacío para usar el aforo general (<?php echo esc_html($s['capacity']); ?>).</p>

      <h2>Fechas extra de apertura</h2>
      <p>Una por línea. Admite <strong>DD-MM-YYYY</strong> o <strong>YYYY-MM-DD</strong>.</p>
      <textarea rows="6" style="width:100%;max-width:900px;" name="<?php echo esc_attr(MR_OPT); ?>[extra_open]"><?php echo esc_textarea($s['extra_open']); ?></textarea>

      <h2>Cierres puntuales</h2>
      <p>Una por línea. Admite <strong>DD-MM-YYYY</strong> o <strong>YYYY-MM-DD</strong>.</p>
      <textarea rows="6" style="width:100%;max-width:900px;" name="<?php echo esc_attr(MR_OPT); ?>[closures]"><?php echo esc_textarea($s['closures']); ?></textarea>

      <h2>reCAPTCHA v3</h2>
      <p>Añade protección anti-spam invisible. <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">Obtener claves</a> (selecciona reCAPTCHA v3).</p>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Site Key</th>
          <td><input type="text" style="width:100%;max-width:500px;" name="<?php echo esc_attr(MR_OPT); ?>[recaptcha_site_key]" value="<?php echo esc_attr($s['recaptcha_site_key'] ?? ''); ?>"></td>
        </tr>
        <tr>
          <th scope="row">Secret Key</th>
          <td><input type="text" style="width:100%;max-width:500px;" name="<?php echo esc_attr(MR_OPT); ?>[recaptcha_secret_key]" value="<?php echo esc_attr($s['recaptcha_secret_key'] ?? ''); ?>"></td>
        </tr>
      </table>
      <p class="description">Si se dejan vacías, el formulario funciona sin reCAPTCHA.</p>

      <?php submit_button(); ?>
    </form>

    <hr>
    <h2>Shortcode</h2>
    <pre><code>[museo_reservas]</code></pre>
  </div>
  <?php
}


