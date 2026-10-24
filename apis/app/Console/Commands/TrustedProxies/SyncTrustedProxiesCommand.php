<?php

declare(strict_types=1);

namespace App\Console\Commands\TrustedProxies;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 重新抓取並同步信任代理 CIDR 清單（target/tickets#4187）。
 *
 * 行為對齊 gsrc/WEB_AgentBackend/scripts/update_trusted_proxies.php：
 *
 *   Cloudflare IPv4/IPv6  自動抓  https://example.invalid/ips-v4 、 ips-v6
 *   Google LB IPv4/IPv6   不抓    health check / GFE 網段只存在於 load balancing
 *                                 文件，不在 gstatic 的 goog.json / cloud.json 內，
 *                                 無機器可讀來源，維持程式內常數。
 *   Mlytics WAF IPv4      半自動  Mlytics 無公開 API，清單以帶日期的檔案交付；
 *                                 沒指定來源時沿用現有 JSON 裡的舊值。
 *
 * 輸出的 JSON（預設 storage/app/trusted-proxies.json）是 Common::resolveClientIp()
 * 讀取的 runtime 資料檔，要 commit 進 git——平時是靜態的，需要更新時才
 * 手動跑一次本指令、檢查差異後 commit。
 */
class SyncTrustedProxiesCommand extends Command
{
    private const CLOUDFLARE_IPV4_URL = 'https://example.invalid/ips-v4';

    private const CLOUDFLARE_IPV6_URL = 'https://example.invalid/ips-v6';

    /**
     * Google Cloud Load Balancer / GFE IPv4。
     * 來源：https://example.invalid/load-balancing/docs/https#firewall-rules
     * 0.0.0.0/22 = GFE 轉發請求到後端的來源網段
     * 0.0.0.0/16  = GFE 轉發請求的來源網段，同時也是 health check 探測來源
     *
     * 無機器可讀來源，需人工核對，變更時同步 gsrc/WEB_AgentBackend 那份。
     */
    private const GOOGLE_LB_IPV4 = ['0.0.0.0/22', '0.0.0.0/16'];

    /**
     * Google Cloud Load Balancer / GFE IPv6（同上）。
     * 2600:2d00:1:b029::/64 = health check 探測來源（IPv6）
     * 2600:2d00:1:1::/64    = GFE 轉發請求的來源網段（IPv6，僅 global external ALB）
     */
    private const GOOGLE_LB_IPV6 = ['2600:2d00:1:b029::/64', '2600:2d00:1:1::/64'];

    /**
     * 我方各站 Load Balancer 的前端 VIP（115 筆）。
     *
     * 上面 GOOGLE_LB_IPV4 那兩段是 GFE 轉發與 health check 的來源網段，**不包含 LB 自己的
     * 前端 VIP**。瀏覽器或外部服務直連 LB 的站台，pod 看到的 REMOTE_ADDR 就是這個 VIP；
     * 它不在任何可枚舉的網段裡，沒列進來的話 resolveClientIp() 第一步就判定連線來源不可信，
     * 整串 X-Forwarded-For 都不採信，所有請求的 IP 會變成 LB 自己
     * （target/op_tickets#3478：客服後台 2026-10-08 實測踩到，稽核欄位整批記成 0.0.0.0）。
     *
     * 列齊之後，掃描是「知道那一跳是誰才跳過」，而不是靠 TRUSTED_PROXY_SKIP_LAST_HOP
     * 盲目丟掉最後一跳——後者是在清單缺 VIP 時的權宜做法，拓樸一變就會默默丟錯。
     *
     * 安全性上多列我方 VIP 不擴大攻擊面——那些位址要生效，對方得真的「成為」它們（它是
     * TCP 對端，或出現在 X-Forwarded-For 中真實來源的右側，而真實來源是 LB 補上去的）。
     * 所以全站共用同一份即可，不必各站只列自己的；但一律列具體 /32，**不要放寬成
     * 0.0.0.0/8 這種整段**，那等於連別人家的 LB 一起信。
     *
     * 格式是 `CIDR => '<GCP 專案> <forwarding rule 名稱>'`，標註會一起寫進 nginx 版本的
     * 每一行後面，review 時才看得出某筆是哪個站的哪個服務。
     *
     * 怎麼重新產生（權威來源是 GCP 的 forwarding rule，不要用 log 取樣——那只抓得到
     * 剛好有流量的那幾個）：
     *
     *   gcloud projects list --format='value(projectId)'   # 先確認專案沒漏
     *   for P in tp-wz3-rd gff-dev tp-wz3-online tp-wz3-tpower gff-yes2win gff-vwb2c; do
     *     gcloud compute forwarding-rules list --project=$P \
     *       --format='value(IPAddress,name,loadBalancingScheme)' | grep EXTERNAL
     *   done
     *
     * 未涵蓋的專案與原因：
     *   gff-alfawin77          已停站
     *   gff-tpower-au          Compute API 未啟用，列舉不到
     *   tp-wz3-qa              同上
     *   VPlus33（無 GCP 專案）  自架，掃不到 forwarding rule，本清單一筆都列不了。
     *                          ⚠️ 這不等於「VPlus33 不需要信任任何代理」，它是唯一
     *                          完全靠 TRUSTED_PROXY_SKIP_LAST_HOP 才會對的站，細節見下。
     *
     * VPlus33 的鏈路長什麼樣（2026-10-12 查 Grafana/Loki，近 72 小時所有多節 XFF 的
     * nginx access log，4,998 筆；該站 log 在自架 Loki 不在 GCP，查法見 CLAUDE.local.md）：
     *
     *   <客戶端>, 0.0.0.0, <最後一跳>      鏈長一律 3 節
     *              ↑ 固定            ↑ 浮動
     *
     *   倒數第二節  distinct 2 個：0.0.0.0（4,969 筆）、0.0.0.0（29 筆）
     *   最後一節    distinct 353 個：34.127.x 佔 252、34.96.x 佔 101，且還在長
     *
     * 所以這一站：
     *   - `0.0.0.0` 由該站 .env 的 TRUSTED_PROXY_EXTRA_CIDRS 單獨帶入（設定在
     *     gffv3 `helm-charts/apis/values_vplus33.yaml`）。**本清單更新後它仍然必要，
     *     不要當成冗餘拿掉**——它是自架 LB 的位址，`trusted-proxies:sync` 永遠掃不到。
     *   - 最後那一跳 353 個位址列不完，只能靠 TRUSTED_PROXY_SKIP_LAST_HOP 無條件跳過。
     *     想改用 nginx 的 real_ip_recursive 處理就得放寬成 0.0.0.0/16 + 0.0.0.0/16，
     *     那是 Google 公用網段、等於信任任何人的 GCP VM，正是下面那條禁止的事。
     *   - ⚠️ 已知缺口：`0.0.0.0` 那 29 筆現在不在 extra_cidrs 裡（它只出現在同一份
     *     values 的 horizon.trusted_proxies）。那些請求 pop 掉最後一跳後會掃到它、它不在
     *     信任清單內，於是被當成客戶端回傳。要補得改 gffv3 的 values，不在本 repo。
     *   - pod 內部互打看到的 0.0.0.0 與 10.244.0.x 由 private_reserved 涵蓋。
     *
     * ⚠️ 加新 VIP 前要確認它在 X-Forwarded-For 裡扮演的是**中繼站**還是**呼叫端**：
     *
     *   中繼站（把別人的流量轉進來，出現在鏈的右側）→ 列進來。掃描會跳過它、繼續往左，
     *                                                 拿到的才是真實來源。
     *   呼叫端（它自己發請求給我們，出現在鏈的最左邊）→ 不用列，列了也沒作用。
     *
     * 判斷方式：看線上 nginx access log 最後一欄的 X-Forwarded-For，它落在第幾節。
     *
     * 📌 「列進來就再也拿不到這個位址」是錯的，不要拿這個當排除理由。resolveClientIp()
     * 的迴圈確實只回傳第一個不在清單內的位址，但迴圈後面還有 `return $chain[0]`——整串
     * 都在清單內時回傳最左邊那筆。所以呼叫端就算被列進來，在「呼叫端 + 我方 LB」這種
     * 兩節鏈裡仍然會被回傳，以它當值的白名單不會失效。
     *
     * 這條原本寫反，據此排除了兩筆。target/op_tickets#3478 於 2026-10-12 查線上 log 更正：
     *
     *   0.0.0.0  gff-yes2win default-webapi-ingress  →  已補進清單
     *       gff-yes2win 近 6 小時 200 筆樣本，它一律在鏈的最後一節：
     *           0.0.0.0, 0.0.0.0, 0.0.0.0
     *       是中繼站不是呼叫端。不列的話，那些會員流量得靠 TRUSTED_PROXY_SKIP_LAST_HOP
     *       才解析得對。它確實出現在 WEB_CustomerServiceSocket `UserController::sync()`
     *       的白名單裡，但那條路徑的鏈是「呼叫端 + CS 的 LB VIP」兩節，實際呼叫端是
     *       0.0.0.0 / 0.0.0.0，不是這顆，列進來不影響該白名單。
     *
     *   0.0.0.0  tp-wz3-online default-frontend-web-ingress  →  維持不列
     *       理由改成：tp-wz3-online 七天內 log 零出現，沒有流量經過它。沒出現就沒有要
     *       解析的東西，列進來純屬冗餘。日後若查到它重新有流量，依上面的中繼站／呼叫端
     *       準則判斷再補。
     *
     * （API_Agent 的 ParseIPAddressAndFilterGooglePlatformIPAddress() 手工濾掉
     * 0.0.0.0 與 192.230.120. 是同一件事的土炮版，之後一併收斂。）
     */
    private const OUR_LB_VIPS = [
        // tp-wz3-rd（dev，12 筆）
        '0.0.0.0/32' => 'tp-wz3-rd onegate-main-gateway',
        '0.0.0.0/32' => 'tp-wz3-rd main-gateway',
        '0.0.0.0/32' => 'tp-wz3-rd devwtt-main-gateway',
        '0.0.0.0/32' => 'tp-wz3-rd cm-cm-backend',
        '0.0.0.0/32' => 'tp-wz3-rd com-ma99s-cdn',
        '0.0.0.0/32' => 'tp-wz3-rd devwtt-internal-gateway',
        '0.0.0.0/32' => 'tp-wz3-rd dev2-internal-gateway',
        '0.0.0.0/32' => 'tp-wz3-rd dev2-main-gateway',
        '0.0.0.0/32' => 'tp-wz3-rd cm-cm-api-frontend',
        '0.0.0.0/32' => 'tp-wz3-rd tgbot-tgbot-php-ingress',
        '0.0.0.0/32' => 'tp-wz3-rd gffpay-gffpay-ingress',
        '0.0.0.0/32' => 'tp-wz3-rd internal-gateway',

        // gff-dev（dev，7 筆）
        '0.0.0.0/32' => 'gff-dev frontend-web-ingress',
        '0.0.0.0/32' => 'gff-dev cs-web-ingress',
        '0.0.0.0/32' => 'gff-dev cs-web-ingress',
        '0.0.0.0/32' => 'gff-dev webapi-ingress',
        '0.0.0.0/32' => 'gff-dev cs-backend-web-ingress',
        '0.0.0.0/32' => 'gff-dev webapi-ingress',
        '0.0.0.0/32' => 'gff-dev agent-webapi-ingress',

        // tp-wz3-online（VWorld/WTT/VWSG，57 筆）
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress4',
        '0.0.0.0/32' => 'tp-wz3-online cdn-ma99s-com',
        '0.0.0.0/32' => 'tp-wz3-online ext-webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-online agent-webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress21',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress14',
        '0.0.0.0/32' => 'tp-wz3-online ext-webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress19',
        '0.0.0.0/32' => 'tp-wz3-online cs-backend-web-ingress2',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress16',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress20',
        '0.0.0.0/32' => 'tp-wz3-online cs-socket-ingress',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress15',
        '0.0.0.0/32' => 'tp-wz3-online matomo',
        '0.0.0.0/32' => 'tp-wz3-online internal-gateway',
        '0.0.0.0/32' => 'tp-wz3-online cdn-ma99s-com-new2',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress10',
        '0.0.0.0/32' => 'tp-wz3-online agent-backend-web-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online cs-backend-web-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online cs-web-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online backend-webapi-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online cs-socket-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online cs-socket-ingress2',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress7',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress3',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress2',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress2-',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress9',
        '0.0.0.0/32' => 'tp-wz3-online gffpay-all-gffpay88-com',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress17',
        '0.0.0.0/32' => 'tp-wz3-online cs-backend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-online cs-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-online cdn-ma99s-com-new',
        '0.0.0.0/32' => 'tp-wz3-online cs-socket-ingress',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress6',
        '0.0.0.0/32' => 'tp-wz3-online cdn-s3-888star-xyz',
        '0.0.0.0/32' => 'tp-wz3-online main-gateway',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress3',
        '0.0.0.0/32' => 'tp-wz3-online webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress13',
        '0.0.0.0/32' => 'tp-wz3-online webapi-ingress2',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress12',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress8',
        '0.0.0.0/32' => 'tp-wz3-online agent-backend-web-ingress2',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress11',
        '0.0.0.0/32' => 'tp-wz3-online cdn-ma99s-com-new1',
        '0.0.0.0/32' => 'tp-wz3-online tgbot-tgbot-ingress',
        '0.0.0.0/32' => 'tp-wz3-online webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-online webapi-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress4',
        '0.0.0.0/32' => 'tp-wz3-online ext-webapi-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online agent-webapi-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online agent-backend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-online backend-web-ingress-',
        '0.0.0.0/32' => 'tp-wz3-online frontend-web-ingress18',
        '0.0.0.0/32' => 'tp-wz3-online main-gateway',

        // tp-wz3-tpower（TPower，25 筆）
        '0.0.0.0/32' => 'tp-wz3-tpower internal-gateway',
        '0.0.0.0/32' => 'tp-wz3-tpower ext-webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower frontend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower agent-backend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower cs-backend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower cs-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower frontend-web-ingress2',
        '0.0.0.0/32' => 'tp-wz3-tpower cs-socket-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower agent-webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower cs-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower mkt-6',
        '0.0.0.0/32' => 'tp-wz3-tpower mkt-5',
        '0.0.0.0/32' => 'tp-wz3-tpower mkt-4',
        '0.0.0.0/32' => 'tp-wz3-tpower mkt-3',
        '0.0.0.0/32' => 'tp-wz3-tpower tpower-official-443',
        '0.0.0.0/32' => 'tp-wz3-tpower cs-backend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower frontend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower webapi-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower tgbot-tgbot-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower frontend-web-ingress4',
        '0.0.0.0/32' => 'tp-wz3-tpower mkt-2',
        '0.0.0.0/32' => 'tp-wz3-tpower agent-backend-web-ingress',
        '0.0.0.0/32' => 'tp-wz3-tpower main-gateway',
        '0.0.0.0/32' => 'tp-wz3-tpower frontend-web-ingress3',

        // gff-yes2win（Yes2Win，12 筆）
        '0.0.0.0/32' => 'gff-yes2win internal-gateway',
        '0.0.0.0/32' => 'gff-yes2win frontend-web-ingress3',
        '0.0.0.0/32' => 'gff-yes2win agent-webapi-ingress',
        '0.0.0.0/32' => 'gff-yes2win frontend-web-ingress2',
        '0.0.0.0/32' => 'gff-yes2win main-gateway',
        '0.0.0.0/32' => 'gff-yes2win default-webapi-ingress', // hardcode-ok: GCP forwarding rule 的實際位址，本常數就是這份清單本體
        '0.0.0.0/32' => 'gff-yes2win agent-backend-web-ingress',
        '0.0.0.0/32' => 'gff-yes2win cs-socket-ingress',
        '0.0.0.0/32' => 'gff-yes2win cs-web-ingress',
        '0.0.0.0/32' => 'gff-yes2win ext-webapi-ingress',
        '0.0.0.0/32' => 'gff-yes2win cs-backend-web-ingress',
        '0.0.0.0/32' => 'gff-yes2win frontend-web-ingress',

        // gff-vwb2c（Me77，3 筆）
        '0.0.0.0/32' => 'gff-vwb2c internal-gateway',
        '0.0.0.0/32' => 'gff-vwb2c main-gateway',
        '0.0.0.0/32' => 'gff-vwb2c tgbot-tgbot-ingress',
    ];

    /**
     * 私網／保留網段：部分環境的 K8s Service 是 NodePort 型態且未設定
     * externalTrafficPolicy: Local，或 Ingress 未啟用 NEG，kube-proxy 對外部
     * 流量做 SNAT，nginx 收到的 REMOTE_ADDR 會變成叢集內部節點的私網 IP，而非
     * 任何公開 CDN/LB 的 IP。若不放寬此判斷，會導致所有請求都被判定為不可
     * 信、無法解析出真實使用者 IP。已知風險：若叢集 NetworkPolicy 未限制
     * 存取，理論上叢集內部來源可被利用來繞過此判斷；根本解法應是修正 K8s
     * Service/Ingress 設定，此為過渡方案（對齊 gsrc/WEB_AgentBackend 的
     * isTrustedProxySource() 立場）。
     */
    private const PRIVATE_RESERVED = [
        '0.0.0.0/8', '0.0.0.0/12', '0.0.0.0/16', // RFC1918 私網
        '0.0.0.0/8', // loopback
        '0.0.0.0/16', // link-local
        'fc00::/7', // IPv6 unique local
        'fe80::/10', // IPv6 link-local
        '::1/128', // IPv6 loopback
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trusted-proxies:sync
        {--dry-run : 只顯示差異，不寫檔}
        {--mlytics-file= : 指定 Mlytics 清單檔（每行一筆 CIDR），覆寫自動挑選}
        {--mlytics-url= : 從 URL 取得 Mlytics 清單（每行一筆 CIDR）}
        {--no-mlytics : 不動 Mlytics 清單，沿用現有檔案內容}
        {path? : 輸出路徑，預設讀 config(trustedproxy.data_path)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重新抓取 Cloudflare／整合 Google LB／Mlytics WAF 信任代理清單，寫入 Common::resolveClientIp() 使用的 JSON 資料檔（target/tickets#4187）';

    public function handle(): int
    {
        $path = $this->argument('path') ?? config('trustedproxy.data_path');
        $existing = $this->readExisting($path);

        $this->info('抓取 Cloudflare IPv4 …');
        $cloudflareIpv4 = $this->fetchCidrList(self::CLOUDFLARE_IPV4_URL);
        if ($cloudflareIpv4 === null) {
            $this->error('Cloudflare IPv4 清單抓取失敗，中止（不寫入半套清單）');

            return self::FAILURE;
        }

        $this->info('抓取 Cloudflare IPv6 …');
        $cloudflareIpv6 = $this->fetchCidrList(self::CLOUDFLARE_IPV6_URL);
        if ($cloudflareIpv6 === null) {
            $this->error('Cloudflare IPv6 清單抓取失敗，中止（不寫入半套清單）');

            return self::FAILURE;
        }

        $mlyticsIpv4 = $this->resolveMlyticsList($existing);
        if ($mlyticsIpv4 === null) {
            return self::FAILURE;
        }

        $cidrs = [
            'cloudflare_ipv4' => $cloudflareIpv4,
            'cloudflare_ipv6' => $cloudflareIpv6,
            'google_lb_ipv4' => self::GOOGLE_LB_IPV4,
            'google_lb_ipv6' => self::GOOGLE_LB_IPV6,
            'our_lb_vips' => array_keys(self::OUR_LB_VIPS),
            'mlytics_ipv4' => $mlyticsIpv4,
            'private_reserved' => self::PRIVATE_RESERVED,
        ];

        foreach ($cidrs as $group => $list) {
            foreach ($list as $cidr) {
                if (!$this->isValidCidr($cidr)) {
                    $this->error("{$group} 含非法 CIDR：{$cidr}");

                    return self::FAILURE;
                }
            }
        }

        $changed = $this->reportDiff($existing['cidrs'] ?? [], $cidrs);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'source' => 'target/gffv3/apis trusted-proxies:sync',
            'cidrs' => $cidrs,
        ];

        // nginx 版本也要比對：清單沒變、但標註或排版改了一樣要重產，
        // 否則 .conf 會停在舊格式而沒有任何徵兆
        $conf = $this->renderNginxConf($payload);
        $confPath = $this->confPath($path);
        $confChanged = !is_file($confPath)
            || $this->withoutTimestamp((string) file_get_contents($confPath)) !== $this->withoutTimestamp($conf);

        if ($confChanged && !$changed) {
            $this->info('CIDR 清單無變更，但 nginx 版本需要重產');
        }

        if (!$changed && !$confChanged) {
            $this->info('清單無變更');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('--dry-run：偵測到差異，未寫檔');

            return 2;
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->error('JSON 編碼失敗');

            return self::FAILURE;
        }

        file_put_contents($path, $json . "\n");

        file_put_contents($confPath, $conf);

        $total = array_sum(array_map('count', $cidrs));
        $this->info("已寫入 {$total} 筆 CIDR 到 {$path}");
        $this->info("已寫入 nginx 版本到 {$confPath}");

        return self::SUCCESS;
    }

    /**
     * 比對 nginx 版本時忽略「產生時間」那一行——它每次都不一樣，
     * 不排除的話每跑一次都會判定有變更。
     */
    private function withoutTimestamp(string $conf): string
    {
        return preg_replace('/^# 產生時間：.*$/m', '', $conf) ?? $conf;
    }

    /**
     * nginx 版本的輸出路徑：與 JSON 同目錄、同主檔名，副檔名換成 .conf。
     */
    private function confPath(string $jsonPath): string
    {
        return preg_replace('/\.json$/', '', $jsonPath) . '.conf';
    }

    /**
     * 產生 nginx 用的 set_real_ip_from 清單（target/op_tickets#3478）。
     *
     * 同一份資料的第二種格式。PHP 端由 Common::resolveClientIp() 讀 JSON；nginx 端
     * 由各站的 nginx.conf `include` 這份 .conf，讓 real_ip 模組自己把 $remote_addr
     * 還原成真實來源——那樣 access log、allow/deny 與傳給 php-fpm 的 REMOTE_ADDR
     * 會同時正確，不必在應用層各自判斷。
     *
     * 各站 nginx.conf 只需要固定這三行，**不帶任何站台專屬的值**：
     *
     *     real_ip_header X-Forwarded-For;
     *     real_ip_recursive on;
     *     include /etc/nginx/trusted-proxies.conf;
     *
     * 🚫 不要手動編輯產出的 .conf，下次 sync 會整個覆蓋。要加 CIDR 請改本檔的常數。
     */
    private function renderNginxConf(array $payload): string
    {
        $lines = [
            '# 信任代理清單（target/op_tickets#3478）',
            '# 由 target/gffv3/apis 的 `php artisan trusted-proxies:sync` 產生，請勿手動編輯。',
            '# 產生時間：' . $payload['generated_at'],
            '#',
            '# 用法：在 nginx.conf 的 http 區塊 include 本檔，並搭配',
            '#   real_ip_header X-Forwarded-For;',
            '#   real_ip_recursive on;',
            '#',
            '# 全站共用同一份：多列其他站台的 VIP 不會擴大攻擊面——set_real_ip_from 只有在該',
            '# 位址真的是連線對端、或出現在 X-Forwarded-For 中真實來源的右側時才會生效，',
            '# 而那些位址都是我方基礎設施，對方成為不了它們。',
            '',
        ];

        foreach ($payload['cidrs'] as $group => $items) {
            if ($items === []) {
                continue;
            }

            $lines[] = '# ' . $group . '（' . count($items) . ' 筆）';

            $width = max(array_map('strlen', $items));

            foreach ($items as $cidr) {
                $line = 'set_real_ip_from ' . $cidr . ';';
                $label = self::OUR_LB_VIPS[$cidr] ?? null;

                // 我方 VIP 逐行標註是哪個專案的哪個服務；沒有標註的（CDN／GFE／私網）
                // 靠上面的群組標題就看得出來源，不補假的說明
                $lines[] = $label === null
                    ? $line
                    : str_pad($line, $width + 22) . '# ' . $label;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * 讀取現有的 JSON 資料檔，找不到時回傳空結構。
     */
    private function readExisting(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 依 --mlytics-file / --mlytics-url / --no-mlytics / 自動挑選 決定
     * Mlytics 清單。都找不到來源時沿用現有 JSON 裡的舊值；連舊值都沒有就
     * 回空陣列。
     */
    private function resolveMlyticsList(array $existing): ?array
    {
        if ($this->option('no-mlytics')) {
            $this->comment('--no-mlytics：Mlytics 清單沿用現有檔案內容');

            return $existing['cidrs']['mlytics_ipv4'] ?? [];
        }

        if ($mlyticsUrl = $this->option('mlytics-url')) {
            $this->info('抓取 Mlytics 清單（URL）…');
            $list = $this->fetchCidrList($mlyticsUrl);
            if ($list === null) {
                $this->error("Mlytics 清單抓取失敗：{$mlyticsUrl}");

                return null;
            }

            return $list;
        }

        $mlyticsFile = $this->option('mlytics-file') ?? $this->resolveLatestMlyticsFile();

        if ($mlyticsFile !== null) {
            if (!is_file($mlyticsFile)) {
                $this->error("找不到 Mlytics 清單檔：{$mlyticsFile}");

                return null;
            }

            $this->info('讀取 Mlytics 清單：' . basename($mlyticsFile));

            return $this->parseCidrLines((string) file_get_contents($mlyticsFile));
        }

        $this->comment('本目錄下找不到 mlytics_YYYYMMDDwaflist.txt，Mlytics 清單沿用現有檔案內容');

        return $existing['cidrs']['mlytics_ipv4'] ?? [];
    }

    /**
     * 挑出 storage/app/trusted-proxies/ 目錄下日期最新的
     * mlytics_YYYYMMDDwaflist.txt。
     *
     * Mlytics 沒有公開 API，清單是以帶日期的檔案交付；以檔名日期而非
     * mtime 判斷，避免 git checkout / 複製造成的時間戳失真。找不到符合
     * 命名的檔案時回傳 null。
     */
    private function resolveLatestMlyticsFile(): ?string
    {
        $dir = storage_path('app/trusted-proxies');
        $found = [];

        foreach ((array) glob("{$dir}/mlytics_*waflist.txt") as $path) {
            if (preg_match('/mlytics_(\d{8})waflist\.txt$/', basename($path), $m)) {
                $found[$m[1]] = $path;
            }
        }

        if ($found === []) {
            return null;
        }

        krsort($found);

        return reset($found);
    }

    /**
     * 抓取「每行一筆 CIDR」的純文字清單。失敗回傳 null。
     */
    private function fetchCidrList(string $url): ?array
    {
        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable $e) {
            $this->error("HTTP 取得失敗 {$url}：{$e->getMessage()}");

            return null;
        }

        if (!$response->successful()) {
            $this->error("HTTP 取得失敗 {$url}（status={$response->status()}）");

            return null;
        }

        $list = $this->parseCidrLines($response->body());

        return $list === [] ? null : $list;
    }

    /**
     * 把純文字內容切成 CIDR 清單，去空行與註解、去重、保留來源順序。
     */
    private function parseCidrLines(string $body): array
    {
        $out = [];

        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $out[$line] = true;
        }

        return array_keys($out);
    }

    /**
     * 印出各組的新增／移除，回傳是否有任何差異。
     */
    private function reportDiff(array $old, array $new): bool
    {
        $changed = false;

        foreach ($new as $group => $newList) {
            $oldList = $old[$group] ?? [];
            $added = array_values(array_diff($newList, $oldList));
            $removed = array_values(array_diff($oldList, $newList));

            if ($added === [] && $removed === []) {
                $this->line(sprintf('%-18s 無變更（%d 筆）', $group, count($newList)));

                continue;
            }

            $changed = true;
            $this->line(sprintf('%-18s %d 筆 → %d 筆', $group, count($oldList), count($newList)));

            foreach ($added as $cidr) {
                $this->line("    + {$cidr}");
            }

            foreach ($removed as $cidr) {
                $this->line("    - {$cidr}");
            }
        }

        return $changed;
    }

    /**
     * CIDR 格式驗證，同時支援 IPv4 / IPv6。對齊
     * gsrc/WEB_AgentBackend/scripts/update_trusted_proxies.php 的
     * isValidCidr()。
     */
    private function isValidCidr(string $cidr): bool
    {
        if (substr_count($cidr, '/') !== 1) {
            return false;
        }

        [$subnet, $bitsRaw] = explode('/', $cidr, 2);

        if ($bitsRaw === '' || !ctype_digit($bitsRaw)) {
            return false;
        }

        $bin = @inet_pton($subnet);
        if ($bin === false) {
            return false;
        }

        $bits = (int) $bitsRaw;

        return $bits >= 0 && $bits <= strlen($bin) * 8;
    }
}
