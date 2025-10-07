<?php
require_once __DIR__ . '/funcs.php';

// .env.phpの中身を確認
echo "<h2>.env.php の内容（app_config）</h2>";
echo "<pre>";
print_r(app_config());
echo "</pre>";

// DB接続テスト
echo "<h2>DB接続テスト</h2>";
try {
    $pdo = db_conn();
    $stmt = $pdo->query("SELECT NOW() as now_time");
    $row = $stmt->fetch();
    echo "✅ DB接続成功！ 現在時刻： " . htmlspecialchars($row['now_time']);
} catch (Exception $e) {
    echo "❌ DB接続に失敗しました：" . htmlspecialchars($e->getMessage());
}
