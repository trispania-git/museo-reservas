<?php
if (!defined('ABSPATH')) exit;

/**
 * Registro de incidencias del plugin (tabla propia wp_mr_log).
 *
 * Qué se guarda:
 * - Servidor: nonces inválidos, reservas rechazadas, errores de BD, fallos de email,
 *   fallos de reCAPTCHA, excepciones, fatales de PHP y peticiones lentas (>3 s).
 * - Cliente (JS): timeouts, respuestas HTTP de error, respuestas no JSON, refrescos de nonce.
 *
 * Se consulta en el menú Museo Reservas · Registro.
 */

define('MR_LOG_RETENTION_DAYS', 30);
define('MR_LOG_MAX_ROWS', 5000);
define('MR_LOG_SLOW_MS', 3000);

function mr_log_table() {
  global $wpdb;
  return $wpdb->prefix . 'mr_log';
}

function mr_log_install() {
  global $wpdb;
  $table = mr_log_table();
  $charset_collate = $wpdb->get_charset_collate();

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $sql = "CREATE TABLE {$table} (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    level VARCHAR(10) NOT NULL DEFAULT 'error',
    source VARCHAR(10) NOT NULL DEFAULT 'server',
    event VARCHAR(60) NOT NULL,
    message TEXT NULL,
    context LONGTEXT NULL,
    ip VARCHAR(45) NULL,
    duration_ms INT NULL,
    PRIMARY KEY  (id),
    KEY created_idx (created_at),
    KEY event_idx (event)
  ) {$charset_collate};";

  dbDelta($sql);
}

/**
 * IP real del visitante (Cloudflare delante del servidor).
 */
function mr_log_client_ip() {
  foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
      if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
  }
  return null;
}

/**
 * Escribe una entrada en el registro. Nunca lanza excepciones ni rompe la petición.
 *
 * @param string $event       Identificador corto (nonce_invalid, booking_rejected, ...)
 * @param string $message     Texto legible
 * @param array  $context     Datos adicionales (se guardan como JSON)
 * @param string $level       error | warning | info
 * @param string $source      server | client
 * @param int|null $duration_ms
 */
function mr_log($event, $message = '', $context = [], $level = 'error', $source = 'server', $duration_ms = null) {
  global $wpdb;

  try {
    $table = mr_log_table();

    $level  = in_array($level, ['error','warning','info'], true) ? $level : 'error';
    $source = in_array($source, ['server','client'], true) ? $source : 'server';

    $ctx = is_array($context) ? $context : ['data' => $context];
    $ctx_json = wp_json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ctx_json === false) $ctx_json = '{}';
    if (strlen($ctx_json) > 20000) $ctx_json = substr($ctx_json, 0, 20000);

    $wpdb->insert($table, [
      'created_at'  => current_time('mysql'),
      'level'       => $level,
      'source'      => $source,
      'event'       => substr(sanitize_key($event), 0, 60),
      'message'     => mb_substr((string)$message, 0, 2000),
      'context'     => $ctx_json,
      'ip'          => mr_log_client_ip(),
      'duration_ms' => $duration_ms === null ? null : (int)$duration_ms,
    ], ['%s','%s','%s','%s','%s','%s','%s','%d']);

    // Espejo en debug.log si está activo
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
      error_log("[museo-reservas][{$level}][{$event}] {$message} " . $ctx_json);
    }

    // Limpieza ocasional (1 de cada 50 escrituras)
    if (mt_rand(1, 50) === 1) mr_log_prune();
  } catch (\Throwable $e) {
    // Nunca romper la petición por culpa del log
  }
}

/**
 * Borra entradas antiguas y limita el tamaño de la tabla.
 */
function mr_log_prune() {
  global $wpdb;
  $table = mr_log_table();

  $wpdb->query($wpdb->prepare(
    "DELETE FROM {$table} WHERE created_at < %s",
    gmdate('Y-m-d H:i:s', time() - MR_LOG_RETENTION_DAYS * DAY_IN_SECONDS)
  ));

  $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
  if ($total > MR_LOG_MAX_ROWS) {
    $excess = $total - MR_LOG_MAX_ROWS;
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $excess));
  }
}

/**
 * Vigila la petición actual: al terminar registra fatales de PHP y peticiones lentas.
 * Llamar al principio de cada handler AJAX del plugin.
 */
function mr_log_watch_request($label) {
  static $armed = false;
  if ($armed) return;
  $armed = true;

  $start = microtime(true);

  register_shutdown_function(function() use ($label, $start) {
    $ms = (int)round((microtime(true) - $start) * 1000);

    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
      mr_log('php_fatal', $err['message'], [
        'request' => $label,
        'file'    => $err['file'],
        'line'    => $err['line'],
        'post'    => mr_log_safe_post(),
      ], 'error', 'server', $ms);
      return;
    }

    if ($ms >= MR_LOG_SLOW_MS) {
      mr_log('slow_request', "La petición {$label} tardó {$ms} ms", [
        'request'     => $label,
        'post'        => mr_log_safe_post(),
        'memory_peak' => round(memory_get_peak_usage(true) / 1048576, 1) . ' MB',
      ], 'warning', 'server', $ms);
    }
  });
}

/**
 * Copia de $_POST sin datos personales (para contexto del log).
 */
function mr_log_safe_post() {
  $keep = ['action', 'date', 'time', 'attendees'];
  $out = [];
  foreach ($keep as $k) {
    if (isset($_POST[$k])) $out[$k] = sanitize_text_field((string)$_POST[$k]);
  }
  return $out;
}

/**
 * Nonce inválido en cualquier AJAX del plugin (check_ajax_referer dispara esta acción antes de morir con 403).
 */
add_action('check_ajax_referer', function($action, $result) {
  if ($action !== 'mr_nonce' || $result !== false) return;
  mr_log('nonce_invalid', 'Nonce inválido o caducado (probable página servida desde caché con nonce antiguo).', [
    'ajax_action' => sanitize_text_field((string)($_POST['action'] ?? $_GET['action'] ?? '')),
    'referer'     => sanitize_text_field((string)($_SERVER['HTTP_REFERER'] ?? '')),
    'user_agent'  => mb_substr(sanitize_text_field((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200),
    'post'        => mr_log_safe_post(),
  ], 'error');
}, 10, 2);

/**
 * Fallos de wp_mail() mientras el plugin está enviando (flag activado en emails.php).
 */
add_action('wp_mail_failed', function($error) {
  if (empty($GLOBALS['mr_mailing'])) return;
  $msg = is_wp_error($error) ? $error->get_error_message() : 'wp_mail falló';
  $data = is_wp_error($error) ? $error->get_error_data() : [];
  if (is_array($data)) unset($data['message']); // no guardar el cuerpo del email
  mr_log('email_failed', $msg, ['data' => $data], 'warning');
});

/**
 * Endpoint público para que el JS reporte errores del navegador
 * (timeouts, HTTP de error, respuestas no JSON). Sin nonce a propósito:
 * el error que se reporta puede ser precisamente un nonce caducado.
 * Limitado a 10 reportes por IP cada 10 minutos.
 */
add_action('wp_ajax_mr_client_log', 'mr_ajax_client_log');
add_action('wp_ajax_nopriv_mr_client_log', 'mr_ajax_client_log');
function mr_ajax_client_log() {
  $ip = mr_log_client_ip() ?: 'unknown';
  $tkey = 'mr_clog_' . md5($ip);
  $n = (int)get_transient($tkey);
  if ($n >= 10) { status_header(204); exit; }
  set_transient($tkey, $n + 1, 10 * MINUTE_IN_SECONDS);

  $allowed = ['client_timeout', 'client_http_error', 'client_bad_json', 'client_network', 'client_nonce_refresh', 'client_recaptcha_error'];
  $event = sanitize_key($_POST['event'] ?? '');
  if (!in_array($event, $allowed, true)) { status_header(204); exit; }

  $level = ($event === 'client_nonce_refresh') ? 'info' : 'error';

  mr_log($event, mb_substr(sanitize_text_field((string)($_POST['message'] ?? '')), 0, 500), [
    'ajax_action' => sanitize_text_field((string)($_POST['ajax_action'] ?? '')),
    'http'        => (int)($_POST['http'] ?? 0),
    'date'        => sanitize_text_field((string)($_POST['date'] ?? '')),
    'snippet'     => mb_substr(sanitize_text_field((string)($_POST['snippet'] ?? '')), 0, 300),
    'url'         => esc_url_raw((string)($_POST['url'] ?? '')),
    'user_agent'  => mb_substr(sanitize_text_field((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200),
  ], $level, 'client', isset($_POST['ms']) ? (int)$_POST['ms'] : null);

  status_header(204);
  exit;
}

/**
 * Admin: vaciar registro
 */
add_action('admin_post_mr_clear_log', function() {
  if (!current_user_can('manage_options')) wp_die('No autorizado.');
  check_admin_referer('mr_clear_log');
  global $wpdb;
  $wpdb->query("TRUNCATE TABLE " . mr_log_table());
  wp_safe_redirect(add_query_arg(['page' => 'museo-reservas-log', 'mr_notice' => 'Registro vaciado.'], admin_url('admin.php')));
  exit;
});

/**
 * Admin: página "Registro"
 */
function mr_admin_log_page() {
  if (!current_user_can('manage_options')) wp_die('No tienes permisos suficientes.');

  global $wpdb;
  $table = mr_log_table();

  $level_f  = sanitize_key($_GET['level'] ?? '');
  $event_f  = sanitize_key($_GET['event'] ?? '');
  $source_f = sanitize_key($_GET['source'] ?? '');
  $q        = sanitize_text_field($_GET['q'] ?? '');
  $page     = max(1, intval($_GET['paged'] ?? 1));
  $per_page = 50;

  $where = "WHERE 1=1";
  $params = [];
  if (in_array($level_f, ['error','warning','info'], true)) { $where .= " AND level = %s"; $params[] = $level_f; }
  if ($event_f)  { $where .= " AND event = %s";  $params[] = $event_f; }
  if (in_array($source_f, ['server','client'], true)) { $where .= " AND source = %s"; $params[] = $source_f; }
  if ($q !== '') {
    $like = '%' . $wpdb->esc_like($q) . '%';
    $where .= " AND (message LIKE %s OR context LIKE %s OR ip LIKE %s)";
    $params[] = $like; $params[] = $like; $params[] = $like;
  }

  $sql_total = "SELECT COUNT(*) FROM {$table} {$where}";
  $total = (int)($params ? $wpdb->get_var($wpdb->prepare($sql_total, ...$params)) : $wpdb->get_var($sql_total));

  $sql = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
  $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per_page, ($page - 1) * $per_page])), ARRAY_A);

  $events = $wpdb->get_col("SELECT DISTINCT event FROM {$table} ORDER BY event ASC");

  // Resumen últimas 24 h
  $since = gmdate('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS);
  $summary = $wpdb->get_results($wpdb->prepare(
    "SELECT event, level, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY event, level ORDER BY n DESC", $since
  ), ARRAY_A);

  $notice = sanitize_text_field($_GET['mr_notice'] ?? '');
  $base = admin_url('admin.php?page=museo-reservas-log');
  $clear_url = wp_nonce_url(admin_url('admin-post.php?action=mr_clear_log'), 'mr_clear_log');

  $level_style = [
    'error'   => 'background:#fde8e8;color:#8a1f1f;',
    'warning' => 'background:#fff4d6;color:#7a5300;',
    'info'    => 'background:#e6f0fb;color:#1d4f8a;',
  ];
  ?>
  <div class="wrap">
    <h1>Registro · Museo Reservas</h1>
    <p class="description">
      Incidencias del formulario de reservas (servidor y navegador). Se conservan <?php echo (int)MR_LOG_RETENTION_DAYS; ?> días
      o un máximo de <?php echo (int)MR_LOG_MAX_ROWS; ?> entradas. Las peticiones que tardan más de <?php echo (int)(MR_LOG_SLOW_MS/1000); ?> s se anotan como <code>slow_request</code>.
    </p>

    <?php if ($notice): ?>
      <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <?php if ($summary): ?>
      <div style="margin:12px 0;padding:10px 14px;background:#fff;border:1px solid #ddd;border-radius:6px;max-width:900px;">
        <strong>Últimas 24 h:</strong>
        <?php foreach ($summary as $srow): ?>
          <a href="<?php echo esc_url(add_query_arg(['event' => $srow['event']], $base)); ?>"
             style="display:inline-block;margin:4px 6px 0 0;padding:2px 8px;border-radius:10px;font-size:12px;text-decoration:none;<?php echo esc_attr($level_style[$srow['level']] ?? ''); ?>">
            <?php echo esc_html($srow['event']); ?> · <?php echo (int)$srow['n']; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="hidden" name="page" value="museo-reservas-log">
      <select name="level">
        <option value="">— Nivel —</option>
        <?php foreach (['error'=>'Error','warning'=>'Aviso','info'=>'Info'] as $k=>$v): ?>
          <option value="<?php echo $k; ?>" <?php selected($level_f, $k); ?>><?php echo $v; ?></option>
        <?php endforeach; ?>
      </select>
      <select name="source">
        <option value="">— Origen —</option>
        <option value="server" <?php selected($source_f, 'server'); ?>>Servidor</option>
        <option value="client" <?php selected($source_f, 'client'); ?>>Navegador</option>
      </select>
      <select name="event">
        <option value="">— Evento —</option>
        <?php foreach ($events as $ev): ?>
          <option value="<?php echo esc_attr($ev); ?>" <?php selected($event_f, $ev); ?>><?php echo esc_html($ev); ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Buscar en mensaje / contexto / IP" style="width:260px;">
      <button class="button button-primary">Filtrar</button>
      <a class="button" href="<?php echo esc_url($base); ?>">Limpiar</a>
      <a class="button button-link-delete" href="<?php echo esc_url($clear_url); ?>" style="margin-left:auto;"
         onclick="return confirm('¿Vaciar todo el registro? Esta acción no se puede deshacer.');">Vaciar registro</a>
    </form>

    <p class="description">Total: <strong><?php echo (int)$total; ?></strong> entradas</p>

    <table class="widefat striped">
      <thead>
        <tr>
          <th style="width:140px;">Fecha</th>
          <th style="width:70px;">Nivel</th>
          <th style="width:80px;">Origen</th>
          <th style="width:170px;">Evento</th>
          <th>Mensaje</th>
          <th style="width:80px;">Duración</th>
          <th style="width:120px;">IP</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7">No hay entradas con esos filtros.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?php echo esc_html(function_exists('mr_admin_fmt_datetime_es') ? mr_admin_fmt_datetime_es($r['created_at']) : $r['created_at']); ?></td>
            <td><span style="padding:2px 8px;border-radius:10px;font-size:12px;<?php echo esc_attr($level_style[$r['level']] ?? ''); ?>"><?php echo esc_html($r['level']); ?></span></td>
            <td><?php echo esc_html($r['source'] === 'client' ? 'Navegador' : 'Servidor'); ?></td>
            <td><code><?php echo esc_html($r['event']); ?></code></td>
            <td>
              <?php echo esc_html($r['message']); ?>
              <?php if (!empty($r['context']) && $r['context'] !== '{}' && $r['context'] !== '[]'): ?>
                <details style="margin-top:4px;">
                  <summary style="cursor:pointer;font-size:12px;opacity:.7;">Contexto</summary>
                  <pre style="white-space:pre-wrap;word-break:break-word;font-size:11px;background:#f6f7f7;padding:6px;border-radius:4px;max-width:700px;"><?php
                    $decoded = json_decode($r['context'], true);
                    echo esc_html(is_array($decoded) ? wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $r['context']);
                  ?></pre>
                </details>
              <?php endif; ?>
            </td>
            <td><?php echo $r['duration_ms'] !== null ? (int)$r['duration_ms'] . ' ms' : '—'; ?></td>
            <td><?php echo esc_html($r['ip'] ?? ''); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php
      $total_pages = (int)ceil($total / $per_page);
      if ($total_pages > 1):
        $qs = ['page' => 'museo-reservas-log', 'level' => $level_f, 'event' => $event_f, 'source' => $source_f, 'q' => $q];
    ?>
      <div style="margin-top:12px;">
        <?php for ($p = 1; $p <= $total_pages; $p++): $qs['paged'] = $p; ?>
          <a href="<?php echo esc_url(add_query_arg($qs, admin_url('admin.php'))); ?>"
             style="margin-right:8px;<?php echo $p === $page ? 'font-weight:700;text-decoration:underline;' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php
}
