#!/bin/bash

# 設定變數
project_id="526"
ref="main" # 要觸發的 branch
pipeline_id=$1

# 定期檢查 Pipeline 狀態
while true; do
  response=$(curl -s -w "\n%{http_code}" --header "PRIVATE-TOKEN: [REDACTED]" \
    "http://example.invalid:1688/api/v4/projects/$project_id/pipelines/$pipeline_id")
  http_code=$(tail -n1 <<< "$response")
  body=$(sed '$d' <<< "$response")

  if [[ "$http_code" != "200" ]]; then
    echo "API request failed (HTTP $http_code): $body"
    exit 1
  fi

  status=$(jq -r '.status' <<< "$body")
  echo $status
  if [[ $status == "success" || $status == "failed" || $status == "canceled" ]]; then
    echo "Pipeline status: $status"
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