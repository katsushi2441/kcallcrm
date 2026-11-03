<?php
/**
 * Kurage テレアポCRM・SFA（kcallcrm）— 共通部品（DB・ログイン・架電リスト・架電先・架電・顧客・商談・集計・CSV）。
 *
 * 架電リストに架電先を取り込み、電話をかけて結果と次回コールを残し、アポが取れたら顧客にして商談を追う。
 * PHP 8 と SQLite だけで動く。レンタルサーバーにフォルダごと置けば動く（常駐プロセス・ビルド・Node.js なし）。
 *
 * 守っている線：
 *  - 一覧はサーバーで絞り込み・並べ替え・ページ送り。架電先は1リスト9万件になっても、画面に全件を持たない。
 *  - 権限はサーバーで決める（管理者だけ：設定・担当者・リストの削除・CSV の書き出し）。
 *  - 消すのは論理削除。消した・変えた・取り込んだ・書き出したは oplog に残す。
 *  - 表記ゆれ（全角半角・株式会社・ハイフン）をそろえた値（k_*）で重複を見つける。携帯番号は重複の判定に使わない。
 *  - 項目名と CSV の列は EspoCRM／Krayin の Lead・Account・Call に合わせ、増えたらそちらへ CSV で移れるようにしておく。
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

const KCC_VERSION = '1.0.0';
const KCC_SCHEMA = 1;

$KCC = [
    'site'        => 'Kurage テレアポCRM・SFA',
    'company'     => '',                                   // 自社名（画面の上）
    'db'          => __DIR__ . '/kcc_data/kcallcrm.sqlite',
    'idle_min'    => 120,                                  // 無操作でログアウトする分
    'backup_days' => 30,                                   // 毎日のバックアップを残す日数
    'page_size'   => 50,
    'api_token'   => '',                                   // api.php の合言葉（空なら API は使えない）
    'demo'        => false,                                // true: ワンクリックで入れる・データを1日で初期化（当社の公開デモ用。本番は必ず false）
    'public_url'  => '',                                   // 公開デモのURL（空なら noindex）
    'buy_url'     => '',
    'nav_links'   => [],                                   // 右上の誘導リンク [['名前','URL'], …]（当社の公開デモ用）
    'track_js'    => '',
];
if (is_file(__DIR__ . '/kcc_config.php')) { $KCC = array_merge($KCC, (array)require __DIR__ . '/kcc_config.php'); }

// 架電の結果（設定で変えられる）。kind が架電先の状態を決める
const KCC_RESULT_KINDS = ['recall' => '再コール', 'appo' => 'アポ', 'lead' => '見込み', 'ng' => '断り', 'busy' => '不通', 'skip' => '対象外'];
const KCC_DEFAULT_RESULTS = [
    ['code' => 'appo',    'label' => 'アポ獲得',           'kind' => 'appo'],
    ['code' => 'recall',  'label' => '再コール',           'kind' => 'recall'],
    ['code' => 'lead',    'label' => '見込み（資料送付）',  'kind' => 'lead'],
    ['code' => 'busy',    'label' => '不在・不通',          'kind' => 'busy'],
    ['code' => 'ng',      'label' => '断り',                'kind' => 'ng'],
    ['code' => 'wrong',   'label' => '番号違い・対象外',    'kind' => 'skip'],
];
const KCC_STATUS = ['new' => '未着手', 'recall' => '再コール', 'appo' => 'アポ', 'lead' => '見込み', 'busy' => '不通', 'ng' => '断り', 'skip' => '対象外', 'customer' => '顧客'];
const KCC_DEFAULT_STAGES = ['アポ', '商談中', '提案', '見積', '受注', '失注'];
const KCC_CLOSED_STAGES = ['受注', '失注'];
const KCC_ACT_KINDS = ['call' => '電話', 'visit' => '訪問', 'mail' => 'メール', 'meeting' => '商談', 'other' => 'その他'];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cfg(string $k) { global $KCC; return $KCC[$k] ?? null; }
function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }
function yen($n): string { return $n === null || $n === '' ? '' : number_format((float)$n) . '円'; }
function valid_date(?string $d): bool { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)); }
function valid_dt(?string $d): bool { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $d) === 1 && valid_date(substr($d, 0, 10)); }
function dt_or_null($v): ?string { $v = trim(str_replace('T', ' ', (string)$v)); return valid_dt($v) ? (strlen($v) === 10 ? $v . ' 09:00' : substr($v, 0, 16)) : null; }
function status_label(string $s): string { return KCC_STATUS[$s] ?? $s; }

/* ================= 表記ゆれ（重複の判定） ================= */

/** 全角英数→半角、半角カナ→全角（intl が無いサーバーでは mb_convert_kana） */
function nfkc(?string $s): string
{
    $s = (string)$s;
    if (class_exists('Normalizer')) { return Normalizer::normalize($s, Normalizer::FORM_KC) ?: $s; }
    return mb_convert_kana($s, 'asKV', 'UTF-8');
}
/** 社名：株式会社／（株）などを外し、前株・後株を吸収して小文字に */
function company_key(?string $s): string
{
    $s = preg_replace('/\s/u', '', nfkc($s));
    $s = preg_replace('/\(株\)|株式会社|\(有\)|有限会社|\(合\)|合同会社|\(同\)|\(社\)|\(医\)|\(一社\)|一般社団法人/u', '', $s);
    $s = mb_convert_kana((string)$s, 'c', 'UTF-8');   // カタカナ→ひらがな（「ミナト工業」と「みなと工業」を同じに）
    return mb_strtolower($s);
}
function digits(?string $s): string { return preg_replace('/\D/u', '', nfkc($s)) ?? ''; }
function is_mobile(string $d): bool { return (bool)preg_match('/^0[789]0/', $d); }
/** 住所：丁目・番地・号と全角ハイフンをそろえ、都道府県と建物名（カッコ内）は外す */
function address_key(?string $s): string
{
    $a = preg_replace('/\s/u', '', nfkc($s));
    $a = preg_replace('/\(.*?\)/u', '', $a);
    $a = preg_replace_callback('/[〇一二三四五六七八九十]+/u', function ($m) {
        $k = ['〇' => 0, '一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        $s = $m[0];
        if ($s === '十') { return '10'; }
        if (preg_match('/^([一二三四五六七八九])?十([一二三四五六七八九])?$/u', $s, $t)) { return (string)(((($t[1] ?? '') !== '') ? $k[$t[1]] : 1) * 10 + ((($t[2] ?? '') !== '') ? $k[$t[2]] : 0)); }
        return implode('', array_map(fn($c) => $k[$c] ?? $c, mb_str_split($s)));
    }, $a);
    $a = preg_replace(['/(\d)ー(\d)/u', '/(\d)の(\d)/u'], '$1-$2', $a);
    $a = preg_replace(['/丁目|番地|番|号/u', '/[‐‑–—−－]/u'], '-', $a);
    $a = preg_replace(['/-+/', '/-$/'], ['-', ''], $a);
    return preg_replace('/^(北海道|東京都|京都府|大阪府|.{2,3}?県)/u', '', $a, 1);
}
function keys_of(array $t): array
{
    $ph = digits($t['phone'] ?? '');
    return ['k_name' => company_key($t['company'] ?? ''), 'k_phone' => is_mobile($ph) ? '' : $ph, 'k_zip' => digits($t['zip'] ?? ''), 'k_addr' => address_key($t['addr'] ?? '')];
}

/* ================= DB ================= */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) { return $pdo; }
    $f = (string)cfg('db');
    if (!is_dir(dirname($f))) { mkdir(dirname($f), 0770, true); }
    $new = !is_file($f);
    $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=8000; PRAGMA synchronous=NORMAL;');
    if ($new) { @chmod($f, 0600); }
    schema($pdo);
    if (cfg('demo')) { if ($new) { seed_demo($pdo); } else { demo_reset_if_old($pdo); } }
    return $pdo;
}

function schema(PDO $p): void
{
    $v = (int)$p->query('PRAGMA user_version')->fetchColumn();
    if ($v >= KCC_SCHEMA) { return; }
    $p->exec(<<<SQL
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT NOT NULL, updated TEXT);
CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, pass_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'member', active INTEGER NOT NULL DEFAULT 1,
  fail_count INTEGER NOT NULL DEFAULT 0, locked_until TEXT, last_login TEXT, created TEXT);
CREATE TABLE IF NOT EXISTS call_lists(
  id INTEGER PRIMARY KEY, name TEXT NOT NULL, kind TEXT NOT NULL DEFAULT '', added_on TEXT, note TEXT NOT NULL DEFAULT '',
  created_by INTEGER, created TEXT, updated TEXT, deleted TEXT);
CREATE TABLE IF NOT EXISTS call_targets(
  id INTEGER PRIMARY KEY, list_id INTEGER NOT NULL REFERENCES call_lists(id),
  company TEXT NOT NULL DEFAULT '', contact TEXT NOT NULL DEFAULT '', dept TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '', phone2 TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', zip TEXT NOT NULL DEFAULT '', pref TEXT NOT NULL DEFAULT '',
  addr TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', industry TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'new', assignee_id INTEGER, next_call TEXT, last_call TEXT, last_result TEXT NOT NULL DEFAULT '',
  call_count INTEGER NOT NULL DEFAULT 0, customer_id INTEGER,
  k_name TEXT NOT NULL DEFAULT '', k_phone TEXT NOT NULL DEFAULT '', k_zip TEXT NOT NULL DEFAULT '', k_addr TEXT NOT NULL DEFAULT '',
  seq INTEGER NOT NULL DEFAULT 0, created TEXT, updated TEXT, deleted TEXT);
CREATE INDEX IF NOT EXISTS ct_list ON call_targets(list_id, deleted, status, seq);
CREATE INDEX IF NOT EXISTS ct_assignee ON call_targets(assignee_id, deleted, status, next_call);
CREATE INDEX IF NOT EXISTS ct_next ON call_targets(deleted, status, next_call);
CREATE INDEX IF NOT EXISTS ct_kphone ON call_targets(k_phone);
CREATE INDEX IF NOT EXISTS ct_kname ON call_targets(k_name, k_zip);
CREATE INDEX IF NOT EXISTS ct_customer ON call_targets(customer_id);
CREATE TABLE IF NOT EXISTS calls(
  id INTEGER PRIMARY KEY, target_id INTEGER REFERENCES call_targets(id), customer_id INTEGER, user_id INTEGER,
  at TEXT NOT NULL, result TEXT NOT NULL, result_kind TEXT NOT NULL, memo TEXT NOT NULL DEFAULT '', next_call TEXT, created TEXT);
CREATE INDEX IF NOT EXISTS calls_target ON calls(target_id, at);
CREATE INDEX IF NOT EXISTS calls_at ON calls(at, user_id);
CREATE INDEX IF NOT EXISTS calls_customer ON calls(customer_id, at);
CREATE TABLE IF NOT EXISTS customers(
  id INTEGER PRIMARY KEY, company TEXT NOT NULL, contact TEXT NOT NULL DEFAULT '', dept TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', zip TEXT NOT NULL DEFAULT '', pref TEXT NOT NULL DEFAULT '', addr TEXT NOT NULL DEFAULT '',
  url TEXT NOT NULL DEFAULT '', industry TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', owner_id INTEGER, source_target_id INTEGER,
  k_name TEXT NOT NULL DEFAULT '', k_phone TEXT NOT NULL DEFAULT '', created TEXT, updated TEXT, deleted TEXT);
CREATE INDEX IF NOT EXISTS cu_kname ON customers(k_name);
CREATE INDEX IF NOT EXISTS cu_kphone ON customers(k_phone);
CREATE TABLE IF NOT EXISTS activities(
  id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES customers(id), deal_id INTEGER, user_id INTEGER,
  at TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'other', body TEXT NOT NULL, created TEXT);
CREATE INDEX IF NOT EXISTS act_customer ON activities(customer_id, at);
CREATE TABLE IF NOT EXISTS deals(
  id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES customers(id), title TEXT NOT NULL, stage TEXT NOT NULL,
  amount INTEGER, expected_on TEXT, owner_id INTEGER, next_action TEXT NOT NULL DEFAULT '', next_due TEXT,
  created TEXT, updated TEXT, closed TEXT, deleted TEXT);
CREATE INDEX IF NOT EXISTS deals_stage ON deals(deleted, stage);
CREATE INDEX IF NOT EXISTS deals_customer ON deals(customer_id);
CREATE TABLE IF NOT EXISTS oplog(
  id INTEGER PRIMARY KEY, at TEXT NOT NULL, user_id INTEGER, action TEXT NOT NULL, tbl TEXT, rid INTEGER, detail TEXT, ip TEXT);
CREATE INDEX IF NOT EXISTS oplog_at ON oplog(at);
SQL);
    $p->exec('PRAGMA user_version = ' . KCC_SCHEMA);
}

function meta(string $k, ?string $v = null): ?string
{
    if ($v !== null) { db()->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(?,?)')->execute([$k, $v]); return $v; }
    $s = db()->prepare('SELECT v FROM meta WHERE k=?'); $s->execute([$k]);
    $r = $s->fetchColumn(); return $r === false ? null : (string)$r;
}
$KCC_SETTING_CACHE = [];
function setting(string $k, $default = null)
{
    global $KCC_SETTING_CACHE;
    if (!array_key_exists($k, $KCC_SETTING_CACHE)) {
        $s = db()->prepare('SELECT v FROM settings WHERE k=?'); $s->execute([$k]);
        $r = $s->fetchColumn(); $KCC_SETTING_CACHE[$k] = $r === false ? null : json_decode((string)$r, true);
    }
    return $KCC_SETTING_CACHE[$k] ?? $default;
}
function set_setting(string $k, $v): void
{
    global $KCC_SETTING_CACHE;
    db()->prepare('INSERT OR REPLACE INTO settings(k,v,updated) VALUES(?,?,?)')->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE), now()]);
    unset($KCC_SETTING_CACHE[$k]);
}
function results(): array { return (array)setting('call_results', KCC_DEFAULT_RESULTS); }
function result_by_code(string $code): ?array { foreach (results() as $r) { if ($r['code'] === $code) { return $r; } } return null; }
function stages(): array { return (array)setting('deal_stages', KCC_DEFAULT_STAGES); }
function closed_stages(): array { return (array)setting('closed_stages', KCC_CLOSED_STAGES); }
function company_name(): string { return (string)(setting('company', cfg('company')) ?: cfg('company')); }

function oplog(?int $uid, string $action, ?string $tbl = null, ?int $rid = null, $detail = null): void
{
    db()->prepare('INSERT INTO oplog(at,user_id,action,tbl,rid,detail,ip) VALUES(?,?,?,?,?,?,?)')
        ->execute([now(), $uid, $action, $tbl, $rid, $detail === null ? null : (is_string($detail) ? mb_substr($detail, 0, 2000) : json_encode($detail, JSON_UNESCAPED_UNICODE)), $_SERVER['REMOTE_ADDR'] ?? '']);
}

/* ================= ログイン・セッション ================= */

function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; }
function session_start_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('kcallcrm');
    session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (!empty($_SESSION['uid']) && (time() - (int)($_SESSION['seen'] ?? 0)) > (int)cfg('idle_min') * 60) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
}
function csrf(): string { return (string)($_SESSION['csrf'] ?? ''); }
function csrf_ok(): bool { return isset($_POST['csrf']) && hash_equals(csrf(), (string)$_POST['csrf']); }
function norm_email(string $e): string { return strtolower(trim($e)); }
function me(): ?array
{
    if (empty($_SESSION['uid'])) { return null; }
    $s = db()->prepare('SELECT * FROM users WHERE id=? AND active=1'); $s->execute([(int)$_SESSION['uid']]);
    return $s->fetch() ?: null;
}
/** 続けて5回間違えたら15分受け付けない */
function try_login(string $email, string $pass): ?string
{
    usleep(250000);
    $s = db()->prepare('SELECT * FROM users WHERE email=?'); $s->execute([norm_email($email)]);
    $u = $s->fetch();
    if ($u && $u['locked_until'] && $u['locked_until'] > now()) { return 'ログインに続けて失敗したため、しばらく受け付けません。15分ほどおいてお試しください'; }
    if (!$u || !password_verify($pass, $u['pass_hash'])) {
        if ($u) { $n = (int)$u['fail_count'] + 1; db()->prepare('UPDATE users SET fail_count=?, locked_until=? WHERE id=?')->execute([$n >= 5 ? 0 : $n, $n >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $u['id']]); }
        oplog(null, 'login.fail', null, null, norm_email($email));
        return 'メールアドレスかパスワードが違います';
    }
    if (!$u['active']) { return 'このアカウントは無効になっています。管理者にお問い合わせください'; }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    db()->prepare('UPDATE users SET fail_count=0, locked_until=NULL, last_login=? WHERE id=?')->execute([now(), $u['id']]);
    oplog((int)$u['id'], 'login');
    return null;
}
function users(bool $activeOnly = true): array
{
    return db()->query('SELECT id,email,name,role,active,last_login FROM users' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY id')->fetchAll();
}
function user_name(?int $id): string
{
    static $m = null;
    if ($m === null) { $m = []; foreach (users(false) as $u) { $m[(int)$u['id']] = $u['name']; } }
    return $id ? ($m[$id] ?? '') : '';
}
function create_user(string $email, string $name, string $pass, string $role): int
{
    $email = norm_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('メールアドレスの形が正しくありません'); }
    if (mb_strlen($pass) < 8) { throw new InvalidArgumentException('パスワードは8文字以上にしてください'); }
    db()->prepare('INSERT INTO users(email,name,pass_hash,role,created) VALUES(?,?,?,?,?)')->execute([$email, trim($name) ?: $email, password_hash($pass, PASSWORD_DEFAULT), $role === 'admin' ? 'admin' : 'member', now()]);
    return (int)db()->lastInsertId();
}

/* ================= 架電リスト ================= */

function lists(bool $withCounts = true): array
{
    $rows = db()->query('SELECT * FROM call_lists WHERE deleted IS NULL ORDER BY id DESC')->fetchAll();
    if (!$withCounts) { return $rows; }
    $c = [];
    foreach (db()->query('SELECT list_id, status, COUNT(*) n FROM call_targets WHERE deleted IS NULL GROUP BY list_id, status') as $r) { $c[(int)$r['list_id']][$r['status']] = (int)$r['n']; }
    foreach ($rows as &$l) { $l['counts'] = $c[(int)$l['id']] ?? []; $l['total'] = array_sum($l['counts']); }
    return $rows;
}
function call_list(int $id): ?array { $s = db()->prepare('SELECT * FROM call_lists WHERE id=? AND deleted IS NULL'); $s->execute([$id]); return $s->fetch() ?: null; }
function create_list(string $name, string $kind, ?string $addedOn, string $note, int $uid): int
{
    $name = trim($name);
    if ($name === '') { throw new InvalidArgumentException('リストの名前を入れてください'); }
    db()->prepare('INSERT INTO call_lists(name,kind,added_on,note,created_by,created,updated) VALUES(?,?,?,?,?,?,?)')
        ->execute([$name, trim($kind), valid_date($addedOn) ? $addedOn : today(), trim($note), $uid, now(), now()]);
    $id = (int)db()->lastInsertId(); oplog($uid, 'list.create', 'call_lists', $id, $name); return $id;
}

/* ================= 架電先 ================= */

const KCC_TARGET_COLS = ['company', 'contact', 'dept', 'title', 'phone', 'phone2', 'email', 'zip', 'pref', 'addr', 'url', 'industry', 'note'];

function target(int $id): ?array { $s = db()->prepare('SELECT * FROM call_targets WHERE id=? AND deleted IS NULL'); $s->execute([$id]); return $s->fetch() ?: null; }

/** 架電先を足す。$check が true なら既存（同じ電話番号、または同じ社名＋郵便番号）を返して足さない */
function add_target(int $listId, array $in, int $uid, bool $check = false): array
{
    $t = []; foreach (KCC_TARGET_COLS as $c) { $t[$c] = trim((string)($in[$c] ?? '')); }
    if ($t['company'] === '' && $t['phone'] === '') { throw new InvalidArgumentException('会社名か電話番号は必要です'); }
    $k = keys_of($t);
    if ($check && ($dup = find_duplicates($k))) { return ['id' => 0, 'dup' => $dup]; }
    $seq = (int)db()->query("SELECT COALESCE(MAX(seq),0)+1 FROM call_targets WHERE list_id=" . $listId)->fetchColumn();
    db()->prepare('INSERT INTO call_targets(list_id,' . implode(',', KCC_TARGET_COLS) . ',assignee_id,k_name,k_phone,k_zip,k_addr,seq,created,updated) VALUES(?,' . str_repeat('?,', count(KCC_TARGET_COLS)) . '?,?,?,?,?,?,?,?)')
        ->execute(array_merge([$listId], array_values($t), [(int)($in['assignee_id'] ?? 0) ?: null, $k['k_name'], $k['k_phone'], $k['k_zip'], $k['k_addr'], $seq, now(), now()]));
    return ['id' => (int)db()->lastInsertId(), 'dup' => []];
}
function update_target(int $id, array $in, int $uid): void
{
    $t = target($id) ?: throw new RuntimeException('架電先が見つかりません');
    $set = []; $v = [];
    foreach (KCC_TARGET_COLS as $c) { if (array_key_exists($c, $in)) { $set[] = "$c=?"; $v[] = trim((string)$in[$c]); $t[$c] = trim((string)$in[$c]); } }
    if (array_key_exists('assignee_id', $in)) { $set[] = 'assignee_id=?'; $v[] = (int)$in['assignee_id'] ?: null; }
    if (array_key_exists('next_call', $in)) { $set[] = 'next_call=?'; $v[] = dt_or_null($in['next_call']); }
    if (array_key_exists('status', $in) && isset(KCC_STATUS[$in['status']])) { $set[] = 'status=?'; $v[] = $in['status']; }
    $k = keys_of($t); foreach ($k as $kk => $kv) { $set[] = "$kk=?"; $v[] = $kv; }
    $set[] = 'updated=?'; $v[] = now(); $v[] = $id;
    db()->prepare('UPDATE call_targets SET ' . implode(',', $set) . ' WHERE id=?')->execute($v);
    oplog($uid, 'target.update', 'call_targets', $id);
}
/** 同じ電話番号（携帯以外）か、同じ社名＋郵便番号の架電先・顧客 */
function find_duplicates(array $k, int $limit = 5): array
{
    $out = [];
    if ($k['k_phone'] !== '') {
        $s = db()->prepare('SELECT id,list_id,company,phone,status FROM call_targets WHERE k_phone=? AND deleted IS NULL LIMIT ?'); $s->execute([$k['k_phone'], $limit]);
        foreach ($s as $r) { $out[] = ['type' => 'target', 'why' => '電話番号'] + $r; }
        $s = db()->prepare('SELECT id,company,phone FROM customers WHERE k_phone=? AND deleted IS NULL LIMIT ?'); $s->execute([$k['k_phone'], $limit]);
        foreach ($s as $r) { $out[] = ['type' => 'customer', 'why' => '電話番号'] + $r; }
    }
    if ($k['k_name'] !== '' && $k['k_zip'] !== '') {
        $s = db()->prepare('SELECT id,list_id,company,phone,status FROM call_targets WHERE k_name=? AND k_zip=? AND deleted IS NULL LIMIT ?'); $s->execute([$k['k_name'], $k['k_zip'], $limit]);
        foreach ($s as $r) { if (!in_array(['target', (int)$r['id']], array_map(fn($o) => [$o['type'], (int)$o['id']], $out), true)) { $out[] = ['type' => 'target', 'why' => '社名＋郵便番号'] + $r; } }
    }
    return array_slice($out, 0, $limit);
}

/** 一覧（サーバーで絞り込み・並べ替え・ページ送り） */
function targets_query(array $f, int $page, int $per = 0): array
{
    $per = $per ?: (int)cfg('page_size');
    $w = ['t.deleted IS NULL']; $v = [];
    if (!empty($f['list_id'])) { $w[] = 't.list_id=?'; $v[] = (int)$f['list_id']; }
    if (!empty($f['status'])) { $w[] = 't.status=?'; $v[] = (string)$f['status']; }
    if (!empty($f['assignee_id'])) { $w[] = 't.assignee_id=?'; $v[] = (int)$f['assignee_id']; }
    if (!empty($f['mine_or_free'])) { $w[] = '(t.assignee_id=? OR t.assignee_id IS NULL)'; $v[] = (int)$f['mine_or_free']; }   // 自分の担当と担当なし（「続けて架電」と同じ範囲）
    if (!empty($f['pref'])) { $w[] = 't.pref=?'; $v[] = (string)$f['pref']; }
    if (!empty($f['q'])) {
        $q = trim((string)$f['q']); $d = digits($q);
        if ($d !== '' && strlen($d) >= 4 && preg_match('/^[\d\-\(\)\s]+$/', nfkc($q))) { $w[] = 't.k_phone LIKE ?'; $v[] = '%' . $d . '%'; }
        else { $w[] = '(t.company LIKE ? OR t.contact LIKE ? OR t.addr LIKE ? OR t.note LIKE ? OR t.k_name LIKE ?)'; $like = '%' . $q . '%'; array_push($v, $like, $like, $like, $like, '%' . company_key($q) . '%'); }
    }
    if (!empty($f['due'])) { $w[] = "t.status='recall' AND t.next_call <= ?"; $v[] = today() . ' 23:59'; }
    $sorts = ['seq' => 't.seq', 'company' => 't.k_name', 'next_call' => 't.next_call', 'last_call' => 't.last_call', 'call_count' => 't.call_count', 'updated' => 't.updated', 'status' => 't.status'];
    $sort = $sorts[$f['sort'] ?? 'seq'] ?? 't.seq';
    $dir = (($f['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
    $where = implode(' AND ', $w);
    $cs = db()->prepare("SELECT COUNT(*) FROM call_targets t WHERE $where"); $cs->execute($v); $total = (int)$cs->fetchColumn();
    $page = max(1, $page);
    $s = db()->prepare("SELECT t.*, l.name list_name FROM call_targets t JOIN call_lists l ON l.id=t.list_id WHERE $where ORDER BY $sort $dir, t.id LIMIT ? OFFSET ?");
    $s->execute(array_merge($v, [$per, ($page - 1) * $per]));
    return ['rows' => $s->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per)), 'per' => $per];
}
function target_counts(array $f = []): array
{
    $w = ['deleted IS NULL']; $v = [];
    if (!empty($f['list_id'])) { $w[] = 'list_id=?'; $v[] = (int)$f['list_id']; }
    if (!empty($f['assignee_id'])) { $w[] = 'assignee_id=?'; $v[] = (int)$f['assignee_id']; }
    $s = db()->prepare('SELECT status, COUNT(*) n FROM call_targets WHERE ' . implode(' AND ', $w) . ' GROUP BY status'); $s->execute($v);
    $c = []; foreach ($s as $r) { $c[$r['status']] = (int)$r['n']; } return $c;
}
/** 「続けて架電」：期限の来た再コール → 未着手（自分の担当か担当なし）の順で次の1件 */
function next_target(int $uid, int $listId = 0): ?array
{
    $lw = $listId ? ' AND list_id=' . $listId : '';
    $s = db()->prepare("SELECT * FROM call_targets WHERE deleted IS NULL AND status='recall' AND next_call <= ? AND (assignee_id=? OR assignee_id IS NULL)$lw ORDER BY next_call LIMIT 1");
    $s->execute([date('Y-m-d H:i'), $uid]);
    if ($r = $s->fetch()) { return $r; }
    $s = db()->prepare("SELECT * FROM call_targets WHERE deleted IS NULL AND status='new' AND (assignee_id=? OR assignee_id IS NULL)$lw ORDER BY list_id DESC, seq LIMIT 1");
    $s->execute([$uid]);
    return $s->fetch() ?: null;
}

/* ================= 架電の記録 ================= */

/** 結果を残す。状態・次回コール・回数を更新し、アポなら顧客を作る */
function record_call(int $targetId, string $code, string $memo, ?string $nextCall, int $uid, ?string $at = null): array
{
    $t = target($targetId) ?: throw new RuntimeException('架電先が見つかりません');
    $r = result_by_code($code) ?: throw new InvalidArgumentException('結果を選んでください');
    $kind = $r['kind']; $next = dt_or_null($nextCall);
    if ($kind === 'recall' && !$next) { throw new InvalidArgumentException('再コールのときは、次回コールの日時を入れてください'); }
    $at = valid_dt((string)$at) ? substr((string)$at, 0, 16) : date('Y-m-d H:i');
    $p = db(); $p->beginTransaction();
    try {
        $p->prepare('INSERT INTO calls(target_id,customer_id,user_id,at,result,result_kind,memo,next_call,created) VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$targetId, $t['customer_id'] ?: null, $uid, $at, $r['label'], $kind, trim($memo), $next, now()]);
        $callId = (int)$p->lastInsertId();
        $status = ($t['status'] === 'customer' && $kind !== 'ng') ? 'customer' : ($kind === 'skip' ? 'skip' : $kind);
        $custId = (int)$t['customer_id'];
        if ($kind === 'appo' && !$custId) { $custId = promote_target($targetId, $uid, false); $status = 'customer'; }
        if ($custId) {
            $p->prepare('INSERT INTO activities(customer_id,user_id,at,kind,body,created) VALUES(?,?,?,?,?,?)')->execute([$custId, $uid, $at, 'call', '【' . $r['label'] . '】' . trim($memo), now()]);
        }
        $p->prepare('UPDATE call_targets SET status=?, next_call=?, last_call=?, last_result=?, call_count=call_count+1, assignee_id=COALESCE(assignee_id,?), customer_id=?, updated=? WHERE id=?')
          ->execute([$status, $kind === 'recall' ? $next : ($kind === 'busy' ? $next : null), $at, $r['label'], $uid, $custId ?: null, now(), $targetId]);
        $p->commit();
    } catch (Throwable $e) { $p->rollBack(); throw $e; }
    oplog($uid, 'call.record', 'calls', $callId, $r['label']);
    return ['call_id' => $callId, 'customer_id' => $custId ?: null, 'status' => $status];
}
function calls_of_target(int $id): array { $s = db()->prepare('SELECT * FROM calls WHERE target_id=? ORDER BY at DESC, id DESC'); $s->execute([$id]); return $s->fetchAll(); }

/* ================= 顧客 ================= */

const KCC_CUSTOMER_COLS = ['company', 'contact', 'dept', 'title', 'phone', 'email', 'zip', 'pref', 'addr', 'url', 'industry', 'note'];

function customer(int $id): ?array { $s = db()->prepare('SELECT * FROM customers WHERE id=? AND deleted IS NULL'); $s->execute([$id]); return $s->fetch() ?: null; }
function customers_query(array $f, int $page, int $per = 0): array
{
    $per = $per ?: (int)cfg('page_size'); $w = ['deleted IS NULL']; $v = [];
    if (!empty($f['q'])) { $q = trim((string)$f['q']); $w[] = '(company LIKE ? OR contact LIKE ? OR k_name LIKE ? OR k_phone LIKE ?)'; array_push($v, "%$q%", "%$q%", '%' . company_key($q) . '%', '%' . digits($q) . '%'); }
    if (!empty($f['owner_id'])) { $w[] = 'owner_id=?'; $v[] = (int)$f['owner_id']; }
    $where = implode(' AND ', $w);
    $cs = db()->prepare("SELECT COUNT(*) FROM customers WHERE $where"); $cs->execute($v); $total = (int)$cs->fetchColumn();
    $s = db()->prepare("SELECT * FROM customers WHERE $where ORDER BY updated DESC, id DESC LIMIT ? OFFSET ?"); $s->execute(array_merge($v, [$per, (max(1, $page) - 1) * $per]));
    return ['rows' => $s->fetchAll(), 'total' => $total, 'page' => max(1, $page), 'pages' => max(1, (int)ceil($total / $per)), 'per' => $per];
}
function create_customer(array $in, int $uid, ?int $fromTarget = null): int
{
    $c = []; foreach (KCC_CUSTOMER_COLS as $k) { $c[$k] = trim((string)($in[$k] ?? '')); }
    if ($c['company'] === '') { throw new InvalidArgumentException('会社名を入れてください'); }
    $k = keys_of($c);
    db()->prepare('INSERT INTO customers(' . implode(',', KCC_CUSTOMER_COLS) . ',owner_id,source_target_id,k_name,k_phone,created,updated) VALUES(' . str_repeat('?,', count(KCC_CUSTOMER_COLS)) . '?,?,?,?,?,?)')
        ->execute(array_merge(array_values($c), [(int)($in['owner_id'] ?? 0) ?: $uid, $fromTarget, $k['k_name'], $k['k_phone'], now(), now()]));
    $id = (int)db()->lastInsertId(); oplog($uid, 'customer.create', 'customers', $id, $c['company']); return $id;
}
function update_customer(int $id, array $in, int $uid): void
{
    $c = customer($id) ?: throw new RuntimeException('顧客が見つかりません');
    $set = []; $v = [];
    foreach (KCC_CUSTOMER_COLS as $k) { if (array_key_exists($k, $in)) { $set[] = "$k=?"; $v[] = trim((string)$in[$k]); $c[$k] = trim((string)$in[$k]); } }
    if (array_key_exists('owner_id', $in)) { $set[] = 'owner_id=?'; $v[] = (int)$in['owner_id'] ?: null; }
    $k = keys_of($c); $set[] = 'k_name=?'; $v[] = $k['k_name']; $set[] = 'k_phone=?'; $v[] = $k['k_phone'];
    $set[] = 'updated=?'; $v[] = now(); $v[] = $id;
    db()->prepare('UPDATE customers SET ' . implode(',', $set) . ' WHERE id=?')->execute($v);
    oplog($uid, 'customer.update', 'customers', $id);
}
/** 架電先を顧客にする（架電先はそのまま残し、customer_id でつなぐ） */
function promote_target(int $targetId, int $uid, bool $log = true): int
{
    $t = target($targetId) ?: throw new RuntimeException('架電先が見つかりません');
    if ($t['customer_id']) { return (int)$t['customer_id']; }
    $in = []; foreach (KCC_CUSTOMER_COLS as $k) { $in[$k] = $t[$k] ?? ''; }
    $in['owner_id'] = $t['assignee_id'] ?: $uid;
    if ($in['company'] === '') { $in['company'] = $t['contact'] ?: ('電話 ' . $t['phone']); }
    $cid = create_customer($in, $uid, $targetId);
    db()->prepare("UPDATE call_targets SET customer_id=?, status='customer', updated=? WHERE id=?")->execute([$cid, now(), $targetId]);
    if ($log) { oplog($uid, 'target.promote', 'call_targets', $targetId, $cid); }
    return $cid;
}
function add_activity(int $customerId, ?int $dealId, string $kind, string $body, ?string $at, int $uid): int
{
    $body = trim($body); if ($body === '') { throw new InvalidArgumentException('内容を入れてください'); }
    db()->prepare('INSERT INTO activities(customer_id,deal_id,user_id,at,kind,body,created) VALUES(?,?,?,?,?,?,?)')
        ->execute([$customerId, $dealId ?: null, $uid, dt_or_null($at) ?? date('Y-m-d H:i'), isset(KCC_ACT_KINDS[$kind]) ? $kind : 'other', $body, now()]);
    db()->prepare('UPDATE customers SET updated=? WHERE id=?')->execute([now(), $customerId]);
    if ($dealId) { db()->prepare('UPDATE deals SET updated=? WHERE id=?')->execute([now(), $dealId]); }
    return (int)db()->lastInsertId();
}
function activities_of(int $customerId): array { $s = db()->prepare('SELECT * FROM activities WHERE customer_id=? ORDER BY at DESC, id DESC'); $s->execute([$customerId]); return $s->fetchAll(); }

/* ================= 商談 ================= */

function deal(int $id): ?array { $s = db()->prepare('SELECT d.*, c.company FROM deals d JOIN customers c ON c.id=d.customer_id WHERE d.id=? AND d.deleted IS NULL'); $s->execute([$id]); return $s->fetch() ?: null; }
function deals_of(int $customerId): array { $s = db()->prepare('SELECT * FROM deals WHERE customer_id=? AND deleted IS NULL ORDER BY id DESC'); $s->execute([$customerId]); return $s->fetchAll(); }
function open_deals(?int $ownerId = null): array
{
    $w = 'd.deleted IS NULL AND d.closed IS NULL' . ($ownerId ? ' AND d.owner_id=' . (int)$ownerId : '');
    return db()->query("SELECT d.*, c.company FROM deals d JOIN customers c ON c.id=d.customer_id WHERE $w ORDER BY d.updated DESC")->fetchAll();
}
function create_deal(int $customerId, array $in, int $uid): int
{
    customer($customerId) ?: throw new RuntimeException('顧客が見つかりません');
    $title = trim((string)($in['title'] ?? '')) ?: '商談';
    $stage = in_array($in['stage'] ?? '', stages(), true) ? $in['stage'] : stages()[0];
    $amount = trim((string)($in['amount'] ?? '')) === '' ? null : (int)preg_replace('/[^\d]/', '', (string)$in['amount']);
    db()->prepare('INSERT INTO deals(customer_id,title,stage,amount,expected_on,owner_id,next_action,next_due,created,updated,closed) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$customerId, $title, $stage, $amount, valid_date($in['expected_on'] ?? null) ? $in['expected_on'] : null, (int)($in['owner_id'] ?? 0) ?: $uid,
                   trim((string)($in['next_action'] ?? '')), valid_date($in['next_due'] ?? null) ? $in['next_due'] : null, now(), now(), in_array($stage, closed_stages(), true) ? now() : null]);
    $id = (int)db()->lastInsertId(); oplog($uid, 'deal.create', 'deals', $id, $title); return $id;
}
function update_deal(int $id, array $in, int $uid): void
{
    $d = deal($id) ?: throw new RuntimeException('商談が見つかりません');
    $amount = trim((string)($in['amount'] ?? '')) === '' ? null : (int)preg_replace('/[^\d]/', '', (string)$in['amount']);
    db()->prepare('UPDATE deals SET title=?,amount=?,expected_on=?,owner_id=?,next_action=?,next_due=?,updated=? WHERE id=?')
        ->execute([trim((string)($in['title'] ?? '')) ?: $d['title'], $amount, valid_date($in['expected_on'] ?? null) ? $in['expected_on'] : null, (int)($in['owner_id'] ?? 0) ?: null,
                   trim((string)($in['next_action'] ?? '')), valid_date($in['next_due'] ?? null) ? $in['next_due'] : null, now(), $id]);
    if (isset($in['stage'])) { set_stage($id, (string)$in['stage'], $uid); }
    oplog($uid, 'deal.update', 'deals', $id);
}
function set_stage(int $id, string $stage, int $uid): void
{
    if (!in_array($stage, stages(), true)) { throw new InvalidArgumentException('そのステージはありません'); }
    $d = deal($id) ?: throw new RuntimeException('商談が見つかりません');
    if ($d['stage'] === $stage) { return; }
    db()->prepare('UPDATE deals SET stage=?, closed=?, updated=? WHERE id=?')->execute([$stage, in_array($stage, closed_stages(), true) ? now() : null, now(), $id]);
    add_activity((int)$d['customer_id'], $id, 'other', 'ステージ：' . $d['stage'] . ' → ' . $stage, null, $uid);
    oplog($uid, 'deal.stage', 'deals', $id, $stage);
}

/* ================= 集計 ================= */

/** 日ごと・担当ごとの架電数・通話（不通以外）・アポ・アポ率 */
function stats(string $from, string $to, ?int $userId = null): array
{
    $w = 'at >= ? AND at <= ?' . ($userId ? ' AND user_id=' . (int)$userId : ''); $v = [$from . ' 00:00', $to . ' 23:59'];
    $byDay = db()->prepare("SELECT substr(at,1,10) d, COUNT(*) calls, SUM(result_kind NOT IN ('busy','skip')) talked, SUM(result_kind='appo') appo, SUM(result_kind='recall') recall, SUM(result_kind='ng') ng FROM calls WHERE $w GROUP BY d ORDER BY d");
    $byDay->execute($v);
    $byUser = db()->prepare("SELECT user_id, COUNT(*) calls, SUM(result_kind NOT IN ('busy','skip')) talked, SUM(result_kind='appo') appo, SUM(result_kind='recall') recall, SUM(result_kind='ng') ng FROM calls WHERE $w GROUP BY user_id ORDER BY calls DESC");
    $byUser->execute($v);
    $tot = db()->prepare("SELECT COUNT(*) calls, SUM(result_kind NOT IN ('busy','skip')) talked, SUM(result_kind='appo') appo FROM calls WHERE $w"); $tot->execute($v);
    return ['days' => $byDay->fetchAll(), 'users' => $byUser->fetchAll(), 'total' => $tot->fetch()];
}
function rate($a, $b): string { return (int)$b ? round((int)$a * 100 / (int)$b, 1) . '%' : '-'; }

/* ================= CSV ================= */

/** 列の見出し（日本語／EspoCRM・Krayin の英語）→ 項目。取込で使う */
const KCC_HEADERS = [
    'company' => ['会社名', '社名', '企業名', '法人名', '会社', 'Account Name', 'Company', 'accountName', 'company', 'organization'],
    'contact' => ['担当者', '担当者名', '氏名', '名前', '担当', 'Name', 'Contact', 'name', 'person'],
    'dept'    => ['部署', '部門', 'Department', 'department'],
    'title'   => ['役職', 'Title', 'title'],
    'phone'   => ['電話番号', '電話', 'TEL', 'Tel', 'tel', 'Phone', 'phone', 'phoneNumber', 'Phone Number'],
    'phone2'  => ['電話番号2', '携帯', '携帯電話', 'Mobile', 'mobile'],
    'email'   => ['メール', 'メールアドレス', 'Email', 'email', 'emailAddress', 'E-mail'],
    'zip'     => ['郵便番号', '〒', 'Postal Code', 'postalCode', 'zip', 'addressPostalCode'],
    'pref'    => ['都道府県', 'State', 'addressState', 'prefecture'],
    'addr'    => ['住所', '所在地', 'Address', 'address', 'addressStreet', 'Street'],
    'url'     => ['URL', 'ホームページ', 'Website', 'website', 'url'],
    'industry'=> ['業種', 'Industry', 'industry'],
    'note'    => ['備考', 'メモ', 'Description', 'description', 'note', 'notes'],
];
/** CSV の文字コードをそろえて行に分ける（UTF-8 / Shift_JIS、BOM あり／なし） */
function csv_rows(string $raw): array
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) { $raw = substr($raw, 3); }
    if (!mb_check_encoding($raw, 'UTF-8')) { $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win'); }
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $rows = []; $h = fopen('php://temp', 'r+'); fwrite($h, $raw); rewind($h);
    while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) { if (count($r) === 1 && trim((string)$r[0]) === '') { continue; } $rows[] = $r; }
    fclose($h); return $rows;
}
function csv_map_header(array $head): array
{
    $map = [];
    foreach ($head as $i => $name) {
        $n = trim(nfkc((string)$name));
        foreach (KCC_HEADERS as $col => $names) { if (in_array($n, $names, true) || in_array(mb_strtolower($n), array_map('mb_strtolower', $names), true)) { if (!isset($map[$col])) { $map[$col] = $i; } break; } }
    }
    return $map;
}
/** 取込。$dryRun なら数えるだけ。戻り：追加・重複（飛ばした）・空行 */
function import_csv(int $listId, string $raw, int $uid, bool $skipDup = true, bool $dryRun = false): array
{
    $rows = csv_rows($raw);
    if (count($rows) < 2) { throw new InvalidArgumentException('見出しの行と、1行以上のデータが必要です'); }
    $map = csv_map_header($rows[0]);
    if (!isset($map['company']) && !isset($map['phone'])) { throw new InvalidArgumentException('見出しに「会社名」か「電話番号」の列が見つかりません（見出し：' . implode('、', array_slice($rows[0], 0, 8)) . '）'); }
    $added = $dup = $empty = 0; $dups = []; $p = db();
    if (!$dryRun) { $p->beginTransaction(); }
    try {
        foreach (array_slice($rows, 1) as $i => $r) {
            $in = []; foreach ($map as $col => $idx) { $in[$col] = trim((string)($r[$idx] ?? '')); }
            if (($in['company'] ?? '') === '' && ($in['phone'] ?? '') === '') { $empty++; continue; }
            $k = keys_of($in); $d = $skipDup ? find_duplicates($k, 1) : [];
            if ($d) { $dup++; if (count($dups) < 50) { $dups[] = ['row' => $i + 2, 'company' => $in['company'] ?? '', 'phone' => $in['phone'] ?? '', 'with' => $d[0]]; } continue; }
            if (!$dryRun) { add_target($listId, $in, $uid, false); }
            $added++;
        }
        if (!$dryRun) { $p->commit(); oplog($uid, 'import.csv', 'call_lists', $listId, ['added' => $added, 'dup' => $dup, 'empty' => $empty]); }
    } catch (Throwable $e) { if (!$dryRun) { $p->rollBack(); } throw $e; }
    return ['added' => $added, 'dup' => $dup, 'empty' => $empty, 'dups' => $dups, 'columns' => array_keys($map), 'rows' => count($rows) - 1];
}
/** 書き出し（UTF-8・BOM つき・Excel で開ける）。列名は EspoCRM／Krayin に寄せる */
function csv_out(string $name, array $head, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . $name . '"');
    echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w'); fputcsv($o, $head, ',', '"', '\\');
    foreach ($rows as $r) { fputcsv($o, $r, ',', '"', '\\'); }
    exit;
}

/* ================= 毎日の仕事（cron なし。その日の最初の操作で） ================= */

function daily_backup(): void
{
    $day = date('Ymd');
    if (meta('last_backup') === $day) { return; }
    meta('last_backup', $day);
    $dir = dirname((string)cfg('db')) . '/backup';
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    $f = $dir . '/kcallcrm-' . $day . '.sqlite';
    if (!is_file($f)) { try { db()->exec('VACUUM INTO ' . db()->quote($f)); @chmod($f, 0600); } catch (Throwable) {} }
    foreach (glob($dir . '/kcallcrm-*.sqlite') ?: [] as $old) { if (filemtime($old) < time() - (int)cfg('backup_days') * 86400) { @unlink($old); } }
}

/* ================= デモ ================= */

function demo_reset_if_old(PDO $p): void
{
    $s = $p->query("SELECT v FROM meta WHERE k='demo_seeded'")->fetchColumn();
    if ($s && $s > date('Y-m-d H:i:s', time() - 86400)) { return; }
    foreach (['oplog', 'activities', 'deals', 'calls', 'customers', 'call_targets', 'call_lists', 'users', 'settings'] as $t) { $p->exec("DELETE FROM $t"); }
    $p->exec("DELETE FROM sqlite_sequence");
    seed_demo($p);
}
function seed_demo(PDO $p): void
{
    $p->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(?,?)')->execute(['demo_seeded', now()]);
    $ph = password_hash('demo-' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
    foreach ([['sato@demo.example', '佐藤（営業）', 'member'], ['suzuki@demo.example', '鈴木（営業）', 'member'], ['admin@demo.example', '管理者', 'admin']] as [$e, $n, $r]) {
        $p->prepare('INSERT INTO users(email,name,pass_hash,role,created) VALUES(?,?,?,?,?)')->execute([$e, $n, $ph, $r, now()]);
    }
    $p->prepare('INSERT OR REPLACE INTO settings(k,v,updated) VALUES(?,?,?)')->execute(['company', json_encode('株式会社エクスブリッジ（デモ）', JSON_UNESCAPED_UNICODE), now()]);
    // 架電先は架空。愛知・岐阜・三重の地名と、よくある業種で機械的に作る
    $towns = [['愛知県', '名古屋市中区栄', '460-0008'], ['愛知県', '名古屋市中村区名駅', '450-0002'], ['愛知県', '豊田市西町', '471-0025'], ['愛知県', '岡崎市康生通西', '444-0059'], ['愛知県', '一宮市本町', '491-0859'],
              ['岐阜県', '岐阜市神田町', '500-8833'], ['岐阜県', '大垣市郭町', '503-0887'], ['三重県', '四日市市諏訪町', '510-0085'], ['三重県', '津市栄町', '514-0004'], ['愛知県', '春日井市鳥居松町', '486-0844']];
    $names1 = ['中部', '東海', '尾張', '三河', 'みなと', 'さくら', '大和', '丸十', '旭', '北星', '大黒', '青葉', '白鳥', '金城', '星野', '松風', '若葉', '光', '緑', '朝日'];
    $names2 = ['製作所', '工業', '商事', '物流', '建設', '不動産', 'フーズ', '印刷', '電機', '運輸', '設備', '住建', '機工', '金属', '包装', 'サービス', '企画', '興業', '産業', '鋼業'];
    $inds = ['製造', '卸売', '建設', '運輸', '不動産', '飲食', 'サービス', '小売'];
    $surn = ['田中', '鈴木', '伊藤', '山本', '加藤', '渡辺', '中村', '小林', '松本', '林', '森', '山田', '斎藤', '清水', '近藤'];
    $lists = [['製造業（愛知・岐阜）', '購入リスト', 120], ['展示会名刺 2026秋', '展示会', 60], ['既存問い合わせ', 'WEB', 25]];
    $tid = 0; $i = 0;
    foreach ($lists as [$ln, $lk, $n]) {
        $p->prepare('INSERT INTO call_lists(name,kind,added_on,note,created_by,created,updated) VALUES(?,?,?,?,?,?,?)')->execute([$ln, $lk, date('Y-m-d', strtotime('-20 days')), '', 3, now(), now()]);
        $lid = (int)$p->lastInsertId();
        for ($j = 0; $j < $n; $j++, $i++) {
            $tw = $towns[$i % 10]; $co = ($i % 3 === 0 ? '株式会社' : '') . $names1[$i % 20] . $names2[($i * 7) % 20] . ($i % 3 === 0 ? '' : '株式会社');
            $t = ['company' => $co, 'contact' => $surn[$i % 15] . ' ' . ($i % 2 ? '一郎' : '花子'), 'dept' => $i % 4 === 0 ? '総務部' : ($i % 4 === 1 ? '営業部' : ''), 'title' => $i % 5 === 0 ? '部長' : ($i % 5 === 1 ? '課長' : ''),
                  'phone' => sprintf('0%d-%03d-%04d', [52, 52, 565, 564, 586, 58, 584, 59, 59, 568][$i % 10], 200 + $i % 700, 1000 + ($i * 37) % 9000), 'phone2' => '', 'email' => '', 'zip' => $tw[2], 'pref' => $tw[0],
                  'addr' => $tw[1] . (1 + $i % 5) . '-' . (1 + $i % 12) . '-' . (1 + $i % 9), 'url' => '', 'industry' => $inds[$i % 8], 'note' => ''];
            $k = keys_of($t);
            $p->prepare('INSERT INTO call_targets(list_id,' . implode(',', KCC_TARGET_COLS) . ',assignee_id,k_name,k_phone,k_zip,k_addr,seq,created,updated) VALUES(?,' . str_repeat('?,', count(KCC_TARGET_COLS)) . '?,?,?,?,?,?,?,?)')
              ->execute(array_merge([$lid], array_values($t), [$i % 3 === 2 ? null : 1 + $i % 2, $k['k_name'], $k['k_phone'], $k['k_zip'], $k['k_addr'], $j + 1, now(), now()]));
            $tid = (int)$p->lastInsertId();
            // 最初の2リストの前半は、すでに架電した形にする
            if ($lk !== 'WEB' && $j < (int)($n * 0.55)) {
                $kinds = ['busy', 'recall', 'ng', 'busy', 'appo', 'recall', 'lead', 'busy', 'ng', 'recall'];
                $kind = $kinds[$j % 10]; $r = null; foreach (KCC_DEFAULT_RESULTS as $x) { if ($x['kind'] === $kind) { $r = $x; break; } }
                $daysAgo = 1 + ($j * 3) % 18; $at = date('Y-m-d', strtotime("-$daysAgo days")) . ' ' . sprintf('%02d:%02d', 10 + $j % 7, ($j * 13) % 60);
                $uid = 1 + $j % 2; $next = null;
                if ($kind === 'recall') { $next = date('Y-m-d', strtotime(($j % 3 === 0 ? '-1' : '+' . (1 + $j % 5)) . ' days')) . ' ' . sprintf('%02d:00', 10 + $j % 7); }
                $memos = ['担当者不在。午後に折り返しとのこと', '担当の' . $surn[($i + 3) % 15] . '様につないでもらえた。資料を見てから判断したいとのこと', '今は間に合っているとのこと', '呼び出すも応答なし', '来週訪問の約束。現場の課題を聞く', '社長が出張中。来週の火曜に再度', '資料送付の了承。メールアドレスを聞いた', '話し中', '必要ないと即答', '見積の時期ではないが、来月また電話してよいとのこと'];
                $p->prepare('INSERT INTO calls(target_id,customer_id,user_id,at,result,result_kind,memo,next_call,created) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$tid, null, $uid, $at, $r['label'], $kind, $memos[$j % 10], $next, now()]);
                $status = $kind; $cust = null;
                if ($kind === 'appo') {
                    $p->prepare('INSERT INTO customers(' . implode(',', KCC_CUSTOMER_COLS) . ',owner_id,source_target_id,k_name,k_phone,created,updated) VALUES(' . str_repeat('?,', count(KCC_CUSTOMER_COLS)) . '?,?,?,?,?,?)')
                      ->execute(array_merge(array_map(fn($c) => $t[$c], KCC_CUSTOMER_COLS), [$uid, $tid, $k['k_name'], $k['k_phone'], now(), now()]));
                    $cust = (int)$p->lastInsertId(); $status = 'customer';
                    $p->prepare('INSERT INTO activities(customer_id,user_id,at,kind,body,created) VALUES(?,?,?,?,?,?)')->execute([$cust, $uid, $at, 'call', '【アポ獲得】' . $memos[4], now()]);
                    $st = stages()[($j / 10) % 4 + 1] ?? stages()[1]; $amt = [300000, 550000, 1200000, 880000][$j % 4];
                    $p->prepare('INSERT INTO deals(customer_id,title,stage,amount,expected_on,owner_id,next_action,next_due,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?)')
                      ->execute([$cust, ['受発注システム', 'AI議事録', '勤怠', 'チャットボット'][$j % 4], $st, $amt, date('Y-m-d', strtotime('+' . (10 + $j % 40) . ' days')), $uid, '提案書を送る', date('Y-m-d', strtotime('+' . (1 + $j % 6) . ' days')), now(), now()]);
                }
                $p->prepare('UPDATE call_targets SET status=?, next_call=?, last_call=?, last_result=?, call_count=?, customer_id=? WHERE id=?')->execute([$status, $next, $at, $r['label'], 1 + $j % 3, $cust, $tid]);
            }
        }
    }
}
