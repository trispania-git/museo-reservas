<?php
if (!defined('ABSPATH')) exit;

function mr_send_booking_emails($booking_id, $booking_data, $settings) {
  $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
  $from_email = sanitize_email(get_option('admin_email'));
  $from_name  = $site_name;

  $to_user  = sanitize_email($booking_data['req_email'] ?? '');
  $to_museo = sanitize_email($settings['group_email'] ?? $from_email);

  if (!$to_user && !$to_museo) return;

  $date = (string)($booking_data['slot_date'] ?? '');
  $time = (string)($booking_data['slot_time'] ?? '');
  $att  = intval($booking_data['attendees'] ?? 0);

  $date_human = $date;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date_human = substr($date, 8, 2) . '-' . substr($date, 5, 2) . '-' . substr($date, 0, 4);
  }

  // ✅ Código nuevo (o fallback)
  $ref = (string)($booking_data['booking_code'] ?? '');
  if ($ref === '') $ref = 'MR-' . intval($booking_id);

  $subject_user  = "Confirmación de reserva {$ref} · {$date_human} {$time}";
  $subject_museo = "Nueva reserva Sala Histórica GR {$ref} · {$date_human} {$time} · {$att} asistentes";

  $companions = [];
  $cj = $booking_data['companions_json'] ?? null;
  if (is_string($cj) && $cj !== '') {
    $decoded = json_decode($cj, true);
    if (is_array($decoded)) $companions = $decoded;
  }

  $req_fullname = trim(($booking_data['req_first_name'] ?? '') . ' ' . ($booking_data['req_last_name'] ?? ''));
  $req_dni   = (string)($booking_data['req_dni'] ?? '');
  $req_phone = (string)($booking_data['req_phone'] ?? '');

  $styles = "
    <style>
      body{font-family:Arial,Helvetica,sans-serif;color:#111}
      .box{border:1px solid #e6e6e6;border-radius:12px;padding:14px}
      .h{font-size:18px;margin:0 0 10px}
      .muted{color:#666;font-size:12px}
      table{border-collapse:collapse;width:100%;margin-top:10px}
      th,td{border:1px solid #e6e6e6;padding:8px;text-align:left;font-size:13px}
      th{background:#f7f7f7}
      .pill{display:inline-block;background:#111;color:#fff;border-radius:999px;padding:6px 10px;font-size:12px}
    </style>
  ";

  $summary_html = "
    <div class='box'>
      <p class='h'>Resumen de la reserva</p>
      <p><span class='pill'>Código: {$ref}</span></p>
      <p><strong>Fecha:</strong> {$date_human}</p>
      <p><strong>Hora:</strong> {$time}</p>
      <p><strong>Asistentes:</strong> {$att}</p>
    </div>
  ";

  $att_rows = '';
  $att_rows .= '<tr><td>Solicitante</td><td>' . esc_html($req_fullname) . '</td><td>' . esc_html($req_dni) . '</td></tr>';

  if (!empty($companions)) {
    $i = 1;
    foreach ($companions as $c) {
      $fn = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));
      $dni = (string)($c['dni'] ?? '');
      $att_rows .= '<tr><td>Acompañante ' . $i . '</td><td>' . esc_html($fn) . '</td><td>' . esc_html($dni) . '</td></tr>';
      $i++;
    }
  }

  $att_table = "
    <table>
      <thead><tr><th>Rol</th><th>Nombre</th><th>DNI/NIE</th></tr></thead>
      <tbody>{$att_rows}</tbody>
    </table>
  ";

  if ($to_user) {
    $body_user = "
      {$styles}
      <p>Hola <strong>" . esc_html($booking_data['req_first_name'] ?? '') . "</strong>,</p>
      <p>Tu reserva para la visita ha quedado <strong>confirmada</strong>.</p>
      {$summary_html}
      <p class='muted'>Recomendación: llega con 10 minutos de antelación.</p>
      <p>Si necesitas modificar o cancelar, responde a este email indicando el <strong>código {$ref}</strong>.</p>
      <hr>
      <p class='muted'>{$site_name}</p>
    ";

    $headers_user = [
      'Content-Type: text/html; charset=UTF-8',
      "From: {$from_name} <{$from_email}>",
    ];

    try { wp_mail($to_user, $subject_user, $body_user, $headers_user); } catch (\Throwable $e) {}
  }

  if ($to_museo) {
    $body_museo = "
      {$styles}
      <p class='h'>Nueva reserva recibida para visitar la Sala Histórica de la Guardia Real</p>
      {$summary_html}

      <div class='box' style='margin-top:10px;'>
        <p><strong>Solicitante</strong></p>
        <p><strong>Nombre:</strong> " . esc_html($req_fullname) . "<br>
           <strong>DNI/NIE:</strong> " . esc_html($req_dni) . "<br>
           <strong>Teléfono:</strong> " . esc_html($req_phone) . "<br>
           <strong>Email:</strong> " . esc_html($to_user) . "
        </p>
      </div>

      <div class='box' style='margin-top:10px;'>
        <p><strong>Asistentes</strong></p>
        {$att_table}
      </div>
    ";

    $headers_museo = [
      'Content-Type: text/html; charset=UTF-8',
      "From: {$from_name} <{$from_email}>",
    ];

    try { wp_mail($to_museo, $subject_museo, $body_museo, $headers_museo); } catch (\Throwable $e) {}
  }
}
