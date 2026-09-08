# Syoka（ショーカ）

**Syoka** は、RSSから紹介したい記事を選び、紹介記事の下書きを作る **独立Webアプリ** です。

WordPressプラグインではありません。WordPressに依存せず、PHPが動くサーバーへ設置して利用します。

## 主な機能

- RSSを **最大10サイト** 登録
- RSSごとに **最新5記事** を候補表示
- 画像・タイトル・URL・日付・紹介文候補を取得
- 記事を確認して、選択したものだけを下書き保存
- 同じGUIDまたは元URLの重複保存を防止
- 画像が無い場合は **「ここに画像が入ります」** と表示
- 下書きでタイトル・紹介文・画像URLを手動編集
- 完成HTMLをワンクリックでコピー
- RSSキャッシュ10分＋手動再取得
- ログイン保護、CSRF対策、RSS URLのSSRF対策
- PC / スマートフォン対応

## 必要環境

- PHP 8.1 以上
- PDO_SQLite
- cURL
- SimpleXML
- mbstring 推奨
- 書き込み可能な `data/` ディレクトリ

データベースサーバーは不要です。初回セットアップ時にSQLiteを自動作成します。

## インストール

1. リポジトリをWebサーバーへ配置します。
2. ブラウザで `install.php` を開きます。
3. ログインIDとパスワードを設定します。
4. セットアップ完了後、`login.php` からログインします。
5. 「RSSサイト管理」からRSSを登録します。

### Apache

同梱の `.htaccess` で内部ファイルと `data/` へのWebアクセスを拒否します。`AllowOverride` が無効な環境では、Webサーバー側で同等のアクセス制限を設定してください。

### Nginx

Nginxでは `.htaccess` が効かないため、少なくとも次のように `data/` と `app.php` への直接アクセスを拒否してください。

```nginx
location ^~ /data/ { deny all; }
location = /app.php { deny all; }
```

## 使い方

### 1. RSSサイト管理

- RSS URLを最大10件まで登録できます。
- 有効 / 無効を切り替えられます。
- URLの編集・削除ができます。

### 2. 候補一覧

有効なRSSごとに最新5記事を表示します。

表示内容：

- 画像
- タイトル
- URL
- 日付
- 紹介文候補
- 下書き済み / 未保存

紹介したい記事をチェックして「選択した記事を下書き作成」を押します。

### 3. 下書き一覧 / 編集

保存した下書きは後から編集できます。

- タイトル
- 画像URL
- 紹介文

画像が取得できなかった記事には、編集画面で次の目印を表示します。

> ここに画像が入ります

画像URLを後から手動で追加できます。

編集画面では、紹介記事用のHTMLを生成してコピーできます。

## RSS画像の取得順

1. `media:thumbnail`
2. `media:content`
3. `enclosure`
4. RSS本文 / description 内の最初の `<img>`

画像はSyokaサーバーへ自動保存せず、RSSから取得した画像URLを下書きへ保存します。

## 重複防止

GUIDがある場合はGUIDを優先し、元記事URLも合わせて確認します。すでに下書き保存済みの記事は候補一覧で選択できません。

## セキュリティ

- パスワードは `password_hash()` で保存
- ログイン成功時にセッションIDを再生成
- POST操作はCSRFトークンで検証
- RSS取得先はHTTP/HTTPSのみ
- localhost / private IP / reserved IP をRSS取得対象から除外
- RSS取得はリダイレクトを自動追跡しない
- 取得サイズ・接続時間・処理時間に上限を設定
- SQLiteファイルはGit管理対象外

## データ保存

初期状態では次へ保存します。

```text
data/syoka.sqlite
```

Gitには保存されません。

## ディレクトリ構成

```text
Syoka/
├── index.php
├── login.php
├── logout.php
├── install.php
├── app.php
├── assets/
│   └── style.css
├── data/
│   └── （SQLiteを自動生成）
├── .htaccess
├── .gitignore
└── README.md
```

## 旧WordPressプラグイン版について

Syokaは当初WordPressプラグインとして設計していましたが、用途を見直し、現在は独立Webアプリとして開発しています。
