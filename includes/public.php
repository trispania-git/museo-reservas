<?php
if (!defined('ABSPATH')) exit;

add_shortcode('museo_reservas', 'mr_shortcode');

add_action('wp_enqueue_scripts', function() {
  if (!is_singular()) return;
  global $post;
  if (!$post || strpos($post->post_content ?? '', '[museo_reservas') === false) return;

  wp_enqueue_style('mr-css', MR_URL . 'assets/booking.css', [], MR_VERSION);

  // Flatpickr
  wp_enqueue_style('flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', [], MR_VERSION);
  wp_enqueue_script('flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr', [], MR_VERSION, true);
  wp_enqueue_script('flatpickr-es', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js', ['flatpickr-js'], MR_VERSION, true);

  wp_enqueue_script('mr-js', MR_URL . 'assets/booking.js', ['flatpickr-js','flatpickr-es'], MR_VERSION, true);

  $s = mr_get_settings();

  $closedDates = [];
  $closures = (string)($s['closures'] ?? '');
  $lines = preg_split("/\r\n|\r|\n/", trim($closures));
  foreach ($lines as $line) {
    $line = trim($line);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) $closedDates[] = $line;
  }

  $extraDates = [];
  $extra = (string)($s['extra_open'] ?? '');
  $lines2 = preg_split("/\r\n|\r|\n/", trim($extra));
  foreach ($lines2 as $line) {
    $line = trim($line);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) $extraDates[] = $line;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+\d{2}:\d{2}$/', $line, $m)) $extraDates[] = $m[1];
  }
  $extraDates = array_values(array_unique($extraDates));

  wp_localize_script('mr-js', 'MR', [
    'ajax' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('mr_nonce'),
    'maxAtt' => 5,
    'openDays' => array_map('intval', (array)($s['days_open'] ?? [])),
    'closedDates' => $closedDates,
    'extraOpenDates' => $extraDates,
  ]);
});

add_action('wp_ajax_mr_get_availability', 'mr_ajax_get_availability');
add_action('wp_ajax_nopriv_mr_get_availability', 'mr_ajax_get_availability');

add_action('wp_ajax_mr_make_booking', 'mr_ajax_make_booking');
add_action('wp_ajax_nopriv_mr_make_booking', 'mr_ajax_make_booking');

function mr_norm_id($value) {
  $v = strtoupper((string)$value);
  $v = preg_replace('/[^A-Z0-9]/', '', $v); // solo alfanumérico
  return $v;
}

/**
 * ✅ Validación simple: alfanumérico sin comprobar DNI/NIE.
 * Ajusta rangos si quieres.
 */
function mr_is_valid_id_simple($value) {
  $id = mr_norm_id($value);
  if ($id === '') return false;
  return (bool)preg_match('/^[A-Z0-9]{1,25}$/', $id);
}

function mr_shortcode() {
  $s = mr_get_settings();
  $bg = esc_attr($s['box_bg_color'] ?? '#f5f5f5');
  ob_start(); ?>
  <div class="mr-wrap" style="background:<?php echo $bg; ?>">
    <h3 class="mr-title">Reserva tu visita</h3>

    <div class="mr-row">
      <div class="mr-field">
        <label>Fecha</label>
        <div class="mr-datefield">
          <input type="text" id="mr_date" placeholder="Selecciona una fecha" autocomplete="off">
          <button type="button" id="mr_datebtn" class="mr-datebtn" aria-label="Abrir calendario" title="Abrir calendario">📅</button>
        </div>
        <div class="mr-hint">Los días no disponibles aparecen deshabilitados.</div>
      </div>

      <div class="mr-field">
        <label>Asistentes (máx. 5)</label>
        <select id="mr_attendees">
          <?php for ($i=1;$i<=5;$i++): ?>
            <option value="<?php echo (int)$i; ?>"><?php echo (int)$i; ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>

    <div class="mr-sessions">
      <strong>Sesiones</strong>
      <div id="mr_times" class="mr-times"></div>
      <input type="hidden" id="mr_time">
    </div>

    <form id="mr_form">
      <div class="mr-row">
        <div class="mr-field">
          <label>Nombre</label>
          <input id="mr_req_first_name" required>
        </div>
        <div class="mr-field">
          <label>Apellidos</label>
          <input id="mr_req_last_name" required>
        </div>
      </div>

      <div class="mr-row">
        <div class="mr-field">
          <label>DNI/NIE</label>
          <input id="mr_req_dni" required>
        </div>
        <div class="mr-field">
          <label>Teléfono</label>
          <input id="mr_req_phone" required>
        </div>
      </div>

      <div class="mr-row">
        <div class="mr-field">
          <label>Email</label>
          <input id="mr_req_email" type="email" required>
        </div>
      </div>

      <div class="mr-companions">
        <strong>Acompañantes</strong>
        <div id="mr_comp_fields"></div>
        <div class="mr-hint">Nombre, apellidos y DNI/NIE de cada acompañante.</div>
      </div>

      <div class="mr-row">
        <label style="display:flex;gap:10px;align-items:flex-start;">
          <input id="mr_privacy" type="checkbox" required style="margin-top:4px;">
          <span>
            He leído y acepto la
            <a href="<?php echo esc_url(apply_filters('mr_privacy_url', home_url('/politica-de-privacidad/'))); ?>"
               target="_blank" rel="noopener noreferrer">política de privacidad</a>.
          </span>
        </label>
      </div>

      <button type="submit" class="mr-btn">Confirmar reserva</button>
      <div id="mr_msg" class="mr-msg" style="display:none"></div>
    </form>
  </div>
  <?php
  return ob_get_clean();
}

function mr_ajax_get_availability() {
  check_ajax_referer('mr_nonce', 'nonce');

  $date = sanitize_text_field($_POST['date'] ?? '');
  $s = mr_get_settings();

  $dow = (string)date('w', strtotime($date));
  $day_cap = isset($s['capacity_by_day'][$dow]) ? $s['capacity_by_day'][$dow] : 'NOT_SET';
  $is_blocked = function_exists('mr_date_is_blocked') ? mr_date_is_blocked($date, $s) : 'N/A';

  $debug = [
    'date' => $date,
    'dow' => $dow,
    'is_open' => mr_is_date_open($date, $s),
    'is_blocked' => $is_blocked,
    'global_capacity' => $s['capacity'],
    'day_capacity_raw' => $day_cap,
    'day_capacity_type' => gettype($day_cap),
    'blocked_dates' => $s['blocked_dates'] ?? '',
    'times_by_day_raw' => $s['times_by_day'][$dow] ?? 'NOT_SET',
    'times_by_day_all' => $s['times_by_day'],
    'days_open' => $s['days_open'],
  ];

  if (!mr_is_date_open($date, $s)) {
    wp_send_json_success(['times' => [], '_debug' => $debug]);
  }

  $times = mr_times_for_date($date, $s);
  $out = [];

  foreach ($times as $t) {
    $remaining = mr_remaining_for_slot($date, $t, $s);
    $out[] = [
      'time' => $t,
      'remaining' => (int)$remaining,
      'is_full' => ((int)$remaining <= 0),
    ];
  }

  wp_send_json_success(['times' => $out, '_debug' => $debug]);
}

function mr_ajax_make_booking() {
  check_ajax_referer('mr_nonce', 'nonce');
  $s = mr_get_settings();

  // reCAPTCHA v3 verification
  $rc_secret = trim($s['recaptcha_secret_key'] ?? '');
  if ($rc_secret !== '') {
    $rc_token = sanitize_text_field($_POST['recaptcha_token'] ?? '');
    if (!$rc_token) {
      wp_send_json_error(['message' => 'Falta la verificación de seguridad. Recarga la página e inténtalo de nuevo.']);
    }
    $rc_response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
      'body' => [
        'secret'   => $rc_secret,
        'response' => $rc_token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
      ],
      'timeout' => 10,
    ]);
    if (is_wp_error($rc_response)) {
      wp_send_json_error(['message' => 'Error al verificar la seguridad. Inténtalo de nuevo.']);
    }
    $rc_body = json_decode(wp_remote_retrieve_body($rc_response), true);
    if (empty($rc_body['success']) || ($rc_body['score'] ?? 0) < 0.5) {
      wp_send_json_error(['message' => 'Verificación de seguridad fallida. Si el problema persiste, contacta con nosotros.']);
    }
  }

  if (isset($_POST['companions']) && is_string($_POST['companions'])) {
    $decoded = json_decode(stripslashes($_POST['companions']), true);
    if (is_array($decoded)) $_POST['companions'] = $decoded;
  }

  $date = sanitize_text_field($_POST['date'] ?? '');
  $time = sanitize_text_field($_POST['time'] ?? '');
  $att  = intval($_POST['attendees'] ?? 0);

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
    wp_send_json_error(['message' => 'Fecha u hora no válidas.']);
  }
  if ($att < 1 || $att > 5) {
    wp_send_json_error(['message' => 'El número de asistentes debe estar entre 1 y 5.']);
  }

  $req_first = sanitize_text_field($_POST['req_first_name'] ?? '');
  $req_last  = sanitize_text_field($_POST['req_last_name'] ?? '');
  $req_dni   = mr_norm_id(sanitize_text_field($_POST['req_dni'] ?? ''));
  $req_phone = sanitize_text_field($_POST['req_phone'] ?? '');
  $req_email = sanitize_email($_POST['req_email'] ?? '');

  if (!$req_first || !$req_last || !$req_dni || !$req_phone || !$req_email) {
    wp_send_json_error(['message' => 'Faltan datos obligatorios del solicitante.']);
  }

  // privacidad obligatoria
  $privacy = isset($_POST['privacy']) ? sanitize_text_field($_POST['privacy']) : '';
  if ($privacy !== '1') {
    wp_send_json_error(['message' => 'Debes aceptar la política de privacidad.']);
  }

  // ✅ cambio: SOLO alfanumérico
  if (!mr_is_valid_id_simple($req_dni)) {
    wp_send_json_error(['message' => 'El identificador del solicitante debe ser alfanumérico.']);
  }

  if (!mr_is_date_open($date, $s)) {
    wp_send_json_error(['message' => 'La fecha seleccionada no está disponible.']);
  }
  $allowedTimes = mr_times_for_date($date, $s);
  if (!in_array($time, $allowedTimes, true) || mr_slot_is_closed($date, $time, $s)) {
    wp_send_json_error(['message' => 'La sesión seleccionada no está disponible.']);
  }

  $companions = [];
  $expected = $att - 1;
  $dni_set = [$req_dni => true];

  if ($expected > 0) {
    if (!is_array($_POST['companions'] ?? null) || count($_POST['companions']) !== $expected) {
      wp_send_json_error(['message' => 'Faltan datos de acompañantes.']);
    }
    $i = 1;
    foreach ($_POST['companions'] as $c) {
      $fn = sanitize_text_field($c['first_name'] ?? '');
      $ln = sanitize_text_field($c['last_name'] ?? '');
      $dni = mr_norm_id(sanitize_text_field($c['dni'] ?? ''));

      if (!$fn || !$ln || !$dni) {
        wp_send_json_error(['message' => 'Datos incompletos en acompañantes.']);
      }

      // ✅ cambio: SOLO alfanumérico
      if (!mr_is_valid_id_simple($dni)) {
        wp_send_json_error(['message' => "El identificador del acompañante {$i} debe ser alfanumérico."]);
      }

      if (isset($dni_set[$dni])) {
        wp_send_json_error(['message' => 'Hay identificadores duplicados en la reserva.']);
      }
      $dni_set[$dni] = true;
      $companions[] = ['first_name'=>$fn,'last_name'=>$ln,'dni'=>$dni];
      $i++;
    }
  }

  global $wpdb;
  $lock = 'mr_' . str_replace('-','',$date) . '_' . str_replace(':','',$time);
  $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s,5)", $lock));
  if ((int)$got !== 1) {
    wp_send_json_error(['message' => 'Sistema ocupado. Inténtalo de nuevo.']);
  }

  $remaining = mr_remaining_for_slot($date, $time, $s);
  if ($remaining < $att) {
    $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));
    wp_send_json_error(['message' => 'No hay plazas suficientes para esa sesión.']);
  }

  $yyyymmdd = str_replace('-', '', $date);
  $hh = substr($time, 0, 2);

  $name3 = strtoupper($req_first);
  $name3 = remove_accents($name3);
  $name3 = preg_replace('/[^A-Z]/', '', $name3);
  $name3 = substr($name3 . 'XXX', 0, 3);

  $seq = mr_db_count_bookings_for_slot($date, $time) + 1;
  $seq2 = str_pad((string)$seq, 2, '0', STR_PAD_LEFT);

  $booking_code = $yyyymmdd . $hh . $name3 . $seq2;

  $booking_data = [
    'booking_code' => $booking_code,
    'slot_date' => $date,
    'slot_time' => $time,
    'capacity'  => (function() use ($date, $s) {
      $dow = (string)date('w', strtotime($date));
      $day_cap = isset($s['capacity_by_day'][$dow]) ? $s['capacity_by_day'][$dow] : '';
      $has = ($day_cap !== '' && $day_cap !== null && (string)$day_cap !== '' && (string)$day_cap !== '0' && intval($day_cap) > 0);
      return $has ? intval($day_cap) : intval($s['capacity']);
    })(),
    'attendees' => $att,
    'req_first_name' => $req_first,
    'req_last_name'  => $req_last,
    'req_dni'        => $req_dni,
    'req_phone'      => $req_phone,
    'req_email'      => $req_email,
    'companions_json'=> $expected ? wp_json_encode($companions) : null,
    'status'    => 'confirmed',
    'created_at'=> current_time('mysql'),

    'privacy_accepted' => 1,
    'privacy_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null,
    'privacy_at' => current_time('mysql'),
  ];

  $id = mr_db_insert_booking($booking_data);

  $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));

  if (!$id) {
    wp_send_json_error(['message' => 'Error al guardar la reserva.']);
  }

  if (function_exists('mr_send_booking_emails')) {
    mr_send_booking_emails($id, $booking_data, $s);
  }

  wp_send_json_success([
    'message' => 'Reserva confirmada correctamente.',
    'booking_code' => $booking_code
  ]);
}
