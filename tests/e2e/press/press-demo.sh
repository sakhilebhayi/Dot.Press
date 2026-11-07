#!/bin/zsh
# usage: press-demo.sh <worktree-dir> <port>   creates a fresh sqlite db with the demo data and serves the app
cd "$1" || exit 1
[ -f .env ] || { cp .env.example .env; php artisan key:generate -q; }
DB=/tmp/press-demo-$(basename "$1").sqlite
rm -f $DB; touch $DB
grep -q '^DB_DATABASE=' .env && sed -i '' "s#^DB_DATABASE=.*#DB_DATABASE=$DB#" .env || echo "DB_DATABASE=$DB" >> .env
sed -i '' "s#^APP_URL=.*#APP_URL=http://127.0.0.1:$2#" .env
php artisan migrate:fresh --seed --seeder=PressDemoSeeder --force >/dev/null 2>&1
(php artisan serve --port=$2 > /tmp/press-serve-$2.log 2>&1 &)
echo "serving $1 on http://127.0.0.1:$2 (owner@demo.test / password, editor@demo.test, viewer@demo.test)"
