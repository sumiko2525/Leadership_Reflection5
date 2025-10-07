ini_set('display_errors','1');
error_reporting(E_ALL);
ini_set('log_errors','1');
ini_set('error_log', __DIR__ . '/php_error.log');

<?php
// admin_users.php — 管理者専用：ユーザー管理（一覧/検索/作成/編集/配属/削除）
declare(strict_types=1);
session_start();
require_once __DIR__ . '/funcs.php';

login_required();
$me = current_user();
if (($me['role'] ?? '') !== 'admin') {
  http_response_code(403);
  exit('権限がありません（adminのみ）');
}

date_default_timezone_set('Asia/Tokyo');
$pdo = db_conn();

/* ---------- ユーティリティ ---------- */
function tableExists(PDO $pdo, string $t): bool {
  try { $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1"); return true; }
  catch(Throwable $e){ return false; }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$hasTeamMembers = tableExists($pdo,'team_members'); // チーム配属は team_members 前提

/* ---------- アクション処理（POST） ---------- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors[] = 'CSRFトークンが不正です。';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      if ($action === 'create') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role  = (string)($_POST['role'] ?? 'member');
        $pass  = (string)($_POST['password'] ?? '');
        $teamId = (int)($_POST['team_id'] ?? 0);

        if ($email === '' || $pass === '') throw new Exception('メール・パスワードは必須です。');
        if (!in_array($role, ['admin','leader','member'], true)) $role = 'member';

        $st = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,created_at) VALUES (:n,:e,:p,:r,NOW())');
        $st->execute([
          ':n'=>$name, ':e'=>$email,
          ':p'=>password_hash($pass, PASSWORD_DEFAULT),
          ':r'=>$role
        ]);
        $newUserId = (int)$pdo->lastInsertId();

        if ($hasTeamMembers && $teamId > 0) {
          $st = $pdo->prepare('INSERT INTO team_members (team_id,user_id,role,joined_at) VALUES (:t,:u,:r,NOW())');
          $st->execute([':t'=>$teamId, ':u'=>$newUserId, ':r'=>($role==='leader'?'leader':'member')]);
        }

      } elseif ($action === 'update') {
        $uid   = (int)($_POST['user_id'] ?? 0);
        $name  = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role  = (string)($_POST['role'] ?? 'member');
        $teamId= (int)($_POST['team_id'] ?? 0);
        $newPw = (string)($_POST['new_password'] ?? '');

        if ($uid <= 0) throw new Exception('対象ユーザーが不正です。');
        if (!in_array($role, ['admin','leader','member'], true)) $role = 'member';

        $st = $pdo->prepare('UPDATE users SET name=:n, email=:e, role=:r WHERE id=:id');
        $st->execute([':n'=>$name, ':e'=>$email, ':r'=>$role, ':id'=>$uid]);

        if ($newPw !== '') {
          $st = $pdo->prepare('UPDATE users SET password_hash=:p WHERE id=:id');
          $st->execute([':p'=>password_hash($newPw, PASSWORD_DEFAULT), ':id'=>$uid]);
        }

        if ($hasTeamMembers) {
          // 既存配属を確認
          $st = $pdo->prepare('SELECT id FROM team_members WHERE user_id=:u LIMIT 1');
          $st->execute([':u'=>$uid]);
          $tmId = $st->fetchColumn();

          if ($teamId > 0) {
            if ($tmId) {
              $st = $pdo->prepare('UPDATE team_members SET team_id=:t, role=:role WHERE user_id=:u');
              $st->execute([':t'=>$teamId, ':role'=>($role==='leader'?'leader':'member'), ':u'=>$uid]);
            } else {
              $st = $pdo->prepare('INSERT INTO team_members (team_id,user_id,role,joined_at) VALUES (:t,:u,:role,NOW())');
              $st->execute([':t'=>$teamId, ':u'=>$uid, ':role'=>($role==='leader'?'leader':'member')]);
            }
          } else {
            // team_id = 0 の場合は配属解除
            if ($tmId) {
              $st = $pdo->prepare('DELETE FROM team_members WHERE user_id=:u');
              $st->execute([':u'=>$uid]);
            }
          }
        }

      } elseif ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid <= 0) throw new Exception('対象ユーザーが不正です。');
        // 自分自身は削除禁止
        if ($uid === (int)$me['id']) throw new Exception('自分自身は削除できません。');

        if ($hasTeamMembers) {
          $st = $pdo->prepare('DELETE FROM team_members WHERE user_id=:u');
          $st->execute([':u'=>$uid]);
        }
        $st = $pdo->prepare('DELETE FROM users WHERE id=:id');
        $st->execute([':id'=>$uid]);
      }
    } catch (Throwable $e) {
      $errors[] = $e->getMessage();
    }
  }
}

/* ---------- 検索条件 & 並べ替え & ページング ---------- */
$q = trim((string)($_GET['q'] ?? ''));
$roleF = (string)($_GET['role'] ?? '');
$teamF = (int)($_GET['team_id'] ?? 0);
$sort  = (string)($_GET['sort'] ?? 'created_desc');
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 20;
$offset = ($page-1)*$per;

/* チーム一覧 */
$teams = [];
if (tableExists($pdo,'teams')) {
  $rs = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC');
  $teams = $rs->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* 検索SQL（users を主、team_members/teams は LEFT JOIN） */
$order = 'u.created_at DESC';
if ($sort === 'name_asc') $order = 'u.name ASC';
if ($sort === 'name_desc') $order = 'u.name DESC';
if ($sort === 'created_asc') $order = 'u.created_at ASC';

$params = [];
$sqlWhere = [];
if ($q !== '') {
  $sqlWhere[] = '(u.name LIKE :q OR u.email LIKE :q)';
  $params[':q'] = "%{$q}%";
}
if (in_array($roleF, ['admin','leader','member'], true)) {
  $sqlWhere[] = 'u.role = :role';
  $params[':role'] = $roleF;
}
$joinTeam = '';
if ($hasTeamMembers) {
  $joinTeam = 'LEFT JOIN team_members tm ON tm.user_id = u.id LEFT JOIN teams t ON t.id = tm.team_id';
  if ($teamF > 0) {
    $sqlWhere[] = 'tm.team_id = :tid';
    $params[':tid'] = $teamF;
  }
} else {
  // team_members が無い場合は users に team_id があるかを試す
  if (tableExists($pdo,'users')) {
    $hasCol = false;
    try {
      $test = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'team_id'");
      $hasCol = (bool)$test->fetch();
    } catch (Throwable $e) { $hasCol = false; }
    if ($hasCol && $teamF > 0) {
      $sqlWhere[] = 'u.team_id = :tid';
      $params[':tid'] = $teamF;
    }
  }
}
$whereSql = $sqlWhere ? ('WHERE '.implode(' AND ', $sqlWhere)) : '';

/* 件数取得 */
$countSql = "SELECT COUNT(DISTINCT u.id) FROM users u {$joinTeam} {$whereSql}";
$st = $pdo->prepare($countSql);
$st->execute($params);
$total = (int)$st->fetchColumn();

/* 一覧取得 */
$listSql = "SELECT u.id, u.name, u.email, u.role, u.created_at,
                   COALESCE(t.name, '') AS team_name, COALESCE(tm.team_id, 0) AS team_id
            FROM users u
            {$joinTeam}
            {$whereSql}
            GROUP BY u.id
            ORDER BY {$order}
            LIMIT :lim OFFSET :ofs";
$st = $pdo->prepare($listSql);
foreach ($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim',$per,PDO::PARAM_INT);
$st->bindValue(':ofs',$offset,PDO::PARAM_INT);
$st->execute();
$users = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* ---------- 画面 ---------- */
include __DIR__ . '/header.php'; // 直下 or 自動判定しているなら適宜
?>
<main class="container" style="max-width:1100px;margin:24px auto;padding:0 12px;">
  <h1 style="margin:.3em 0 .6em;">ユーザー管理</h1>

  <?php if ($errors): ?>
    <div style="background:#fff3cd;border:1px solid #ffeeba;padding:10px;border-radius:8px;margin-bottom:12px;">
      <?php foreach($errors as $e): ?>
        <div><?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- 検索フォーム -->
  <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="名前/メールで検索" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
    <select name="role" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
      <option value="">すべてのロール</option>
      <option value="admin"  <?= $roleF==='admin'?'selected':'' ?>>admin</option>
      <option value="leader" <?= $roleF==='leader'?'selected':'' ?>>leader</option>
      <option value="member" <?= $roleF==='member'?'selected':'' ?>>member</option>
    </select>
    <select name="team_id" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
      <option value="0">すべてのチーム</option>
      <?php foreach($teams as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $teamF===(int)$t['id']?'selected':'' ?>><?= h($t['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" style="padding:8px;border:1px solid #d1d5db;border-radius:8px;">
      <option value="created_desc" <?= $sort==='created_desc'?'selected':'' ?>>新しい順</option>
      <option value="created_asc"  <?= $sort==='created_asc'?'selected':'' ?>>古い順</option>
      <option value="name_asc"     <?= $sort==='name_asc'?'selected':'' ?>>名前A→Z</option>
      <option value="name_desc"    <?= $sort==='name_desc'?'selected':'' ?>>名前Z→A</option>
    </select>
    <button type="submit" style="padding:8px 12px;border-radius:8px;border:1px solid #0d9488;background:#0d9488;color:#fff;">検索</button>
  </form>

  <!-- 新規作成 -->
  <details style="margin-bottom:16px;">
    <summary style="cursor:pointer;font-weight:700;">＋ 新規ユーザー作成</summary>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-top:8px;background:#f8fafc;padding:12px;border-radius:10px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label>名前<input type="text" name="name" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;"></label>
      <label>メール<input type="email" name="email" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;"></label>
      <label>パスワード<input type="text" name="password" required style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;"></label>
      <label>ロール
        <select name="role" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;">
          <option value="member">member</option>
          <option value="leader">leader</option>
          <option value="admin">admin</option>
        </select>
      </label>
      <label>チーム
        <select name="team_id" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:8px;">
          <option value="0">配属しない</option>
          <?php foreach($teams as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div><button type="submit" style="padding:8px 12px;border-radius:8px;border:1px solid #0d9488;background:#0d9488;color:#fff;">作成</button></div>
    </form>
  </details>

  <!-- 一覧 -->
  <div style="font-size:.92rem;opacity:.8;margin-bottom:6px;">全 <?= (int)$total ?> 件</div>
  <div style="overflow:auto;">
    <table style="width:100%;border-collapse:collapse;">
      <thead>
        <tr style="background:#e0f2f1;">
          <th style="text-align:left;padding:8px;border:1px solid #cbd5e1;">ID</th>
          <th style="text-align:left;padding:8px;border:1px solid #cbd5e1;">名前</th>
          <th style="text-align:left;padding:8px;border:1px solid #cbd5e1;">メール</th>
          <th style="text-align:left;padding:8px;border:1px solid #cbd5e1;">ロール</th>
          <th style="text-align:left;padding:8px;border:1px solid #cbd5e1;">チーム</th>
          <th style="text-align:left;padding:8px;border:1px solid #cbd5e1;">作成日</th>
          <th style="text-align:left;padding:8px;border:1px solid #cbd5e1;">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($users as $u): ?>
          <tr>
            <td style="padding:8px;border:1px solid #e2e8f0;"><?= (int)$u['id'] ?></td>
            <td style="padding:8px;border:1px solid #e2e8f0;"><?= h($u['name']) ?></td>
            <td style="padding:8px;border:1px solid #e2e8f0;"><?= h($u['email']) ?></td>
            <td style="padding:8px;border:1px solid #e2e8f0;"><?= h($u['role']) ?></td>
            <td style="padding:8px;border:1px solid #e2e8f0;"><?= h($u['team_name']) ?></td>
            <td style="padding:8px;border:1px solid #e2e8f0;"><?= h($u['created_at']) ?></td>
            <td style="padding:8px;border:1px solid #e2e8f0;">
              <details>
                <summary style="cursor:pointer;">編集</summary>
                <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px;margin-top:6px;background:#fafafa;padding:8px;border-radius:8px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <label>名前<input type="text" name="name" value="<?= h($u['name']) ?>" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:8px;"></label>
                  <label>メール<input type="email" name="email" value="<?= h($u['email']) ?>" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:8px;"></label>
                  <label>ロール
                    <select name="role" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:8px;">
                      <option value="member" <?= $u['role']==='member'?'selected':'' ?>>member</option>
                      <option value="leader" <?= $u['role']==='leader'?'selected':'' ?>>leader</option>
                      <option value="admin"  <?= $u['role']==='admin'?'selected':''  ?>>admin</option>
                    </select>
                  </label>
                  <label>新PW（空なら変更なし）
                    <input type="text" name="new_password" placeholder="********" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:8px;">
                  </label>
                  <label>チーム
                    <select name="team_id" style="width:100%;padding:6px;border:1px solid #d1d5db;border-radius:8px;">
                      <option value="0">配属しない</option>
                      <?php foreach($teams as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= ((int)$u['team_id']===(int)$t['id'])?'selected':'' ?>>
                          <?= h($t['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <div style="display:flex;gap:6px;align-items:center;">
                    <button type="submit" style="padding:6px 10px;border-radius:8px;border:1px solid #0d9488;background:#0d9488;color:#fff;">更新</button>
                  </div>
                </form>
                <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                  <form method="post" onsubmit="return confirm('本当に削除しますか？この操作は元に戻せません。');" style="margin-top:6px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" style="padding:6px 10px;border-radius:8px;border:1px solid #ef4444;background:#ef4444;color:#fff;">削除</button>
                  </form>
                <?php endif; ?>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ページャ -->
  <?php
    $pages = (int)ceil($total / $per);
    if ($pages < 1) $pages = 1;
  ?>
  <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">
    <?php for($p=1;$p<=$pages;$p++): 
      $qstr = http_build_query(['q'=>$q,'role'=>$roleF,'team_id'=>$teamF,'sort'=>$sort,'page'=>$p]);
    ?>
      <a href="?<?= h($qstr) ?>" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;<?= $p===$page?'background:#0d9488;color:#fff;border-color:#0d9488;':'' ?>">
        <?= $p ?>
      </a>
    <?php endfor; ?>
  </div>
</main>

<footer style="margin:40px 0 24px;text-align:center;color:#666;">
  Leadership Reflection® ver 0.9.0 © リーダーシップ開発研究所
</footer>
