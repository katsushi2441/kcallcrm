<?php
/**
 * Kurage テレアポCRM・SFA — 画面（ログイン・今日の架電・リスト・架電先・架電・顧客・商談・集計・設定・取込・書き出し）。
 */
declare(strict_types=1);
require __DIR__ . '/kcc_lib.php';

session_start_once();
$p = (string)($_GET['p'] ?? 'home');
$a = (string)($_POST['a'] ?? '');
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
function go(string $q, ?string $msg = null): never { if ($msg) { $_SESSION['flash'] = $msg; } header('Location: ?' . $q); exit; }
function qs(array $over = []): string { return http_build_query(array_filter(array_merge($_GET, $over), fn($v) => $v !== '' && $v !== null)); }

/* ---------- ログイン前 ---------- */
$noUsers = !(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($a === 'demo_login' && cfg('demo') && csrf_ok()) {
    $s = db()->prepare('SELECT id FROM users WHERE id=?'); $s->execute([(int)($_POST['uid'] ?? 1)]);
    if ($id = $s->fetchColumn()) { session_regenerate_id(true); $_SESSION['uid'] = (int)$id; }
    go('p=home');
}
if ($a === 'login' && csrf_ok()) {
    $err = try_login((string)($_POST['email'] ?? ''), (string)($_POST['pass'] ?? ''));
    if ($err) { go('p=login', $err); }
    go('p=home');
}
if ($a === 'setup' && $noUsers && !cfg('demo') && csrf_ok()) {
    try { $id = create_user((string)$_POST['email'], (string)$_POST['name'], (string)$_POST['pass'], 'admin'); } catch (InvalidArgumentException $e) { go('p=login', $e->getMessage()); }
    if (trim((string)($_POST['company'] ?? '')) !== '') { set_setting('company', trim((string)$_POST['company'])); }
    $_SESSION['uid'] = $id; go('p=home', '最初の管理者を作りました。右上の「設定」で担当者と架電の結果の選択肢を整えてください');
}
if ($a === 'logout') { $_SESSION = []; session_destroy(); header('Location: ?p=login'); exit; }

$me = me();
if (!$me) { page_login($noUsers, $flash); exit; }
$uid = (int)$me['id'];
$isAdmin = $me['role'] === 'admin';
daily_backup();

/* ---------- 操作 ---------- */
if ($a !== '') {
    if (!csrf_ok()) { http_response_code(400); exit('画面を読み込み直してから、もう一度操作してください'); }
    $id = (int)($_POST['id'] ?? 0);
    $back = (string)($_POST['back'] ?? '');
    try {
        switch ($a) {
            case 'new_list':
                $lid = create_list((string)$_POST['name'], (string)($_POST['kind'] ?? ''), $_POST['added_on'] ?? null, (string)($_POST['note'] ?? ''), $uid);
                go('p=import&list_id=' . $lid, 'リストを作りました。CSV を取り込むか、1件ずつ足してください');
            case 'update_list':
                call_list($id) ?: throw new RuntimeException('リストが見つかりません');
                db()->prepare('UPDATE call_lists SET name=?,kind=?,added_on=?,note=?,updated=? WHERE id=?')->execute([trim((string)$_POST['name']) ?: 'リスト', trim((string)$_POST['kind']), valid_date($_POST['added_on'] ?? null) ? $_POST['added_on'] : null, trim((string)$_POST['note']), now(), $id]);
                oplog($uid, 'list.update', 'call_lists', $id); go('p=targets&list_id=' . $id, '保存しました');
            case 'delete_list':
                if (!$isAdmin) { throw new RuntimeException('リストの削除は管理者だけができます'); }
                db()->prepare('UPDATE call_lists SET deleted=? WHERE id=?')->execute([now(), $id]);
                db()->prepare('UPDATE call_targets SET deleted=? WHERE list_id=? AND deleted IS NULL AND customer_id IS NULL')->execute([now(), $id]);
                oplog($uid, 'list.delete', 'call_lists', $id); go('p=lists', 'リストを消しました（顧客になった架電先は残しています）');
            case 'import':
                $lid = (int)$_POST['list_id']; call_list($lid) ?: throw new RuntimeException('リストが見つかりません');
                if (empty($_FILES['csv']['tmp_name'])) { throw new InvalidArgumentException('CSV ファイルを選んでください'); }
                if (filesize($_FILES['csv']['tmp_name']) > 50 * 1024 * 1024) { throw new InvalidArgumentException('CSV は 50MB までです'); }
                $res = import_csv($lid, (string)file_get_contents($_FILES['csv']['tmp_name']), $uid, !empty($_POST['skip_dup']), !empty($_POST['dry']));
                $_SESSION['import_result'] = $res;
                go('p=import&list_id=' . $lid . '&done=1', (empty($_POST['dry']) ? '取り込みました' : '確かめました（まだ取り込んでいません）') . '：追加 ' . $res['added'] . '件・重複 ' . $res['dup'] . '件・空 ' . $res['empty'] . '件');
            case 'new_target':
                $lid = (int)$_POST['list_id']; call_list($lid) ?: throw new RuntimeException('リストが見つかりません');
                $r = add_target($lid, $_POST, $uid, empty($_POST['force']));
                if (!$r['id']) { $_SESSION['pending_target'] = $_POST; go('p=new_target&list_id=' . $lid . '&dup=1'); }
                oplog($uid, 'target.create', 'call_targets', $r['id']); go('p=target&id=' . $r['id'], '架電先を足しました');
            case 'update_target':
                update_target($id, $_POST, $uid); go('p=target&id=' . $id, '保存しました');
            case 'delete_target':
                $t = target($id) ?: throw new RuntimeException('架電先が見つかりません');
                db()->prepare('UPDATE call_targets SET deleted=? WHERE id=?')->execute([now(), $id]); oplog($uid, 'target.delete', 'call_targets', $id);
                go('p=targets&list_id=' . (int)$t['list_id'], '架電先を消しました');
            case 'record_call':
                $r = record_call($id, (string)($_POST['result'] ?? ''), (string)($_POST['memo'] ?? ''), $_POST['next_call'] ?? null, $uid, $_POST['at'] ?? null);
                $msg = '記録しました' . ($r['status'] === 'customer' && !empty($_POST['result']) && (result_by_code((string)$_POST['result'])['kind'] ?? '') === 'appo' ? '。アポなので顧客にしました' : '');
                if ($back === 'call') { go('p=call&list_id=' . (int)($_POST['list_id'] ?? 0), $msg); }
                go('p=target&id=' . $id, $msg);
            case 'promote':
                $cid = promote_target($id, $uid); go('p=customer&id=' . $cid, '顧客にしました');
            case 'new_customer':
                $cid = create_customer($_POST, $uid); go('p=customer&id=' . $cid, '顧客を作りました');
            case 'update_customer':
                update_customer($id, $_POST, $uid); go('p=customer&id=' . $id, '保存しました');
            case 'add_activity':
                add_activity($id, (int)($_POST['deal_id'] ?? 0) ?: null, (string)($_POST['kind'] ?? 'other'), (string)$_POST['body'], $_POST['at'] ?? null, $uid);
                go(($back === 'deal' && (int)($_POST['deal_id'] ?? 0)) ? 'p=deal&id=' . (int)$_POST['deal_id'] : 'p=customer&id=' . $id, '記録しました');
            case 'new_deal':
                $did = create_deal($id, $_POST, $uid); go('p=deal&id=' . $did, '商談を作りました');
            case 'update_deal':
                update_deal($id, $_POST, $uid); go('p=deal&id=' . $id, '保存しました');
            case 'stage':   // かんばんのドラッグ
                set_stage($id, (string)$_POST['stage'], $uid); header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
            case 'settings':
                if (!$isAdmin) { throw new RuntimeException('設定は管理者だけができます'); }
                set_setting('company', trim((string)($_POST['company'] ?? '')));
                $rs = [];
                foreach ((array)($_POST['r_label'] ?? []) as $i => $label) {
                    $label = trim((string)$label); $kind = (string)($_POST['r_kind'][$i] ?? ''); $code = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['r_code'][$i] ?? ''))) ?: 'r' . $i;
                    if ($label !== '' && isset(KCC_RESULT_KINDS[$kind])) { $rs[] = ['code' => $code, 'label' => $label, 'kind' => $kind]; }
                }
                if (count($rs) >= 2) { set_setting('call_results', $rs); }
                $st = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['stages'] ?? ''))), fn($x) => $x !== ''));
                $cl = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['closed'] ?? ''))), fn($x) => in_array($x, $st, true)));
                if (count($st) >= 2) { set_setting('deal_stages', $st); set_setting('closed_stages', $cl); }
                oplog($uid, 'settings.update'); go('p=settings', '設定を保存しました');
            case 'new_user':
                if (!$isAdmin) { throw new RuntimeException('担当者の追加は管理者だけができます'); }
                create_user((string)$_POST['email'], (string)$_POST['name'], (string)$_POST['pass'], (string)$_POST['role']); oplog($uid, 'user.create'); go('p=settings', '担当者を足しました');
            case 'toggle_user':
                if (!$isAdmin || $id === $uid) { throw new RuntimeException('自分自身は無効にできません'); }
                db()->prepare('UPDATE users SET active=1-active WHERE id=?')->execute([$id]); oplog($uid, 'user.toggle', 'users', $id); go('p=settings', '変えました');
            case 'reset_pass':
                if (!$isAdmin) { throw new RuntimeException('管理者だけができます'); }
                if (mb_strlen((string)$_POST['pass']) < 8) { throw new InvalidArgumentException('パスワードは8文字以上にしてください'); }
                db()->prepare('UPDATE users SET pass_hash=?, fail_count=0, locked_until=NULL WHERE id=?')->execute([password_hash((string)$_POST['pass'], PASSWORD_DEFAULT), $id]); oplog($uid, 'user.reset_pass', 'users', $id); go('p=settings', 'パスワードを変えました');
            default:
                throw new RuntimeException('その操作はありません');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        $_SESSION['flash'] = $e->getMessage();
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?p=home')); exit;
    }
}

/* ---------- 書き出し（管理者） ---------- */
if ($p === 'csv') {
    if (!$isAdmin) { http_response_code(403); exit('CSV の書き出しは管理者だけができます'); }
    $what = (string)($_GET['what'] ?? 'targets'); oplog($uid, 'csv.export', null, null, $what);
    if ($what === 'customers') {
        csv_out('customers.csv', ['id', 'accountName', 'contactName', 'department', 'title', 'phoneNumber', 'emailAddress', 'addressPostalCode', 'addressState', 'addressStreet', 'website', 'industry', 'description', 'assignedUser', 'createdAt'],
            (function () { foreach (db()->query('SELECT * FROM customers WHERE deleted IS NULL ORDER BY id') as $c) { yield [$c['id'], $c['company'], $c['contact'], $c['dept'], $c['title'], $c['phone'], $c['email'], $c['zip'], $c['pref'], $c['addr'], $c['url'], $c['industry'], $c['note'], user_name((int)$c['owner_id']), $c['created']]; } })());
    }
    if ($what === 'calls') {
        $s = db()->query('SELECT c.*, t.company, t.phone FROM calls c LEFT JOIN call_targets t ON t.id=c.target_id ORDER BY c.at');
        csv_out('calls.csv', ['id', 'dateStart', 'accountName', 'phoneNumber', 'status', 'description', 'nextCall', 'assignedUser'], (function () use ($s) { foreach ($s as $c) { yield [$c['id'], $c['at'], $c['company'], $c['phone'], $c['result'], $c['memo'], $c['next_call'], user_name((int)$c['user_id'])]; } })());
    }
    $lid = (int)($_GET['list_id'] ?? 0);
    $s = db()->query('SELECT t.*, l.name list_name FROM call_targets t JOIN call_lists l ON l.id=t.list_id WHERE t.deleted IS NULL' . ($lid ? ' AND t.list_id=' . $lid : '') . ' ORDER BY t.list_id, t.seq');
    csv_out('leads.csv', ['id', 'list', 'accountName', 'contactName', 'department', 'title', 'phoneNumber', 'mobile', 'emailAddress', 'addressPostalCode', 'addressState', 'addressStreet', 'website', 'industry', 'description', 'status', 'assignedUser', 'nextCall', 'lastCall', 'lastResult', 'callCount'],
        (function () use ($s) { foreach ($s as $t) { yield [$t['id'], $t['list_name'], $t['company'], $t['contact'], $t['dept'], $t['title'], $t['phone'], $t['phone2'], $t['email'], $t['zip'], $t['pref'], $t['addr'], $t['url'], $t['industry'], $t['note'], status_label($t['status']), user_name((int)$t['assignee_id']), $t['next_call'], $t['last_call'], $t['last_result'], $t['call_count']]; } })());
}

/* ---------- 画面 ---------- */
layout_top($me, $p, $flash);
switch ($p) {
    case 'lists':      page_lists(); break;
    case 'import':     page_import((int)($_GET['list_id'] ?? 0)); break;
    case 'targets':    page_targets(); break;
    case 'target':     page_target((int)($_GET['id'] ?? 0), $me); break;
    case 'new_target': page_new_target((int)($_GET['list_id'] ?? 0)); break;
    case 'call':       page_call((int)($_GET['list_id'] ?? 0), $me); break;
    case 'customers':  page_customers(); break;
    case 'customer':   page_customer((int)($_GET['id'] ?? 0), $me); break;
    case 'new_customer': page_new_customer(); break;
    case 'deals':      page_deals($me); break;
    case 'deal':       page_deal((int)($_GET['id'] ?? 0), $me); break;
    case 'stats':      page_stats($me); break;
    case 'settings':   page_settings($isAdmin); break;
    default:           page_home($me);
}
layout_bottom();

/* ================= 共通の枠 ================= */

function layout_head(string $title): void
{ ?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title><?php $u = (string)cfg('public_url'); if ($u !== ''): $desc = 'テレアポ営業のCRM・SFAのデモ。架電リストに架電先を取り込み、電話の結果と次回コールを残し、アポが取れたら顧客にして商談をかんばんで追います。担当ごとの架電数・アポ率を集計。PHPとSQLiteだけで動き、月額なし・買い切りで自社サーバーに置けます。'; ?>
<meta name="description" content="<?= h($desc) ?>"><link rel="canonical" href="<?= h($u) ?>"><meta property="og:type" content="website"><meta property="og:title" content="<?= h($title) ?>"><meta property="og:description" content="<?= h($desc) ?>"><meta property="og:url" content="<?= h($u) ?>"><meta property="og:image" content="<?= h($u) ?>kcc_ogp.png"><meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image">
<?php else: ?><meta name="robots" content="noindex"><?php endif; ?>
<link rel="icon" href="kcc_favicon.png">
<style>
:root{--ink:#14232b;--sub:#53646f;--line:#d9e3e8;--bg:#f4f8f9;--teal:#0a8f85;--teal-d:#07756d;--soft:#eef8f6;--amber:#b7791f;--red:#c0392b;--blue:#2f6fd1}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.7 "Hiragino Sans","Noto Sans JP",system-ui,sans-serif}
a{color:var(--teal-d)}header.top{background:#fff;border-bottom:1px solid var(--line);padding:10px 16px;display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;position:sticky;top:0;z-index:20}
header.top .brand{font-weight:900;text-decoration:none;color:var(--ink);font-size:17px;display:flex;align-items:center;gap:8px}header.top .brand img{width:30px;height:34px;object-fit:contain}header.top .brand small{display:block;font-size:11px;color:var(--sub);font-weight:700}
header.top nav{display:flex;flex-wrap:wrap;gap:4px 14px;font-weight:800;font-size:14px}header.top nav a{text-decoration:none}header.top nav a.on{color:var(--ink);border-bottom:2px solid var(--teal)}
header.top .promo{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:13px;font-weight:800;margin-right:8px}header.top .promo a{text-decoration:none;color:#b45309}
header.top .me{margin-left:auto;flex-wrap:wrap;font-size:13px;color:var(--sub);display:flex;gap:8px;align-items:center}
main{padding:16px;max-width:1400px;margin:0 auto}.demo{background:#fff6dc;border:1px solid #ecd9a6;border-radius:10px;padding:8px 14px;margin:0 0 12px;font-size:13.5px}
.flash{background:var(--soft);border:1px solid #bfe0e6;border-radius:10px;padding:10px 14px;margin:0 0 12px;font-weight:700}
h1{font-size:22px;margin:4px 0 12px}h2{font-size:17px;margin:0 0 10px}h3{font-size:15px;margin:0 0 8px}
.btn{display:inline-block;border:0;border-radius:9px;background:var(--teal);color:#fff;font-weight:800;padding:9px 16px;font-size:14px;cursor:pointer;text-decoration:none;font-family:inherit;line-height:1.4}
.btn.sub{background:#fff;color:var(--teal-d);border:1px solid #bfe0e6}.btn.warn{background:#fff;color:var(--red);border:1px solid #f0c4be}.btn.sm{padding:5px 10px;font-size:12.5px}.btn.big{font-size:17px;padding:14px 24px}
input,select,textarea{font:inherit;border:1px solid var(--line);border-radius:8px;padding:8px 10px;background:#fff;width:100%;color:var(--ink)}textarea{min-height:80px}
label{display:block;font-size:12.5px;font-weight:800;color:var(--sub);margin:8px 0 3px}
.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin:0 0 14px}
.grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 16px}.kpi b{display:block;font-size:30px;line-height:1.1;color:var(--teal-d)}.kpi span{font-size:12.5px;color:var(--sub);font-weight:700}
table.t{width:100%;border-collapse:collapse;font-size:14px}table.t th,table.t td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left;vertical-align:top}table.t th a{text-decoration:none}.wide{overflow-x:auto}
.tag{display:inline-block;border-radius:999px;padding:1px 9px;font-size:11.5px;font-weight:800;white-space:nowrap}
.st-new{background:#eef2f5;color:#4a5a66}.st-recall{background:#fff2d6;color:#7a5200}.st-appo,.st-customer{background:#dff3ee;color:#0a6b63}.st-lead{background:#e9f0ff;color:#2a4f9a}.st-busy{background:#f1f1f1;color:#666}.st-ng{background:#fde7e4;color:var(--red)}.st-skip{background:#f1f1f1;color:#888}
.tag.over{background:#fde7e4;color:var(--red)}.tag.amt{background:var(--soft);color:var(--teal-d)}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.filters>div{min-width:120px}.filters input,.filters select{width:auto;min-width:120px}
.pager{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:13px;margin:10px 0}.pager a,.pager b{padding:4px 10px;border-radius:8px;border:1px solid var(--line);background:#fff;text-decoration:none}.pager b{background:var(--teal);color:#fff;border-color:var(--teal)}
.results{display:flex;flex-wrap:wrap;gap:8px}.results label{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:8px 14px;cursor:pointer;margin:0;font-size:14px;color:var(--ink)}.results input{width:auto}.results label:has(input:checked){background:var(--soft);border-color:var(--teal);color:var(--teal-d)}
.callhead{display:flex;flex-wrap:wrap;gap:8px 20px;align-items:baseline}.callhead .co{font-size:24px;font-weight:900}.callhead .tel{font-size:26px;font-weight:900;color:var(--teal-d);letter-spacing:.04em}.callhead .tel a{text-decoration:none}
.board{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(230px,1fr);gap:12px;overflow-x:auto;padding-bottom:10px}
.col{background:#eaf1f3;border-radius:12px;padding:10px;min-height:160px}.col h3{margin:0 0 8px;font-size:14px;display:flex;justify-content:space-between;gap:6px}.col h3 small{color:var(--sub);font-weight:700}.col.over{outline:2px dashed var(--teal)}
.card{display:block;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin:0 0 8px;text-decoration:none;color:var(--ink);cursor:grab}
.card b{display:block;font-size:14px;line-height:1.45}.card .co{font-size:12.5px;color:var(--sub)}.card .meta{display:flex;flex-wrap:wrap;gap:4px 8px;font-size:12px;margin-top:6px;color:var(--sub)}
.tl{list-style:none;margin:0;padding:0}.tl li{border-left:3px solid var(--line);padding:4px 0 10px 12px;margin-left:4px}.tl time{font-size:12px;color:var(--sub);font-weight:800;margin-right:6px}.tl .who{font-size:12px;color:var(--sub)}
.muted{color:var(--sub);font-size:13px}.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.dupbox{background:#fff6dc;border:1px solid #ecd9a6;border-radius:10px;padding:10px 14px;margin:0 0 12px}
.hero{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:16px;align-items:center}.hero img{width:100%;height:auto;object-fit:contain}
@media(max-width:900px){.grid2,.grid3,.grid4,.hero{grid-template-columns:minmax(0,1fr)}header.top .me{margin-left:0}.filters input,.filters select{width:100%}.callhead .co,.callhead .tel{font-size:20px}}
</style><?= (string)cfg('track_js') ?></head><body><?php
}
function brand(): string { return '<a class="brand" href="?p=home"><img src="kcc_favicon.png" alt="">' . h(cfg('site')) . '<small>' . h(company_name()) . '</small></a>'; }
function layout_top(array $me, string $p, ?string $flash): void
{
    layout_head(cfg('site'));
    $nav = ['home' => '今日', 'call' => '続けて架電', 'lists' => 'リスト', 'targets' => '架電先', 'customers' => '顧客', 'deals' => '商談', 'stats' => '集計'];
    if ($me['role'] === 'admin') { $nav['settings'] = '設定'; }
    echo '<header class="top">' . brand() . '<nav>';
    foreach ($nav as $k => $v) { echo '<a href="?p=' . $k . '"' . ($p === $k || ($p === 'target' && $k === 'targets') || ($p === 'customer' && $k === 'customers') || ($p === 'deal' && $k === 'deals') ? ' class="on"' : '') . '>' . h($v) . '</a>'; }
    echo '</nav><div class="me">' . promo_links() . h($me['name']) . '<form method="post" style="margin:0"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="a" value="logout"><button class="btn sub sm">ログアウト</button></form></div></header><main>';
    if (cfg('demo')) {
        echo '<div class="demo">これはデモです。会社・人はすべて架空で、データは1日で元に戻ります。'
            . (cfg('buy_url') ? ' <a href="' . h(cfg('buy_url')) . '" target="_blank" rel="noopener"><b>自社のサーバーに置く一式はこちら</b></a>' : '') . '</div>';
    }
    if ($flash) { echo '<div class="flash">' . h($flash) . '</div>'; }
}
function layout_bottom(): void
{
    echo '</main><script>
document.querySelectorAll("[data-confirm]").forEach(f=>f.addEventListener("submit",e=>{if(!confirm(f.dataset.confirm))e.preventDefault()}));
document.querySelectorAll(".results input[type=radio]").forEach(r=>r.addEventListener("change",()=>{const n=document.getElementById("next_call_box");if(n){n.style.display=r.dataset.kind==="recall"||r.dataset.kind==="busy"?"block":"none";if(r.dataset.kind==="recall"&&!n.querySelector("input").value){const d=new Date(Date.now()+86400000);n.querySelector("input").value=d.toISOString().slice(0,10)+"T10:00"}}}));
</script></body></html>';
}
function promo_links(): string
{
    $l = (array)cfg('nav_links'); if (!$l) { return ''; }
    return '<span class="promo">' . implode('', array_map(fn($x) => '<a href="' . h($x[1]) . '" target="_blank" rel="noopener">' . h($x[0]) . '</a>', $l)) . '</span>';
}
function hidden(string $a, int $id = 0): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="a" value="' . h($a) . '">' . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : ''); }
function st(string $s): string { return '<span class="tag st-' . h($s) . '">' . h(status_label($s)) . '</span>'; }
function dt(?string $s): string { return $s ? h(substr(str_replace('T', ' ', $s), 0, 16)) : ''; }
function sel(string $name, array $opts, $cur, string $blank = ''): string
{
    $o = '<select name="' . h($name) . '">' . ($blank !== '' ? '<option value="">' . h($blank) . '</option>' : '');
    foreach ($opts as $k => $v) { $o .= '<option value="' . h((string)$k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . h($v) . '</option>'; }
    return $o . '</select>';
}
function user_opts(): array { $o = []; foreach (users() as $u) { $o[(int)$u['id']] = $u['name']; } return $o; }
function pager(array $r, array $keep): string
{
    if ($r['pages'] <= 1) { return '<div class="pager"><span class="muted">' . $r['total'] . '件</span></div>'; }
    $o = '<div class="pager"><span class="muted">' . number_format($r['total']) . '件</span>';
    $pages = array_unique(array_filter([1, $r['page'] - 2, $r['page'] - 1, $r['page'], $r['page'] + 1, $r['page'] + 2, $r['pages']], fn($x) => $x >= 1 && $x <= $r['pages']));
    $prev = null;
    foreach ($pages as $pg) { if ($prev !== null && $pg - $prev > 1) { $o .= '<span>…</span>'; } $o .= $pg === $r['page'] ? "<b>$pg</b>" : '<a href="?' . h(qs(array_merge($keep, ['page' => $pg]))) . "\">$pg</a>"; $prev = $pg; }
    return $o . '</div>';
}

/* ================= ログイン ================= */

function page_login(bool $noUsers, ?string $flash): void
{
    layout_head('テレアポ営業のCRM・SFA｜' . cfg('site') . (cfg('demo') ? '（デモ）' : ''));
    echo '<header class="top">' . brand() . '<div class="me">' . promo_links() . '</div></header>';
    echo '<main style="max-width:820px;padding-top:24px"><div class="panel"><div class="hero"><div><h1>' . (cfg('demo') ? 'テレアポ営業の、架電から商談まで' : h(cfg('site'))) . '</h1><p class="muted">' . h(cfg('site')) . (company_name() ? '（' . h(company_name()) . '）' : '') . '</p>';
    if ($flash) { echo '<div class="flash">' . h($flash) . '</div>'; }
    if (cfg('demo')) {
        echo '<p><b>Kurage テレアポCRM・SFA とは、</b>架電リストに架電先を取り込み、電話をかけて結果と次回コールを残し、アポが取れたら顧客にして商談をかんばんで追う、テレアポ営業のためのCRM・SFAです。担当ごとの架電数・通話数・アポ率を集計します。</p></div><img src="kcc_ogp.png" alt=""></div>';
        echo '<p class="muted">会社・人はすべて架空です。データは1日で元に戻ります。</p>';
        foreach (users() as $u) { echo '<form method="post" style="margin:0 0 8px">' . hidden('demo_login') . '<input type="hidden" name="uid" value="' . (int)$u['id'] . '"><button class="btn" style="width:100%">' . h($u['name']) . ' として試す</button></form>'; }
        if (cfg('buy_url')) { echo '<p style="margin:12px 0 0"><a href="' . h(cfg('buy_url')) . '" target="_blank" rel="noopener"><b>自社のサーバーに置く一式（Kurage App Store）</b></a></p>'; }
        echo '</div><div class="panel"><h2>できること</h2><ul style="margin:0;padding-left:1.2em;line-height:1.9">'
            . '<li>架電リストを CSV で取り込む（UTF-8／Shift_JIS、見出しで列を探す）。同じ電話番号・同じ社名＋郵便番号は重複として飛ばす</li>'
            . '<li>「続けて架電」で、期限の来た再コール→未着手の順に1件ずつ出す。結果・メモ・次回コールを残す</li>'
            . '<li>アポが取れたら1クリックで顧客に。活動の記録と商談（かんばん）を追う</li>'
            . '<li>担当ごと・日ごとの架電数・通話数・アポ数・アポ率</li>'
            . '<li>架電の結果の選択肢・商談のステージは設定で変えられる。CSV は EspoCRM／Krayin の列名で書き出せる。MCP 対応</li></ul></div>';
        echo '<div class="panel"><h2>よくある質問</h2>';
        foreach (login_faq() as [$q, $ans]) { echo '<details style="margin:0 0 8px"><summary style="font-weight:800;cursor:pointer">' . h($q) . '</summary><p style="margin:6px 0 0">' . h($ans) . '</p></details>'; }
        echo '</div>' . login_jsonld() . '</main></body></html>'; return;
    }
    echo '</div><img src="kcc_ogp.png" alt=""></div>';
    if ($noUsers) {
        echo '<h2>最初の管理者を作る</h2><form method="post">' . hidden('setup') . '<label>会社名</label><input name="company"><label>名前</label><input name="name"><label>メールアドレス</label><input name="email" type="email" required><label>パスワード（8文字以上）</label><input name="pass" type="password" required minlength="8"><p><button class="btn">作る</button></p></form>';
    } else {
        echo '<form method="post">' . hidden('login') . '<label>メールアドレス</label><input name="email" type="email" required autocomplete="username"><label>パスワード</label><input name="pass" type="password" required autocomplete="current-password"><p><button class="btn">ログイン</button></p></form>';
    }
    echo '</div></main></body></html>';
}
function login_faq(): array
{
    return [
        ['テレアポ営業向けのCRM・SFAは、ふつうのCRMと何が違いますか？', '架電リスト・架電先・架電の結果・次回コールを中心に作ってあります。1リスト9万件、全体で数十万件の架電先でも、一覧をサーバーで絞り込むので画面が重くなりません。アポが取れた先だけを顧客にして、商談を追います。'],
        ['月額はかかりますか？', 'かかりません。PHPとSQLiteだけで動き、レンタルサーバーにフォルダごと置けます。担当者が増えても追加料金はありません。'],
        ['既存のリストはどう入れますか？', 'CSVを取り込みます。UTF-8でもShift_JISでも、見出しの行から「会社名」「電話番号」などの列を探します。同じ電話番号（携帯以外）や、同じ社名＋郵便番号の架電先は重複として飛ばし、一覧にします。'],
        ['架電の結果の種類は変えられますか？', '変えられます。設定で、結果の名前と「再コール・アポ・見込み・不通・断り・対象外」のどれに当たるかを決めます。商談のステージも同じです。'],
        ['大きくなったら他のCRMに移れますか？', '移れます。CSVの列名を EspoCRM や Krayin（オープンソースのCRM）の Lead・Account・Call に合わせてあるので、そのまま取り込めます。'],
    ];
}
function login_jsonld(): string
{
    $u = (string)cfg('public_url'); if ($u === '') { return ''; }
    $app = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Kurage テレアポCRM・SFA', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web（PHP 8 + SQLite）',
            'url' => $u, 'image' => $u . 'kcc_ogp.png', 'description' => '架電リストに架電先を取り込み、電話の結果と次回コールを残し、アポが取れたら顧客にして商談を追う、テレアポ営業のCRM・SFA。',
            'offers' => ['@type' => 'Offer', 'price' => '220000', 'priceCurrency' => 'JPY'] + (cfg('buy_url') ? ['url' => (string)cfg('buy_url')] : []),
            'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/']];
    $faq = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], login_faq())];
    $o = ''; foreach ([$app, $faq] as $j) { $o .= '<script type="application/ld+json">' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    return $o;
}

/* ================= 今日 ================= */

function page_home(array $me): void
{
    $uid = (int)$me['id'];
    $st = stats(today(), today(), $uid); $tot = $st['total'];
    $due = targets_query(['due' => 1, 'mine_or_free' => $uid], 1, 20);
    $dueAll = (int)db()->query("SELECT COUNT(*) FROM call_targets WHERE deleted IS NULL AND status='recall' AND next_call <= '" . today() . " 23:59'")->fetchColumn();
    $news = (int)db()->query("SELECT COUNT(*) FROM call_targets WHERE deleted IS NULL AND status='new' AND (assignee_id=$uid OR assignee_id IS NULL)")->fetchColumn();
    $overdue = db()->query("SELECT d.*, c.company FROM deals d JOIN customers c ON c.id=d.customer_id WHERE d.deleted IS NULL AND d.closed IS NULL AND d.next_due < '" . today() . "' AND d.owner_id=$uid ORDER BY d.next_due LIMIT 10")->fetchAll();
    echo '<h1>今日　<span class="muted" style="font-weight:700">' . h(today()) . '　' . h($me['name']) . '</span></h1>';
    echo '<div class="grid4"><div class="kpi"><b>' . (int)$tot['calls'] . '</b><span>今日の架電</span></div><div class="kpi"><b>' . (int)$tot['talked'] . '</b><span>通話（不通以外）</span></div><div class="kpi"><b>' . (int)$tot['appo'] . '</b><span>アポ</span></div><div class="kpi"><b>' . $due['total'] . '</b><span>今日までの再コール（自分の担当と担当なし）</span></div></div>';
    echo '<p style="margin:14px 0"><a class="btn big" href="?p=call">続けて架電する →</a> <span class="muted">再コールの期限が来たもの' . $dueAll . '件、未着手' . number_format($news) . '件（自分の担当と担当なし）</span></p>';
    echo '<div class="grid2"><div class="panel"><h2>今日までの再コール</h2>';
    if (!$due['rows']) { echo '<p class="muted">ありません。</p>'; } else {
        echo '<table class="t"><tr><th>次回</th><th>会社</th><th>前回</th></tr>';
        foreach ($due['rows'] as $t) { echo '<tr><td>' . dt($t['next_call']) . '</td><td><a href="?p=target&id=' . (int)$t['id'] . '">' . h($t['company'] ?: $t['phone']) . '</a><br><span class="muted">' . h($t['phone']) . '</span></td><td class="muted">' . h($t['last_result']) . '</td></tr>'; }
        echo '</table>';
    }
    echo '</div><div class="panel"><h2>期日が過ぎた商談</h2>';
    if (!$overdue) { echo '<p class="muted">ありません。</p>'; } else {
        echo '<table class="t"><tr><th>期日</th><th>商談</th><th>次にやること</th></tr>';
        foreach ($overdue as $d) { echo '<tr><td><span class="tag over">' . h($d['next_due']) . '</span></td><td><a href="?p=deal&id=' . (int)$d['id'] . '">' . h($d['title']) . '</a><br><span class="muted">' . h($d['company']) . '</span></td><td>' . h($d['next_action']) . '</td></tr>'; }
        echo '</table>';
    }
    echo '</div></div>';
    echo '<div class="panel"><h2>リスト</h2>' . lists_table(lists()) . '</div>';
}

/* ================= リスト・取込 ================= */

function lists_table(array $ls): string
{
    if (!$ls) { return '<p class="muted">まだリストがありません。</p>'; }
    $o = '<div class="wide"><table class="t"><tr><th>リスト</th><th>区分</th><th>投入日</th><th>件数</th><th>未着手</th><th>再コール</th><th>アポ・顧客</th><th>見込み</th><th>断り・対象外</th><th></th></tr>';
    foreach ($ls as $l) {
        $c = $l['counts'];
        $o .= '<tr><td><a href="?p=targets&list_id=' . (int)$l['id'] . '"><b>' . h($l['name']) . '</b></a></td><td>' . h($l['kind']) . '</td><td>' . h($l['added_on']) . '</td><td>' . number_format($l['total']) . '</td><td>' . ($c['new'] ?? 0) . '</td><td>' . ($c['recall'] ?? 0) . '</td><td>' . (($c['appo'] ?? 0) + ($c['customer'] ?? 0)) . '</td><td>' . ($c['lead'] ?? 0) . '</td><td>' . (($c['ng'] ?? 0) + ($c['skip'] ?? 0)) . '</td>'
            . '<td class="row"><a class="btn sub sm" href="?p=call&list_id=' . (int)$l['id'] . '">続けて架電</a> <a class="btn sub sm" href="?p=import&list_id=' . (int)$l['id'] . '">CSV取込</a></td></tr>';
    }
    return $o . '</table></div>';
}
function page_lists(): void
{
    echo '<h1>架電リスト</h1><div class="grid2"><div class="panel">' . lists_table(lists()) . '</div>';
    echo '<div class="panel"><h2>リストを作る</h2><form method="post">' . hidden('new_list') . '<label>名前</label><input name="name" required placeholder="例：製造業（愛知）2026年11月"><label>区分</label><input name="kind" placeholder="例：購入リスト／展示会／WEB問い合わせ"><label>投入日</label><input name="added_on" type="date" value="' . today() . '"><label>メモ</label><textarea name="note"></textarea><p><button class="btn">作って、CSV を取り込む</button></p></form></div></div>';
}
function page_import(int $listId): void
{
    $l = call_list($listId);
    if (!$l) { echo '<p class="muted">リストを選んでください。</p>'; page_lists(); return; }
    $res = $_SESSION['import_result'] ?? null; if (!empty($_GET['done'])) { unset($_SESSION['import_result']); } else { $res = null; }
    echo '<h1>CSV 取込：' . h($l['name']) . '</h1><div class="grid2"><div class="panel">';
    echo '<form method="post" enctype="multipart/form-data">' . hidden('import') . '<input type="hidden" name="list_id" value="' . $listId . '"><label>CSV ファイル（UTF-8 か Shift_JIS。1行目は見出し）</label><input type="file" name="csv" accept=".csv,text/csv" required>'
        . '<p><label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="skip_dup" value="1" checked style="width:auto">同じ電話番号・同じ社名＋郵便番号の架電先は飛ばす</label></p>'
        . '<p class="row"><button class="btn sub" name="dry" value="1">まず確かめる（取り込まない）</button> <button class="btn">取り込む</button></p></form>';
    echo '<h3>見出しの例</h3><p class="muted">会社名, 担当者, 部署, 役職, 電話番号, 携帯, メール, 郵便番号, 都道府県, 住所, URL, 業種, 備考（英語の Account Name, Phone, Postal Code… も読めます。順番は自由で、無い列は空のままです）</p>';
    echo '</div><div class="panel"><h2>1件ずつ足す</h2><p><a class="btn sub" href="?p=new_target&list_id=' . $listId . '">架電先を1件足す</a></p><h2 style="margin-top:16px">このリストの状況</h2>' . lists_table(array_values(array_filter(lists(), fn($x) => (int)$x['id'] === $listId))) . '</div></div>';
    if ($res) {
        echo '<div class="panel"><h2>結果</h2><p>読んだ行 ' . $res['rows'] . '・追加 <b>' . $res['added'] . '</b>・重複で飛ばした <b>' . $res['dup'] . '</b>・空の行 ' . $res['empty'] . '　／　読めた列：' . h(implode('、', array_map(fn($c) => KCC_HEADERS[$c][0], $res['columns']))) . '</p>';
        if ($res['dups']) {
            echo '<div class="wide"><table class="t"><tr><th>行</th><th>CSV の会社</th><th>電話</th><th>同じとみなした相手</th></tr>';
            foreach ($res['dups'] as $d) { $w = $d['with']; echo '<tr><td>' . $d['row'] . '</td><td>' . h($d['company']) . '</td><td>' . h($d['phone']) . '</td><td>' . h($w['why']) . '：' . ($w['type'] === 'target' ? '<a href="?p=target&id=' . (int)$w['id'] . '">' : '<a href="?p=customer&id=' . (int)$w['id'] . '">') . h($w['company']) . '</a>（' . ($w['type'] === 'target' ? '架電先・' . status_label($w['status']) : '顧客') . '）</td></tr>'; }
            echo '</table></div>' . (count($res['dups']) >= 50 ? '<p class="muted">50件まで表示しています。</p>' : '');
        }
        echo '</div>';
    }
}

/* ================= 架電先 ================= */

function target_form(array $t, int $listId): string
{
    $f = fn($n, $l, $type = 'text') => '<label>' . $l . '</label><input name="' . $n . '" type="' . $type . '" value="' . h($t[$n] ?? '') . '">';
    return '<div class="grid2"><div>' . $f('company', '会社名') . $f('contact', '担当者') . $f('dept', '部署') . $f('title', '役職') . $f('phone', '電話番号') . $f('phone2', '携帯') . $f('email', 'メール') . '</div><div>'
        . $f('zip', '郵便番号') . $f('pref', '都道府県') . $f('addr', '住所') . $f('url', 'URL') . $f('industry', '業種') . '<label>担当</label>' . sel('assignee_id', user_opts(), $t['assignee_id'] ?? '', '（担当なし）') . '</div></div><label>備考</label><textarea name="note">' . h($t['note'] ?? '') . '</textarea>';
}
function page_new_target(int $listId): void
{
    $l = call_list($listId); if (!$l) { echo '<p class="muted">リストを選んでください。</p>'; page_lists(); return; }
    $pending = $_SESSION['pending_target'] ?? []; unset($_SESSION['pending_target']);
    echo '<h1>架電先を足す：' . h($l['name']) . '</h1><div class="panel">';
    if (!empty($_GET['dup']) && $pending) {
        $d = find_duplicates(keys_of($pending));
        echo '<div class="dupbox"><b>同じとみなせる架電先・顧客があります。</b>それでも足すなら、下の「重複を承知で足す」を押してください。<ul style="margin:6px 0 0">';
        foreach ($d as $w) { echo '<li>' . h($w['why']) . '：' . ($w['type'] === 'target' ? '<a href="?p=target&id=' . (int)$w['id'] . '">' : '<a href="?p=customer&id=' . (int)$w['id'] . '">') . h($w['company']) . '</a>（' . ($w['type'] === 'target' ? '架電先・' . status_label($w['status']) : '顧客') . '）</li>'; }
        echo '</ul></div>';
    }
    echo '<form method="post">' . hidden('new_target') . '<input type="hidden" name="list_id" value="' . $listId . '">' . target_form($pending, $listId) . '<p class="row"><button class="btn">足す</button>' . ($pending ? ' <button class="btn sub" name="force" value="1">重複を承知で足す</button>' : '') . '</p></form></div>';
}
function page_targets(): void
{
    $f = ['list_id' => (int)($_GET['list_id'] ?? 0), 'status' => (string)($_GET['status'] ?? ''), 'assignee_id' => (int)($_GET['assignee_id'] ?? 0), 'q' => (string)($_GET['q'] ?? ''), 'sort' => (string)($_GET['sort'] ?? 'seq'), 'dir' => (string)($_GET['dir'] ?? 'asc'), 'due' => (int)($_GET['due'] ?? 0)];
    $r = targets_query($f, (int)($_GET['page'] ?? 1));
    $l = $f['list_id'] ? call_list($f['list_id']) : null;
    $counts = target_counts(array_filter(['list_id' => $f['list_id'], 'assignee_id' => $f['assignee_id']]));
    echo '<h1>架電先' . ($l ? '：' . h($l['name']) : '') . '</h1>';
    if ($l) {
        echo '<div class="panel"><details' . (!empty($_GET['edit']) ? ' open' : '') . '><summary style="cursor:pointer;font-weight:800">リストの名前・区分・メモを直す</summary><form method="post" style="margin-top:8px">' . hidden('update_list', (int)$l['id']) . '<div class="grid3"><div><label>名前</label><input name="name" value="' . h($l['name']) . '"></div><div><label>区分</label><input name="kind" value="' . h($l['kind']) . '"></div><div><label>投入日</label><input name="added_on" type="date" value="' . h($l['added_on']) . '"></div></div><label>メモ</label><textarea name="note">' . h($l['note']) . '</textarea><p class="row"><button class="btn">保存</button></p></form>';
        if ((me()['role'] ?? '') === 'admin') { echo '<form method="post" data-confirm="このリストと、顧客になっていない架電先を消します。よろしいですか？" style="margin-top:6px">' . hidden('delete_list', (int)$l['id']) . '<button class="btn warn sm">このリストを消す</button></form>'; }
        echo '</details></div>';
    }
    echo '<div class="panel"><form method="get" class="filters"><input type="hidden" name="p" value="targets"><div><label>リスト</label>' . sel('list_id', array_column(lists(false), 'name', 'id'), $f['list_id'], 'すべて') . '</div><div><label>状態</label>' . sel('status', KCC_STATUS, $f['status'], 'すべて') . '</div><div><label>担当</label>' . sel('assignee_id', user_opts(), $f['assignee_id'], 'すべて') . '</div><div><label>会社名・担当者・電話・住所</label><input name="q" value="' . h($f['q']) . '"></div><div><label>&nbsp;</label><label style="display:inline-flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="due" value="1" style="width:auto"' . ($f['due'] ? ' checked' : '') . '>今日までの再コール</label></div><div><label>&nbsp;</label><button class="btn">絞り込む</button></div></form>';
    echo '<p class="row" style="margin:10px 0 0">'; foreach (KCC_STATUS as $k => $v) { echo '<a href="?' . h(qs(['status' => $k, 'page' => 1])) . '" style="text-decoration:none">' . st($k) . ' ' . ($counts[$k] ?? 0) . '</a> '; }
    echo ($l ? '<a class="btn sub sm" href="?p=call&list_id=' . (int)$l['id'] . '">このリストを続けて架電</a> <a class="btn sub sm" href="?p=new_target&list_id=' . (int)$l['id'] . '">1件足す</a> <a class="btn sub sm" href="?p=import&list_id=' . (int)$l['id'] . '">CSV取込</a>' : '') . ((me()['role'] ?? '') === 'admin' ? ' <a class="btn sub sm" href="?p=csv&what=targets' . ($l ? '&list_id=' . (int)$l['id'] : '') . '">CSV書き出し</a>' : '') . '</p></div>';
    $th = function (string $k, string $label) use ($f) { $d = ($f['sort'] === $k && $f['dir'] === 'asc') ? 'desc' : 'asc'; return '<th><a href="?' . h(qs(['sort' => $k, 'dir' => $d, 'page' => 1])) . '">' . $label . ($f['sort'] === $k ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '') . '</a></th>'; };
    echo pager($r, []) . '<div class="wide"><table class="t"><tr>' . $th('seq', '#') . $th('company', '会社') . '<th>担当者・電話</th><th>所在地</th>' . $th('status', '状態') . $th('next_call', '次回コール') . $th('last_call', '前回') . $th('call_count', '回数') . '<th>担当</th></tr>';
    foreach ($r['rows'] as $t) {
        echo '<tr><td class="muted">' . (int)$t['seq'] . '</td><td><a href="?p=target&id=' . (int)$t['id'] . '"><b>' . h($t['company'] ?: '（会社名なし）') . '</b></a>' . ($f['list_id'] ? '' : '<br><span class="muted">' . h($t['list_name']) . '</span>') . '</td><td>' . h($t['contact']) . '<br><span class="muted">' . h($t['phone']) . '</span></td><td class="muted">' . h($t['pref'] . ' ' . mb_strimwidth($t['addr'], 0, 24, '…')) . '</td><td>' . st($t['status']) . '</td><td>' . ($t['status'] === 'recall' && $t['next_call'] && $t['next_call'] < today() . ' 00:00' ? '<span class="tag over">' . dt($t['next_call']) . '</span>' : dt($t['next_call'])) . '</td><td class="muted">' . dt($t['last_call']) . '<br>' . h($t['last_result']) . '</td><td>' . (int)$t['call_count'] . '</td><td>' . h(user_name((int)$t['assignee_id'])) . '</td></tr>';
    }
    if (!$r['rows']) { echo '<tr><td colspan="9" class="muted">該当する架電先がありません。</td></tr>'; }
    echo '</table></div>' . pager($r, []);
}
function call_form(array $t, string $back = '', int $listId = 0): string
{
    $o = '<form method="post" data-busy>' . hidden('record_call', (int)$t['id']) . '<input type="hidden" name="back" value="' . h($back) . '"><input type="hidden" name="list_id" value="' . $listId . '"><label>結果</label><div class="results">';
    foreach (results() as $i => $r) { $o .= '<label><input type="radio" name="result" value="' . h($r['code']) . '" data-kind="' . h($r['kind']) . '" required>' . h($r['label']) . '</label>'; }
    $o .= '</div><div id="next_call_box" style="display:none"><label>次回コール（日時）</label><input name="next_call" type="datetime-local" value="' . h($t['next_call'] ? str_replace(' ', 'T', substr($t['next_call'], 0, 16)) : '') . '" style="max-width:260px"></div>';
    $o .= '<label>メモ（話した相手・内容・次にやること）</label><textarea name="memo" placeholder="例：総務の田中様。今は間に合っているが来期に検討。3月に再度"></textarea><p class="row"><button class="btn big">記録する' . ($back === 'call' ? '　→ 次へ' : '') . '</button></p></form>';
    return $o;
}
function page_target(int $id, array $me): void
{
    $t = target($id); if (!$t) { echo '<p class="muted">架電先が見つかりません。</p>'; return; }
    $l = call_list((int)$t['list_id']); $dups = find_duplicates(keys_of($t)); $dups = array_values(array_filter($dups, fn($d) => !($d['type'] === 'target' && (int)$d['id'] === $id) && !($d['type'] === 'customer' && (int)$d['id'] === (int)$t['customer_id'])));
    echo '<p class="muted"><a href="?p=targets&list_id=' . (int)$t['list_id'] . '">' . h($l['name'] ?? '') . '</a> › 架電先</p>';
    echo '<div class="panel"><div class="callhead"><span class="co">' . h($t['company'] ?: '（会社名なし）') . '</span><span class="tel"><a href="tel:' . h(digits($t['phone'])) . '">' . h($t['phone']) . '</a></span>' . st($t['status']) . '<span class="muted">' . h(trim($t['contact'] . ' ' . $t['dept'] . ' ' . $t['title'])) . '</span></div>';
    echo '<p class="muted" style="margin:6px 0 0">' . h(trim($t['zip'] . ' ' . $t['pref'] . ' ' . $t['addr'])) . ($t['url'] ? ' ／ <a href="' . h($t['url']) . '" target="_blank" rel="noopener">サイト</a>' : '') . ($t['industry'] ? ' ／ ' . h($t['industry']) : '') . '　担当：' . h(user_name((int)$t['assignee_id']) ?: 'なし') . '　架電 ' . (int)$t['call_count'] . '回' . ($t['next_call'] ? '　次回 ' . dt($t['next_call']) : '') . '</p>';
    if ($t['note']) { echo '<p style="white-space:pre-wrap;margin:6px 0 0">' . h($t['note']) . '</p>'; }
    if ($t['customer_id']) { echo '<p style="margin:8px 0 0"><a class="btn sub sm" href="?p=customer&id=' . (int)$t['customer_id'] . '">顧客ページへ →</a></p>'; }
    else { echo '<form method="post" style="margin:8px 0 0">' . hidden('promote', $id) . '<button class="btn sub sm">顧客にする（アポなしでも）</button></form>'; }
    if ($dups) { echo '<div class="dupbox" style="margin-top:10px"><b>同じとみなせる相手：</b>'; foreach ($dups as $d) { echo ($d['type'] === 'target' ? '<a href="?p=target&id=' . (int)$d['id'] . '">' : '<a href="?p=customer&id=' . (int)$d['id'] . '">') . h($d['company']) . '</a>（' . h($d['why']) . '・' . ($d['type'] === 'target' ? status_label($d['status']) : '顧客') . '）　'; } echo '</div>'; }
    echo '</div><div class="grid2"><div class="panel"><h2>架電の結果を記録する</h2>' . call_form($t) . '</div>';
    echo '<div class="panel"><h2>履歴</h2><ul class="tl">';
    foreach (calls_of_target($id) as $c) { echo '<li><time>' . dt($c['at']) . '</time><b>' . h($c['result']) . '</b> <span class="who">' . h(user_name((int)$c['user_id'])) . '</span>' . ($c['next_call'] ? ' <span class="muted">→ 次回 ' . dt($c['next_call']) . '</span>' : '') . '<div style="white-space:pre-wrap">' . h($c['memo']) . '</div></li>'; }
    if (!calls_of_target($id)) { echo '<li class="muted">まだ架電していません。</li>'; }
    echo '</ul></div></div>';
    echo '<div class="panel"><details><summary style="cursor:pointer;font-weight:800">架電先の情報を直す</summary><form method="post" style="margin-top:8px">' . hidden('update_target', $id) . target_form($t, (int)$t['list_id']) . '<label>状態</label>' . sel('status', KCC_STATUS, $t['status']) . '<label>次回コール</label><input name="next_call" type="datetime-local" value="' . h($t['next_call'] ? str_replace(' ', 'T', substr($t['next_call'], 0, 16)) : '') . '" style="max-width:260px"><p class="row"><button class="btn">保存</button></p></form>'
        . '<form method="post" data-confirm="この架電先を消します。よろしいですか？">' . hidden('delete_target', $id) . '<button class="btn warn sm">消す</button></form></details></div>';
}
function page_call(int $listId, array $me): void
{
    $t = next_target((int)$me['id'], $listId); $l = $listId ? call_list($listId) : null;
    echo '<h1>続けて架電' . ($l ? '：' . h($l['name']) : '') . '</h1>';
    if (!$t) { echo '<div class="panel"><p>いま架電できる先がありません（期限の来た再コールも、未着手の架電先も、自分の担当か担当なしの中にありません）。</p><p><a class="btn sub" href="?p=lists">リストを見る</a></p></div>'; return; }
    $why = $t['status'] === 'recall' ? '再コール（' . dt($t['next_call']) . '）' : '未着手';
    $l2 = call_list((int)$t['list_id']);
    echo '<div class="panel"><p class="muted">' . h($why) . '　／　' . h($l2['name'] ?? '') . '　／　<a href="?p=target&id=' . (int)$t['id'] . '">この架電先の詳しい画面</a></p>';
    echo '<div class="callhead"><span class="co">' . h($t['company'] ?: '（会社名なし）') . '</span><span class="tel"><a href="tel:' . h(digits($t['phone'])) . '">' . h($t['phone']) . '</a></span><span class="muted">' . h(trim($t['contact'] . ' ' . $t['dept'] . ' ' . $t['title'])) . '</span></div>';
    echo '<p class="muted" style="margin:6px 0 0">' . h(trim($t['pref'] . ' ' . $t['addr'])) . ($t['industry'] ? ' ／ ' . h($t['industry']) : '') . '　架電 ' . (int)$t['call_count'] . '回' . ($t['last_result'] ? '　前回：' . h($t['last_result']) : '') . '</p>' . ($t['note'] ? '<p style="white-space:pre-wrap;margin:6px 0 0">' . h($t['note']) . '</p>' : '');
    $hist = calls_of_target((int)$t['id']);
    if ($hist) { echo '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:800">これまでの' . count($hist) . '回</summary><ul class="tl" style="margin-top:6px">'; foreach (array_slice($hist, 0, 5) as $c) { echo '<li><time>' . dt($c['at']) . '</time><b>' . h($c['result']) . '</b> ' . h($c['memo']) . '</li>'; } echo '</ul></details>'; }
    echo '</div><div class="panel">' . call_form($t, 'call', $listId) . '<p class="muted">記録すると、次の架電先が出ます。飛ばしたいときは <a href="?p=target&id=' . (int)$t['id'] . '">詳しい画面</a>で担当や状態を変えてください。</p></div>';
}

/* ================= 顧客 ================= */

function customer_form(array $c): string
{
    $f = fn($n, $l) => '<label>' . $l . '</label><input name="' . $n . '" value="' . h($c[$n] ?? '') . '">';
    return '<div class="grid2"><div>' . $f('company', '会社名') . $f('contact', '担当者') . $f('dept', '部署') . $f('title', '役職') . $f('phone', '電話番号') . $f('email', 'メール') . '</div><div>' . $f('zip', '郵便番号') . $f('pref', '都道府県') . $f('addr', '住所') . $f('url', 'URL') . $f('industry', '業種') . '<label>営業担当</label>' . sel('owner_id', user_opts(), $c['owner_id'] ?? '', '（なし）') . '</div></div><label>備考</label><textarea name="note">' . h($c['note'] ?? '') . '</textarea>';
}
function page_customers(): void
{
    $f = ['q' => (string)($_GET['q'] ?? ''), 'owner_id' => (int)($_GET['owner_id'] ?? 0)];
    $r = customers_query($f, (int)($_GET['page'] ?? 1));
    echo '<h1>顧客</h1><div class="panel"><form method="get" class="filters"><input type="hidden" name="p" value="customers"><div><label>会社名・担当者・電話</label><input name="q" value="' . h($f['q']) . '"></div><div><label>営業担当</label>' . sel('owner_id', user_opts(), $f['owner_id'], 'すべて') . '</div><div><label>&nbsp;</label><button class="btn">絞り込む</button></div><div><label>&nbsp;</label><a class="btn sub" href="?p=new_customer">顧客を作る</a>' . ((me()['role'] ?? '') === 'admin' ? ' <a class="btn sub" href="?p=csv&what=customers">CSV書き出し</a>' : '') . '</div></form></div>';
    echo pager($r, []) . '<div class="wide"><table class="t"><tr><th>会社</th><th>担当者・電話</th><th>所在地</th><th>営業担当</th><th>更新</th></tr>';
    foreach ($r['rows'] as $c) { echo '<tr><td><a href="?p=customer&id=' . (int)$c['id'] . '"><b>' . h($c['company']) . '</b></a></td><td>' . h($c['contact']) . '<br><span class="muted">' . h($c['phone']) . '</span></td><td class="muted">' . h($c['pref'] . ' ' . mb_strimwidth($c['addr'], 0, 24, '…')) . '</td><td>' . h(user_name((int)$c['owner_id'])) . '</td><td class="muted">' . dt($c['updated']) . '</td></tr>'; }
    if (!$r['rows']) { echo '<tr><td colspan="5" class="muted">顧客がいません。架電でアポが取れると、ここに入ります。</td></tr>'; }
    echo '</table></div>' . pager($r, []);
}
function page_new_customer(): void
{
    echo '<h1>顧客を作る</h1><div class="panel"><form method="post">' . hidden('new_customer') . customer_form([]) . '<p><button class="btn">作る</button></p></form></div>';
}
function page_customer(int $id, array $me): void
{
    $c = customer($id); if (!$c) { echo '<p class="muted">顧客が見つかりません。</p>'; return; }
    echo '<p class="muted"><a href="?p=customers">顧客</a> › ' . h($c['company']) . '</p>';
    echo '<div class="panel"><div class="callhead"><span class="co">' . h($c['company']) . '</span><span class="tel"><a href="tel:' . h(digits($c['phone'])) . '">' . h($c['phone']) . '</a></span><span class="muted">' . h(trim($c['contact'] . ' ' . $c['dept'] . ' ' . $c['title'])) . '</span></div>';
    echo '<p class="muted" style="margin:6px 0 0">' . h(trim($c['zip'] . ' ' . $c['pref'] . ' ' . $c['addr'])) . ($c['email'] ? ' ／ ' . h($c['email']) : '') . ($c['url'] ? ' ／ <a href="' . h($c['url']) . '" target="_blank" rel="noopener">サイト</a>' : '') . '　営業担当：' . h(user_name((int)$c['owner_id']) ?: 'なし') . ($c['source_target_id'] ? '　<a href="?p=target&id=' . (int)$c['source_target_id'] . '">元の架電先</a>' : '') . '</p>' . ($c['note'] ? '<p style="white-space:pre-wrap;margin:6px 0 0">' . h($c['note']) . '</p>' : '') . '</div>';
    echo '<div class="grid2"><div><div class="panel"><h2>商談</h2>';
    $ds = deals_of($id);
    foreach ($ds as $d) { echo deal_card($d, $c['company']); }
    echo '<details' . ($ds ? '' : ' open') . '><summary style="cursor:pointer;font-weight:800">商談を作る</summary><form method="post" style="margin-top:8px">' . hidden('new_deal', $id) . deal_form([]) . '<p><button class="btn">作る</button></p></form></details></div>';
    echo '<div class="panel"><h2>活動を記録する</h2><form method="post">' . hidden('add_activity', $id) . '<div class="grid3"><div><label>種類</label>' . sel('kind', KCC_ACT_KINDS, 'call') . '</div><div><label>日時</label><input name="at" type="datetime-local" value="' . date('Y-m-d\TH:i') . '"></div><div><label>商談</label>' . sel('deal_id', array_column($ds, 'title', 'id'), '', '（なし）') . '</div></div><label>内容</label><textarea name="body" required></textarea><p><button class="btn">記録する</button></p></form></div></div>';
    echo '<div class="panel"><h2>活動の履歴</h2><ul class="tl">';
    $acts = activities_of($id);
    foreach ($acts as $x) { echo '<li><time>' . dt($x['at']) . '</time><b>' . h(KCC_ACT_KINDS[$x['kind']] ?? $x['kind']) . '</b> <span class="who">' . h(user_name((int)$x['user_id'])) . '</span><div style="white-space:pre-wrap">' . h($x['body']) . '</div></li>'; }
    if (!$acts) { echo '<li class="muted">まだありません。</li>'; }
    echo '</ul></div></div>';
    echo '<div class="panel"><details><summary style="cursor:pointer;font-weight:800">顧客の情報を直す</summary><form method="post" style="margin-top:8px">' . hidden('update_customer', $id) . customer_form($c) . '<p><button class="btn">保存</button></p></form></details></div>';
}

/* ================= 商談 ================= */

function deal_form(array $d): string
{
    return '<div class="grid2"><div><label>商談の名前</label><input name="title" value="' . h($d['title'] ?? '') . '" placeholder="例：受発注システム"><label>ステージ</label>' . sel('stage', array_combine(stages(), stages()), $d['stage'] ?? stages()[0]) . '<label>金額（円・任意）</label><input name="amount" value="' . h($d['amount'] ?? '') . '" inputmode="numeric"></div><div><label>受注見込み日</label><input name="expected_on" type="date" value="' . h($d['expected_on'] ?? '') . '"><label>営業担当</label>' . sel('owner_id', user_opts(), $d['owner_id'] ?? '', '（なし）') . '<label>次にやること</label><input name="next_action" value="' . h($d['next_action'] ?? '') . '"><label>その期日</label><input name="next_due" type="date" value="' . h($d['next_due'] ?? '') . '"></div></div>';
}
function deal_card(array $d, string $company = ''): string
{
    $tags = ($d['amount'] !== null ? '<span class="tag amt">' . yen($d['amount']) . '</span>' : '') . ($d['next_due'] && !$d['closed'] && $d['next_due'] < today() ? '<span class="tag over">期日切れ ' . h($d['next_due']) . '</span>' : '');
    return '<a class="card" draggable="true" data-id="' . (int)$d['id'] . '" href="?p=deal&id=' . (int)$d['id'] . '"><b>' . h($d['title']) . '</b><span class="co">' . h($company ?: ($d['company'] ?? '')) . '　' . h($d['stage']) . '</span>' . ($d['next_action'] ? '<div class="meta">次：' . h(mb_strimwidth($d['next_action'], 0, 60, '…')) . ($d['next_due'] ? '（' . h(substr($d['next_due'], 5)) . '）' : '') . '</div>' : '') . '<div class="meta">' . $tags . '<span>' . h(user_name((int)$d['owner_id'])) . '</span></div></a>';
}
function page_deals(array $me): void
{
    $mine = (int)($_GET['mine'] ?? 0); $ds = open_deals($mine ? (int)$me['id'] : null);
    $by = []; foreach ($ds as $d) { $by[$d['stage']][] = $d; }
    $sum = array_sum(array_map(fn($d) => (int)$d['amount'], $ds));
    echo '<h1>商談　<span class="muted" style="font-weight:700">' . count($ds) . '件・' . yen($sum) . '</span></h1><p class="row"><a class="btn sub sm" href="?p=deals' . ($mine ? '' : '&mine=1') . '">' . ($mine ? 'すべての商談' : '自分の商談だけ') . '</a></p>';
    echo '<div class="board">';
    foreach (stages() as $s) {
        $xs = $by[$s] ?? [];
        echo '<div class="col" data-stage="' . h($s) . '"><h3>' . h($s) . '<small>' . count($xs) . '件' . ($xs ? '・' . yen(array_sum(array_map(fn($d) => (int)$d['amount'], $xs))) : '') . '</small></h3>';
        foreach ($xs as $d) { echo deal_card($d); }
        echo '</div>';
    }
    echo '</div><p class="muted">カードを横の列へ動かすと、ステージが変わります（活動の履歴にも残ります）。</p>';
    echo '<script>const CSRF=' . json_encode(csrf()) . ';let drag=null;document.querySelectorAll(".card").forEach(c=>{c.addEventListener("dragstart",e=>{drag=c.dataset.id;e.dataTransfer.effectAllowed="move"});c.addEventListener("click",e=>{if(c.dataset.moved){e.preventDefault();delete c.dataset.moved}})});
document.querySelectorAll(".col").forEach(col=>{col.addEventListener("dragover",e=>{e.preventDefault();col.classList.add("over")});col.addEventListener("dragleave",()=>col.classList.remove("over"));col.addEventListener("drop",async e=>{e.preventDefault();col.classList.remove("over");if(!drag)return;const fd=new FormData();fd.append("csrf",CSRF);fd.append("a","stage");fd.append("id",drag);fd.append("stage",col.dataset.stage);const r=await fetch("?p=deals",{method:"POST",body:fd});if(r.ok){location.reload()}else{alert("動かせませんでした")}})});</script>';
}
function page_deal(int $id, array $me): void
{
    $d = deal($id); if (!$d) { echo '<p class="muted">商談が見つかりません。</p>'; return; }
    echo '<p class="muted"><a href="?p=deals">商談</a> › <a href="?p=customer&id=' . (int)$d['customer_id'] . '">' . h($d['company']) . '</a> › ' . h($d['title']) . '</p>';
    echo '<div class="grid2"><div class="panel"><h1 style="margin-top:0">' . h($d['title']) . '　<span class="tag amt">' . h($d['stage']) . '</span></h1><form method="post">' . hidden('update_deal', $id) . deal_form($d) . '<p><button class="btn">保存</button></p></form></div>';
    echo '<div class="panel"><h2>活動を記録する</h2><form method="post">' . hidden('add_activity', (int)$d['customer_id']) . '<input type="hidden" name="back" value="deal"><input type="hidden" name="deal_id" value="' . $id . '"><div class="grid2"><div><label>種類</label>' . sel('kind', KCC_ACT_KINDS, 'meeting') . '</div><div><label>日時</label><input name="at" type="datetime-local" value="' . date('Y-m-d\TH:i') . '"></div></div><label>内容</label><textarea name="body" required></textarea><p><button class="btn">記録する</button></p></form>';
    echo '<h2 style="margin-top:14px">この商談の活動</h2><ul class="tl">';
    $acts = array_filter(activities_of((int)$d['customer_id']), fn($x) => (int)$x['deal_id'] === $id);
    foreach ($acts as $x) { echo '<li><time>' . dt($x['at']) . '</time><b>' . h(KCC_ACT_KINDS[$x['kind']] ?? $x['kind']) . '</b> <span class="who">' . h(user_name((int)$x['user_id'])) . '</span><div style="white-space:pre-wrap">' . h($x['body']) . '</div></li>'; }
    if (!$acts) { echo '<li class="muted">まだありません。</li>'; }
    echo '</ul></div></div>';
}

/* ================= 集計 ================= */

function page_stats(array $me): void
{
    $from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime('-13 days')); $to = valid_date($_GET['to'] ?? null) ? $_GET['to'] : today();
    $uid = (int)($_GET['user_id'] ?? 0); $st = stats($from, $to, $uid ?: null); $tot = $st['total'];
    echo '<h1>集計</h1><div class="panel"><form method="get" class="filters"><input type="hidden" name="p" value="stats"><div><label>から</label><input type="date" name="from" value="' . h($from) . '"></div><div><label>まで</label><input type="date" name="to" value="' . h($to) . '"></div><div><label>担当</label>' . sel('user_id', user_opts(), $uid, 'すべて') . '</div><div><label>&nbsp;</label><button class="btn">集計</button></div>' . (($me['role'] ?? '') === 'admin' ? '<div><label>&nbsp;</label><a class="btn sub" href="?p=csv&what=calls">架電のCSV</a></div>' : '') . '</form></div>';
    echo '<div class="grid4"><div class="kpi"><b>' . (int)$tot['calls'] . '</b><span>架電</span></div><div class="kpi"><b>' . (int)$tot['talked'] . '</b><span>通話（不通・対象外以外）</span></div><div class="kpi"><b>' . (int)$tot['appo'] . '</b><span>アポ</span></div><div class="kpi"><b>' . rate($tot['appo'], $tot['talked']) . '</b><span>アポ率（アポ÷通話）</span></div></div>';
    echo '<div class="grid2"><div class="panel"><h2>担当ごと</h2><table class="t"><tr><th>担当</th><th>架電</th><th>通話</th><th>再コール</th><th>アポ</th><th>断り</th><th>アポ率</th></tr>';
    foreach ($st['users'] as $u) { echo '<tr><td>' . h(user_name((int)$u['user_id']) ?: '（不明）') . '</td><td>' . (int)$u['calls'] . '</td><td>' . (int)$u['talked'] . '</td><td>' . (int)$u['recall'] . '</td><td><b>' . (int)$u['appo'] . '</b></td><td>' . (int)$u['ng'] . '</td><td>' . rate($u['appo'], $u['talked']) . '</td></tr>'; }
    echo '</table></div><div class="panel"><h2>日ごと</h2><table class="t"><tr><th>日</th><th>架電</th><th>通話</th><th>再コール</th><th>アポ</th><th>断り</th><th>アポ率</th></tr>';
    foreach ($st['days'] as $d) { echo '<tr><td>' . h($d['d']) . '</td><td>' . (int)$d['calls'] . '</td><td>' . (int)$d['talked'] . '</td><td>' . (int)$d['recall'] . '</td><td><b>' . (int)$d['appo'] . '</b></td><td>' . (int)$d['ng'] . '</td><td>' . rate($d['appo'], $d['talked']) . '</td></tr>'; }
    if (!$st['days']) { echo '<tr><td colspan="7" class="muted">この期間の架電はありません。</td></tr>'; }
    echo '</table></div></div>';
    $lc = db()->query("SELECT l.name, COUNT(t.id) n, SUM(t.status IN ('appo','customer')) appo, SUM(t.status='new') new_ FROM call_lists l LEFT JOIN call_targets t ON t.list_id=l.id AND t.deleted IS NULL WHERE l.deleted IS NULL GROUP BY l.id ORDER BY l.id DESC")->fetchAll();
    echo '<div class="panel"><h2>リストごとのアポ率</h2><table class="t"><tr><th>リスト</th><th>件数</th><th>未着手</th><th>アポ・顧客</th><th>アポ率（件数あたり）</th></tr>';
    foreach ($lc as $l) { echo '<tr><td>' . h($l['name']) . '</td><td>' . (int)$l['n'] . '</td><td>' . (int)$l['new_'] . '</td><td><b>' . (int)$l['appo'] . '</b></td><td>' . rate($l['appo'], (int)$l['n'] - (int)$l['new_']) . '</td></tr>'; }
    echo '</table></div>';
}

/* ================= 設定 ================= */

function page_settings(bool $isAdmin): void
{
    if (!$isAdmin) { echo '<p class="muted">設定は管理者だけが見られます。</p>'; return; }
    echo '<h1>設定</h1><div class="grid2"><div class="panel"><form method="post">' . hidden('settings') . '<h2>会社名</h2><input name="company" value="' . h(company_name()) . '">';
    echo '<h2 style="margin-top:16px">架電の結果の選択肢</h2><p class="muted">名前は自由に。「扱い」が架電先の状態を決めます（再コールは次回コールの日時が必要）。空の行は消えます。</p><table class="t"><tr><th>名前</th><th>扱い</th><th>記号</th></tr>';
    $rs = results(); $rs[] = ['code' => '', 'label' => '', 'kind' => 'recall']; $rs[] = ['code' => '', 'label' => '', 'kind' => 'recall'];
    foreach ($rs as $i => $r) { echo '<tr><td><input name="r_label[' . $i . ']" value="' . h($r['label']) . '"></td><td>' . sel('r_kind[' . $i . ']', KCC_RESULT_KINDS, $r['kind']) . '</td><td><input name="r_code[' . $i . ']" value="' . h($r['code']) . '" placeholder="英数字" style="max-width:120px"></td></tr>'; }
    echo '</table><h2 style="margin-top:16px">商談のステージ</h2><p class="muted">1行に1つ。上から順に並びます。</p><textarea name="stages" style="min-height:140px">' . h(implode("\n", stages())) . '</textarea><label>終わりのステージ（カンマ区切り。かんばんの集計から外れます）</label><input name="closed" value="' . h(implode(',', closed_stages())) . '"><p><button class="btn">保存</button></p></form></div>';
    echo '<div class="panel"><h2>担当者</h2><table class="t"><tr><th>名前</th><th>メール</th><th>権限</th><th>最終ログイン</th><th></th></tr>';
    foreach (users(false) as $u) {
        echo '<tr><td>' . h($u['name']) . '</td><td class="muted">' . h($u['email']) . '</td><td>' . ($u['role'] === 'admin' ? '管理者' : '営業') . '</td><td class="muted">' . dt($u['last_login']) . '</td><td class="row"><form method="post">' . hidden('toggle_user', (int)$u['id']) . '<button class="btn sub sm">' . ($u['active'] ? '無効にする' : '有効にする') . '</button></form>'
            . '<form method="post" class="row">' . hidden('reset_pass', (int)$u['id']) . '<input name="pass" type="password" placeholder="新しいパスワード" style="width:150px" minlength="8"><button class="btn sub sm">変更</button></form></td></tr>';
    }
    echo '</table><h3 style="margin-top:14px">担当者を足す</h3><form method="post">' . hidden('new_user') . '<div class="grid2"><div><label>名前</label><input name="name" required><label>メールアドレス</label><input name="email" type="email" required></div><div><label>パスワード（8文字以上）</label><input name="pass" type="password" required minlength="8"><label>権限</label>' . sel('role', ['member' => '営業', 'admin' => '管理者'], 'member') . '</div></div><p><button class="btn">足す</button></p></form>';
    echo '<h2 style="margin-top:16px">書き出し・連携</h2><p class="row"><a class="btn sub sm" href="?p=csv&what=targets">架電先CSV</a> <a class="btn sub sm" href="?p=csv&what=customers">顧客CSV</a> <a class="btn sub sm" href="?p=csv&what=calls">架電CSV</a></p><p class="muted">列名は EspoCRM／Krayin の Lead・Account・Call に合わせています。API（api.php）と MCP（kcc_mcp.php）の使い方は README に書いてあります。</p>';
    echo '<p class="muted">バックアップ：毎日その日の最初の操作で kcc_data/backup/ に取り、' . (int)cfg('backup_days') . '日ぶん残します。版：' . KCC_VERSION . '</p></div></div>';
}
