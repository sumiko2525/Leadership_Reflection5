<?php
// make_hash.php  ← 使い終わったら必ず削除
header('Content-Type: text/plain; charset=utf-8');
echo "leader1:  " . password_hash('Leader!2025x', PASSWORD_DEFAULT) . PHP_EOL;
echo "member2:  " . password_hash('Member!2025x', PASSWORD_DEFAULT) . PHP_EOL;
