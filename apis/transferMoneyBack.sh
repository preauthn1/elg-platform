#!/bin/bash

# 可配置變數
VENDOR_ID=27
AGENT_ID=71541
SKIP_START=0     # 起始 skip
SKIP_END=30000   # 最大 skip
SKIP_STEP=1000   # 每次增加
LIMIT=1000       # 每次 batch 的 limit

# 迴圈執行
for skip in $(seq $SKIP_START $SKIP_STEP $SKIP_END)
do
  php artisan vendor:transfer_money_back \
    --vendorId=$VENDOR_ID \
    --agentId=$AGENT_ID \
    --skip=$skip \
    --limit=$LIMIT \
    > transfer_${skip}.log 2>&1 &
done