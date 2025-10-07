<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
echo "<pre>HEALTHCHECK\n";

$cands = [__DIR__.'/lib/funcs.php', __DIR__.'/../lib/funcs.php', __DIR__.'/funcs.php'];
$hit = null;
foreach($cands as $p){ if(is_file($p)){ $hit=$p; require_once $p; break; } }
echo "funcs.php: ".($hit ?: 'NOT FOUND')."\n";

if (!function_exists('db_conn')) { echo "db_conn() missing\n"; exit; }

echo "PHP: ".PHP_VERSION."\n";
echo "pdo_mysql: ".(extension_loaded('pdo_mysql')?'YES':'NO')."\n";

try {
  $pdo = db_conn();
  echo "DB CONNECT: OK\n";
  echo "SELECT 1: ".$pdo->query('SELECT 1')->fetchColumn()."\n";
} catch (Throwable $e) {
  echo "DB ERROR: ".$e->getMessage()."\n";
}

if (function_exists('app_config')) {
  $env = app_config();
  $keys = implode(', ', array_keys($env));
  echo ".env keys: {$keys}\n";
} else {
  echo "app_config() missing\n";
}
echo "</pre>";
