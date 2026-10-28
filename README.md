# gyugyu

PHP + SQLiteによる、共通Basic認証下のスケジュール調整アプリです。開発仕様の正本は [docs/SPEC.md](docs/SPEC.md) です。

実装済み：複数案件の一覧・作成・プレビュー・詳細、調整名／説明編集、確認付き論理削除・復元、版数競合検知、監査ログ。日別候補グリッドと候補帯編集も実装済みです。参加者登録・共有回答編集・確認付き非表示化・参加者数／提出済人数も実装済みです。グループ・集計・エクスポート・受付状態の変更UI/APIは後続です。

## 必要環境と初期化

PHP **8.3以上**（`pdo_sqlite`, `mbstring`, `session`）、SQLite、テスト用Python 3。Composer、Node、外部CDNは不要です。Debian等では `php-cli php-sqlite3 php-mbstring` を導入します。

既存のチェックアウトを使用してください。タスクごとのworktree作成は不要です。

リポジトリルートで実行：

```sh
mkdir -p var
chmod 700 var
export GYUGYU_DB="$PWD/var/gyugyu.sqlite"
export GYUGYU_BASE_PATH=/gyugyu
export GYUGYU_ORIGIN=http://127.0.0.1:8080
php bin/migrate.php
```

`GYUGYU_DB` は公開ディレクトリ外の既存ディレクトリ内を指定します。既定値は `var/gyugyu.sqlite`。`GYUGYU_BASE_PATH` は末尾スラッシュなし（ルート設置は空文字）、`GYUGYU_ORIGIN` はブラウザーから見た正確なscheme＋host＋port（パス・末尾スラッシュなし）です。本番では必ず `https://...` に設定してください。Hostや転送ヘッダーからOriginを推測しません。

マイグレーションはバージョン管理され、再実行可能です。Webリクエスト中にスキーマ更新は行いません。稼働DBのマイグレーション前にはバックアップが必要です。

## ローカル開発サーバー

開発専用ルーターはloopback接続のみを受け付け、実際のBasicパスワード照合を行います。認証をスキップするフラグはありません。使い捨てのローカル開発用パスワードを対話入力し、ハッシュだけを環境に渡します（本番の共通パスワードを使用しないでください）。Bashで：

```sh
read -r -s -p 'Local development password: ' dev_password; echo
export GYUGYU_DEV_PASSWORD_HASH="$(printf '%s' "$dev_password" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);')"
unset dev_password
php -S 127.0.0.1:8080 -t public bin/dev-router.php
```

`http://127.0.0.1:8080/gyugyu/` を開き、ユーザー名 `developer` と入力した開発用パスワードで認証します。停止はCtrl-C。プロセスは新しいタスクでは再起動が必要です。標準のPHPサーバーをルーターなしで公開しないでください。ローカルHTTPのため、この開発サーバーで本番資格情報を送信しないでください。

## テスト

```sh
php tests/run.php
python3 tests/http_test.py
# PHPのコマンド名が異なる場合：PHP_BINARY=/path/to/php python3 tests/http_test.py
# Bash: propagate a syntax error to the command exit status
while IFS= read -r -d '' file; do php -l "$file" || exit 1; done < <(find src public bin tests -name '*.php' -print0)
git diff --check
# 任意：Python Playwright + Chromium導入済みなら実ブラウザー確認
python3 tests/browser.py
```

PHPテストは一時DBでマイグレーション、入力境界、48スロット、UTC変換、別案件の分離、競合、削除・復元、ロールバックを確認します。HTTPテストは一時DBとランダムな使い捨てBasic資格情報を使い、ローカルサーバーを自動起動・停止します。ポータル／詳細／API、認証、CSRF、Origin、XSS、サブパス、公開範囲を検証します。本番WebサーバーのTLS・静的ファイル認証は別途導入先で検証が必要です。

## API

入口は `api/index.php?route=/instances`。GETは一覧、POSTは作成です。`route=/instances/{id}` はGET／PATCH／DELETE、末尾 `/restore` はPOST。その他は405または404を返します。通常GETは削除済みを404とし、一覧のみ `&deleted=1` で削除済みを明示取得できます。応答はJSONです。

作成例：

```json
{"title":"会議", "description":"任意の説明\n複数行可", "dates":["2026-11-02"], "initial_window":{"start":"09:00","end":"18:00"}, "timezone":"Asia/Tokyo"}
```

翌日終了は `initial_window.end_next_day: true`（例：22:00〜翌06:00）。日付は1900年〜9998年、入力最大366行。重複は一意化し昇順、プレビューで警告します。開始は当日06:00以降、終了は翌06:00まで。日付ごとに48個の固定IDスロットを保存し候補マスクを分離しているため、後続の回答／候補編集で識別子を維持できます。

変更にはJSONの `expected_version` が必要です。PATCHは `title`, `description` のみ（省略は維持、空説明はクリア）。DELETEは加えて現在の案件名と完全一致する `confirmation` が必要です。復元は削除前状態へ戻ります。メタデータ編集は受付中のみ。削除・復元でも版数が進みます。

APIのGETで返る `X-CSRF-Token` とセッションCookieを同じクライアントで保持し、変更時はそのトークンを `X-CSRF-Token` で送信します。JSON Content-Typeと一致するOriginも必須です。未認証401、CSRF／Origin不正403、不存在404、競合409、入力不正422です。画面のフォームも同じサーバー側検査を通ります。

## 本番配置の前提（自動デプロイはしません）

- DocumentRoot／alias対象を **public/のみ** に設定。`src/`, `database/`, `bin/`, `var/`, `.git/` はURLからアクセスさせません。PHP-FPM実行ユーザーのみがDBディレクトリへ書き込めるようにします。WAL/SHMも同じディレクトリに作成されます。
- HTTPSと既存Webサーバーの**共通Basic認証をpublic/全体に適用**します。静的CSS・SVG、PHP、API、将来の出力も保護してください。`.htaccess` の存在を前提としません。
- Apache＋PHP-FPMでは認証済み `REMOTE_USER` の伝達を確認します。nginx＋PHP-FPMでは認証保護されたlocationで `fastcgi_param REMOTE_USER $remote_user;` と `fastcgi_param HTTPS on;`（TLS終端の信頼境界内）を設定します。未認証の別locationからPHPに到達させないでください。外部入力の `X-Remote-User` 等をマッピングしません。
- PHPは `REMOTE_USER` が欠けると401、HTTPS状態がない本番リクエストは403で拒否します。Basicヘッダー自体を本番アプリが認証することはありません。`bin/dev-router.php` を本番に配線せず、`GYUGYU_DEV_PASSWORD_HASH` も本番では設定しません。
- セッションCookieは本番でSecure／HttpOnly／SameSite=Strict。全応答をキャッシュさせず、ローカルCSSのみ使用します。DBは準備済みSQL、外部入力はプレーンテキストとしてエスケープします。
- 全利用者は同権限です。個別ログイン・PIN・秘密編集トークンはありません。監査actorは `shared-basic` で、個人を特定しません。
- 導入先で未認証GETが一覧／API／静的資産すべて401になること、正しいURLプレフィックス、HTTPS、Cookie属性、DBアクセス拒否を実測してください。現段階は本番導入の前提を検証済みとはしていません。

## バックアップと復旧

運用では日次およびマイグレーション前に、Web公開外のアクセス制限された別領域へバックアップし、組織の保持期間に沿って保管してください。実際のジョブ登録・保存先・保持期間は導入環境で設定します。

```sh
php bin/backup.php /private/backups/gyugyu-YYYYMMDD.sqlite
```

`VACUUM INTO` で一貫したスナップショットを作成します。既存ファイルは上書きしません。稼働中のDB本体のみをコピーしないでください。バックアップも個人情報として保護します。

復旧時はWeb/PHPワーカーを停止し、現行DB・WAL・SHMを退避してから、バックアップを新しいDBパスへコピーし、そのパスを `GYUGYU_DB` に設定します。所有者・権限を確認し、`PRAGMA integrity_check` と `PRAGMA foreign_key_check`、マイグレーション、一覧と詳細の確認後に再開します。バックアップと整合性の自動往復検証はテストに含まれます。削除済み案件は期限なく削除済み一覧から復元できます。物理削除はありません。

## 日別候補グリッド・候補帯編集（第2段階）

詳細画面で日別48セル（当日06:00〜翌06:00）を縦に表示します。セルは16px標準、20px/24px切替可能です。モバイルは表内を横スクロールし、時間見出しをスワイプして移動できます。候補編集モードではパレットの値でセルを塗り、Undo/Redo、一括プレビュー、保存／確認付き取消を使えます。キーボードはセルへフォーカスしてSpace/Enterで適用します。

一括操作は全日／選択日の置換・追加・除外を下書きへ適用します。実保存は複数日をまとめた `PATCH .../candidate-slots/bulk` で、案件版数・候補版数の両方を検査します。API契約と未実装範囲はSPEC第19節を参照してください。候補外化してもスロットや参照履歴を削除しません。実回答の影響件数は保存前にサーバーで計数し、回答更新後の古い確認を拒否します。

保存失敗時は画面の下書きを保持します。競合時は入力を控えて最新版と比較し、確認付きで再読み込みします。保存の応答を失った場合も、版数を勝手に更新して再送しません。下書きは現在のページ内に保持し、ブラウザー再起動後の永続復元は提供していません。

追加の開発用検証（Nodeは本番では不要）：

```sh
node --test tests/*.test.mjs
python3 tests/candidate_browser.py
python3 tests/answer_browser.py
```

CIではPHP／HTTP／JSに加え、Playwright Chromiumの既存CRUD・候補編集・参加者回答編集を検証します。ローカルでChromiumの場所が異なる場合は `CHROMIUM_BINARY=/path/to/chromium` を指定してください。


## 参加者と回答（第3段階）

「参加者を登録」で氏名と回答を入力し、一度に保存します。共有Basicアカウントで全員の参加者行から回答を編集できます。個人ログイン・PINはありません。○/△/×と未入力を区別し、候補外の過去回答は灰色で保持します。クリアした有効候補は未入力になります。全unknownでも保存を押せば提出済みです。非表示は氏名確認付きで履歴を保持し、復帰UIは後続です。

既存環境にはバックアップ後に `php bin/migrate.php` を実行し、`002_participants.sql` を適用してください。Webアクセス時の自動マイグレーションは行いません。参加者・回答・監査もSQLiteバックアップに含まれ、HTTP試験でバックアップからの復元を検証します。

APIの具体的な契約はSPEC第20節を参照してください。新規登録の `submission_key` は重複送信検出用の公開値で、既存回答の編集資格情報ではありません。候補保存にはプレビューで返した `answers_revision` を `expected_answers_revision` として送信します。プレビュー後に他端末が回答を保存した場合、候補保存は409となり再確認が必要です。
