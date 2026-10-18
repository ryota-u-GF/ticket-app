#!/bin/bash
set -e

cd /var/www/ticket-app

echo "==> 最新のコードを取得しています (git pull)"
git pull origin main

echo "==> PHP の依存パッケージをインストールしています (composer install)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> フロントエンドをビルドしています (npm ci && npm run build)"
npm ci && npm run build

echo "==> マイグレーションを実行しています (php artisan migrate)"
php artisan migrate --force

echo "==> キャッシュを最適化しています (php artisan optimize)"
php artisan optimize

echo "==> デプロイが完了しました"
