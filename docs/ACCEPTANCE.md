# MVP受入確認記録

2026-10-28。対象は [SPEC第15節](SPEC.md#15-受入テスト初版完成判定) の全29項目。機能追加は停止し、既存実装の必須受入確認だけを行った。起点masterは `57aa05d7c648840ecdc996b3d2481160f06e7566`（PR #10統合後）。SPECの未チェック欄は未実装一覧ではなく、原仕様のチェックリストとして残す。

**判定：隔離環境で29項目のアプリ側動作を確認。うち2項目は導入先でも実測が必要。導入先の安全性・本番稼働を確認済みとはしない。** 必須のアプリ機能に未実装・不具合は発見されなかった。追加したのは各日の初期候補マスクの厳密照合、候補ドラッグ除外→復元の集計照合、ブラウザープロセス終了→新規起動の回答照合のみ。製品コード・DBスキーマ・仕様要件は変更していない。必須の隔離試験で未確認のまま残した項目はなく、未実施の導入確認はD1–D5に限定して列挙する。

## 実行環境と結果

一時SQLite DB・ランダムな開発用Basic資格情報・loopbackの `/gyugyu/` 配下で実行。実データ・本番資格情報は不使用。ローカルPHP 8.4、Python Playwright＋Chromium、Nodeによる確認。Apache/nginx/PHP-FPM/TLS終端や実端末での試験ではない。モバイルはChromiumのviewport/touchエミュレーション。

| 実行コマンド | 結果 |
|---|---|
| `php tests/run.php` | 472 assertions成功（従来471、全候補日の厳密マスク照合へ変更して1増加） |
| `python3 tests/http_test.py` | 14 tests成功 |
| `node --test tests/*.test.mjs` | 15 tests成功。PHP/JS実装と独立参照値の照合を含む |
| `php -l`（src/public/bin/tests内の全PHP） | 40ファイル成功 |
| 下記Chromium 10スクリプト | 全成功 |
| `git diff --check` | 成功 |

```sh
# PHP 8.3+ / PDO SQLite / mbstring、Node、Python PlaywrightとChromiumが必要
set -e
php tests/run.php
python3 tests/http_test.py
node --test tests/*.test.mjs
while IFS= read -r -d '' file; do
  php -l "$file" || exit 1
done < <(find src public bin tests -name '*.php' -print0)
for test in browser candidate_browser answer_browser group_browser summary_browser export_browser day_browser restore_browser reception_browser confirmation_browser; do
  python3 "tests/$test.py"
done
git diff --check
```

途中の追加試験では、先頭参加者の回答種別を固定したfixture前提で1回失敗した。同時作成時のID順に依存しないよう、保存済み回答を独立に数えて復元後の○△×全3行と照合する形に修正し、全試験を再実行した。製品の不具合としては扱っていない。

Bash用。ローカルでPHPの配置が異なる場合はPATHを設定し、Chromiumは`CHROMIUM_BINARY`で指定できる。[CI定義](../.github/workflows/tests.yml)も同じ全試験を実行する。最終コミットのSHAとpush/PR両方のActions実行URL・実ログは、この報告を含むPRの検証欄に記録する（文書自身のSHAを本文に埋め込む循環を避ける）。

## 第15節の全項目との対応

番号末尾は各小節のチェック欄の順番。**検証済**＝上記隔離環境で実行成功。**導入確認併用**＝アプリ側の隔離試験は成功したが、記載した導入先確認が未実施。テストはファイル中の試験名・assertion文言で特定できる。

| 項目 | 条件 | 実装箇所 | 具体的試験・証拠 | 判定 |
|---|---|---|---|---|
| 15.1-1 | 同名別ID・通常リンク | [Schedules.php](../src/Schedules.php) create/listing、[web.php](../src/web.php) | [run.php](../tests/run.php) `independent instances with duplicate names`、[http_test.py](../tests/http_test.py) `test_workflow_and_security`のhref確認、[browser.py](../tests/browser.py)リンク操作 | 検証済 |
| 15.1-2 | 日付羅列・09–18一括初期化 | Schedules::preview/create | run.php `09-18 exact mask on every listed date`で全日の48値、browser.py重複日プレビューと作成 | 検証済 |
| 15.1-3 | 空title拒否・空/複数行説明編集消去 | Schedules::preview/change、web.php | run.php入力境界/改行正規化、browser.py複数行作成・編集・説明消去 | 検証済 |
| 15.1-4 | 説明は詳細だけ | Schedules::listing、web.php | run.php `no portal description`、HTTP `test_workflow_and_security`、browser.pyポータル非表示 | 検証済（検索機能なし） |
| 15.1-5 | script文字列を実行しない | web.phpのHTMLエスケープ | HTTP `test_workflow_and_security`のescaped HTML、browser.py説明のtext_content照合 | 検証済 |
| 15.1-6 | 名前確認付き論理削除・回答ごと復元 | Schedules::change、web.php削除画面 | browser.py誤名拒否/削除復元、HTTP通常URL404、[participants.php](../tests/participants.php) `schedule delete/restore retains answers` | 検証済 |
| 15.2-1 | 共通Basicの別端末から他人を編集 | [Participants.php](../src/Participants.php) submit、[answers.mjs](../public/assets/answers.mjs) | HTTP `test_participant_submission`独立CookieセッションのPUT成功、[answer_browser.py](../tests/answer_browser.py)独立contextと二画面編集 | 検証済（別端末相当の独立セッション） |
| 15.2-2 | 個人login/PIN/編集token不要 | Participants::submit、answers.mjs | 同HTTP試験が参加者ID・版数・回答のみで既存参加者を更新。ブラウザーも共有Basicのみ。submission_keyは新規登録の重複検出値で編集資格ではない | 検証済 |
| 15.2-3 | 未認証拒否・本番fail closed | [Security.php](../src/Security.php)、[dev-router.php](../bin/dev-router.php) | HTTP `test_workflow_and_security`/`test_exports`各401、`test_production_fails_closed`は本番入口PHPへ偽造ヘッダーを渡して401。実Webサーバー境界はD1–D3 | 導入確認併用 |
| 15.2-4 | 監査shared-basic | Schedules/Participants等の監査書込み | run.php `shared actor`、participants.php `shared audit actor`、各機能PHP試験で監査・rollback確認 | 検証済（個人識別なし） |
| 15.2-5 | CSRF・入力検証・不正更新拒否 | Security::mutation、各service/API | HTTP全mutation試験の無token/異Origin/無認証/異案件/型不正拒否、run.php入力境界 | 検証済 |
| 15.3-1 | 日別48列・固定16px | [grid.php](../src/grid.php)、[gyugyu.css](../public/assets/gyugyu.css) | [candidate_browser.py](../tests/candidate_browser.py)2日縦配置/48列/16px/約916px、広いviewportとmobile。answer_browser.py回答列16px | 検証済 |
| 15.3-2 | 1時間2列の時ラベル | grid.php | candidate_browser.py colspan=2が24個・翌日24、grid.phpヘッダー生成を照合（30分セル内HH:mmなし） | 検証済 |
| 15.3-3 | ○△×unknown・ドラッグ | answers.mjs、grid.php、gyugyu.css | answer_browser.py4状態/ドラッグ/clear/取消/pointercancel/キーボード/タッチ | 検証済 |
| 15.3-4 | 数字3行のみ | [summary.mjs](../public/assets/summary.mjs)、grid.php | [summary_browser.py](../tests/summary_browser.py)3行・個別数値・表示モード、描画実装に棒/濃度表現なし | 検証済 |
| 15.3-5 | Y+T+X+U=N | [Summary.php](../src/Summary.php)、summary.mjs | [summary.test.mjs](../tests/summary.test.mjs)4人全256組合せ×5マスク=1,280ケースの独立人数参照＋PHP bridge照合 | 検証済 |
| 15.3-6 | N=5等の順位がX昇順/Y降順 | Summary::calculate、summarize | summary.test.mjs `PHP/JS rank multiple slots...through N=6`。N=0–6の全人数組合せを実装に入力し、独立X/Yソートと比較。T/U同点・候補外も検証（数式だけの比較ではない） | 検証済 |
| 15.3-7 | 未入力は暫定・N0/候補外scoreなし | Summary、summaryView | summary.test.mjs provisional/null/ranking、summary_browser.py暫定表示・N0の—/順位なし・候補除外 | 検証済 |
| 15.4-1 | 0群で登録・全員集計 | Participants、Summary、[groups.mjs](../public/assets/groups.mjs) | answer_browser.py0群で登録、[group_browser.py](../tests/group_browser.py)全群archive後対象3人、summary.test.mjs0群includeでも全員 | 検証済 |
| 15.4-2 | Web名前だけで群追加・0–9制限 | [Groups.php](../src/Groups.php)、[group-page.php](../src/group-page.php)、003 migration | group_browser.py名前だけ追加・9件UI停止、[groups.php](../tests/groups.php)10件API拒否/DB INSERT・復元trigger拒否 | 検証済 |
| 15.4-3 | OR重複排除・include/exclude/all | Summary、groups.mjs | group_browser.py複数所属の対象人数、[groups.test.mjs](../tests/groups.test.mjs)、summary.test.mjs独立人数/PHP照合 | 検証済 |
| 15.4-4 | 縦複数日・全日/指定日への一括3操作 | [candidates.mjs](../public/assets/candidates.mjs)、Schedules::candidates | candidate_browser.py縦配置/指定日一括プレビュー保存、[candidates.test.mjs](../tests/candidates.test.mjs) `bulk selected days, replace/add/remove`、[candidates.php](../tests/candidates.php)複数日の原子的保存 | 検証済 |
| 15.4-5 | 候補drag除外→集計除外→再有効化復元 | candidates.mjs、Summary、Participants | summary_browser.py実ドラッグで2枠除外/復元し3行数値・ranking・全回答不変を照合、answer_browser.py候補外回答保持、participants.php再有効化履歴 | 検証済 |
| 15.4-6 | 回答/候補排他・取消/Undo/保存状態 | answers.mjs、candidates.mjs | answer_browser.py/candidate_browser.py排他/Undo/Redo/取消/成功・通信失敗・競合表示 | 検証済 |
| 15.5-1 | SQLite・ブラウザー閉じ別端末相当で回答一致 | [Database.php](../src/Database.php)、Participants | answer_browser.py全ブラウザープロセス終了後に新規起動・新規セッションで全参加者×全日48セルと保存済回答を照合、DB snapshot不変 | 検証済 |
| 15.5-2 | 二端末同参加者競合409 | Participants::submit | HTTP `test_participant_submission`旧版409、answer_browser.py独立contextの競合再送と下書き保持 | 検証済 |
| 15.5-3 | 4出力・JSON説明/候補外履歴 | [Export.php](../src/Export.php) | [exports.php](../tests/exports.php)4形式/完全履歴・型・元説明、HTTP `test_exports`、[export_browser.py](../tests/export_browser.py)実ダウンロード | 検証済 |
| 15.5-4 | CSV数式防御・氏名/説明escape | Export::csvText、HTML描画 | exports.php数式/空白/制御文字/改行/引用符、日本語・負数score、HTTP `test_exports`/`test_participant_submission`/`test_workflow_and_security`のXSS文字列 | 検証済 |
| 15.5-5 | DBをURL非公開・実バックアップ復旧 | public境界、[backup.php](../bin/backup.php) | HTTP `test_workflow_and_security`私有パス404、`test_backup_restore`別DBへ復元して回答/所属照合・integrity/FK検査、confirmation.php確定情報復元。導入先境界/運用はD2/D4 | 導入確認併用 |

## 残る導入先チェック（未実施）

機能開発の追加バックログではない。**試用環境への導入はユーザー承認後**に行い、実際のURL・サーバー構成で次の証拠を記録する。本作業では配置・稼働DBのmigration・設定変更を実施していない。

| ID | 試用導入時の確認と合格条件 |
|---|---|
| D1 | PHP 8.3+ / pdo_sqlite / mbstring / sessionとSQLite、PHP-FPMの実行環境を確認。HTTPS証明書とHTTPからの安全な導線、既存の共通Basicを検証。PHPへ渡すREMOTE_USER/HTTPSが信頼されたサーバー由来で、クライアント偽造値を採用しない。開発ルーター・開発資格情報を配置経路に使わない。 |
| D2 | DocumentRoot/aliasはpublic/のみ。認証なしでポータル・管理画面・API・CSV/JSON・JS/CSS/SVGがすべて401。認証ありでもDB本体/WAL/SHM・backup・設定・src・database・bin・.gitが取得不可（403/404）。隔離した導入検証経路で認証情報欠落時のPHP fail closedを確認し、稼働認証を外して試験しない。 |
| D3 | 実サブパスのリンク・API・ダウンロードを確認。CookieのSecure/HttpOnly/SameSite=Strict/pathとno-store、正しいOrigin/CSRFだけ更新可。共有アカウントの独立セッションで登録→他人の回答編集→競合拒否を試用データで確認。 |
| D4 | DB/backupをWeb外へ置き、PHP実行ユーザーの必要な書込みと他ユーザーのアクセス制限を確認。バックアップ保存先・日次/移行前ジョブ・保持期間・担当者を定める。既存DBがあれば事前backup後にmigration。導入先のバックアップを別の隔離DBへ復元しintegrity/FKと回答/所属/確定情報を確認する。隔離試験での復旧成功を「未検証」に戻す趣旨ではなく、現地の権限・手順を検証する。 |
| D5 | 試用データで作成→回答→集計→出力→論理削除/復元を実URLから確認し、利用者に全員同権限・個人特定不可を説明。結果と残る運用上の問題を記録して試用開始を判断する。 |

## 制限と終了条件

- 下書き保持は現在のページ内。ブラウザー終了後に未保存下書きを復元しない。他端末の更新は再読込みで取得する。競合/応答喪失時に無条件再送しない。
- 新規日付の初期時間帯は当日06時以降開始＋翌日終了指定。翌日早朝だけの候補は既存の候補編集で設定する。日数は履歴を含む累計366日/案件。
- 回答・候補・日付・復帰・受付/確定等の対話操作にはJavaScriptが必要。日時確定は30分1枠のみ。中クリック等の別タブ遷移では確定画面のマスク初期値が全員になる場合があり、表示された条件を再確認する。
- 共通Basicの監査はshared-basicで個人を識別しない。実端末・他ブラウザーへの対応範囲拡張、性能目標、連続候補推奨、import、通知等は今回の完了条件に追加しない。
- READMEの過去の非阻害QAメモは任意の追加調査であり、第15節の未達項目ではない。

**この報告と試験のPRをレビューして区切る。自動的に次の実装段階へ進まない。** 次はユーザー承認を得た試用環境への導入とD1–D5の実測であり、試用で発見した既存スコープ内の問題だけを扱う。
