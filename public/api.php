<?php
/**
 * Kurage テレアポCRM・SFA — JSON API（外部のシステム・自動化から使う窓口）。
 *
 * 合言葉：kcc_config.php の api_token を、ヘッダー Authorization: Bearer <token> で送る（空なら API は使えない）。
 *   GET  api.php?r=lists                               リスト一覧
 *   GET  api.php?r=targets&list_id=&status=&q=&page=   架電先（サーバーで絞り込み・ページ送り）
 *   GET  api.php?r=target&id=                          架電先1件と履歴
 *   GET  api.php?r=recalls&date=YYYY-MM-DD             その日までに期限の来た再コール
 *   POST api.php?r=target   {list_id, company, phone, …}         架電先を足す（重複なら 409 と相手を返す。force=true で足す）
 *   POST api.php?r=call     {target_id, result(code), memo, next_call, user_email?}   架電の結果を記録
 *   GET  api.php?r=customers&q=&page=                  顧客
 *   GET  api.php?r=stats&from=&to=                     集計
 * 記録の user は user_email で指定（無ければ「api」という名前の利用者＝無ければ最初の管理者）。
 */
declare(strict_types=1);
require __DIR__ . '/kcc_lib.php';
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex');
function out($v, int $code = 200): never { http_response_code($code); echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$token = (string)cfg('api_token');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($token === '' || !hash_equals('Bearer ' . $token, $auth)) { out(['error' => 'unauthorized'], 401); }

$r = (string)($_GET['r'] ?? '');
$body = $_SERVER['REQUEST_METHOD'] === 'POST' ? (array)(json_decode((string)file_get_contents('php://input'), true) ?: $_POST) : [];
function api_user(array $b): int
{
    if (!empty($b['user_email'])) { $s = db()->prepare('SELECT id FROM users WHERE email=? AND active=1'); $s->execute([norm_email((string)$b['user_email'])]); if ($id = $s->fetchColumn()) { return (int)$id; } }
    $id = db()->query("SELECT id FROM users WHERE name='api' AND active=1")->fetchColumn() ?: db()->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();
    return (int)$id;
}
function api_target_add(array $body): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { out(['error' => 'POST'], 405); }
    $lid = (int)($body['list_id'] ?? 0); call_list($lid) ?: out(['error' => 'list not found'], 404);
    $uid = api_user($body);
    $res = add_target($lid, $body, $uid, empty($body['force']));
    if (!$res['id']) { out(['error' => 'duplicate', 'duplicates' => $res['dup']], 409); }
    oplog($uid, 'target.create', 'call_targets', $res['id'], 'api');
    out(['id' => $res['id']], 201);
}
try {
    switch ($r) {
        case 'lists': out(['lists' => lists()]);
        case 'targets': out(targets_query(['list_id' => (int)($_GET['list_id'] ?? 0), 'status' => (string)($_GET['status'] ?? ''), 'q' => (string)($_GET['q'] ?? ''), 'assignee_id' => (int)($_GET['assignee_id'] ?? 0), 'due' => (int)($_GET['due'] ?? 0)], (int)($_GET['page'] ?? 1), min(500, (int)($_GET['per'] ?? 50) ?: 50)));
        case 'target':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') { api_target_add($body); }
            $t = target((int)($_GET['id'] ?? 0)) ?: out(['error' => 'not found'], 404);
            out(['target' => $t, 'calls' => calls_of_target((int)$t['id']), 'duplicates' => find_duplicates(keys_of($t))]);
        case 'recalls':
            $d = valid_date($_GET['date'] ?? null) ? $_GET['date'] : today();
            $s = db()->prepare("SELECT * FROM call_targets WHERE deleted IS NULL AND status='recall' AND next_call <= ? ORDER BY next_call LIMIT 500"); $s->execute([$d . ' 23:59']);
            out(['date' => $d, 'targets' => $s->fetchAll()]);
        case 'customers': out(customers_query(['q' => (string)($_GET['q'] ?? '')], (int)($_GET['page'] ?? 1)));
        case 'stats':
            $from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime('-13 days')); $to = valid_date($_GET['to'] ?? null) ? $_GET['to'] : today();
            out(['from' => $from, 'to' => $to] + stats($from, $to, (int)($_GET['user_id'] ?? 0) ?: null));
        case 'results': out(['results' => results(), 'statuses' => KCC_STATUS, 'stages' => stages()]);
        case 'target_add':
            api_target_add($body);
        case 'call':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { out(['error' => 'POST'], 405); }
            $uid = api_user($body);
            out(record_call((int)($body['target_id'] ?? 0), (string)($body['result'] ?? ''), (string)($body['memo'] ?? ''), $body['next_call'] ?? null, $uid, $body['at'] ?? null), 201);
        default: out(['error' => 'unknown r', 'routes' => ['lists', 'targets', 'target', 'recalls', 'customers', 'stats', 'results', 'POST target', 'POST call']], 404);
    }
} catch (InvalidArgumentException $e) { out(['error' => $e->getMessage()], 400);
} catch (RuntimeException $e) { out(['error' => $e->getMessage()], 404);
}
