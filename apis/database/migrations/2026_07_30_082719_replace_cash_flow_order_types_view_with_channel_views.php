<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 以兩張顆粒度乾淨的 view 取代舊 v_cash_flow_order_types（僅存在 DB、未入版控、寫死 language_id=2、
     * 綁 DEFINER 帳號、且完全沒帶 ACF 配置層）。view 為 schema 物件非資料表，SchemaMonitor 不適用，
     * 一律 SQL SECURITY INVOKER（不寫 DEFINER，不綁任何 DB 帳號）。
     *
     * - v_cash_flow_channels（平台渠道目錄）：頂層 OT（parent=0）一列；銀行子列以 bank_option_count
     *   彙總；常用語言 pivot 成 name_en / name_zh_tw / name_zh_cn（language_id 1/2/3）。
     * - v_agent_cash_flow_channels（代理配置）：ACFOT 一列；空殼 ACF（零 ACFOT）以單列 NULL 呈現；
     *   軟刪列全部呈現（附兩層 deleted_at，不內建過濾）。結構過濾靠 order_type_parent：
     *   0=渠道列、>0=銀行子列（值即父 OT id）、NULL=查無 OT（空殼 ACF 或懸空 ACFOT）。
     *   刻意不設 is_bank_option 語意欄：頂層 OT 也可能本身就是銀行（舊式一行一銀行渠道），
     *   資料層給不出可靠的「銀行與否」語意，view 只陳述結構事實。
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_cash_flow_order_types');
        DB::statement('DROP VIEW IF EXISTS v_cash_flow_channels');
        DB::statement('DROP VIEW IF EXISTS v_agent_cash_flow_channels');

        DB::statement(<<<'SQL'
        CREATE SQL SECURITY INVOKER VIEW v_cash_flow_channels AS
        SELECT
            cf.id                 AS cash_flow_id,
            cf.third_party_code   AS cash_flow_code,
            cf.name               AS cash_flow_name,
            cf.type               AS cash_flow_type,
            cf.status             AS cash_flow_status,
            ot.id                 AS order_type_id,
            ot.`key`              AS channel_key,
            ot.name               AS channel_name,
            ot.type               AS order_type_type,
            ot.currency_id        AS currency_id,
            ot.status             AS order_type_status,
            ot.sorts              AS order_type_sorts,
            ot.choose_bank        AS choose_bank,
            (SELECT COUNT(*) FROM order_types c WHERE c.parent = ot.id) AS bank_option_count,
            otn_en.name           AS name_en,
            otn_tw.name           AS name_zh_tw,
            otn_cn.name           AS name_zh_cn,
            ot.created_at         AS order_type_created_at,
            ot.updated_at         AS order_type_updated_at
        FROM order_types ot
        LEFT JOIN cash_flows cf           ON cf.id = ot.cash_flow_id
        LEFT JOIN order_type_names otn_en ON otn_en.type_id = ot.id AND otn_en.language_id = 1
        LEFT JOIN order_type_names otn_tw ON otn_tw.type_id = ot.id AND otn_tw.language_id = 2
        LEFT JOIN order_type_names otn_cn ON otn_cn.type_id = ot.id AND otn_cn.language_id = 3
        WHERE ot.parent = 0
        SQL);

        DB::statement(<<<'SQL'
        CREATE SQL SECURITY INVOKER VIEW v_agent_cash_flow_channels AS
        SELECT
            acf.agent_group_id    AS agent_group_id,
            acf.currency_id       AS currency_id,
            acf.cash_flow_id      AS cash_flow_id,
            cf.third_party_code   AS cash_flow_code,
            cf.name               AS cash_flow_name,
            cf.type               AS cash_flow_type,
            acf.id                AS agent_cash_flow_id,
            acf.name              AS agent_cash_flow_name,
            acf.cost              AS acf_cost,
            acf.status            AS acf_status,
            acf.deleted_at        AS acf_deleted_at,
            acfot.id              AS acfot_id,
            acfot.order_type_id   AS order_type_id,
            ot.parent             AS order_type_parent,
            COALESCE(pot.id, ot.id)       AS channel_order_type_id,
            COALESCE(pot.`key`, ot.`key`) AS channel_key,
            ot.`key`              AS order_type_key,
            ot.name               AS order_type_name,
            acfot.cost            AS acfot_cost,
            acfot.cost_type       AS acfot_cost_type,
            acfot.min_amount      AS min_amount,
            acfot.max_amount      AS max_amount,
            acfot.status          AS acfot_status,
            acfot.deleted_at      AS acfot_deleted_at,
            acfot.updated_at      AS acfot_updated_at
        FROM agent_cash_flows acf
        LEFT JOIN agent_cash_flow_order_types acfot ON acfot.agent_cash_flow_id = acf.id
        LEFT JOIN order_types ot  ON ot.id = acfot.order_type_id
        LEFT JOIN order_types pot ON pot.id = ot.parent
        LEFT JOIN cash_flows cf   ON cf.id = acf.cash_flow_id
        SQL);
    }

    /**
     * 回滾：移除兩張新 view、依原始 SELECT 本體重建舊 v_cash_flow_order_types。
     * 原 view 的 DEFINER=developer@% 不予重建（需 SET_USER_ID 權限且正是要根治的帳號綁定），
     * 一律改 SQL SECURITY INVOKER。
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_cash_flow_channels');
        DB::statement('DROP VIEW IF EXISTS v_agent_cash_flow_channels');

        DB::statement(<<<'SQL'
        CREATE SQL SECURITY INVOKER VIEW v_cash_flow_order_types AS
        SELECT
            cf.id          AS cash_flow_id,
            cf.third_party_code AS cash_flow_third_party_code,
            cf.name        AS cash_flow_name,
            cf.rate        AS cash_flow_rate,
            cf.percentage  AS cash_flow_percentage,
            cf.type        AS cash_flow_type,
            cf.status      AS cash_flow_status,
            cf.remark      AS cash_flow_remark,
            example.invalid        AS cash_flow_info,
            cf.admin       AS cash_flow_admin,
            ot.id          AS order_type_id,
            ot.parent      AS order_type_parent,
            ot.name        AS order_type_name,
            ot.`key`       AS order_type_key,
            ot.type        AS order_type_type,
            ot.currency_id AS order_type_currency_id,
            example.invalid        AS order_type_info,
            ot.choose_bank AS order_type_choose_bank,
            ot.need_first_name AS order_type_need_first_name,
            ot.sorts       AS order_type_sorts,
            ot.status      AS order_type_status,
            ot.image_path  AS order_type_image_path,
            ot.admin       AS order_type_admin,
            otn.id         AS order_type_name_id,
            otn.name       AS order_type_lang_name,
            cf.created_at  AS cash_flow_created_at,
            cf.updated_at  AS cash_flow_updated_at,
            ot.created_at  AS order_type_created_at,
            ot.updated_at  AS order_type_updated_at,
            otn.created_at AS order_type_name_created_at,
            otn.updated_at AS order_type_name_updated_at
        FROM cash_flows cf
        LEFT JOIN order_types ot       ON ot.cash_flow_id = cf.id
        LEFT JOIN order_type_names otn ON otn.type_id = ot.id AND otn.language_id = 2
        SQL);
    }
};
