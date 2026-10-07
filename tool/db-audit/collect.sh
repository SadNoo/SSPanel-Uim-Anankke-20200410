#!/usr/bin/env bash
# 收集面板实际部署版本和数据库结构信息，用于和代码仓库对比。
# 不导出任何用户数据，不读取配置项的值（包括密码）。
#
# 用法（在面板服务器上执行）：
#   bash collect.sh <面板根目录> <数据库名> [数据库用户] [数据库地址] [端口]
#   例：bash collect.sh /www/wwwroot/sspanel sspanel root 127.0.0.1 3306
# 运行时会提示输入数据库密码。
# 完成后生成 db-audit-<时间>.tar.gz，把它发回即可。

set -euo pipefail

PANEL_DIR=${1:?请提供面板根目录}
DB_NAME=${2:?请提供数据库名}
DB_USER=${3:-root}
DB_HOST=${4:-127.0.0.1}
DB_PORT=${5:-3306}

OUT="db-audit-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUT"

read -r -s -p "数据库密码（${DB_USER}@${DB_HOST}）: " DB_PASS
echo

# 用临时配置文件传密码，避免出现在进程列表里
CNF=$(mktemp)
trap 'rm -f "$CNF"' EXIT
chmod 600 "$CNF"
printf '[client]\nuser="%s"\npassword="%s"\nhost="%s"\nport=%s\n' "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT" > "$CNF"

MYSQL=(mysql --defaults-extra-file="$CNF")
MYSQLDUMP=(mysqldump --defaults-extra-file="$CNF")

echo "[1/5] 数据库版本和参数"
{
  mysql --version || true
  "${MYSQL[@]}" -e "SELECT VERSION() AS version, @@version_comment AS comment;"
  "${MYSQL[@]}" -e "SHOW VARIABLES WHERE Variable_name IN (
      'character_set_server','collation_server','character_set_database',
      'sql_mode','default_authentication_plugin','authentication_policy',
      'innodb_version','innodb_file_per_table','have_ssl','require_secure_transport',
      'tls_version','time_zone','system_time_zone','lower_case_table_names');"
} > "$OUT/db-version.txt" 2>&1

echo "[2/5] 表结构（不含数据）"
DUMP_OPTS=(--no-data --skip-comments --routines --triggers --events --single-transaction)
# 新版 mysqldump 连接旧版 MySQL 时需要关闭 column-statistics
if ! "${MYSQLDUMP[@]}" "${DUMP_OPTS[@]}" "$DB_NAME" > "$OUT/schema.sql" 2> "$OUT/schema.err"; then
  "${MYSQLDUMP[@]}" --column-statistics=0 "${DUMP_OPTS[@]}" "$DB_NAME" > "$OUT/schema.sql"
fi

echo "[3/5] 表行数、大小、字符集"
"${MYSQL[@]}" -e "
  SELECT TABLE_NAME, ENGINE, TABLE_ROWS, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) AS size_mb,
         TABLE_COLLATION, CREATE_TIME, UPDATE_TIME
  FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' ORDER BY TABLE_NAME;" \
  > "$OUT/tables.tsv"

echo "[4/5] 面板代码版本"
{
  echo "== git =="
  if [ -d "$PANEL_DIR/.git" ]; then
    git -C "$PANEL_DIR" log -5 --format='%H %ad %s' --date=iso || true
    git -C "$PANEL_DIR" remote -v | sed -E 's#://[^@/]+@#://***@#' || true
    git -C "$PANEL_DIR" status --short | head -100 || true
  else
    echo "no .git"
  fi
  echo "== composer.json require =="
  [ -f "$PANEL_DIR/composer.json" ] && sed -n '/"require"/,/}/p' "$PANEL_DIR/composer.json"
  echo "== composer.lock packages =="
  if [ -f "$PANEL_DIR/composer.lock" ]; then
    grep -E '"name"|"version"' "$PANEL_DIR/composer.lock" | paste - - | sed 's/[",]//g' | head -300
  fi
} > "$OUT/code-version.txt" 2>&1

# PHP 文件指纹：用于在仓库历史中定位最接近的提交
( cd "$PANEL_DIR" && find app config resources/views -type f \( -name '*.php' -o -name '*.tpl' \) \
    ! -name '.config.php' -print0 2>/dev/null | sort -z | xargs -0 sha1sum ) > "$OUT/file-hashes.txt" || true
( cd "$PANEL_DIR" && ls -la && ls sql 2>/dev/null ) > "$OUT/tree.txt" 2>&1 || true

# 只取配置项名称，不取值
if [ -f "$PANEL_DIR/config/.config.php" ]; then
  grep -oE "\\\$(System_Config|_ENV)\['[A-Za-z0-9_]+'\]" "$PANEL_DIR/config/.config.php" \
    | sed -E "s/.*\['(.*)'\]/\1/" | sort -u > "$OUT/config-keys.txt"
fi

echo "[5/5] PHP 环境"
{ php -v; php -m; } > "$OUT/php.txt" 2>&1 || echo "php not found" > "$OUT/php.txt"

tar czf "$OUT.tar.gz" "$OUT"
rm -rf "$OUT"
echo "完成：$OUT.tar.gz"
echo "发送前可以先解压检查内容；里面不包含用户数据和密码。"
