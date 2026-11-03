<?php
/**
 * Kurage テレアポCRM・SFA — MCPサーバー（1ファイル・依存ライブラリなし）
 *
 * Claude Code や Claude Desktop などの AI エージェントから、架電先・再コール・顧客・集計を調べ、架電の結果を記録するための口。
 *
 *   claude mcp add kcallcrm -- php /path/to/kcc_mcp.php
 *   Claude Desktop: {"mcpServers":{"kcallcrm":{"command":"php","args":["/path/to/kcc_mcp.php"]}}}
 *
 * AI ができるのは「読む」と「架電の結果を記録する」まで。架電先や顧客の情報の書き換え・削除・設定の変更はできない。
 * 記録の担当者は、kcc_config.php の mcp_user_email（無ければ「api」という名前の利用者、無ければ最初の管理者）。
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/kcc_lib.php';

const KCC_MCP_VERSION = '1.0.0';

function mcp_user(): int
{
    $e = (string)(cfg('mcp_user_email') ?? '');
    if ($e !== '') { $s = db()->prepare('SELECT id FROM users WHERE email=? AND active=1'); $s->execute([norm_email($e)]); if ($id = $s->fetchColumn()) { return (int)$id; } }
    return (int)(db()->query("SELECT id FROM users WHERE name='api' AND active=1")->fetchColumn() ?: db()->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn());
}
function slim(array $t): array
{
    return ['id' => (int)$t['id'], 'list_id' => (int)$t['list_id'], 'company' => $t['company'], 'contact' => $t['contact'], 'phone' => $t['phone'], 'pref' => $t['pref'], 'addr' => $t['addr'], 'industry' => $t['industry'],
            'status' => status_label($t['status']), 'assignee' => user_name((int)$t['assignee_id']), 'next_call' => $t['next_call'], 'last_call' => $t['last_call'], 'last_result' => $t['last_result'], 'call_count' => (int)$t['call_count'], 'customer_id' => $t['customer_id'] ? (int)$t['customer_id'] : null, 'note' => $t['note']];
}

$TOOLS = [
    ['name' => 'kcc_lists', 'description' => '架電リストの一覧（件数と状態ごとの内訳つき）。', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []]],
    ['name' => 'kcc_search_targets', 'description' => '架電先を探す。会社名・担当者・電話番号・住所の一部、リスト、状態（new/recall/appo/lead/busy/ng/skip/customer）、担当者で絞れる。',
     'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string'], 'list_id' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'assignee' => ['type' => 'string', 'description' => '担当者の名前'], 'page' => ['type' => 'integer']], 'required' => []]],
    ['name' => 'kcc_get_target', 'description' => '架電先1件の中身と、これまでの架電の履歴、同じとみなせる相手。', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
    ['name' => 'kcc_recalls', 'description' => 'その日までに期限の来た再コールの一覧（既定は今日）。', 'inputSchema' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'assignee' => ['type' => 'string']], 'required' => []]],
    ['name' => 'kcc_record_call', 'description' => '架電の結果を記録する。result は設定の結果の code（kcc_results で確かめる）。再コールのときは next_call（YYYY-MM-DD HH:MM）が必要。アポなら自動で顧客になる。',
     'inputSchema' => ['type' => 'object', 'properties' => ['target_id' => ['type' => 'integer'], 'result' => ['type' => 'string'], 'memo' => ['type' => 'string'], 'next_call' => ['type' => 'string']], 'required' => ['target_id', 'result']]],
    ['name' => 'kcc_results', 'description' => '架電の結果の選択肢（code・名前・扱い）と、架電先の状態、商談のステージ。', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []]],
    ['name' => 'kcc_customers', 'description' => '顧客を探す（会社名・担当者・電話）。', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string'], 'page' => ['type' => 'integer']], 'required' => []]],
    ['name' => 'kcc_get_customer', 'description' => '顧客1件と、活動の履歴、商談。', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
    ['name' => 'kcc_stats', 'description' => '期間の架電数・通話数・アポ数・アポ率（担当ごと・日ごと）。', 'inputSchema' => ['type' => 'object', 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string']], 'required' => []]],
];

function user_id_by_name(string $n): ?int { foreach (users() as $u) { if ($u['name'] === $n) { return (int)$u['id']; } } return null; }
function call_tool(string $name, array $a): array
{
    switch ($name) {
        case 'kcc_lists': return ['lists' => array_map(fn($l) => ['id' => (int)$l['id'], 'name' => $l['name'], 'kind' => $l['kind'], 'added_on' => $l['added_on'], 'total' => $l['total'], 'counts' => array_combine(array_map('status_label', array_keys($l['counts'])), array_values($l['counts']))], lists())];
        case 'kcc_search_targets':
            $r = targets_query(['q' => (string)($a['q'] ?? ''), 'list_id' => (int)($a['list_id'] ?? 0), 'status' => (string)($a['status'] ?? ''), 'assignee_id' => !empty($a['assignee']) ? (int)user_id_by_name((string)$a['assignee']) : 0], (int)($a['page'] ?? 1), 50);
            return ['total' => $r['total'], 'page' => $r['page'], 'pages' => $r['pages'], 'targets' => array_map('slim', $r['rows'])];
        case 'kcc_get_target':
            $t = target((int)($a['id'] ?? 0)) ?: throw new InvalidArgumentException('架電先が見つかりません');
            return ['target' => slim($t), 'calls' => array_map(fn($c) => ['at' => $c['at'], 'result' => $c['result'], 'memo' => $c['memo'], 'next_call' => $c['next_call'], 'user' => user_name((int)$c['user_id'])], calls_of_target((int)$t['id'])), 'duplicates' => find_duplicates(keys_of($t))];
        case 'kcc_recalls':
            $d = valid_date($a['date'] ?? null) ? $a['date'] : today(); $w = ''; $v = [$d . ' 23:59'];
            if (!empty($a['assignee']) && ($uid = user_id_by_name((string)$a['assignee']))) { $w = ' AND assignee_id=?'; $v[] = $uid; }
            $s = db()->prepare("SELECT * FROM call_targets WHERE deleted IS NULL AND status='recall' AND next_call <= ?$w ORDER BY next_call LIMIT 200"); $s->execute($v);
            return ['date' => $d, 'targets' => array_map('slim', $s->fetchAll())];
        case 'kcc_record_call':
            $r = record_call((int)($a['target_id'] ?? 0), (string)($a['result'] ?? ''), (string)($a['memo'] ?? ''), $a['next_call'] ?? null, mcp_user());
            return $r + ['message' => '記録しました'];
        case 'kcc_results': return ['results' => results(), 'statuses' => KCC_STATUS, 'stages' => stages()];
        case 'kcc_customers':
            $r = customers_query(['q' => (string)($a['q'] ?? '')], (int)($a['page'] ?? 1), 50);
            return ['total' => $r['total'], 'customers' => array_map(fn($c) => ['id' => (int)$c['id'], 'company' => $c['company'], 'contact' => $c['contact'], 'phone' => $c['phone'], 'owner' => user_name((int)$c['owner_id']), 'updated' => $c['updated']], $r['rows'])];
        case 'kcc_get_customer':
            $c = customer((int)($a['id'] ?? 0)) ?: throw new InvalidArgumentException('顧客が見つかりません');
            return ['customer' => $c, 'activities' => array_map(fn($x) => ['at' => $x['at'], 'kind' => KCC_ACT_KINDS[$x['kind']] ?? $x['kind'], 'body' => $x['body'], 'user' => user_name((int)$x['user_id'])], activities_of((int)$c['id'])),
                    'deals' => array_map(fn($d) => ['id' => (int)$d['id'], 'title' => $d['title'], 'stage' => $d['stage'], 'amount' => $d['amount'], 'next_action' => $d['next_action'], 'next_due' => $d['next_due']], deals_of((int)$c['id']))];
        case 'kcc_stats':
            $from = valid_date($a['from'] ?? null) ? $a['from'] : date('Y-m-d', strtotime('-13 days')); $to = valid_date($a['to'] ?? null) ? $a['to'] : today();
            $s = stats($from, $to); return ['from' => $from, 'to' => $to, 'total' => $s['total'], 'users' => array_map(fn($u) => ['user' => user_name((int)$u['user_id'])] + $u, $s['users']), 'days' => $s['days']];
    }
    throw new InvalidArgumentException('使えないツールです');
}

function send(array $m): void { echo json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; flush(); }
while (($line = fgets(STDIN)) !== false) {
    $req = json_decode(trim($line), true);
    if (!is_array($req)) { continue; }
    $id = $req['id'] ?? null; $method = (string)($req['method'] ?? ''); $params = (array)($req['params'] ?? []);
    if ($id === null && str_starts_with($method, 'notifications/')) { continue; }
    switch ($method) {
        case 'initialize':
            send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => (string)($params['protocolVersion'] ?? '2024-11-05'), 'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'kcallcrm', 'version' => KCC_MCP_VERSION],
                'instructions' => 'テレアポ営業のCRM・SFA（Kurage テレアポCRM・SFA）の窓口です。架電先・再コール・顧客・集計を読み、架電の結果を記録できます。架電先や顧客の情報の書き換え・削除・設定の変更はできません。']]);
            break;
        case 'ping': send(['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()]); break;
        case 'tools/list': send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $TOOLS]]); break;
        case 'tools/call':
            try { $res = call_tool((string)($params['name'] ?? ''), (array)($params['arguments'] ?? []));
                send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => json_encode($res, JSON_UNESCAPED_UNICODE)]], 'isError' => false]]);
            } catch (Throwable $e) { send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)]], 'isError' => true]]); }
            break;
        default:
            if ($id !== null) { send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]]); }
    }
}
