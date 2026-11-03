# Kurage テレアポCRM・SFA（kcallcrm）— 開発の決まり

**AI エージェント（Claude Code・Codex）も人も、直す前にこのファイルを最後まで読んでください。**（`CLAUDE.md` と同じ内容です）

## 構成

```
public/index.php      画面と操作（ログイン・今日・続けて架電・リスト・架電先・顧客・商談・集計・設定・取込・CSV）
public/kcc_lib.php    共通部品（設定・DB・スキーマ・ログイン・表記ゆれ・架電先・架電・顧客・商談・集計・CSV・バックアップ・デモの種）
public/api.php        JSON API（Bearer の合言葉）
public/kcc_mcp.php    MCP サーバー（stdio・1ファイル）
public/kcc_config.php 設定（git に入れない。example を写す）
public/kcc_data/      SQLite とバックアップ（外から読めないよう .htaccess で拒否。できれば公開フォルダの外に置く）
scripts/check.php     自己テスト。直したら必ず通す
scripts/e2e.py        ブラウザでの通しの試験（Playwright。デモ設定で動かした手元のサーバーに対して）
```

## 書き方の決まり

- **PHP 8 と SQLite だけ。** Composer・フレームワーク・Node.js・ビルドを足さない。画面は PHP が出す HTML と、数行の JS。
- **権限はサーバーで決める。** 画面でボタンを隠すだけにしない。管理者だけの操作（設定・担当者・リストの削除・CSV 書き出し）は index.php の操作の中で `$isAdmin` を確かめる。
- **一覧はサーバーで絞り込み・並べ替え・ページ送り。** 架電先は1リスト9万件、全体で数十万件になる。画面に全件を持たない。新しい絞り込みを足すときは `targets_query()` に足し、索引（`CREATE INDEX`）が効く形にする。
- **スキーマを変えるときは `KCC_SCHEMA` を上げ、`schema()` で `user_version` を見て差分だけ流す。** 既存の表を作り直さない。
- **消すのは論理削除**（`deleted` に日時）。消した・変えた・取り込んだ・書き出したは `oplog()` に残す。
- **表記ゆれの判定は `keys_of()` に集める。** 社名（株式会社・カタカナひらがな・全角半角）、電話（数字だけ・携帯は使わない）、郵便番号、住所（丁目番地・ハイフン）。重複の判定は `find_duplicates()` だけが行う。
- **架電の結果は「設定」で変えられる。** コードに結果の名前を直書きしない。扱い（kind）は `KCC_RESULT_KINDS` の6つだけで、架電先の状態（`KCC_STATUS`）はこの扱いから決まる。
- **アポは自動で顧客にする**（`record_call()`）。顧客は架電先を残したまま `customer_id` でつなぐ（二度作らない）。
- **CSV の列名は EspoCRM／Krayin に合わせる**（取込の見出しは `KCC_HEADERS`、書き出しは index.php の `csv_out`）。増えた会社がそちらへ移れるように。
- 画面の文字は必ず `h()` で出す。SQL は必ずプレースホルダ。
- 時刻は日本時間。日付は `YYYY-MM-DD`、日時は `YYYY-MM-DD HH:MM`。
- 秘密の値（合言葉・鍵）は `kcc_config.php` に置き、git に入れない。
- デモ（`demo => true`）は本番で使わない。デモの種（`seed_demo()`）の会社・人はすべて架空のまま保つ。

## 直したら

```
php -l public/*.php
php scripts/check.php
```

画面を変えたら `scripts/e2e.py` も流し、320px・390px で横にはみ出さないことを確かめる。
