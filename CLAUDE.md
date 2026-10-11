@AGENTS.md

# まちチケ

説明 : 主催者がイベントと券種を登録し、参加者が Stripe Checkout でチケットを買うアプリ

## このプロジェクトの決まり

- 画面は Livewire 4 の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`）で作る。Volt は使わない
- UI は Flux UI の Free 版のコンポーネントだけを使う（Pro 専用は使わない）。無いものは Tailwind で作る
- 文言は日本語。日付は `isoFormat('M月D日(ddd) HH:mm')`、金額は「3,000円」の形式
- 認可は `app/Policies/` のポリシーで行い、画面側では `$this->authorize()` を使う
- 外部サービス（Stripe など）に触るコードは `app/Services/` に閉じ込める。テストではそのクラスをモックする
- 機能を追加したら `tests/Feature/` に Pest テストを追加し、`/check` が通ることを確認する
- 開発コマンドは Sail 経由（`sail artisan ...`、`sail composer ...`、`sail npm ...`）。ホストの php や composer は使わない
- ビューを追加・変更したら `sail npm run build` を実行する（Tailwind がクラスを拾うため）

## 作業の進め方

- 変更の前に、何をどのファイルに書くかを短く説明してから実装する
- 1回の指示で扱う範囲は1機能まで。大きい依頼は分割案を先に出す
- 既存のファイルの書き方に合わせる（同じ階層のファイルを先に読む）
- `.env` の値、API キー、パスワードはコードやコミットに含めない
- 失敗したコマンドは、原因の推測と対処案を1つずつ示してから再実行する

## 設計（Day4 以降に追記していく）

詳細: [doc/design/database.md](doc/design/database.md)（ER 図・カラム定義・残り枚数）、[doc/design/screens.md](doc/design/screens.md)（画面・URL・ロール）

### 用語

- イベント（`events`）: 主催者が登録する催し。`draft`（下書き）/ `published`（公開）/ `unpublished`（管理者が非公開化。主催者は戻せない）
- 券種（`ticket_types`）: イベントごとの価格・定員・販売期間の区分（一般、学生など）
- 注文（`orders`）と注文明細（`order_items`）: 1回の購入と、券種ごとの枚数。`pending`（支払い待ち）→ `paid`（支払い済み）
- チケット（`tickets`）: `paid` になったとき、1枚ずつ発行する。固有のコード（ULID）を持つ

### データベース

- 主催者は `events.organizer_id`、注文者は `orders.user_id`。`user_id` と混同しない
- ロール・イベントの状態・注文の状態は string カラムに入れ、Enum（`UserRole` / `EventStatus` / `OrderStatus`）にキャストする
- 金額は円の整数（unsigned int）。明細には注文時点の単価を `unit_price` に保存し、券種の `price` は参照しない
- 1注文 = 1イベント（`orders.event_id`）。明細の券種はすべて同じイベントのもの
- 売上に関わる外部キーは restrict（注文があるイベント・券種は削除不可）。子データ（券種・明細・チケット）だけ cascade
- 残り枚数はカラムに持たず `TicketType::remainingCount()` で計算する。数える注文は2種類: `paid` と、作成から35分以内の `pending`

### 画面

- 公開側は `/`（一覧）と `/events/{event}`（詳細・購入）。下書きは主催者本人以外には 404
- 主催者用は `/organizer/events` 配下。券種の編集と公開の切り替えは、イベント編集画面の中で行う
- マイページは `/my/orders` 配下。他人のイベント・注文はポリシーで 403
- 購入はログインユーザー全員（主催者も含む）が使える
