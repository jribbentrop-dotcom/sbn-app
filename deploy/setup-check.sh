#!/bin/bash
echo "=== OS ===" && cat /etc/os-release | grep PRETTY
echo "=== nginx ===" && nginx -v 2>&1 || echo "not installed"
echo "=== PHP ===" && php -v 2>&1 | head -1 || echo "not installed"
echo "=== git ===" && git --version || echo "not installed"
echo "=== node ===" && node -v 2>&1 || echo "not installed"
echo "=== composer ===" && composer --version 2>&1 | head -1 || echo "not installed"
echo "=== free memory ===" && free -h | head -2
echo "=== disk ===" && df -h / | tail -1
