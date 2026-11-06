#!/bin/sh
# SQLite DB 일일 백업 (cron: 15 3 * * * /var/www/hannun/bin/backup.sh). docs/DEPLOY.md 참고
# 쓰는 중에 파일을 그대로 복사하면 깨질 수 있어 sqlite3 .backup 으로 뜬다.
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${DB_PATH:-$ROOT/storage/database.sqlite}
DIR=${BACKUP_DIR:-/var/backups/hannun}
KEEP_DAYS=${KEEP_DAYS:-14}

mkdir -p "$DIR"
FILE="$DIR/hannun-$(date +%Y%m%d).sqlite"
sqlite3 "$DB" ".backup '$FILE'"
gzip -f "$FILE"
find "$DIR" -name 'hannun-*.sqlite.gz' -mtime +"$KEEP_DAYS" -delete
echo "$(date '+%Y-%m-%d %H:%M:%S') backup: $FILE.gz"
