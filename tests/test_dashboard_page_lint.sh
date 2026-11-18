#!/bin/bash
# tests/test_dashboard_page_lint.sh
set -e
php -l <(sed -n '/^<?php/,/^?>/p' src/ServerStatusDashboard.page) || { echo "FAIL: PHP syntax error in ServerStatusDashboard.page"; exit 1; }
grep -q 'ss-row-live' src/ServerStatusDashboard.page || { echo "FAIL: missing live row container"; exit 1; }
grep -q 'ss-row-recording' src/ServerStatusDashboard.page || { echo "FAIL: missing recording row container"; exit 1; }
grep -q 'activestreams_status.php' src/ServerStatusDashboard.page || { echo "FAIL: not polling the status endpoint"; exit 1; }
echo "ALL PASS"
