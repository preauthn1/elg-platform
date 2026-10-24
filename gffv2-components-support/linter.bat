@echo off

echo ==================== phpcs start ====================

call vendor\bin\phpcs --standard=psr2 src

echo ==================== phpcs finish ====================

echo ==================== phpmd start ====================

call vendor\bin\phpmd .\src text codesize,design,naming,unusedcode

echo ==================== phpmd finish ====================
