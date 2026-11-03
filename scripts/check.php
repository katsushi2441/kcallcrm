<?php
/** 自己テスト（決まりの部分だけ確かめる）。デプロイの前に必ず通す。  php scripts/check.php */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/kcc_check_' . getmypid(); @mkdir($tmp);
file_put_contents($tmp . '/kcc_config.php', "<?php return ['db' => '{$tmp}/t.sqlite', 'demo' => false];");
$KCC_OVERRIDE = require $tmp . '/kcc_config.php';
require __DIR__ . '/../public/kcc_lib.php';
$KCC = array_merge($KCC, $KCC_OVERRIDE);
$ok = 0; $ng = 0;
function t(string $name, bool $c) { global $ok, $ng; if ($c) { $ok++; } else { $ng++; echo "NG: $name\n"; } }

schema(db());
$admin = create_user('a@x.example', '管理者', 'password123', 'admin');
$sato = create_user('s@x.example', '佐藤', 'password123', 'member');
t('表記ゆれ：株式会社と全角', company_key('株式会社　ミナト工業') === company_key('ミナト工業（株）') && company_key('ＭＩＮＡＴＯ') === 'minato');
t('電話は数字だけ', digits('０５２-１２３-４５６７') === '0521234567');
t('携帯は重複判定に使わない', keys_of(['phone' => '090-1234-5678'])['k_phone'] === '');
t('住所：丁目番地と全角ハイフン', address_key('愛知県名古屋市中区栄三丁目４番５号') === address_key('名古屋市中区栄3-4-5'));
try { try_login('a@x.example', 'wrong'); t('ログイン失敗はメッセージ', true); } catch (Throwable) { t('ログイン失敗はメッセージ', false); }

$lid = create_list('テスト', '購入', today(), '', $admin);
$r1 = add_target($lid, ['company' => '株式会社みなと工業', 'phone' => '052-123-4567', 'zip' => '460-0008', 'addr' => '名古屋市中区栄3-4-5'], $admin, true);
t('架電先を足す', $r1['id'] > 0);
$r2 = add_target($lid, ['company' => 'みなと工業（株）', 'phone' => '052-123-4567'], $admin, true);
t('同じ電話番号は重複', $r2['id'] === 0 && $r2['dup'][0]['why'] === '電話番号');
$r3 = add_target($lid, ['company' => 'ミナト工業', 'phone' => '090-0000-0000', 'zip' => '4600008'], $admin, true);
t('携帯＋同じ社名・郵便番号は重複', $r3['id'] === 0 && $r3['dup'][0]['why'] === '社名＋郵便番号');
$r4 = add_target($lid, ['company' => 'ミナト工業', 'phone' => '090-0000-0000', 'zip' => '4600008'], $admin, false);
t('force なら足せる', $r4['id'] > 0);
try { add_target($lid, ['contact' => 'x'], $admin); t('会社も電話も無いと弾く', false); } catch (InvalidArgumentException) { t('会社も電話も無いと弾く', true); }

$csv = "会社名,電話番号,担当者,郵便番号,住所\n東海商事,052-999-0001,田中,460-0001,名古屋市中区三の丸1-1\n株式会社みなと工業,052-123-4567,,,\n,,,,\n尾張製作所,0586-11-2222,,491-0859,一宮市本町1-2\n";
$res = import_csv($lid, $csv, $admin, true, true);
t('CSV 確認：追加2・重複1・空1', $res['added'] === 2 && $res['dup'] === 1 && $res['empty'] === 1);
t('CSV 確認では増えない', targets_query(['list_id' => $lid], 1)['total'] === 2);
$res = import_csv($lid, mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'), $admin, true, false);
t('Shift_JIS も取り込める', $res['added'] === 2 && targets_query(['list_id' => $lid], 1)['total'] === 4);
$res = import_csv($lid, "Account Name,Phone\nEspo Inc,03-1111-2222\n", $admin);
t('英語の見出しも読む', $res['added'] === 1);
try { import_csv($lid, "名前,住所\nx,y\n", $admin); t('会社も電話も無い見出しは弾く', false); } catch (InvalidArgumentException) { t('会社も電話も無い見出しは弾く', true); }

$tid = (int)$r1['id'];
try { record_call($tid, 'recall', 'あとで', null, $sato); t('再コールは日時が必要', false); } catch (InvalidArgumentException) { t('再コールは日時が必要', true); }
$c = record_call($tid, 'recall', '担当不在', '2026-12-01 10:00', $sato);
$t = target($tid);
t('再コールで状態と次回が入る', $t['status'] === 'recall' && $t['next_call'] === '2026-12-01 10:00' && (int)$t['call_count'] === 1 && (int)$t['assignee_id'] === $sato);
$c = record_call($tid, 'appo', '来週訪問', null, $sato);
$t = target($tid);
t('アポで顧客になる', $c['customer_id'] > 0 && $t['status'] === 'customer' && (int)$t['customer_id'] === $c['customer_id'] && $t['next_call'] === null);
$cu = customer((int)$c['customer_id']);
t('顧客は架電先の情報を引き継ぐ', $cu['company'] === '株式会社みなと工業' && (int)$cu['owner_id'] === $sato && (int)$cu['source_target_id'] === $tid);
t('アポは活動にも残る', count(activities_of((int)$cu['id'])) === 1);
t('同じ顧客を二度作らない', promote_target($tid, $sato) === (int)$cu['id']);
$q = targets_query(['q' => '052-123'], 1); t('電話番号で探せる', $q['total'] === 1);
$q = targets_query(['q' => 'ミナト'], 1); t('表記ゆれでも社名で探せる', $q['total'] >= 2);
$q = targets_query(['list_id' => $lid, 'status' => 'new'], 1); t('状態で絞れる', $q['total'] === 4);

$did = create_deal((int)$cu['id'], ['title' => '受発注', 'amount' => '1,200,000', 'stage' => '商談中'], $sato);
$d = deal($did); t('商談：金額のカンマを外す', (int)$d['amount'] === 1200000 && $d['stage'] === '商談中' && $d['closed'] === null);
set_stage($did, '受注', $sato); t('受注で閉じる', deal($did)['closed'] !== null);
try { set_stage($did, '存在しない', $sato); t('無いステージは弾く', false); } catch (InvalidArgumentException) { t('無いステージは弾く', true); }
t('ステージの変更は活動に残る', count(activities_of((int)$cu['id'])) === 2);

$n = next_target($sato, $lid);
t('続けて架電：未着手が出る', $n && $n['status'] === 'new');
record_call((int)$n['id'], 'busy', '', null, $sato);
$st = stats(today(), today());
t('集計：架電3・通話2（不通は数えない）・アポ1', (int)$st['total']['calls'] === 3 && (int)$st['total']['talked'] === 2 && (int)$st['total']['appo'] === 1);
t('アポ率', rate(1, 2) === '50%');

set_setting('call_results', [['code' => 'ok', 'label' => '話せた', 'kind' => 'lead'], ['code' => 'x', 'label' => 'だめ', 'kind' => 'ng']]);
t('結果の選択肢を変えられる', result_by_code('ok')['label'] === '話せた' && result_by_code('recall') === null);
daily_backup(); t('バックアップが取れる', count(glob($tmp . '/backup/kcallcrm-*.sqlite')) === 1);

// デモ：1日で元に戻る
$KCC['demo'] = true; $KCC['db'] = $tmp . '/demo.sqlite';
$pdo = new PDO('sqlite:' . $KCC['db']); schema($pdo); seed_demo($pdo);
t('デモの種：リスト3・架電先205', (int)$pdo->query('SELECT COUNT(*) FROM call_lists')->fetchColumn() === 3 && (int)$pdo->query('SELECT COUNT(*) FROM call_targets')->fetchColumn() === 205);
t('デモの種：アポの分だけ顧客と商談', (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === (int)$pdo->query('SELECT COUNT(*) FROM deals')->fetchColumn() && (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() > 5);
t('デモの種：再コールの期限が来たものがある', (int)$pdo->query("SELECT COUNT(*) FROM call_targets WHERE status='recall' AND next_call < '" . today() . "'")->fetchColumn() > 0);

foreach (glob($tmp . '/backup/*') ?: [] as $f) { unlink($f); } @rmdir($tmp . '/backup');
foreach (glob($tmp . '/*') ?: [] as $f) { unlink($f); } @rmdir($tmp);
echo "ok=$ok ng=$ng\n"; exit($ng ? 1 : 0);
