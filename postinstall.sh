#!/bin/bash

# Register daily cron at 18:05 for rolling window publish (today 18:00 → tomorrow 17:59:59)
CRONLINE="5 18 * * * /usr/bin/php $LBPHTMLAUTHDIR/run_rolling_fetch.php >/dev/null 2>&1"
TMP=$(mktemp)
crontab -l 2>/dev/null | grep -v 'run_rolling_fetch.php' > "$TMP"
echo "$CRONLINE" >> "$TMP"
crontab "$TMP"
rm "$TMP"
echo "[postinstall] Cron registered: $CRONLINE"

exit 0
