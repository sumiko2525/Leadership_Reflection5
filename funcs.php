<?php
declare(strict_types=1);

/**
 * funcs.php（安全・拡張・統合版 / 2025-10-06）
 *
 * 変更点（この版で追加/統合した主な機能）
 * - チーム＆ロール機能：current_user() / team_required() / role_at_least()
 * - CSRF互換エイリアス：csrf_validate() → csrf_verify() を内部呼び出し
 * - セッション安全化：session_regenerate_safe()
 * - 既存APIは維持：app_config()/db_conn()/h()/redirect()/login_required()/chk_ssid()/csrf系/sql_try()/miibo_api_key()
 *
 * 想定：.env.php は配列を return する形式
 *   return [
 *     'DB_HOST' => 'localhost',
 *     'DB_NAME' => 'your_db',
 *     'DB_USER' => 'root',
 *     'DB_PASS' => '',
 *     'MIIBO_API_KEY' => '...',
 *   ];
 */

// ------------------------------------------------------------
// セッション開始（重複防止）
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*============================================================
  設定ロード（.env.php / 配列 return）
============================================================*/
function app_config(?string $key = null, $default = null) {
    static $conf = null;

    if ($conf === null) {
        $base = __DIR__;
        $candidates = [
            $base . '/.env.php',          // 推奨: 同階層
            $base . '/lib/.env.php',      // 互換: lib/ 配下
            dirname($base) . '/.env.php', // 保険: 1つ上
        ];
        $path = null;
        foreach ($candidates as $p) {
            if (is_file($p)) { $path = $p; break; }
        }
        if (!$path) {
            // なるべく画面には詳細を出さない（ログは別途）
            error_log('[ENV] .env.php not found. searched: ' . implode(',', $candidates));
            exit('環境設定ファイル(.env.php)が見つかりません。配置を確認してください。');
        }

        /** @var mixed $loaded */
        $loaded = include $path; // return [...]
        if (!is_array($loaded)) {
            exit('.env.php の形式が不正です（配列を return してください）');
        }
        $conf = array_map(
            static fn($v) => is_string($v) ? trim($v) : $v,
            $loaded
        );
    }

    return $key === null ? $conf : ($conf[$key] ?? $default);
}

/*============================================================
  DB接続（PDO / 例外 / UTF8MB4 / static再利用）
============================================================*/
function db_conn(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string)app_config('DB_HOST', '');
    $db   = (string)app_config('DB_NAME', '');
    $user = (string)app_config('DB_USER', '');
    $pass = (string)app_config('DB_PASS', '');

    $dsn = "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4";
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $opt);
        return $pdo;
    } catch (Throwable $e) {
        error_log('[DB CONNECT ERROR] ' . $e->getMessage());
        exit('DB接続に失敗しました。時間をおいて再度お試しください。');
    }
}

/*============================================================
  共通ユーティリティ
============================================================*/
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
    header("Location: {$path}");
    exit;
}

/**
 * ログイン必須（互換: chk_ssid）
 * 想定：ログイン成功時に $_SESSION['user_id'] をセット
 */
function login_required(): void {
    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }
}
function chk_ssid(): void { // 既存互換
    login_required();
}

/*============================================================
  CSRF（発行・埋込・検証）
============================================================*/
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool {
    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// 互換：旧名 csrf_validate() を使っている画面のために残す
if (!function_exists('csrf_validate')) {
    function csrf_validate(?string $token): bool {
        return csrf_verify($token);
    }
}

/*============================================================
  SQL実行の共通エラーハンドラ
============================================================*/
function sql_try(callable $fn) {
    try {
        return $fn();
    } catch (Throwable $e) {
        error_log('[SQL ERROR] ' . $e->getMessage());
        exit('処理中にエラーが発生しました。時間をおいて再度お試しください。');
    }
}

/*============================================================
  MIIBO等：外部キー取得（将来用）
============================================================*/
function miibo_api_key(): string {
    return (string)app_config('MIIBO_API_KEY', '');
}

/*============================================================
  追加：チーム & ロール（RBAC）ユーティリティ
  - role の序列：viewer(1) < member(2) < leader(3) < admin(4)
============================================================*/

/**
 * 現在のユーザー情報（セッションから取得）
 * @return array{id:int|null, team_id:int|null, role:string}
 */
function current_user(): array {
    $id      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $team_id = isset($_SESSION['team_id']) ? (int)$_SESSION['team_id'] : null;
    $role    = isset($_SESSION['role'])    ? (string)$_SESSION['role'] : 'viewer';
    return ['id' => $id, 'team_id' => $team_id, 'role' => $role];
}

/**
 * ログイン + チーム所属 必須のガード
 * 未ログイン or チーム未所属なら login.php へ
 */
function team_required(): void {
    if (empty($_SESSION['user_id']) || empty($_SESSION['team_id'])) {
        redirect('login.php');
    }
}

/**
 * ロール閾値チェック（viewer < member < leader < admin）
 */
function role_at_least(string $min): bool {
    static $order = ['viewer'=>1,'member'=>2,'leader'=>3,'admin'=>4];
    $have = isset($_SESSION['role']) ? (string)$_SESSION['role'] : 'viewer';
    return ($order[$have] ?? 0) >= ($order[$min] ?? 99);
}

/**
 * セッションIDの安全な再発行（ログイン直後など）
 */
function session_regenerate_safe(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/*============================================================
 （任意）便利ヘルパ：型安全にセッションへ格納
============================================================*/
/**
 * ログイン確定時に呼び出すと便利な一括セット
 * @param int $userId
 * @param int $teamId
 * @param string $role 'viewer'|'member'|'leader'|'admin'
 */
function session_set_identity(int $userId, int $teamId, string $role): void {
    $_SESSION['user_id'] = $userId;
    $_SESSION['team_id'] = $teamId;
    $_SESSION['role']    = $role;
    session_regenerate_safe();
}
