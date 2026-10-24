#!/bin/bash

# 設定變數
ref="main" # 要觸發的 branch
pipeline_id=$1

# 定期檢查 Pipeline 狀態
while true; do
  response=$(curl -s --header "PRIVATE-TOKEN: [REDACTED]" \
    "http://example.invalid:1688/api/v4/projects/$DEPLOY_PROJECT_ID/pipelines/$pipeline_id")
  status=$(jq -r '.status' <<< "$response")
  echo $status
  if [[ $status == "success" || $status == "failed" || $status == "canceled" ]]; then
    echo "Pipe sline status: $status"
    break
  fi

  echo "Waiting for pipeline to finish..."
  sleep 3
done

if [[ $status == "success" ]]; then
  echo "Pipeline completed successfully!"
else
  echo "Pipeline failed."
  exit 1
fi
