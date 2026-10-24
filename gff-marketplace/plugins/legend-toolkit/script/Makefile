SHELL := /bin/bash
# repo 路徑由環境變數 / 命令列傳入（不再用 .env）；未給則用下方預設。
# 取得路徑：跑 /resolve-repos 後 eval 它輸出的 export 行，或 make VAR=/path ...
AGENT_BACKEND_REPO_DIR  ?= $(HOME)/projects/www/WEB_AgentBackend
API_AGENT_REPO_DIR      ?= $(HOME)/projects/www/API_Agent
CS_SOCKET_REPO_DIR      ?= $(HOME)/projects/www/WEB_CustomerServiceSocket
TOOLS_DIR := $(CURDIR)/tools/git

# ===================================================
# 分支清單（SSOT：只改這裡，back/ 與 api/ 與 cs/ 同時生效）
#
# 拓樸：dev 由 feature 分支「直接」合入；master 只收 QA 驗收通過的內容，
#       再由 master 合往各 prod 分支。站台 master（tpower-master / vw-master）
#       本身是 prod 目標之一，不是 merge 來源。
#
#   feature ──┬─> develop / tpower-develop / vw-develop / wtt-develop  （上 dev，QA 驗收）
#             │
#             └─(驗收通過)─> master ──> 站台 master + 各白標分支         （上 prod）
#
# 為什麼 dev 不從 master 合：dev 是驗收場，內容要能單獨進退；master 若先收了
# 未驗收的 feature，被退掉的那份就洗不掉，之後每次上 prod 都會帶著它。
# ===================================================
MASTER       := master

# 上 dev 目標
# COMMON_DEV 是不綁白標的共用 dev 分支，與三支站台 dev 並列、同樣直接收 feature。
# 註：develop2 刻意不納入——最後一次提交 2024-12-29，已判定為停用分支。
COMMON_DEV   := develop
TPOWER_DEV   := tpower-develop
VWORLD_DEV   := vw-develop
WINTOTO_DEV  := wtt-develop
ALL_DEV      := $(COMMON_DEV) $(TPOWER_DEV) $(VWORLD_DEV) $(WINTOTO_DEV)

# 上 prod 目標（依站台分三組，可全部一起或一站一站）
# 註：tpower-hk 刻意不納入（現行由 tpower-master 手動合入，不走本 Makefile）
TPOWER_PROD  := tpower-master tpower-new alfawin77-new fon666 vplus33 yes2win
VWORLD_PROD  := vw-master vwb2c
WINTOTO_PROD := wintoto

# vworld 上 prod 後要打時間戳記 tag 的分支
VWORLD_TAG_BRANCH := vw-master

# pull-all / push-all 的全分支清單，由上面各清單組成（不另外維護一份）
ALL_BRANCHES := $(MASTER) $(ALL_DEV) $(TPOWER_PROD) $(VWORLD_PROD) $(WINTOTO_PROD)

# 以 master 為來源的合併（上 prod）一律帶 STRICT_SOURCE=1：merge_branches.sh 會先 fetch
# 並確認本機 master 與 origin 同步（落後自動快轉；領先或分岔則中止，避免把遠端沒有的
# 提交推上白標站台分支）。
MERGE_FROM_MASTER := SOURCE_BRANCH="$(MASTER)" STRICT_SOURCE=1

# 以 feature 分支為來源的合併（上 dev、promote-master）不帶 STRICT_SOURCE：
# 本機 feature 領先遠端、甚至遠端還沒有這支，都是正常的，只警告不中止。
# 用 `=`（遞延展開）而非 `:=`，確保取到 FEATURE 的最終值。
MERGE_FROM_FEATURE = SOURCE_BRANCH="$(FEATURE)"

.PHONY: help \
        rd/alfawin77/deploy rd/alfawin77/ssh rd/alfawin77/log \
        rd/yes2win/deploy   rd/yes2win/ssh   rd/yes2win/log \
        rd/tpower/deploy    rd/tpower/ssh    rd/tpower/log \
        rd/vworld/deploy    rd/vworld/ssh    rd/vworld/log \
        rd/me77/deploy      rd/me77/ssh      rd/me77/log \
        rd/dev/deploy       rd/dev/ssh       rd/dev/log \
        back/ensure-branches back/promote-master back/pull-all back/push-all back/merge-all \
        back/deploy-dev back/deploy-prod \
        back/tpower/deploy-dev back/vworld/deploy-dev back/wintoto/deploy-dev \
        back/tpower/deploy-prod back/vworld/deploy-prod back/wintoto/deploy-prod \
        back/vworld/tag back/clear-feature \
        api/ensure-branches api/promote-master api/pull-all api/push-all api/merge-all \
        api/deploy-dev api/deploy-prod \
        api/tpower/deploy-dev api/vworld/deploy-dev api/wintoto/deploy-dev \
        api/tpower/deploy-prod api/vworld/deploy-prod api/wintoto/deploy-prod \
        api/vworld/tag api/clear-feature \
        cs/ensure-branches cs/promote-master cs/pull-all cs/push-all cs/merge-all \
        cs/deploy-dev cs/deploy-prod \
        cs/tpower/deploy-dev cs/vworld/deploy-dev cs/wintoto/deploy-dev \
        cs/tpower/deploy-prod cs/vworld/deploy-prod cs/wintoto/deploy-prod \
        cs/vworld/tag cs/clear-feature

# ===================================================
# 預設 target：顯示說明
# ===================================================
help:
	@printf "\033[1;36m======  rd-studio  ======\033[0m\n"
	@printf "  make rd/alfawin77/deploy    部署 alfawin77\n"
	@printf "  make rd/yes2win/deploy      部署 yes2win\n"
	@printf "  make rd/tpower/deploy       部署 tpower\n"
	@printf "  make rd/vworld/deploy       部署 vworld\n"
	@printf "  make rd/me77/deploy         部署 me77\n"
	@printf "  make rd/dev/deploy          部署 dev\n"
	@printf "  make rd/<站台>/ssh          SSH 進入站台\n"
	@printf "  make rd/<站台>/log          查看站台 git log\n"
	@printf "\n"
	@printf "\033[1;36m====  上版流程（back = WEB_AgentBackend、api = API_Agent、cs = WEB_CustomerServiceSocket，指令相同）  ====\033[0m\n"
	@printf "  1. make <前綴>/deploy-dev FEATURE=<分支>       <分支> → $(ALL_DEV)\n"
	@printf "  2. （QA 驗 dev；有修正就改同一支 feature 後重跑步驟 1）\n"
	@printf "  3. make <前綴>/promote-master FEATURE=<分支>   驗收通過 → <分支> 合進 $(MASTER) 並推送\n"
	@printf "  4. make <前綴>/deploy-prod                     $(MASTER) → prod，互動選擇範圍\n"
	@printf "\n"
	@printf "\033[1;36m====  單一站台（可跳過互動選單直接指定）  ====\033[0m\n"
	@printf "  make <前綴>/tpower/deploy-dev FEATURE=<分支>   <分支> → $(TPOWER_DEV)\n"
	@printf "  make <前綴>/vworld/deploy-dev FEATURE=<分支>   <分支> → $(VWORLD_DEV)\n"
	@printf "  make <前綴>/wintoto/deploy-dev FEATURE=<分支>  <分支> → $(WINTOTO_DEV)\n"
	@printf "  make <前綴>/tpower/deploy-prod       $(MASTER) → $(TPOWER_PROD)\n"
	@printf "  make <前綴>/vworld/deploy-prod       $(MASTER) → $(VWORLD_PROD)（完成後打 tag）\n"
	@printf "  make <前綴>/wintoto/deploy-prod      $(MASTER) → $(WINTOTO_PROD)\n"
	@printf "\n"
	@printf "\033[1;36m====  其他  ====\033[0m\n"
	@printf "  make <前綴>/ensure-branches          補齊本機缺少的分支（新 clone 先跑一次）\n"
	@printf "  make <前綴>/pull-all                 拉取所有分支\n"
	@printf "  make <前綴>/push-all                 推送所有分支\n"
	@printf "  make <前綴>/merge-all                $(MASTER) 同步到全部分支（維護用，不是上版路徑）\n"
	@printf "  make <前綴>/vworld/tag               為 $(VWORLD_TAG_BRANCH) 建立時間戳記 tag\n"
	@printf "  make <前綴>/clear-feature            刪除本地 feature/* 分支\n"
	@printf "  make <前綴>/clear-feature DRYRUN=1   預覽要刪除的 feature/* 分支\n"

# ===================================================
# 共用 helper
# ===================================================

define CONFIRM
	@printf "\033[1;33m即將執行：$(1)\033[0m\n"
	@read -p "確定要繼續？ [y/N] " _c && { [ "$$_c" = "y" ] || [ "$$_c" = "Y" ]; } || { echo "已取消"; exit 1; }
endef

# 上 dev 與 promote-master 都以 feature 分支為來源，FEATURE 未給即中止。
# $(1)=target 名稱（只用於錯誤訊息示範正確用法）
define REQUIRE_FEATURE
	@[ -n "$(FEATURE)" ] || { printf "\033[1;31m請指定來源分支：make $(1) FEATURE=<分支名>\033[0m\n"; exit 1; }
endef

# 上 prod 的互動範圍選單。$(1)=前綴（back / api / cs）、$(2)=標籤（BACK / API / CS）
define PROD_SCOPE_MENU
	@printf "\033[1;32m>>> [$(2)] DEPLOY PROD：選擇範圍 <<<\033[0m\n"
	@printf "  [a] 全部三組（tpower + vworld + wintoto）\n"
	@printf "  [t] 只 tpower   → $(TPOWER_PROD)\n"
	@printf "  [v] 只 vworld   → $(VWORLD_PROD)\n"
	@printf "  [w] 只 wintoto  → $(WINTOTO_PROD)\n"
	@printf "  [q] 取消\n"
	@read -p "選擇 [a/t/v/w/q] " _s; \
	case "$$_s" in \
	  a|A) $(MAKE) $(1)/tpower/deploy-prod $(1)/vworld/deploy-prod $(1)/wintoto/deploy-prod ;; \
	  t|T) $(MAKE) $(1)/tpower/deploy-prod ;; \
	  v|V) $(MAKE) $(1)/vworld/deploy-prod ;; \
	  w|W) $(MAKE) $(1)/wintoto/deploy-prod ;; \
	  *)   echo "已取消"; exit 1 ;; \
	esac
endef

# ===================================================
# rd-studio
# ===================================================

# ---- alfawin77 ----
rd/alfawin77/deploy:
	$(call CONFIRM,rd/alfawin77/deploy)
	@echo "\033[1;33m>>> [RD] DEPLOY alfawin77 <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-alfawin77" --project "gff-alfawin77" --command "sudo -i sh /vwsource/update_live.sh"

rd/alfawin77/ssh:
	$(call CONFIRM,rd/alfawin77/ssh)
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-alfawin77" --project "gff-alfawin77"

rd/alfawin77/log:
	$(call CONFIRM,rd/alfawin77/log)
	@echo "\033[1;36m>>> [RD] GIT LOG alfawin77 <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-alfawin77" --project "gff-alfawin77" --command "cd /vwsource/RD-Studio-01 && sudo git log -1"

# ---- yes2win ----
rd/yes2win/deploy:
	$(call CONFIRM,rd/yes2win/deploy)
	@echo "\033[1;33m>>> [RD] DEPLOY yes2win <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-yes2win" --project "gff-yes2win" --command "sudo -i sh /vwsource/update_live.sh"

rd/yes2win/ssh:
	$(call CONFIRM,rd/yes2win/ssh)
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-yes2win" --project "gff-yes2win"

rd/yes2win/log:
	$(call CONFIRM,rd/yes2win/log)
	@echo "\033[1;36m>>> [RD] GIT LOG yes2win <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-yes2win" --project "gff-yes2win" --command "cd /vwsource/RD-Studio-01 && sudo git log -1"

# ---- tpower ----
rd/tpower/deploy:
	$(call CONFIRM,rd/tpower/deploy)
	@echo "\033[1;33m>>> [RD] DEPLOY tpower <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower" --command "sudo -i sh /vwsource/update_live.sh"

rd/tpower/ssh:
	$(call CONFIRM,rd/tpower/ssh)
	gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower"

rd/tpower/log:
	$(call CONFIRM,rd/tpower/log)
	@echo "\033[1;36m>>> [RD] GIT LOG tpower <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-a" "rd-sudio-01-tpower" --project "tp-wz3-tpower" --command "cd /vwsource/RD-Studio-01 && sudo git log -1"

# ---- vworld ----
rd/vworld/deploy:
	$(call CONFIRM,rd/vworld/deploy)
	@echo "\033[1;33m>>> [RD] DEPLOY vworld <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-a" "rd-studio-01" --project "tp-wz3-online" --command "sudo -i sh ~vwsource/update_live.sh"

rd/vworld/ssh:
	$(call CONFIRM,rd/vworld/ssh)
	gcloud compute ssh --zone "asia-southeast1-a" "rd-studio-01" --project "tp-wz3-online"

rd/vworld/log:
	$(call CONFIRM,rd/vworld/log)
	@echo "\033[1;36m>>> [RD] GIT LOG vworld <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-a" "rd-studio-01" --project "tp-wz3-online" --command "sudo bash -c 'cd /home/vwsource/RD-Studio-01 && git log -1'"

# ---- me77 ----
rd/me77/deploy:
	$(call CONFIRM,rd/me77/deploy)
	@echo "\033[1;33m>>> [RD] DEPLOY me77 <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-vwb2c" --project "gff-vwb2c" --command "sudo -i sh /vwsource/update_live.sh"

rd/me77/ssh:
	$(call CONFIRM,rd/me77/ssh)
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-vwb2c" --project "gff-vwb2c"

rd/me77/log:
	$(call CONFIRM,rd/me77/log)
	@echo "\033[1;36m>>> [RD] GIT LOG me77 <<<\033[0m"
	gcloud compute ssh --zone "asia-southeast1-c" "rd-studio-vwb2c" --project "gff-vwb2c" --command "cd /vwsource/RD-Studio-01 && sudo git log -1"

# ---- dev ----
rd/dev/deploy:
	$(call CONFIRM,rd/dev/deploy)
	@echo "\033[1;33m>>> [RD] DEPLOY dev <<<\033[0m"
	gcloud compute ssh --zone "asia-east1-a" "rd-studio-01" --project "tp-wz3-rd" --command "sudo -i sh ~vwsource/update_rd.sh"

rd/dev/ssh:
	$(call CONFIRM,rd/dev/ssh)
	gcloud compute ssh --zone "asia-east1-a" "rd-studio-01" --project "tp-wz3-rd"

rd/dev/log:
	$(call CONFIRM,rd/dev/log)
	@echo "\033[1;36m>>> [RD] GIT LOG dev <<<\033[0m"
	gcloud compute ssh --zone "asia-east1-a" "rd-studio-01" --project "tp-wz3-rd" --command "sudo bash -c 'cd /home/vwsource/RD-Studio-01 && git log -10'"

# ===================================================
# WEB_AgentBackend — 1. 上 dev（來源是 feature 分支，不經 master）
# 用法：make back/deploy-dev FEATURE=feature/issue_4030
# ===================================================
back/deploy-dev:
	$(call REQUIRE_FEATURE,back/deploy-dev)
	$(call CONFIRM,back/deploy-dev FEATURE=$(FEATURE) → $(ALL_DEV))
	@printf "\033[1;32m>>> [BACK] DEPLOY DEV：$(FEATURE) → $(ALL_DEV) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(ALL_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [BACK] PUSH $(ALL_DEV) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(ALL_DEV)" sh $(TOOLS_DIR)/push_branches.sh

back/tpower/deploy-dev:
	$(call REQUIRE_FEATURE,back/tpower/deploy-dev)
	$(call CONFIRM,back/tpower/deploy-dev FEATURE=$(FEATURE) → $(TPOWER_DEV))
	@printf "\033[1;32m>>> [BACK] DEPLOY tpower dev：$(FEATURE) → $(TPOWER_DEV) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(TPOWER_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(TPOWER_DEV)" sh $(TOOLS_DIR)/push_branches.sh

back/vworld/deploy-dev:
	$(call REQUIRE_FEATURE,back/vworld/deploy-dev)
	$(call CONFIRM,back/vworld/deploy-dev FEATURE=$(FEATURE) → $(VWORLD_DEV))
	@printf "\033[1;32m>>> [BACK] DEPLOY vworld dev：$(FEATURE) → $(VWORLD_DEV) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(VWORLD_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(VWORLD_DEV)" sh $(TOOLS_DIR)/push_branches.sh

back/wintoto/deploy-dev:
	$(call REQUIRE_FEATURE,back/wintoto/deploy-dev)
	$(call CONFIRM,back/wintoto/deploy-dev FEATURE=$(FEATURE) → $(WINTOTO_DEV))
	@printf "\033[1;32m>>> [BACK] DEPLOY wintoto dev：$(FEATURE) → $(WINTOTO_DEV) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(WINTOTO_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(WINTOTO_DEV)" sh $(TOOLS_DIR)/push_branches.sh

# ===================================================
# WEB_AgentBackend — 3. QA 驗收通過：feature → master
# 用法：make back/promote-master FEATURE=feature/issue_4030
# 這一步是上 prod 的前提；未驗收過不要跑。
# ===================================================
back/promote-master:
	$(call REQUIRE_FEATURE,back/promote-master)
	$(call CONFIRM,back/promote-master FEATURE=$(FEATURE) → $(MASTER)（確認 QA 已驗收？）)
	@printf "\033[1;32m>>> [BACK] PROMOTE $(FEATURE) → $(MASTER) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(MASTER)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [BACK] PUSH $(MASTER) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(MASTER)" sh $(TOOLS_DIR)/push_branches.sh

# ===================================================
# WEB_AgentBackend — 4. 上 prod（先選範圍：全部 / 一站一站）
# ===================================================
back/deploy-prod:
	$(call PROD_SCOPE_MENU,back,BACK)

back/tpower/deploy-prod:
	$(call CONFIRM,back/tpower/deploy-prod)
	@printf "\033[1;32m>>> [BACK] DEPLOY tpower prod：$(MASTER) → $(TPOWER_PROD) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(TPOWER_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [BACK] PUSH $(TPOWER_PROD) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(TPOWER_PROD)" sh $(TOOLS_DIR)/push_branches.sh

back/vworld/deploy-prod:
	$(call CONFIRM,back/vworld/deploy-prod)
	@printf "\033[1;32m>>> [BACK] DEPLOY vworld prod：$(MASTER) → $(VWORLD_PROD) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(VWORLD_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [BACK] PUSH $(VWORLD_PROD) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(VWORLD_PROD)" sh $(TOOLS_DIR)/push_branches.sh
	$(MAKE) back/vworld/tag

back/wintoto/deploy-prod:
	$(call CONFIRM,back/wintoto/deploy-prod)
	@printf "\033[1;32m>>> [BACK] DEPLOY wintoto prod：$(MASTER) → $(WINTOTO_PROD) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(WINTOTO_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [BACK] PUSH $(WINTOTO_PROD) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(WINTOTO_PROD)" sh $(TOOLS_DIR)/push_branches.sh

back/vworld/tag:
	$(call CONFIRM,back/vworld/tag)
	@printf "\033[1;33m>>> [BACK] TAG $(VWORLD_TAG_BRANCH) 建立時間戳記 tag <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && TAG_BRANCH="$(VWORLD_TAG_BRANCH)" sh $(TOOLS_DIR)/tag_release.sh

# ===================================================
# WEB_AgentBackend — 其他
# ===================================================
back/ensure-branches:
	@printf "\033[1;33m>>> [BACK] 補齊本機缺少的分支（從 origin 建立）<<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/ensure_branches.sh

back/pull-all:
	$(call CONFIRM,back/pull-all)
	@printf "\033[1;33m>>> [BACK] PULL 所有分支 <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/pull_branches.sh

back/push-all:
	$(call CONFIRM,back/push-all)
	@printf "\033[1;33m>>> [BACK] PUSH 所有分支 <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/push_branches.sh

# 維護用（不是上版路徑）：讓落後的分支追上 master，例如長期沒動的站台分支。
back/merge-all:
	$(call CONFIRM,back/merge-all)
	@printf "\033[1;33m>>> [BACK] MERGE ALL（維護用）：$(MASTER) → 全部 dev + prod（不推送）<<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(ALL_DEV) $(TPOWER_PROD) $(VWORLD_PROD) $(WINTOTO_PROD)" sh $(TOOLS_DIR)/merge_branches.sh

# 用法：
#   make back/clear-feature           → 直接刪除
#   make back/clear-feature DRYRUN=1  → 僅預覽，不刪除
back/clear-feature:
	$(call CONFIRM,back/clear-feature)
	@printf "\033[1;33m>>> [BACK] CLEAR feature/* 分支$(if $(DRYRUN), (dryrun 模式),) <<<\033[0m\n"
	cd $(AGENT_BACKEND_REPO_DIR) && sh $(TOOLS_DIR)/clear_feature_branch.sh $(if $(DRYRUN),--dryrun,)

# ===================================================
# API_Agent — 1. 上 dev（來源是 feature 分支，不經 master）
# 用法：make api/deploy-dev FEATURE=feature/4040
# ===================================================
api/deploy-dev:
	$(call REQUIRE_FEATURE,api/deploy-dev)
	$(call CONFIRM,api/deploy-dev FEATURE=$(FEATURE) → $(ALL_DEV))
	@printf "\033[1;32m>>> [API] DEPLOY DEV：$(FEATURE) → $(ALL_DEV) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(ALL_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [API] PUSH $(ALL_DEV) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(ALL_DEV)" sh $(TOOLS_DIR)/push_branches.sh

api/tpower/deploy-dev:
	$(call REQUIRE_FEATURE,api/tpower/deploy-dev)
	$(call CONFIRM,api/tpower/deploy-dev FEATURE=$(FEATURE) → $(TPOWER_DEV))
	@printf "\033[1;32m>>> [API] DEPLOY tpower dev：$(FEATURE) → $(TPOWER_DEV) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(TPOWER_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(TPOWER_DEV)" sh $(TOOLS_DIR)/push_branches.sh

api/vworld/deploy-dev:
	$(call REQUIRE_FEATURE,api/vworld/deploy-dev)
	$(call CONFIRM,api/vworld/deploy-dev FEATURE=$(FEATURE) → $(VWORLD_DEV))
	@printf "\033[1;32m>>> [API] DEPLOY vworld dev：$(FEATURE) → $(VWORLD_DEV) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(VWORLD_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(VWORLD_DEV)" sh $(TOOLS_DIR)/push_branches.sh

api/wintoto/deploy-dev:
	$(call REQUIRE_FEATURE,api/wintoto/deploy-dev)
	$(call CONFIRM,api/wintoto/deploy-dev FEATURE=$(FEATURE) → $(WINTOTO_DEV))
	@printf "\033[1;32m>>> [API] DEPLOY wintoto dev：$(FEATURE) → $(WINTOTO_DEV) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(WINTOTO_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(WINTOTO_DEV)" sh $(TOOLS_DIR)/push_branches.sh

# ===================================================
# API_Agent — 3. QA 驗收通過：feature → master
# 用法：make api/promote-master FEATURE=feature/4040
# 這一步是上 prod 的前提；未驗收過不要跑。
# ===================================================
api/promote-master:
	$(call REQUIRE_FEATURE,api/promote-master)
	$(call CONFIRM,api/promote-master FEATURE=$(FEATURE) → $(MASTER)（確認 QA 已驗收？）)
	@printf "\033[1;32m>>> [API] PROMOTE $(FEATURE) → $(MASTER) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(MASTER)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [API] PUSH $(MASTER) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(MASTER)" sh $(TOOLS_DIR)/push_branches.sh

# ===================================================
# API_Agent — 4. 上 prod（先選範圍：全部 / 一站一站）
# ===================================================
api/deploy-prod:
	$(call PROD_SCOPE_MENU,api,API)

api/tpower/deploy-prod:
	$(call CONFIRM,api/tpower/deploy-prod)
	@printf "\033[1;32m>>> [API] DEPLOY tpower prod：$(MASTER) → $(TPOWER_PROD) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(TPOWER_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [API] PUSH $(TPOWER_PROD) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(TPOWER_PROD)" sh $(TOOLS_DIR)/push_branches.sh

api/vworld/deploy-prod:
	$(call CONFIRM,api/vworld/deploy-prod)
	@printf "\033[1;32m>>> [API] DEPLOY vworld prod：$(MASTER) → $(VWORLD_PROD) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(VWORLD_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [API] PUSH $(VWORLD_PROD) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(VWORLD_PROD)" sh $(TOOLS_DIR)/push_branches.sh
	$(MAKE) api/vworld/tag

api/wintoto/deploy-prod:
	$(call CONFIRM,api/wintoto/deploy-prod)
	@printf "\033[1;32m>>> [API] DEPLOY wintoto prod：$(MASTER) → $(WINTOTO_PROD) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(WINTOTO_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [API] PUSH $(WINTOTO_PROD) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(WINTOTO_PROD)" sh $(TOOLS_DIR)/push_branches.sh

api/vworld/tag:
	$(call CONFIRM,api/vworld/tag)
	@printf "\033[1;33m>>> [API] TAG $(VWORLD_TAG_BRANCH) 建立時間戳記 tag <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && TAG_BRANCH="$(VWORLD_TAG_BRANCH)" sh $(TOOLS_DIR)/tag_release.sh

# ===================================================
# API_Agent — 其他
# ===================================================
api/ensure-branches:
	@printf "\033[1;33m>>> [API] 補齊本機缺少的分支（從 origin 建立）<<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/ensure_branches.sh

api/pull-all:
	$(call CONFIRM,api/pull-all)
	@printf "\033[1;33m>>> [API] PULL 所有分支 <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/pull_branches.sh

api/push-all:
	$(call CONFIRM,api/push-all)
	@printf "\033[1;33m>>> [API] PUSH 所有分支 <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/push_branches.sh

# 維護用（不是上版路徑）：讓落後的分支追上 master，例如長期沒動的站台分支。
api/merge-all:
	$(call CONFIRM,api/merge-all)
	@printf "\033[1;33m>>> [API] MERGE ALL（維護用）：$(MASTER) → 全部 dev + prod（不推送）<<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(ALL_DEV) $(TPOWER_PROD) $(VWORLD_PROD) $(WINTOTO_PROD)" sh $(TOOLS_DIR)/merge_branches.sh

# 用法：
#   make api/clear-feature           → 直接刪除
#   make api/clear-feature DRYRUN=1  → 僅預覽，不刪除
api/clear-feature:
	$(call CONFIRM,api/clear-feature)
	@printf "\033[1;33m>>> [API] CLEAR feature/* 分支$(if $(DRYRUN), (dryrun 模式),) <<<\033[0m\n"
	cd $(API_AGENT_REPO_DIR) && sh $(TOOLS_DIR)/clear_feature_branch.sh $(if $(DRYRUN),--dryrun,)

# ===================================================
# WEB_CustomerServiceSocket — 1. 上 dev（來源是 feature 分支，不經 master）
# 用法：make cs/deploy-dev FEATURE=feature/4169
# ===================================================
cs/deploy-dev:
	$(call REQUIRE_FEATURE,cs/deploy-dev)
	$(call CONFIRM,cs/deploy-dev FEATURE=$(FEATURE) → $(ALL_DEV))
	@printf "\033[1;32m>>> [CS] DEPLOY DEV：$(FEATURE) → $(ALL_DEV) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(ALL_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [CS] PUSH $(ALL_DEV) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(ALL_DEV)" sh $(TOOLS_DIR)/push_branches.sh

cs/tpower/deploy-dev:
	$(call REQUIRE_FEATURE,cs/tpower/deploy-dev)
	$(call CONFIRM,cs/tpower/deploy-dev FEATURE=$(FEATURE) → $(TPOWER_DEV))
	@printf "\033[1;32m>>> [CS] DEPLOY tpower dev：$(FEATURE) → $(TPOWER_DEV) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(TPOWER_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(TPOWER_DEV)" sh $(TOOLS_DIR)/push_branches.sh

cs/vworld/deploy-dev:
	$(call REQUIRE_FEATURE,cs/vworld/deploy-dev)
	$(call CONFIRM,cs/vworld/deploy-dev FEATURE=$(FEATURE) → $(VWORLD_DEV))
	@printf "\033[1;32m>>> [CS] DEPLOY vworld dev：$(FEATURE) → $(VWORLD_DEV) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(VWORLD_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(VWORLD_DEV)" sh $(TOOLS_DIR)/push_branches.sh

cs/wintoto/deploy-dev:
	$(call REQUIRE_FEATURE,cs/wintoto/deploy-dev)
	$(call CONFIRM,cs/wintoto/deploy-dev FEATURE=$(FEATURE) → $(WINTOTO_DEV))
	@printf "\033[1;32m>>> [CS] DEPLOY wintoto dev：$(FEATURE) → $(WINTOTO_DEV) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(WINTOTO_DEV)" sh $(TOOLS_DIR)/merge_branches.sh
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(WINTOTO_DEV)" sh $(TOOLS_DIR)/push_branches.sh

# ===================================================
# WEB_CustomerServiceSocket — 3. QA 驗收通過：feature → master
# 用法：make cs/promote-master FEATURE=feature/4169
# 這一步是上 prod 的前提；未驗收過不要跑。
# ===================================================
cs/promote-master:
	$(call REQUIRE_FEATURE,cs/promote-master)
	$(call CONFIRM,cs/promote-master FEATURE=$(FEATURE) → $(MASTER)（確認 QA 已驗收？）)
	@printf "\033[1;32m>>> [CS] PROMOTE $(FEATURE) → $(MASTER) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_FEATURE) BRANCHES="$(MASTER)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [CS] PUSH $(MASTER) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(MASTER)" sh $(TOOLS_DIR)/push_branches.sh

# ===================================================
# WEB_CustomerServiceSocket — 4. 上 prod（先選範圍：全部 / 一站一站）
# ===================================================
cs/deploy-prod:
	$(call PROD_SCOPE_MENU,cs,CS)

cs/tpower/deploy-prod:
	$(call CONFIRM,cs/tpower/deploy-prod)
	@printf "\033[1;32m>>> [CS] DEPLOY tpower prod：$(MASTER) → $(TPOWER_PROD) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(TPOWER_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [CS] PUSH $(TPOWER_PROD) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(TPOWER_PROD)" sh $(TOOLS_DIR)/push_branches.sh

cs/vworld/deploy-prod:
	$(call CONFIRM,cs/vworld/deploy-prod)
	@printf "\033[1;32m>>> [CS] DEPLOY vworld prod：$(MASTER) → $(VWORLD_PROD) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(VWORLD_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [CS] PUSH $(VWORLD_PROD) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(VWORLD_PROD)" sh $(TOOLS_DIR)/push_branches.sh
	$(MAKE) cs/vworld/tag

cs/wintoto/deploy-prod:
	$(call CONFIRM,cs/wintoto/deploy-prod)
	@printf "\033[1;32m>>> [CS] DEPLOY wintoto prod：$(MASTER) → $(WINTOTO_PROD) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(WINTOTO_PROD)" sh $(TOOLS_DIR)/merge_branches.sh
	@printf "\033[1;33m>>> [CS] PUSH $(WINTOTO_PROD) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(WINTOTO_PROD)" sh $(TOOLS_DIR)/push_branches.sh

cs/vworld/tag:
	$(call CONFIRM,cs/vworld/tag)
	@printf "\033[1;33m>>> [CS] TAG $(VWORLD_TAG_BRANCH) 建立時間戳記 tag <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && TAG_BRANCH="$(VWORLD_TAG_BRANCH)" sh $(TOOLS_DIR)/tag_release.sh

# ===================================================
# WEB_CustomerServiceSocket — 其他
# ===================================================
cs/ensure-branches:
	@printf "\033[1;33m>>> [CS] 補齊本機缺少的分支（從 origin 建立）<<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/ensure_branches.sh

cs/pull-all:
	$(call CONFIRM,cs/pull-all)
	@printf "\033[1;33m>>> [CS] PULL 所有分支 <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/pull_branches.sh

cs/push-all:
	$(call CONFIRM,cs/push-all)
	@printf "\033[1;33m>>> [CS] PUSH 所有分支 <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && BRANCHES="$(ALL_BRANCHES)" sh $(TOOLS_DIR)/push_branches.sh

# 維護用（不是上版路徑）：讓落後的分支追上 master，例如長期沒動的站台分支。
cs/merge-all:
	$(call CONFIRM,cs/merge-all)
	@printf "\033[1;33m>>> [CS] MERGE ALL（維護用）：$(MASTER) → 全部 dev + prod（不推送）<<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && $(MERGE_FROM_MASTER) BRANCHES="$(ALL_DEV) $(TPOWER_PROD) $(VWORLD_PROD) $(WINTOTO_PROD)" sh $(TOOLS_DIR)/merge_branches.sh

# 用法：
#   make cs/clear-feature           → 直接刪除
#   make cs/clear-feature DRYRUN=1  → 僅預覽，不刪除
cs/clear-feature:
	$(call CONFIRM,cs/clear-feature)
	@printf "\033[1;33m>>> [CS] CLEAR feature/* 分支$(if $(DRYRUN), (dryrun 模式),) <<<\033[0m\n"
	cd $(CS_SOCKET_REPO_DIR) && sh $(TOOLS_DIR)/clear_feature_branch.sh $(if $(DRYRUN),--dryrun,)
