# gyugyu

PHP + SQLiteによる、共通Basic認証下のスケジュール調整アプリです。開発仕様の正本は [docs/SPEC.md](docs/SPEC.md) です。

実装済み：複数案件の一覧・作成・プレビュー・詳細、調整名／説明編集、確認付き論理削除・復元、版数競合検知、監査ログ。日別候補グリッドと候補帯編集も実装済みです。参加者登録・共有回答編集・確認付き非表示化・参加者数／提出済人数も実装済みです。グループ管理・複数所属・参加者表示フィルターも実装済みです。○△×3行集計・未入力人数・暫定順位・表示モードも実装済みです。CSV（ワイド／ロング／集計）・完全JSONエクスポートも実装済みです。候補日の追加・確認付きアーカイブ・同日履歴復元も実装済みです。非表示参加者の確認付き復帰も実装済みです。受付終了・再開も実装済みです。30分枠の日時確定・確定取消も実装済みです。importは後続です。

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

## グループと所属

`php bin/migrate.php` で003移行を適用します。既存の参加者・回答は保持され、初期所属は空です。詳細の「グループを登録・管理」から0〜9件の追加・編集・確認付き削除・復元を行います。回答編集内の所属チェックボックスを氏名・回答とまとめて保存します。

APIは `/instances/{id}/groups` のGET/POST、`/groups/{group_id}` のPATCH/DELETE、`/groups/{group_id}/restore` のPOST。期待値は `expected_version`、`expected_schema_revision`、既存グループは `expected_group_version`。削除には `confirmation`（保存済み名）と影響人数確認時の `expected_answers_revision` も必要です。参加者submissionの `group_ids` は同案件の有効ID配列。空配列は未所属、キー省略は既存所属維持です。

表示マスクは全員／選択グループを含む（OR）／除外。include空は0人、exclude空は全員。0グループでは全員でUI非表示。ブラウザーのタブ内で条件を保持し、DB回答を変更しません。同じ対象集合で集計・未入力人数・順位を更新します。

追加の回帰確認：`node --test tests/*.test.mjs`、`python3 tests/candidate_browser.py`、`python3 tests/answer_browser.py`、`python3 tests/group_browser.py`。CIはこれらを全て実行します。

## 集計・順位

日別表の参加者行の下に○△×の3行を表示します。セルへのフォーカス・タップで対象人数／未入力人数、名前内訳、スコア、暫定状態を確認できます。順位は30分単位で、×が少ない順、次に○が多い順。同点は同順位で日時順に並べます。対象0人は数値・スコアが「—」で順位を出しません。

全員表示／対象者のみ／集計のみは同じマスク集合を使います。全員表示ではマスク外を区別します。編集中は保存済み回答だけを集計し、下書きを含めません。他端末の変更を自動取得しないため、最新状態は再読み込みで確認します。

GET `/instances/{id}/summary?mode=all|include|exclude&group_ids[]=...`（実際には既存の`route`クエリに続けて`&mode=...`）で同じ計算結果と版数を取得できます。同案件の有効グループ以外を拒否し、1つの読取りtransaction内で集計します。HTTP応答はno-storeです。追加試験は `python3 tests/summary_browser.py`。NodeテストはPHPとの1,280ケース照合も含むためテスト時はPHPが必要です。

連続60/90分の候補推奨は未実装で、今回の対象外です。30分候補枠の日時確定・取消は下記の専用機能で実装済みです。

## CSV／JSONエクスポート

案件詳細の出力欄からダウンロードします。**サーバー上の現在の保存済みスナップショット**を生成するため、他端末の更新により画面読込み時点とは異なる場合があります。編集中は出力不可、生成中は新しい編集・表示条件変更を停止します。失敗しても入力・DBを変更しません。生成日時と版数を出力に含めます。

API例：`api/index.php?route=/instances/{id}/export&format=long&scope=mask&mode=include&group_ids[]={group_id}`。

- `format=json`: 全履歴固定。`scope=all`、マスク指定なし。UTF-8、BOMなし。案件・日・全48枠・候補フラグ・active/inactive参加者・active/archived群と所属・候補外やarchived日の回答を保持します。秘密設定・セッション・登録用submission_key・内部name_key・監査ログは出力しません。JSONはDB文字列をCSV防御で変更せず、整数・真偽値・nullを保持します。unknownは回答レコードなしです。
- `format=wide|long|summary`: UTF-8 BOMあり、行区切りCRLF、RFC4180形式の二重引用符処理。scopeは全有効参加者`all`／現在のマスク`mask`。有効日の有効候補のみ。マスクは既存集計と同じall/include/exclude・ORです。JSONはCSV範囲指定を受け付けません。
- CSV共通列は案件ID・名前・説明・timezone・status・案件/schema/answers版数・生成日時・scope・mask_mode・選択グループIDのJSON配列。ワイドは参加者ID・氏名・所属JSON配列に続き、JST開始/終了とslot IDを列名とする○△×/空欄。ロングは参加者情報＋slot/day ID、UTC/JST開始終了、yes/maybe/no/unknown。集計はslot/day ID、UTC/JST開始終了、N/Y/T/X/U/score/provisional/all_yesです。対象0人の集計ではN=0、Y/T/X/U/scoreは「—」、フラグ0です。候補0件のロング/集計、対象0人のワイド/ロングはヘッダーのみです。
- CSV文字列は先頭が`= + - @`、対応する全角記号、Unicode空白・制御文字の場合にアポストロフィを追加します。これはCSVセルだけの防御です。数値scoreの負値を文字列扱いして変更しません。
- UTCは`Z`、JSTは`+09:00`、timezoneはAsia/Tokyo。ファイル名はASCIIの案件ID・UTC生成日時・scope・formatのみで、入力名は使用しません。Basic認証・no-store・nosniffを適用し、deleted案件は404、locked/confirmedは読取り出力できます。未対応queryや別案件/archived group IDは422です。

完全JSONのschemaは`format_version:1, generated_at, scope, unknown_representation, instance, days, groups, participants`。各dayにslots、各participantに全所属group_idsとanswersを持ち、履歴状態はarchived_at/is_activeで識別します。これはimport機能やSQLite物理バックアップの代替ではありません。追加試験：`python3 tests/export_browser.py`。


## 非阻害の将来QAメモ

PR #6の独立レビューから継続する確認項目（今回の日付管理では機能拡張しない）：exportのexclude／OR／0グループ条件、候補0件／翌日帯の出力、locked画面でのダウンロード、HTTPエラー／タイムアウトからの回復、ExportでのSummary重複計算の規模拡大時の性能。


## 候補日の管理

詳細の「候補日を追加・復元・アーカイブ」から1日ずつ操作します。新規だけ初期時間帯を指定し、同日の再追加は以前のID・候補帯・全回答を復元します。有効日付の重複は変更しません。アーカイブは全保存回答数と表示対象回答数を確認し、対象日を再入力して確定します。

**上限はアーカイブ済みを含む累計366日／案件**です。繰返し作成・アーカイブによる無制限な増大を防ぎます。同日の復元は上限でも可能です。最後の日をアーカイブして0件になっても、参加者一覧・集計対象人数・出力・追加復元導線を保持します。参加者回答グリッド編集は日付の追加・復元後に再開します。

保存・プレビューのAPI契約はSPEC第24節を参照してください。複数日batchは受け付けず全体を拒否します。競合・通信失敗では下書きと元の版数を維持し、保存結果不明の場合は別画面で確認してください。日付変更にはJavaScriptが必要です。追加migrationは不要、既存SQLiteバックアップに日付履歴も含まれます。追加の実ブラウザー試験は `python3 tests/day_browser.py`（CIにも含む）です。


## 非表示参加者の復帰

詳細の「非表示参加者を復帰」で不変ID・氏名・元の提出日時・履歴回答数・所属を確認し、氏名を再入力します。同姓同名はIDで区別してください。復帰では回答・所属・提出日時を変更せず、アーカイブ済みグループや日自体は復元しません。日付0件でも復帰可能です。全unknownの提出者も提出済みのまま戻ります。

API・期待版数はSPEC第25節を参照してください。確認後に他端末が変更した場合や、応答を失った復帰を再送した場合は409とし、入力を保持して再確認します。復帰画面にはJavaScriptが必要です。追加migration不要。回帰試験：`python3 tests/restore_browser.py`（Chromiumで取消・通信失敗・応答喪失・競合・キーボード・実タッチのモバイル／0日復帰）。本番デプロイは行っていません。

将来の広範囲受入QA：実日付APIのアーカイブ／復元前後でsummary・ranking・各CSVの消失／復帰を一括照合するケース（PR #7の独立レビュー由来）。今回の参加者復帰の範囲は広げません。


## 受付終了・再開

詳細の「受付終了・再開」で保存済み内容と操作を確認し、案件名を入力します。受付終了後も閲覧・表示条件切替・集計・CSV/JSON出力は可能ですが、参加者／回答／所属／候補／グループ／調整名の変更は停止します。受付再開は明示操作です。説明に締切を書いても自動終了しません。0日／0参加者でも使用できます。

POST `/instances/{id}/reception` の契約はSPEC第26節。終了・再開のたびに案件versionを進め、終了前の古い編集は再開後も409にします。確認後に回答や構成が変われば受付変更も409となり、操作・入力・元版数を保持します。応答喪失時は別画面で状態を確認してから再読み込みしてください。lockedの案件を論理削除・復元するとlockedへ戻ります。受付操作UIはJavaScript必須、追加migration不要。

追加の実ブラウザー試験：`python3 tests/reception_browser.py`。過去の段階別記録にある未実装表記はその導入時点を表し、現在の累積実装はSPEC第19〜27節を参照してください。今回、日時確定・確定取消・import・本番デプロイは含みません。

継続QA：参加者復帰時の有効回答による集計／mask CSVの変化と、有効2群＋アーカイブ群所属の復帰を広範囲受入試験で追加照合する。既存のexport条件・日付復元QAも継続する（今回範囲を拡大しない）。


## 30分候補枠の日時確定・取消

既存DBはバックアップ後に `php bin/migrate.php` を実行し、004移行を適用してください。既存の回答・所属・候補日は保持します。旧confirmedデータに確定詳細がない場合も日時を推測せず未登録と表示します。

詳細の「日時確定・確定取消」で候補枠を明示選択し、対象条件をプレビューします。現在のマスクを初期表示しますが、決定には日時・条件・人数・氏名を確認し、案件名と確認チェックが必要です。0人、未回答、×を含む場合も確定可能。候補がなければ確定できません。

確定後はJST日時と決定時の対象を詳細に表示します。現在の表示フィルターやCSV条件を変えても決定は変わりません。取消は確認付きでopenへ戻し、旧決定全体を監査に保持します。confirmedで削除した案件を復元すると確定詳細も戻ります。全編集APIはconfirmedを拒否し、読取りと出力を維持します。

APIとsnapshot schemaはSPEC第27節。完全JSONの `instance.confirmation` は現在の決定またはnullです。CSVの共通列へ `confirmed_slot_id, confirmed_start_utc, confirmed_end_utc, confirmed_at, confirmed_mask_json` を追加しました。列名で読み取ってください。決定maskは出力maskと別の保存済みメタデータです。日時はUTC Z、画面はJSTです。空結果CSVはヘッダーのみ、完全なメタデータ取得にはJSONを使用します。監査exportは今回含みません。

追加試験は `python3 tests/confirmation_browser.py`。確定UIはJavaScript必須。連続枠・通知・import・本番デプロイは未実装です。以前の段階別記録はその時点の範囲を示し、現状はSPEC第27節までを参照してください。
