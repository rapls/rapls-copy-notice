# Rapls Copy Notice

訪問者が記事の文章をコピーしたことを検知して、サイト管理者にメールで知らせる WordPress プラグインです。コピーを**止めるためのものではありません**。右クリックや範囲選択はそのまま使えます。どの記事のどの箇所がコピーされているかが分かるだけです。

外部サービスは一切呼びません。コピーの記録は自分のサイトにだけ送られ、通知は WordPress 自身のメール機能で届きます。

- **WordPress.org**: https://wordpress.org/plugins/rapls-copy-notice/
- **プラグイン紹介ページ**: https://raplsworks.com/plugins/rapls-copy-notice/
- **マニュアル**: [日本語](https://raplsworks.com/rapls-copy-notice-free-manual-ja/) / [English](https://raplsworks.com/rapls-copy-notice-free-manual-en/)

## Features

- **コピーログ** — ツール → Rapls Copy Notice に、日時・記事・コピーされた文章・短縮した IP アドレス・ブラウザーを記録。記事で絞り込み、選んで削除、全削除
- **メール通知** — 1 時間ごとのまとめ（既定）か、コピーごとに 1 通（上限つき。超えた分はまとめに回る）。件名ラベルは自由に設定できる
- **検知の条件** — 20 文字以上（変更可）、記事本文の中だけ、選んだ投稿タイプだけ。テーマの構造が特殊でも、本文の終わりに目印を置くオプションで検知できる
- **除外** — ログイン中の管理者・編集者は既定で無視。IP アドレス（CIDR 可）と個別の記事も除外できる
- **ダッシュボードウィジェット** — 直近 7 日のコピー数と、よくコピーされた記事 3 件
- **サイトヘルス** — ログテーブル、予約タスク、通知先、失敗が続く通知、監視対象の投稿タイプ、プロキシ設定、データベースロックの 7 項目を点検
- **読者向けの任意機能（既定オフ）** — コピー直後の短いメッセージ表示と、コピーした文章への記事タイトルと URL の追記
- **自動削除** — ログは 90 日（変更可）で消える
- **ページキャッシュと共存** — 検知スクリプトは訪問者ごとのトークンを使わない
- **多言語対応** — 日本語翻訳は [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/rapls-copy-notice/) で管理

## 訪問者データの扱い

コピー報告に含まれる値はすべて訪問者のブラウザーから来るので、全部を信用できない入力として扱います。

- **出力時に必ずエスケープ** — コピーされた文章、ユーザーエージェント、リファラー、IP アドレスは、管理画面に出すたびに、出す場所（HTML テキスト、属性、URL）に合わせてエスケープします。保存時の無害化には頼りません
- **メールはテキストのみ** — 通知は常に `text/plain`。件名はサイト名、ラベル、件数だけで、訪問者が送った値や記事タイトルは入りません
- **レート制限** — 既定で 1 IP あたり 1 分 10 件、サイト全体で 1 時間 500 件。超えた報告は保存も通知もされません
- **実在する記事だけ** — 公開済みで監視対象の投稿に対する報告だけを受け付けます。文章は 500 文字、ユーザーエージェントとリファラーのホストは 255 文字で切ります
- **何も返さない** — エンドポイントはステータスコードと空のボディだけを返します
- **IP アドレスは既定で短縮** — IPv4 は末尾 1 オクテット、IPv6 は下位 80 ビットを落として保存。`X-Forwarded-For` は、信頼するプロキシとして登録したアドレスからのリクエストでだけ読みます

Cookie は使わず、訪問者のブラウザーには何も保存しません。レート制限のために IP アドレスの一方向ハッシュを最長 1 時間だけ持ちます。プライバシーポリシーの文例は **設定 → プライバシー** に追加されます。

## Installation

### WordPress.org から（推奨）

1. WordPress 管理画面 → **プラグイン** → **新規追加**
2. 「Rapls Copy Notice」で検索 → **インストール**
3. 有効化

### GitHub から

```bash
cd wp-content/plugins
git clone https://github.com/rapls/rapls-copy-notice.git
```

有効化後はそれだけで動きます。既定の設定で、投稿と固定ページからの 20 文字以上のコピーが記録され、1 時間ごとにサイトの管理者メールアドレスへまとめが届きます。

## セットアップ

### 前提条件

- **WordPress 6.3 以上**
- **PHP 8.2 以上**
- メールが届くサイト（多くのホスティングでは SMTP プラグインが必要です）
- 段落の指紋（下記）を記録するには HTTPS

### クイックスタート

1. **ツール → Rapls Copy Notice** を開く
2. 「通知」タブで通知先と件名ラベルを決め、**テストメールを送信** で届くことを確かめる
3. 「検出」タブで最小文字数、投稿タイプ、除外する IP や記事を決める
4. ログアウトした状態（またはプライベートウィンドウ）で記事の文章をコピーし、「ログ」タブに出るのを確認する

詳しくは [マニュアル](https://raplsworks.com/rapls-copy-notice-free-manual-ja/) を参照。

## よくある質問 / トラブルシューティング

### Q: コピーを防止できますか？

**A:** できませんし、そのために作っていません。選択や右クリックの禁止は普通の読者の邪魔になるだけで、本気の相手は止められません。このプラグインは「何がコピーされているか」を知らせるだけです。

### Q: 何も記録されません

**A:** 管理者と編集者は既定で無視されるので、ログアウトするかプライベートウィンドウで試してください。1 つの記事または固定ページの本文から 20 文字以上をコピーします。テーマが本文を `.entry-content`、`.post-content`、`article` で囲んでいない場合は、「検出 → 本文の範囲」に正しい CSS セレクターを入れるか、「検出 → 本文の見つけ方」で本文の終わりに目印を置く方式に切り替えてください。コメント欄やお問い合わせフォームなど、入力欄からのコピーは記録しません。

### Q: 1 時間ごとのまとめが遅れて届きます

**A:** まとめは WP-Cron で送るので、誰かがサイトを訪れたときに動きます。アクセスの少ないサイトでは、予定時刻のあとの最初の訪問で送られます。

### Q: Cloudflare などのプロキシの後ろにいて、IP が全部同じになります

**A:** 「検出 → 信頼するプロキシ」にプロキシの IP アドレスか範囲を登録してください。そのプロキシから来たリクエストに限って `X-Forwarded-For` から訪問者のアドレスを読みます。

### Q: Google Analytics などでコピーを数えたい

**A:** プラグイン自体は解析サービスへ何も送りません。代わりに、サイトへ報告するたびにページ上で `rapls-cn:copy` イベントを発火します（投稿 ID と選択した長さが入っています）。自分のスクリプトで拾って転送してください。

```js
document.addEventListener( 'rapls-cn:copy', function ( e ) {
    // e.detail.postId, e.detail.length
} );
```

### Q: Check Copy Contents から乗り換えられますか？

**A:** はい。同じ用途をゼロから書き直した別のプラグインです。初回有効化のときに旧プラグインの設定がデータベースに残っていれば、通知先、最小文字数、独自の件名を引き継ぎます。引き継いだあとは旧プラグインを停止してください。そうしないとコピーごとにメールが 2 通届きます。

---

## 段落の指紋

コピーごとに、元になった段落の指紋（段落の文章の SHA-1 ハッシュ）も記録します。ハッシュは読者のブラウザーの中で計算し、段落そのものは保存しません。ログの **この段落のコピー** から、同じ段落から取られたコピーを一覧できます。ブラウザーがハッシュ関数を提供するのは HTTPS のページだけなので、HTTP のサイトでは指紋なしで記録されます。

## 開発者向けフック

| フック | 種類 | 用途 |
|---|---|---|
| `rapls_cn_logged` | action | コピーが保存された後。行 ID と保存した値を渡す |
| `rapls_cn_notification_delivered` | filter | アドオンが通知を別のチャンネルへ送り、送れたら `true` を返す |
| `rapls_cn_email_rows` | filter | 通知メールに載せる行を選ぶ。空配列を返すとそのメールは送らない |
| `rapls_cn_privacy_policy_paragraphs` | filter | プライバシーポリシー文例（`collected` / `ip` / `use` / `retention`） |
| `rapls_cn_log_emptied` | action | 「全削除」でログが空になった後 |
| `rapls_cn_admin_tabs` / `rapls_cn_admin_load` / `rapls_cn_render_tab_{key}` | filter / action | 設定画面にタブを追加する |
| `rapls_cn_is_pro` / `rapls_cn_show_upsell` / `rapls_cn_pro_url` | filter | Pro アドオンの有無と、Pro 機能タブの表示・リンク先 |

フックに渡る値のうち訪問者のブラウザー由来のものは、保存されたままの形です。出力する場所に合わせてエスケープしてください。

---

## Documentation

- [WordPress.org プラグインページ](https://wordpress.org/plugins/rapls-copy-notice/)
- [プラグイン紹介ページ](https://raplsworks.com/plugins/rapls-copy-notice/)
- [マニュアル（日本語）](https://raplsworks.com/rapls-copy-notice-free-manual-ja/) / [Manual (English)](https://raplsworks.com/rapls-copy-notice-free-manual-en/)
- [サポートフォーラム](https://wordpress.org/support/plugin/rapls-copy-notice/)

## Pro 版

無料版だけで完結し、試用期間や上限はありません。Rapls Copy Notice Pro は、コピーに「対処したい」サイト向けの別プラグインです。

- 記事別・段落別の統計（前の期間との比較つき）
- Slack / Chatwork / Discord / 任意の Webhook への通知。配信履歴と再送、送る訪問者データの選択
- コピーをチャンネルへ振り分けるルールと、急増アラート
- よくコピーされる段落の毎日の Web 検索（自分の検索 API キーを使用）
- ログの CSV エクスポート
- マルチサイト: 5 サイトライセンスでネットワーク全体をカバー、全サイトの一覧

買い切りで、サブスクリプションではありません。

詳細: [Rapls Copy Notice Pro（日本語）](https://raplsworks.com/rapls-copy-notice-pro-ja/) / [English](https://raplsworks.com/rapls-copy-notice-pro-en/) / [Pro マニュアル](https://raplsworks.com/rapls-copy-notice-manual-pro-ja/)

---

## Development

### Requirements

- WordPress 6.3 以上
- PHP 8.2 以上
- 外部ライブラリなし（Composer 不要）

### Contributing

バグ報告・機能要望は [Issues](../../issues) までお願いします。Pull Request も歓迎です。

## Changelog

詳細は [readme.txt](./readme.txt) をご覧ください。

## Author

**Rapls（ラプルス）**
フリーランス Web 開発者 / WordPress Polyglots PTE（日本語翻訳責任者）
- [Rapls Works](https://raplsworks.com/)
- [WordPress.org プロフィール](https://profiles.wordpress.org/rapls/)
- [GitHub](https://github.com/rapls)

## License

GPL v2 or later
