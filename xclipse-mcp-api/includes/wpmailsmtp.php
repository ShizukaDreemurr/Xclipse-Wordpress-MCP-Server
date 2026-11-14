<?php
/**
 * WP Mail SMTP — REST tools v4.9.0
 *
 * Viết dựa trên source WP Mail SMTP 4.10.3 (Lite), các lớp được dùng:
 *   - WPMailSMTP\Options              get / get_group / set, is_const_defined, Options::$mailers
 *   - WPMailSMTP\Admin\DebugEvents    bảng {prefix}wpmailsmtp_debug_events (id, content, initiator, event_type, created_at)
 *   - WPMailSMTP\EmailSendingDebug    option wp_mail_smtp_email_sending_debug (lỗi gửi mail theo connection, từ 4.9.0)
 *   - WPMailSMTP\TestEmail\TestEmail  gửi mail test (từ 4.9.0)
 *
 * Lưu ý thiết kế (rút ra từ source plugin):
 *   1. Options::set() mặc định GHI ĐÈ toàn bộ → module tự merge từ option gốc trong DB rồi mới gọi set().
 *   2. Plugin chỉ sanitize/mã hoá group của mailer ĐANG ACTIVE → module tự sanitize mọi key, và tự mã hoá
 *      smtp.pass khi mailer đích không phải 'smtp' (tránh lưu mật khẩu SMTP dạng plaintext).
 *   3. Secret (pass, api_key, token...) luôn bị che khi trả ra; nội dung debug event được scrub.
 *   4. Key bị khoá bởi hằng số WPMS_* (wp-config.php) sẽ không bị ghi, chỉ báo lại trong 'locked_skipped'.
 *   5. Class Debug cũ đã deprecated ở 4.10.x → không dùng; lỗi gửi mail đọc từ DebugEvents + EmailSendingDebug.
 * Tương thích PHP 7.4.
 */
defined('ABSPATH') || exit;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function xclipse_wpms_active(): bool {
    return function_exists('wp_mail_smtp') && class_exists('\\WPMailSMTP\\Options');
}

/** @return WP_Error|null */
function xclipse_wpms_guard() {
    return xclipse_wpms_active() ? null : xclipse_err('wpms_inactive', 'WP Mail SMTP chưa cài hoặc chưa active', 400);
}

function xclipse_wpms_bool($v): bool {
    return filter_var($v, FILTER_VALIDATE_BOOLEAN);
}

function xclipse_wpms_bool_keys(): array {
    return ['return_path', 'from_name_force', 'from_email_force', 'autotls', 'auth', 'do_not_send',
            'summary_report_email_disabled', 'email_debug', 'has_pro_plan'];
}

/** Whitelist group → key được phép đọc/ghi qua API (khớp Options::$map của WP Mail SMTP 4.10.3). */
function xclipse_wpms_allowed(): array {
    return [
        'mail'         => ['from_name', 'from_email', 'mailer', 'return_path', 'from_name_force', 'from_email_force'],
        'smtp'         => ['host', 'port', 'encryption', 'autotls', 'auth', 'user', 'pass'],
        'general'      => ['do_not_send', 'summary_report_email_disabled'],
        'debug_events' => ['email_debug', 'retention_period'],
        'gmail'        => ['client_id', 'client_secret'],
        'outlook'      => ['client_id', 'client_secret'],
        'zoho'         => ['domain', 'client_id', 'client_secret'],
        'amazonses'    => ['client_id', 'client_secret', 'region'],
        'mailgun'      => ['api_key', 'domain', 'region'],
        'mailjet'      => ['api_key', 'secret_key'],
        'mailersend'   => ['api_key', 'has_pro_plan'],
        'mandrill'     => ['api_key'],
        'sendgrid'     => ['api_key', 'domain'],
        'sparkpost'    => ['api_key', 'region'],
        'postmark'     => ['server_api_token', 'message_stream'],
        'smtpcom'      => ['api_key', 'channel'],
        'sendinblue'   => ['api_key', 'domain'],
        'sendlayer'    => ['api_key'],
        'elasticemail' => ['api_key'],
        'smtp2go'      => ['api_key'],
        'resend'       => ['api_key'],
        'pepipostapi'  => ['api_key'],
    ];
}

function xclipse_wpms_is_secret(string $group, string $key): bool {
    if ($group === 'license') return true;
    return (bool) preg_match('/(pass|secret|token|api_key|apikey|auth_code|user_details|connections|webhook|^key$)/i', $key);
}

function xclipse_wpms_mask($v) {
    if ($v === '' || $v === null || $v === false || $v === []) return '';
    return '********';
}

/** Che các chuỗi nhạy cảm có thể nằm trong nội dung debug event / lỗi SMTP. */
function xclipse_wpms_scrub($s) {
    if (!is_string($s) || $s === '') return $s;
    $s = preg_replace('/(Authorization|X-Api-Key|X-Auth-Token|Api-Key)\s*:\s*[^\r\n]+/i', '$1: [redacted]', $s);
    $s = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-\._~\+\/=]{8,}/i', '$1 [redacted]', $s);
    $s = preg_replace(
        '/((?:api[_-]?key|apikey|secret(?:_key)?|client_secret|access_token|refresh_token|token|password|passwd|pass)["\']?\s*[:=]\s*["\']?)[^\s"\',&}\]]+/i',
        '$1[redacted]',
        $s
    );
    $s = preg_replace('/(AUTH\s+(?:LOGIN|PLAIN|XOAUTH2)\s+)[A-Za-z0-9+\/=]{8,}/i', '$1[redacted]', $s);
    $s = preg_replace('/^(CLIENT\s*->\s*SERVER:\s*)[A-Za-z0-9+\/=]{16,}\s*$/m', '$1[redacted]', $s);
    return $s;
}

function xclipse_wpms_scrub_deep($v) {
    if (is_string($v)) return xclipse_wpms_scrub($v);
    if (is_array($v)) {
        foreach ($v as $k => $x) $v[$k] = xclipse_wpms_scrub_deep($x);
    }
    return $v;
}

/** Validate + sanitize 1 giá trị. Trả null và gán $err nếu không hợp lệ. */
function xclipse_wpms_clean_value(string $group, string $key, $v, &$err) {
    $err = null;
    if (in_array($key, xclipse_wpms_bool_keys(), true)) return xclipse_wpms_bool($v);

    if (is_array($v) || is_object($v)) { $err = 'giá trị phải là chuỗi/số/bool'; return null; }

    switch ($key) {
        case 'port':
            $p = (int) $v;
            if ($p < 1 || $p > 65535) { $err = 'port phải trong khoảng 1-65535'; return null; }
            return $p;
        case 'retention_period':
            $p = (int) $v;
            if ($p < 0) { $err = 'retention_period phải >= 0 (giây)'; return null; }
            return $p;
        case 'from_email':
            $e = sanitize_email((string) $v);
            if (!is_email($e)) { $err = 'email không hợp lệ'; return null; }
            return $e;
        case 'encryption':
            $e = strtolower(trim((string) $v));
            if (!in_array($e, ['none', 'ssl', 'tls'], true)) { $err = 'phải là none | ssl | tls'; return null; }
            return $e;
        case 'mailer':
            $m = sanitize_key((string) $v);
            $valid = array_merge(['mail'], \WPMailSMTP\Options::$mailers);
            if (!in_array($m, $valid, true)) { $err = 'mailer không hỗ trợ: ' . $m; return null; }
            return $m;
        case 'pass':
            return trim((string) $v);
        case 'region':
            $r = trim((string) $v);
            if (in_array($group, ['mailgun', 'sparkpost'], true)) {
                $r = strtoupper($r);
                if (!in_array($r, ['US', 'EU'], true)) { $err = 'region phải là US hoặc EU'; return null; }
            }
            return sanitize_text_field($r);
    }
    return sanitize_text_field((string) $v);
}

/** Danh sách "group.key" đang bị khoá bởi hằng số WPMS_*. */
function xclipse_wpms_locked(): array {
    $opt = \WPMailSMTP\Options::init();
    $locked = [];
    foreach (xclipse_wpms_allowed() as $group => $keys) {
        foreach ($keys as $k) {
            if ((bool) $opt->is_const_defined($group, $k)) $locked[] = $group . '.' . $k;
        }
    }
    return $locked;
}

function xclipse_wpms_read_group($opt, string $group): array {
    $out  = [];
    $bool = xclipse_wpms_bool_keys();
    foreach (xclipse_wpms_allowed()[$group] ?? [] as $k) {
        $v = $opt->get($group, $k);
        if (xclipse_wpms_is_secret($group, $k)) { $out[$k] = xclipse_wpms_mask($v); continue; }
        if (in_array($k, $bool, true)) { $out[$k] = (bool) $v; continue; }
        $out[$k] = $v === null ? '' : $v;
    }
    return $out;
}

/** Kiểm tra cấu hình mailer đã đủ chưa (heuristic tĩnh, KHÔNG gọi API nhà cung cấp, không có side effect). */
function xclipse_wpms_check_complete(string $mailer): array {
    $o = \WPMailSMTP\Options::init();
    $need = [];

    switch ($mailer) {
        case 'mail':
            return ['complete' => true, 'missing' => []];
        case 'smtp':
            $need = [['smtp', 'host'], ['smtp', 'port']];
            if ($o->get('smtp', 'auth')) { $need[] = ['smtp', 'user']; $need[] = ['smtp', 'pass']; }
            break;
        case 'gmail':
        case 'outlook':
            $need = [[$mailer, 'access_token']];
            if (!$o->get($mailer, 'one_click_setup_enabled')) { $need[] = [$mailer, 'client_id']; $need[] = [$mailer, 'client_secret']; }
            break;
        case 'zoho':
            $need = [['zoho', 'client_id'], ['zoho', 'client_secret'], ['zoho', 'access_token']];
            break;
        case 'amazonses':
            $need = [['amazonses', 'client_id'], ['amazonses', 'client_secret'], ['amazonses', 'region']];
            break;
        case 'mailgun':
            $need = [['mailgun', 'api_key'], ['mailgun', 'domain']];
            break;
        case 'mailjet':
            $need = [['mailjet', 'api_key'], ['mailjet', 'secret_key']];
            break;
        case 'postmark':
            $need = [['postmark', 'server_api_token']];
            break;
        case 'smtpcom':
            $need = [['smtpcom', 'api_key'], ['smtpcom', 'channel']];
            break;
        default:
            $need = [[$mailer, 'api_key']];
    }

    $missing = [];
    foreach ($need as $pair) {
        $v = $o->get($pair[0], $pair[1]);
        if ($v === null || $v === '' || $v === false || $v === []) $missing[] = $pair[0] . '.' . $pair[1];
    }
    return ['complete' => empty($missing), 'missing' => $missing];
}

function xclipse_wpms_events_table() {
    if (!class_exists('\\WPMailSMTP\\Admin\\DebugEvents\\DebugEvents')) return null;
    if (!\WPMailSMTP\Admin\DebugEvents\DebugEvents::is_valid_db()) return null;
    return \WPMailSMTP\Admin\DebugEvents\DebugEvents::get_table_name();
}

function xclipse_wpms_last_event_id(): int {
    $table = xclipse_wpms_events_table();
    if (!$table) return 0;
    global $wpdb;
    return (int) $wpdb->get_var("SELECT MAX(id) FROM `{$table}`");
}

function xclipse_wpms_event_row(array $row, int $max): array {
    $content = xclipse_wpms_scrub((string) ($row['content'] ?? ''));
    if ($max > 0 && mb_strlen($content) > $max) $content = mb_substr($content, 0, $max) . '…';

    $raw  = (string) ($row['initiator'] ?? '');
    $init = json_decode($raw, true);
    if (is_array($init)) {
        if (!empty($init['file']) && is_string($init['file'])) {
            $init['file'] = str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($init['file']));
        }
    } else {
        $init = $raw === '' ? null : mb_substr($raw, 0, 200);
    }

    return xclipse_compact([
        'id'        => (int) $row['id'],
        'type'      => ((int) $row['event_type'] === 1) ? 'debug' : 'error',
        'at_utc'    => $row['created_at'] ?? '',
        'content'   => $content,
        'initiator' => $init,
    ]);
}

function xclipse_wpms_send_errors_raw(): array {
    $all = get_option('wp_mail_smtp_email_sending_debug', []);
    return is_array($all) ? $all : [];
}

// ─── STATUS ──────────────────────────────────────────────────────────────────

function xclipse_wpms_status(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;
    global $wpdb;

    $opt    = \WPMailSMTP\Options::init();
    $mailer = (string) $opt->get('mail', 'mailer');
    $check  = xclipse_wpms_check_complete($mailer);

    $is_pro = false;
    try { $is_pro = (bool) wp_mail_smtp()->is_pro(); } catch (\Throwable $t) {}

    // wp_mail() có bị plugin khác override không?
    $override = null;
    try {
        $rf   = new ReflectionFunction('wp_mail');
        $file = wp_normalize_path((string) $rf->getFileName());
        if (strpos($file, wp_normalize_path(ABSPATH . WPINC . '/pluggable.php')) === false) {
            $override = str_replace(wp_normalize_path(WP_CONTENT_DIR), 'wp-content', $file);
        }
    } catch (\Throwable $t) {}

    // Plugin email/SMTP khác đang active
    $others = [];
    foreach ((array) get_option('active_plugins', []) as $p) {
        if (strpos($p, 'wp-mail-smtp') === 0) continue;
        if (preg_match('/smtp|postman|sendgrid|mailgun|brevo|sendinblue|fluent-?smtp|mailer/i', $p)) $others[] = $p;
    }

    // Debug events
    $events = ['table' => false];
    $table  = xclipse_wpms_events_table();
    if ($table) {
        $errs30 = \WPMailSMTP\Admin\DebugEvents\DebugEvents::get_error_debug_events_count('-30 days');
        $last   = $wpdb->get_row("SELECT id, content, created_at FROM `{$table}` WHERE event_type = 0 ORDER BY id DESC LIMIT 1", ARRAY_A);
        $events = [
            'table'            => true,
            'total'            => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"),
            'errors_30d'       => is_wp_error($errs30) ? null : (int) $errs30,
            'email_debug'      => (bool) $opt->get('debug_events', 'email_debug'),
            'retention_period' => (int) $opt->get('debug_events', 'retention_period'),
            'last_error'       => $last ? xclipse_wpms_event_row($last + ['initiator' => '', 'event_type' => 0], 300) : null,
        ];
    }

    $send_errors = [];
    if (class_exists('\\WPMailSMTP\\EmailSendingDebug') && method_exists('\\WPMailSMTP\\EmailSendingDebug', 'get_messages')) {
        $send_errors = array_map('xclipse_wpms_scrub', \WPMailSMTP\EmailSendingDebug::get_messages());
    }

    $notes = [];
    if ($mailer === 'mail')
        $notes[] = 'Mailer đang là PHP mail() mặc định: gửi "thành công" chỉ nghĩa là server nhận lệnh, chưa chắc mail tới inbox (dễ vào spam). Nên chuyển sang SMTP/API mailer.';
    if ($opt->get('general', 'do_not_send'))
        $notes[] = 'do_not_send đang BẬT: plugin sẽ không gửi email thật.';
    if (!$check['complete'])
        $notes[] = 'Cấu hình mailer chưa đủ: thiếu ' . implode(', ', $check['missing']);
    if ($override)
        $notes[] = 'wp_mail() đang bị ghi đè bởi: ' . $override . ' (có thể xung đột với WP Mail SMTP).';
    if ($others)
        $notes[] = 'Có plugin email khác đang active: ' . implode(', ', $others);
    if ($events['table'] && !$events['email_debug'])
        $notes[] = 'email_debug đang tắt: chỉ lỗi nghiêm trọng được ghi, bật lên khi cần chẩn đoán SMTP.';

    return xclipse_ok(xclipse_compact([
        'active'            => true,
        'version'           => defined('WPMS_PLUGIN_VER') ? WPMS_PLUGIN_VER : null,
        'is_pro'            => $is_pro,
        'mailer'            => $mailer,
        'from_email'        => $opt->get('mail', 'from_email'),
        'from_name'         => $opt->get('mail', 'from_name'),
        'from_email_force'  => (bool) $opt->get('mail', 'from_email_force'),
        'from_name_force'   => (bool) $opt->get('mail', 'from_name_force'),
        'return_path'       => (bool) $opt->get('mail', 'return_path'),
        'do_not_send'       => (bool) $opt->get('general', 'do_not_send'),
        'config_complete'   => $check['complete'],
        'missing'           => $check['missing'],
        'locked_by_consts'  => count(xclipse_wpms_locked()),
        'debug_events'      => $events,
        'send_errors'       => $send_errors,
        'wp_mail_override'  => $override,
        'other_mail_plugins'=> $others,
        'email_log'         => $is_pro ? 'pro (xem trong WP admin)' : 'không có ở bản Lite',
        'notes'             => $notes,
    ]));
}

// ─── MAILERS ─────────────────────────────────────────────────────────────────

function xclipse_wpms_mailers(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;

    $labels = [
        'mail' => 'PHP mail() mặc định', 'smtp' => 'Other SMTP', 'sendlayer' => 'SendLayer', 'smtpcom' => 'SMTP.com',
        'sendinblue' => 'Brevo (Sendinblue)', 'amazonses' => 'Amazon SES', 'elasticemail' => 'Elastic Email',
        'gmail' => 'Google / Gmail', 'mailgun' => 'Mailgun', 'mailjet' => 'Mailjet', 'mailersend' => 'MailerSend',
        'mandrill' => 'Mandrill', 'outlook' => 'Microsoft Outlook', 'pepipostapi' => 'Pepipost API',
        'postmark' => 'Postmark', 'resend' => 'Resend', 'sendgrid' => 'SendGrid', 'smtp2go' => 'SMTP2GO',
        'sparkpost' => 'SparkPost', 'zoho' => 'Zoho Mail', 'pepipost' => 'Pepipost SMTP',
    ];
    $oauth   = ['gmail', 'outlook', 'zoho'];
    $allowed = xclipse_wpms_allowed();
    $active  = (string) \WPMailSMTP\Options::init()->get('mail', 'mailer');
    $loader  = null;
    try { $loader = wp_mail_smtp()->get_providers(); } catch (\Throwable $t) {}

    $list = [];
    foreach (array_merge(['mail'], \WPMailSMTP\Options::$mailers) as $slug) {
        $auth = 'api_key';
        if ($slug === 'mail') $auth = 'none';
        elseif ($slug === 'smtp' || $slug === 'pepipost') $auth = 'smtp';
        elseif (in_array($slug, $oauth, true)) $auth = 'oauth';

        $registered = null;
        if ($loader && method_exists($loader, 'get_provider_path')) $registered = $loader->get_provider_path($slug) !== null;

        $list[] = xclipse_compact([
            'slug'       => $slug,
            'label'      => $labels[$slug] ?? $slug,
            'auth'       => $auth,
            'fields'     => $allowed[$slug] ?? [],
            'active'     => $slug === $active,
            'registered' => $registered,
            'note'       => $auth === 'oauth' ? 'Cần bấm Authorize trong WP admin; API chỉ lưu được client_id/client_secret.' : '',
        ]);
    }
    return xclipse_ok(['active' => $active, 'count' => count($list), 'mailers' => $list]);
}

// ─── SETTINGS ────────────────────────────────────────────────────────────────

function xclipse_wpms_settings_get(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;

    $opt    = \WPMailSMTP\Options::init();
    $mailer = (string) $opt->get('mail', 'mailer');

    $out = [
        'active_mailer' => $mailer,
        'mail'          => xclipse_wpms_read_group($opt, 'mail'),
        'smtp'          => xclipse_wpms_read_group($opt, 'smtp'),
        'general'       => xclipse_wpms_read_group($opt, 'general'),
        'debug_events'  => xclipse_wpms_read_group($opt, 'debug_events'),
    ];
    if (!in_array($mailer, ['mail', 'smtp'], true) && isset(xclipse_wpms_allowed()[$mailer])) {
        $out[$mailer] = xclipse_wpms_read_group($opt, $mailer);
    }
    $out['locked_by_consts'] = xclipse_wpms_locked();
    $out['secrets_masked']   = true;
    return xclipse_ok($out);
}

function xclipse_wpms_settings_set(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;

    $b = $r->get_json_params();
    if (!is_array($b)) return xclipse_err('bad_request', 'Body phải là JSON object', 400);

    $dry   = !empty($b['dry_run']) && xclipse_wpms_bool($b['dry_run']);
    $patch = isset($b['settings']) && is_array($b['settings']) ? $b['settings'] : $b;
    unset($patch['dry_run']);
    if (empty($patch)) return xclipse_err('bad_request', 'Thiếu "settings": {mail:{...}, smtp:{...}, ...}', 400);

    $opt     = \WPMailSMTP\Options::init();
    $allowed = xclipse_wpms_allowed();
    $clean   = [];
    $errors  = [];
    $ignored = [];
    $locked  = [];

    foreach ($patch as $group => $keys) {
        if (!isset($allowed[$group]) || !is_array($keys)) { $ignored[] = (string) $group; continue; }
        foreach ($keys as $k => $v) {
            if (!in_array($k, $allowed[$group], true)) { $ignored[] = $group . '.' . $k; continue; }
            if ((bool) $opt->is_const_defined($group, $k)) { $locked[] = $group . '.' . $k; continue; }
            $err = null;
            $val = xclipse_wpms_clean_value($group, $k, $v, $err);
            if ($err !== null) { $errors[] = $group . '.' . $k . ' (' . $err . ')'; continue; }
            $clean[$group][$k] = $val;
        }
    }
    if ($errors)
        return xclipse_err('invalid_settings', 'Giá trị không hợp lệ: ' . implode('; ', $errors), 400);

    // Phân loại thay đổi (chỉ trả tên key, không trả giá trị secret)
    $changed = [];
    $same    = [];
    foreach ($clean as $group => $keys) {
        foreach ($keys as $k => $val) {
            $cur  = $opt->get($group, $k);
            $a    = is_bool($cur) ? (string) (int) $cur : (string) $cur;
            $bnew = is_bool($val) ? (string) (int) $val : (string) $val;
            if ($a === $bnew) $same[] = $group . '.' . $k; else $changed[] = $group . '.' . $k;
        }
    }

    $db = get_option(\WPMailSMTP\Options::META_KEY, []);
    $merged = is_array($db) ? $db : [];
    foreach ($clean as $group => $keys) {
        foreach ($keys as $k => $val) $merged[$group][$k] = $val;
    }
    $final_mailer = (string) ($merged['mail']['mailer'] ?? 'mail');

    // Xử lý smtp.pass: set() chỉ mã hoá khi mailer active là 'smtp' và cần nhận plaintext.
    $pass_in_patch = isset($clean['smtp']['pass']);
    if ($final_mailer === 'smtp') {
        if (!$pass_in_patch && !empty($merged['smtp']['pass'])) {
            $merged['smtp']['pass'] = $opt->get('smtp', 'pass', false); // plaintext, set() sẽ mã hoá lại
        }
    } elseif ($pass_in_patch && $merged['smtp']['pass'] !== '' && class_exists('\\WPMailSMTP\\Helpers\\Crypto')) {
        try { $merged['smtp']['pass'] = \WPMailSMTP\Helpers\Crypto::encrypt($merged['smtp']['pass']); } catch (\Throwable $t) {}
    }

    if (!$dry && $clean) {
        $opt->set($merged, false, true);
    }

    $check = xclipse_wpms_check_complete($final_mailer);
    $notes = [];
    if (in_array($final_mailer, ['gmail', 'outlook', 'zoho'], true))
        $notes[] = 'Mailer OAuth: cần bấm Authorize trong WP admin để lấy token (API không làm được bước này).';
    if ($final_mailer === 'mail')
        $notes[] = 'Mailer là PHP mail() mặc định — nên dùng SMTP/API mailer để mail tới inbox ổn định.';
    if (!empty($merged['general']['do_not_send']))
        $notes[] = 'do_not_send đang BẬT: không có email nào được gửi thật.';
    if (!$check['complete'])
        $notes[] = 'Cấu hình chưa đủ: thiếu ' . implode(', ', $check['missing']);

    return xclipse_ok(xclipse_compact([
        'ok'             => true,
        'dry_run'        => $dry,
        'saved'          => !$dry && !empty($clean),
        'active_mailer'  => $final_mailer,
        'changed'        => $changed,
        'unchanged'      => $same,
        'locked_skipped' => $locked,
        'ignored'        => $ignored,
        'config_complete'=> $check['complete'],
        'missing'        => $check['missing'],
        'notes'          => $notes,
    ]));
}

// ─── TEST EMAIL ──────────────────────────────────────────────────────────────

function xclipse_wpms_test_email(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;

    $b  = $r->get_json_params();
    $b  = is_array($b) ? $b : [];
    $to = sanitize_email((string) ($b['to'] ?? ''));
    if (!is_email($to)) return xclipse_err('bad_email', 'Cần "to" là email hợp lệ', 400);

    if (!class_exists('\\WPMailSMTP\\TestEmail\\TestEmail'))
        return xclipse_err('unsupported', 'WP Mail SMTP quá cũ: cần >= 4.9.0 (lớp TestEmail)', 400);

    $html     = !array_key_exists('html', $b) || xclipse_wpms_bool($b['html']);
    $opt      = \WPMailSMTP\Options::init();
    $start_id = xclipse_wpms_last_event_id();
    $wp_error = null;
    $capture  = function ($err) use (&$wp_error) { $wp_error = $err; };

    add_action('wp_mail_failed', $capture);
    $t0 = microtime(true);
    try {
        $test   = new \WPMailSMTP\TestEmail\TestEmail();
        $result = $test->as_html($html)->with_domain_check(false)->send($to);
    } catch (\Throwable $t) {
        remove_action('wp_mail_failed', $capture);
        return xclipse_err('send_exception', 'Lỗi khi gửi: ' . xclipse_wpms_scrub($t->getMessage()), 500);
    }
    remove_action('wp_mail_failed', $capture);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    $status = [0 => 'failed', 1 => 'success', 2 => 'success_domain_issues'];

    // Debug events phát sinh trong lúc gửi
    $new_events = [];
    $table = xclipse_wpms_events_table();
    if ($table) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, content, initiator, event_type, created_at FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT 20", $start_id),
            ARRAY_A
        );
        foreach ((array) $rows as $row) $new_events[] = xclipse_wpms_event_row($row, 1200);
    }

    $send_errors = [];
    if (class_exists('\\WPMailSMTP\\EmailSendingDebug') && method_exists('\\WPMailSMTP\\EmailSendingDebug', 'get_messages')) {
        $send_errors = array_map('xclipse_wpms_scrub', \WPMailSMTP\EmailSendingDebug::get_messages());
    }

    $mailer = (string) $opt->get('mail', 'mailer');
    $ok     = $result === 1 || $result === 2;
    $warn   = [];
    if ($opt->get('general', 'do_not_send'))
        $warn[] = 'do_not_send đang BẬT — email KHÔNG được gửi thật dù kết quả báo thành công.';
    if ($ok && $mailer === 'mail')
        $warn[] = 'Mailer là PHP mail(): thành công chỉ nghĩa là server nhận lệnh, hãy kiểm tra inbox/spam.';

    return xclipse_ok(xclipse_compact([
        'ok'          => $ok,
        'result'      => $result === null ? 'invalid_recipient' : ($status[$result] ?? (string) $result),
        'to'          => $to,
        'mailer'      => $mailer,
        'from_email'  => $opt->get('mail', 'from_email'),
        'html'        => $html,
        'elapsed_ms'  => $ms,
        'error'       => $wp_error instanceof WP_Error ? xclipse_wpms_scrub($wp_error->get_error_message()) : '',
        'error_code'  => $wp_error instanceof WP_Error ? $wp_error->get_error_code() : '',
        'new_events'  => $new_events,
        'send_errors' => $send_errors,
        'warnings'    => $warn,
    ]));
}

// ─── DEBUG EVENTS ────────────────────────────────────────────────────────────

function xclipse_wpms_debug_events(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;
    $table = xclipse_wpms_events_table();
    if (!$table) return xclipse_err('wpms_no_table', 'Bảng debug events chưa tồn tại (plugin chưa tạo DB)', 404);
    global $wpdb;

    $type   = sanitize_key((string) ($r['type'] ?? 'all'));
    $limit  = max(1, min(100, (int) ($r['limit'] ?? 20)));
    $offset = max(0, (int) ($r['offset'] ?? 0));
    $max    = max(0, min(20000, (int) ($r['max_chars'] ?? 1500)));

    $where = ['1=1'];
    $args  = [];
    if ($type === 'error')      { $where[] = 'event_type = %d'; $args[] = 0; }
    elseif ($type === 'debug')  { $where[] = 'event_type = %d'; $args[] = 1; }

    $search = trim((string) ($r['search'] ?? ''));
    if ($search !== '') { $where[] = 'content LIKE %s'; $args[] = '%' . $wpdb->esc_like($search) . '%'; }

    $since = trim((string) ($r['since'] ?? ''));
    if ($since !== '') {
        $ts = strtotime($since);
        if (!$ts) return xclipse_err('bad_since', 'since không hợp lệ (vd: 2026-11-01 hoặc "-2 days")', 400);
        $where[] = 'created_at >= %s';
        $args[]  = gmdate('Y-m-d H:i:s', $ts);
    }

    $w     = implode(' AND ', $where);
    $count = "SELECT COUNT(*) FROM `{$table}` WHERE {$w}";
    $total = (int) $wpdb->get_var($args ? $wpdb->prepare($count, $args) : $count);
    $rows  = $wpdb->get_results(
        $wpdb->prepare("SELECT id, content, initiator, event_type, created_at FROM `{$table}` WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge($args, [$limit, $offset])),
        ARRAY_A
    );

    $items = [];
    foreach ((array) $rows as $row) $items[] = xclipse_wpms_event_row($row, $max);

    return xclipse_ok([
        'total'       => $total,
        'limit'       => $limit,
        'offset'      => $offset,
        'email_debug' => \WPMailSMTP\Admin\DebugEvents\DebugEvents::is_debug_enabled(),
        'items'       => $items,
    ]);
}

function xclipse_wpms_debug_event_get(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;
    $table = xclipse_wpms_events_table();
    if (!$table) return xclipse_err('wpms_no_table', 'Bảng debug events chưa tồn tại', 404);
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT id, content, initiator, event_type, created_at FROM `{$table}` WHERE id = %d", (int) $r['id']),
        ARRAY_A
    );
    if (!$row) return xclipse_err('not_found', 'Không có event #' . (int) $r['id']);
    return xclipse_ok(xclipse_wpms_event_row($row, 0));
}

function xclipse_wpms_debug_events_delete(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;
    $table = xclipse_wpms_events_table();
    if (!$table) return xclipse_err('wpms_no_table', 'Bảng debug events chưa tồn tại', 404);
    global $wpdb;

    $b   = $r->get_json_params();
    $b   = is_array($b) ? $b : [];
    $ids = isset($b['ids']) && is_array($b['ids']) ? array_values(array_unique(array_filter(array_map('intval', $b['ids'])))) : [];

    if ($ids) {
        if (count($ids) > 500) return xclipse_err('too_many', 'Tối đa 500 id mỗi lần', 400);
        $ph = implode(', ', array_fill(0, count($ids), '%d'));
        $n  = $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id IN ({$ph})", $ids));
        $deleted = (int) $n;
    } elseif (!empty($b['all'])) {
        if (empty($b['confirm']) || !xclipse_wpms_bool($b['confirm']))
            return xclipse_err('confirm_required', 'Xoá toàn bộ cần confirm=true', 400);
        $before  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $res     = $wpdb->query("TRUNCATE TABLE `{$table}`");
        if ($res === false) return xclipse_err('db_error', 'Không xoá được: ' . $wpdb->last_error, 500);
        $deleted = $before;
    } else {
        return xclipse_err('bad_request', 'Cần ids:[...] hoặc all:true + confirm:true', 400);
    }

    if (class_exists('\\WPMailSMTP\\Admin\\DebugEvents\\DebugEvents')) {
        delete_transient(\WPMailSMTP\Admin\DebugEvents\DebugEvents::ERROR_DEBUG_EVENTS_TRANSIENT . '_' . sanitize_title_with_dashes('-30 days'));
    }
    return xclipse_ok(['ok' => true, 'deleted' => $deleted]);
}

// ─── SEND ERRORS (EmailSendingDebug) ─────────────────────────────────────────

function xclipse_wpms_send_errors(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;

    $records  = xclipse_wpms_scrub_deep(xclipse_wpms_send_errors_raw());
    $messages = [];
    if (class_exists('\\WPMailSMTP\\EmailSendingDebug') && method_exists('\\WPMailSMTP\\EmailSendingDebug', 'get_messages')) {
        $messages = array_map('xclipse_wpms_scrub', \WPMailSMTP\EmailSendingDebug::get_messages());
    }
    return xclipse_ok(['count' => count($records), 'connections' => $records, 'messages' => $messages]);
}

function xclipse_wpms_send_errors_clear(WP_REST_Request $r) {
    if ($e = xclipse_wpms_guard()) return $e;

    $b  = $r->get_json_params();
    $id = is_array($b) ? sanitize_key((string) ($b['connection_id'] ?? '')) : '';

    if (class_exists('\\WPMailSMTP\\EmailSendingDebug')) {
        if ($id !== '' && method_exists('\\WPMailSMTP\\EmailSendingDebug', 'clear')) \WPMailSMTP\EmailSendingDebug::clear($id);
        elseif ($id === '' && method_exists('\\WPMailSMTP\\EmailSendingDebug', 'clear_all')) \WPMailSMTP\EmailSendingDebug::clear_all();
    } else {
        update_option('wp_mail_smtp_email_sending_debug', [], false);
    }
    return xclipse_ok(['ok' => true, 'cleared' => $id !== '' ? $id : 'all']);
}
