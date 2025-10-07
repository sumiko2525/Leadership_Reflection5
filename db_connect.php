<?php
declare(strict_types=1);

/**
 * db_connect.php（最上案 / SSOT＋互換＋自己診断）
 * 役割：
 *  1) funcs.php を堅牢に読み込む（＝接続情報の単一情報源）
 *  2) 旧コードの互換エイリアス（db_conn, pdo, db）
 *  3) 直アクセス時の簡易ヘルスチェック（?check=1）
 */

// 1) funcs.php を多段候補で探索（配置場所が動いてもOK）
$base = __DIR__;
$candidates = [
  $base . '/funcs.php',          // 推奨：直下
  $base . '/lib/funcs.php',      // 互換：lib配下
  dirname($base) . '/funcs.php', // 保険：一段上
];

$loaded = false;
foreach ($candidates as $p) {
  if (is_file($p)) { require_once $p; $loaded = true; break; }
}
if (!$loaded) {
  http_response_code(500);
  exit('funcs.php が見つかりません（db_connect.php）。配置を確認してください。');
}

// 2) 必須関数の存在チェック
if (!function_exists('db_conn')) {
  http_response_code(500);
  exit('db_conn() が見つかりません。funcs.php に定義してください。');
}

// 3) 後方互換エイリアス（古いコード救済）
if (!function_exists('pdo')) {
  function pdo(): PDO { return db_conn(); } // 「pdo()」派の古いファイル向け
}
if (!function_exists('db')) {
  function db(): PDO { return db_conn(); }  // 「db()」派の古いファイル向け
}

// 4) 直アクセス時は簡易ヘルスチェックを提供（?check=1 のときのみ）
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
  if (isset($_GET['check'])) {
    header('Content-Type: text/plain; charset=utf-8');
    try {
      // funcs.php 側の app_config があれば、ホスト解決も併せて確認
      $host = function_exists('app_config') ? (string)app_config('DB_HOST', '') : '';
      $resolved = $host ? @gethostbyname($host) : '';
      $pdo = db_conn();
      $now = $pdo->query('SELECT NOW()')->fetchColumn();
      echo "OK\n";
      if ($host) {
        echo "DB_HOST: {$host}\n";
        echo "Resolved: {$resolved}\n";
      }
      echo "DB_TIME: {$now}\n";
    } catch (Throwable $e) {
      http_response_code(500);
      echo "NG: " . $e->getMessage() . "\n";
    }
    exit;
  }
  // 直アクセスで ?check=1 が無ければ何も出さない（露出抑制）
  http_response_code(204);
  exit;
}
