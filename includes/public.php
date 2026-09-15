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

  // Días bloqueados: se deshabilitan en el calendario igual que los cierres
  $blockedDates = function_exists('mr_parse_dates_list') ? mr_parse_dates_list($s['blocked_dates'] ?? '') : [];
  $closedDates = array_values(array_unique(array_merge($closedDates, $blockedDates)));

  // LiteSpeed Cache: si ESI está activo, el nonce se inyecta fresco en cada visita
  // aunque la página venga de caché (sin esto caduca a las 24 h y el AJAX devuelve 403).
  do_action('litespeed_nonce', 'mr_nonce');

  $mr_data = [
    'ajax' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('mr_nonce'),
    'maxAtt' => 5,
    'openDays' => array_map('intval', (array)($s['days_open'] ?? [])),
    'closedDates' => $closedDates,
    'extraOpenDates' => $extraDates,
    'ajaxTimeout' => 20000,
  ];

  // reCAPTCHA v3
  $rc_site_key = trim($s['recaptcha_site_key'] ?? '');
  if ($rc_site_key !== '') {
    $mr_data['recaptchaSiteKey'] = $rc_site_key;
    wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . urlencode($rc_site_key), [], null, true);
  }

  wp_localize_script('mr-js', 'MR', $mr_data);
});

add_action('wp_ajax_mr_get_availability', 'mr_ajax_get_availability');
add_action('wp_ajax_nopriv_mr_get_availability', 'mr_ajax_get_availability');

add_action('wp_ajax_mr_get_nonce', 'mr_ajax_get_nonce');
add_action('wp_ajax_nopriv_mr_get_nonce', 'mr_ajax_get_nonce');

add_action('wp_ajax_mr_make_booking', 'mr_ajax_make_booking');
add_action('wp_ajax_nopriv_mr_make_booking', 'mr_ajax_make_booking');

/**
 * Nonce fresco para el JS (se pide si la reserva devuelve 403 por nonce caducado en caché).
 */
function mr_ajax_get_nonce() {
  nocache_headers();
  wp_send_json_success(['nonce' => wp_create_nonce('mr_nonce')]);
}

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
        <label>Asistentes</label>
        <select id="mr_attendees">
          <?php for ($i=1;$i<=5;$i++): ?>
            <option value="<?php echo (int)$i; ?>"><?php echo (int)$i; ?></option>
          <?php endfor; ?>
          <option value="6">+6</option>
        </select>
      </div>
    </div>

    <?php
      $group_text = $s['group_text'] ?? '';
      $group_url  = $s['group_file_url'] ?? '';
    ?>
    <div id="mr_group_msg" class="mr-group-msg" style="display:none;">
      <p><?php echo wp_kses_post($group_text); ?></p>
      <?php if ($group_url): ?>
        <a href="<?php echo esc_url($group_url); ?>" class="mr-btn" target="_blank" rel="noopener noreferrer">Descargar impreso</a>
      <?php endif; ?>
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

      <div class="mr-hp" aria-hidden="true" style="position:absolute !important;left:-10000px !important;top:auto !important;width:1px !important;height:1px !important;overflow:hidden !important;">
        <label for="mr_hp">No rellenar este campo</label>
        <input type="text" id="mr_hp" name="mr_hp_field" tabindex="-1" autocomplete="off" value="">
      </div>

      <button type="submit" class="mr-btn">Confirmar reserva</button>
      <div id="mr_msg" class="mr-msg" style="display:none"></div>
    </form>
  </div>
  <?php
  return ob_get_clean();
}

/**
 * Disponibilidad de una fecha. Sin nonce a propósito: solo devuelve datos públicos
 * (las plazas libres) y el nonce embebido en la página cacheada caduca a las 24 h.
 */
function mr_ajax_get_availability() {
  mr_log_watch_request('mr_get_availability');
  nocache_headers();

  try {
    $date = sanitize_text_field($_POST['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      wp_send_json_error(['message' => 'Fecha no válida.']);
    }

    $s = mr_get_settings();

    if (!mr_is_date_open($date, $s)) {
      wp_send_json_success(['times' => []]);
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

    wp_send_json_success(['times' => $out]);
  } catch (\Throwable $e) {
    mr_log('availability_exception', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine(), 'post' => mr_log_safe_post()]);
    wp_send_json_error(['message' => 'Error al consultar la disponibilidad. Inténtalo de nuevo.']);
  }
}

function mr_ajax_make_booking() {
  mr_log_watch_request('mr_make_booking');
  nocache_headers();
  check_ajax_referer('mr_nonce', 'nonce');

  try {
    mr_process_booking();
  } catch (\Throwable $e) {
    mr_log('booking_exception', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine(), 'post' => mr_log_safe_post()]);
    wp_send_json_error(['message' => 'Error inesperado al procesar la reserva. Inténtalo de nuevo.']);
  }
}

function mr_process_booking() {
  $s = mr_get_settings();

  // Honeypot: campo invisible que solo rellenan los bots
  if (trim((string)($_POST['hp'] ?? '')) !== '') {
    mr_log('honeypot_triggered', 'Campo trampa relleno: reserva descartada (probable bot)', ['post' => mr_log_safe_post()], 'warning');
    wp_send_json_error(['message' => 'No se ha podido completar la reserva.']);
  }

  // Límite de reservas confirmadas por IP (ventana de 1 hora)
  $rl_ip = mr_log_client_ip() ?: 'unknown';
  $rl_key = 'mr_rl_' . md5($rl_ip);
  $rl_count = (int)get_transient($rl_key);
  if ($rl_count >= MR_RATE_LIMIT_BOOKINGS) {
    mr_log('rate_limited', sprintf('Límite de %d reservas/hora alcanzado para esta IP', MR_RATE_LIMIT_BOOKINGS), ['post' => mr_log_safe_post()], 'warning');
    wp_send_json_error(['message' => 'Se han realizado demasiadas reservas desde tu conexión en poco tiempo. Inténtalo más tarde o escríbenos por email.']);
  }

  // reCAPTCHA v3 verification
  $rc_note = 'desactivado';
  $rc_help = ' Si usas un bloqueador de anuncios, una VPN o un navegador con protección estricta (Brave, Firefox en modo estricto...), desactívalo para esta página o prueba con otro navegador.';
  $rc_secret = trim($s['recaptcha_secret_key'] ?? '');
  if ($rc_secret !== '') {
    $rc_token = sanitize_text_field($_POST['recaptcha_token'] ?? '');
    if (!$rc_token) {
      mr_log('recaptcha_missing', 'Reserva sin token de reCAPTCHA (script de Google bloqueado en el navegador, o bot)', [
        'recaptcha_status' => sanitize_key($_POST['recaptcha_status'] ?? ''),
        'post' => mr_log_safe_post(),
      ], 'warning');
      wp_send_json_error(['message' => 'No se ha podido cargar la verificación de seguridad.' . $rc_help]);
    }
    $rc_response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
      'body' => [
        'secret'   => $rc_secret,
        'response' => $rc_token,
        'remoteip' => mr_log_client_ip() ?: '',
      ],
      'timeout' => 10,
    ]);
    if (is_wp_error($rc_response)) {
      mr_log('recaptcha_http_error', $rc_response->get_error_message(), ['post' => mr_log_safe_post()], 'warning');
      wp_send_json_error(['message' => 'Error al verificar la seguridad. Inténtalo de nuevo.']);
    }
    $rc_body = json_decode(wp_remote_retrieve_body($rc_response), true);
    $rc_codes = is_array($rc_body['error-codes'] ?? null) ? $rc_body['error-codes'] : [];

    if (empty($rc_body['success']) && in_array('browser-error', $rc_codes, true)) {
      // Google no pudo evaluar el navegador (bloqueadores, privacidad estricta, VPN...).
      // No indica bot: se permite la reserva y se anota. Siguen activos honeypot, límite por IP y nonce.
      mr_log('recaptcha_browser_error', 'reCAPTCHA no pudo evaluar el navegador (browser-error): reserva permitida', [
        'post' => mr_log_safe_post(),
      ], 'warning');
      $rc_note = 'browser-error (permitida)';
    } elseif (empty($rc_body['success']) || ($rc_body['score'] ?? 0) < MR_RECAPTCHA_MIN_SCORE) {
      mr_log('recaptcha_failed', 'reCAPTCHA rechazado', [
        'score' => $rc_body['score'] ?? null,
        'error_codes' => $rc_codes ?: null,
        'post' => mr_log_safe_post(),
      ], 'warning');
      wp_send_json_error(['message' => 'No hemos podido verificar que no eres un robot.' . $rc_help]);
    } else {
      $rc_note = 'score ' . $rc_body['score'];
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
    mr_log('booking_lock_timeout', 'GET_LOCK no obtenido en 5 s (otra reserva simultánea en la misma sesión)', ['post' => mr_log_safe_post()], 'warning');
    wp_send_json_error(['message' => 'Sistema ocupado. Inténtalo de nuevo.']);
  }

  $remaining = mr_remaining_for_slot($date, $time, $s);
  if ($remaining < $att) {
    $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));
    mr_log('booking_rejected', "Plazas insuficientes: quedan {$remaining}, se pedían {$att}", ['post' => mr_log_safe_post()], 'info');
    wp_send_json_error(['message' => 'No hay plazas suficientes para esa sesión.']);
  }

  $yyyymmdd = str_replace('-', '', $date);
  $hh = substr($time, 0, 2);

  $name3 = strtoupper($req_first);
  $name3 = remove_accents($name3);
  $name3 = preg_replace('/[^A-Z]/', '', $name3);
  $name3 = substr($name3 . 'XXX', 0, 3);

  // Secuencial: partimos del nº de reservas confirmadas + 1 y saltamos códigos ya usados
  // (una reserva cancelada/eliminada dejaba su código ocupado y la siguiente chocaba con la clave única).
  $seq = mr_db_count_bookings_for_slot($date, $time) + 1;
  do {
    $booking_code = $yyyymmdd . $hh . $name3 . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
    $seq++;
  } while (mr_db_booking_code_exists($booking_code) && $seq < 1000);

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
    'privacy_ip' => mr_log_client_ip(), // IP real del visitante (Cloudflare delante)
    'privacy_at' => current_time('mysql'),
  ];

  $id = mr_db_insert_booking($booking_data);

  $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));

  if (!$id) {
    mr_log('booking_db_error', $wpdb->last_error ?: 'insert devolvió false', ['booking_code' => $booking_code, 'post' => mr_log_safe_post()]);
    wp_send_json_error(['message' => 'Error al guardar la reserva.']);
  }

  set_transient($rl_key, $rl_count + 1, HOUR_IN_SECONDS);

  mr_log('booking_created', "Reserva {$booking_code} creada", [
    'booking_code' => $booking_code,
    'recaptcha' => $rc_note,
    'post' => mr_log_safe_post(),
  ], 'info');

  if (function_exists('mr_send_booking_emails')) {
    mr_send_booking_emails($id, $booking_data, $s);
  }

  wp_send_json_success([
    'message' => 'Reserva confirmada correctamente.',
    'booking_code' => $booking_code
  ]);
}
