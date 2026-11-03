# Kurage テレアポCRM・SFA（kcallcrm）

架電リストに架電先を取り込み、電話をかけて結果と次回コールを残し、アポが取れたら顧客にして商談を追う、テレアポ営業のための CRM・SFA です。
PHP 8 と SQLite だけで動きます。レンタルサーバーにフォルダごと置けば動きます（常駐プロセス・ビルド・Node.js・MySQL なし）。月額はかかりません。

デモ: https://proto.exbridge.jp/kcallcrm/ ／ 導入版: https://kappstore.exbridge.jp/

## できること

| 営業担当 | 管理者 |
|---|---|
| 「続けて架電」：期限の来た再コール → 未着手の順に1件ずつ出す。結果・メモ・次回コールを残す | 担当者の追加・無効化・パスワードの変更 |
| 架電先の検索（会社名・担当者・電話番号・住所・状態・担当）。1リスト9万件でも一覧はサーバーで絞る | 架電の結果の選択肢（名前と扱い）、商談のステージ |
| アポが取れたら自動で顧客に。活動の記録、商談（かんばん） | リストの削除、CSV の書き出し（架電先・顧客・架電） |
| CSV 取込（UTF-8／Shift_JIS、見出しで列を探す）。同じ電話番号・同じ社名＋郵便番号は重複として飛ばす | |
| 集計：担当ごと・日ごと・リストごとの架電数・通話数・アポ数・アポ率 | |

守っていること：権限はサーバーで決める／消すのは論理削除で操作は oplog に残す／毎日その日の最初の操作でバックアップ（`kcc_data/backup/`）／ログインは5回失敗で15分止める／表記ゆれ（全角半角・株式会社・カタカナひらがな・丁目番地）をそろえて重複を見つける／携帯番号は重複の判定に使わない。

## 置き方

1. `public/` の中身をサーバーの公開フォルダに置く（例: `https://example.jp/crm/`）。
2. `kcc_config.php.example` を `kcc_config.php` という名前で同じ場所に置き、会社名などを書く。
   データベースはできれば公開フォルダの外（`db` の場所）。公開フォルダの中に置く場合は `kcc_data/.htaccess` が外からの読み取りを止めます。
3. `kcc_data/` にウェブサーバーが書き込める権限を付ける（レンタルサーバーなら 705 か 707）。
4. ブラウザで開き、最初の管理者を作る。
5. PHP の版の指定がいるサーバー（heteml など）は `.htaccess` に `AddHandler php-script .php` のような1行を足す。

必要なもの：PHP 8.1 以上、SQLite（PDO）、mbstring。`intl` があれば表記ゆれの判定がより正確になります（無くても動きます）。

## CSV

取り込みの見出し（順番は自由。無い列は空のまま）：会社名, 担当者, 部署, 役職, 電話番号, 携帯, メール, 郵便番号, 都道府県, 住所, URL, 業種, 備考。英語（Account Name, Phone, Postal Code…）も読めます。
書き出しの列名は EspoCRM／Krayin の Lead・Account・Call に合わせてあり、大きくなったらそちらへそのまま移れます。

## API

`kcc_config.php` の `api_token` を決め、`Authorization: Bearer <token>` で呼びます。

```
GET  api.php?r=lists
GET  api.php?r=targets&list_id=1&status=recall&q=052&page=1
GET  api.php?r=target&id=123
GET  api.php?r=recalls&date=2026-11-05
POST api.php?r=target   {"list_id":1,"company":"…","phone":"…"}      重複なら 409（force:true で足す）
POST api.php?r=call     {"target_id":123,"result":"recall","memo":"…","next_call":"2026-11-06 10:00","user_email":"…"}
GET  api.php?r=customers&q=
GET  api.php?r=stats&from=&to=
GET  api.php?r=results
```

## MCP（Claude Code・Claude Desktop など）

```
claude mcp add kcallcrm -- php /path/to/public/kcc_mcp.php
```

AI ができるのは、架電先・再コール・顧客・集計を読むことと、架電の結果を記録することまでです。情報の書き換え・削除・設定の変更はできません。

## 開発

```
php scripts/check.php                 # 自己テスト（36件）
php -S 127.0.0.1:8080 -t public       # 手元で動かす
/usr/bin/python3 scripts/e2e.py http://127.0.0.1:8080/   # ブラウザでの通しの試験（デモ設定で）
```

決まりは `AGENTS.md`（`CLAUDE.md` も同じ内容）に書いてあります。

## ライセンス

MIT（`LICENSE`）。株式会社エクスブリッジ https://exbridge.jp/
