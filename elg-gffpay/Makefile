# GitLab 配置
GITLAB_URL ?= http://example.invalid:1688
# PROJECT_ID 和 PRIVATE_TOKEN 应从环境变量获取或通过命令行传递

# 检查 pipeline 状态
# 使用方法: make checkstatus PIPELINE_ID=123
checkstatus:
	@if [ -z "$(PIPELINE_ID)" ]; then \
		echo "错误: 请提供 PIPELINE_ID 参数"; \
		echo "使用方法: make checkstatus PIPELINE_ID=123"; \
		exit 1; \
	fi
	@if [ -z "$$PROJECT_ID" ] && [ -z "$(PROJECT_ID)" ]; then \
		echo "错误: 请设置 PROJECT_ID 环境变量或通过命令行传递"; \
		echo "使用方法: PROJECT_ID=535 make checkstatus PIPELINE_ID=123"; \
		echo "或者: export PROJECT_ID=535 && make checkstatus PIPELINE_ID=123"; \
		exit 1; \
	fi
	@if [ -z "$$PRIVATE_TOKEN" ] && [ -z "$(PRIVATE_TOKEN)" ]; then \
		echo "错误: 请设置 PRIVATE_TOKEN 环境变量或通过命令行传递"; \
		echo "使用方法: PRIVATE_TOKEN=[REDACTED] make checkstatus PIPELINE_ID=123"; \
		echo "或者: export PRIVATE_TOKEN=[REDACTED] && make checkstatus PIPELINE_ID=123"; \
		exit 1; \
	fi
	@echo "开始监听 pipeline $(PIPELINE_ID) 的状态..."
	echo "PRIVATE_TOKEN: [REDACTED]"
	echo "PROJECT_ID: $(PROJECT_ID)"
	@token=[REDACTED] -n "$(PRIVATE_TOKEN)" ] && echo "$(PRIVATE_TOKEN)" || echo "$$PRIVATE_TOKEN"); \
	project_id=$$([ -n "$(PROJECT_ID)" ] && echo "$(PROJECT_ID)" || echo "$$PROJECT_ID"); \
	while true; do \
		response=$$(curl -s --header "PRIVATE-TOKEN: [REDACTED]" \
			"$(GITLAB_URL)/api/v4/projects/$$project_id/pipelines/$(PIPELINE_ID)"); \
		status=$$(echo $$response | jq -r '.status'); \
		if [ "$$status" = "null" ] || [ -z "$$status" ]; then \
			echo "错误: 无法获取 pipeline 状态，返回内容: $$response"; \
			exit 1; \
		fi; \
		echo "当前状态: $$status"; \
		if [ "$$status" = "success" ] || [ "$$status" = "failed" ] || [ "$$status" = "canceled" ]; then \
			echo "Pipeline 状态: $$status"; \
			if [ "$$status" = "success" ]; then \
				echo "Pipeline 执行成功！"; \
				exit 0; \
			else \
				echo "Pipeline 执行失败或已取消。"; \
				exit 1; \
			fi; \
		fi; \
		echo "等待 pipeline 完成..."; \
		sleep 3; \
	done

.PHONY: checkstatus

