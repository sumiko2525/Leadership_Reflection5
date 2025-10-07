<?php
/**
 * lib/ai_client.php
 * ダミーAI関数置き場（将来、本物のAI APIに差し替え可能）
 */
require_once __DIR__ . '/../funcs.php';

/** ダッシュボード用：今日のひとこと */
function ai_today_tip(): string {
    $hour = (int)date('G');
    if ($hour < 10) return "Start small. 1つだけ終わらせて流れをつくろう。";
    if ($hour < 15) return "Focus window: 25分集中→5分休憩のリズムを2セット。";
    if ($hour < 19) return "Delegateタイム：自分でやらないことを3つ決める。";
    return "Good job. 夜は反省でなく回復を。1分のチェックアウトへ。";
}

/**
 * Quick SOS 用：状況テキストから「次の3アクション」を返す（ダミー）
 */
function ai_suggest_actions(string $text): array {
    $t = mb_strtolower($text, 'UTF-8');

    $suggestions = [];

    // 1) 緊急ワード
    if (preg_match('/(締切|期限|デッドライン|クレーム|炎上|トラブル|障害)/u', $t)) {
        $suggestions[] = "事実を1分で整理して共有（現状・影響・一次対応・次の判断）。";
        $suggestions[] = "ステークホルダーへ先手連絡：「いま把握していること／次の報告時刻」を明確に。";
        $suggestions[] = "自分で抱えない：役割を切り出して2人に委任（連絡係／一次対応）。";
    }

    // 2) 人間関係
    if (preg_match('/(部下|メンバー|人間関係|伝え方|フィードバック|叱る|注意)/u', $t)) {
        $suggestions[] = "相手の意図を先に確認（推測で決めつけない）。観察事実→影響→要望の順で短く伝える。";
        $suggestions[] = "5分1on1：質問を3つだけ用意（何がうまくいった？何が詰まってる？私にできる支援は？）。";
        $suggestions[] = "良い点を先に1つ具体化（行動レベル）。改善提案は“小さく試す”に限定。";
    }

    // 3) 優先順位・過多
    if (preg_match('/(優先|多すぎ|パンク|時間|残業|忙)/u', $t) || mb_strlen($t, 'UTF-8') > 120) {
        $suggestions[] = "ToDoを3つに圧縮：Must（今日）／Should（今週）／Later（手放す）を1分で仕分け。";
        $suggestions[] = "25分集中タイマーを開始。終わりに「次の小タスク」だけ残しておく。";
        $suggestions[] = "やらないこと宣言：影響が小さい仕事を1つ止める／委任する。";
    }

    // 4) デフォルト
    if (count($suggestions) < 3) {
        $suggestions[] = "状況を3行で要約（事実／課題／望む状態）。書くと整理されます。";
        $suggestions[] = "関係者を1人だけ選び、10行の共有メモを送る（曖昧さを減らす）。";
        $suggestions[] = "次の1歩を30分以内にできるサイズへ小さく切る。";
    }

    $suggestions = array_values(array_unique($suggestions));
    return array_slice($suggestions, 0, 3);
}
