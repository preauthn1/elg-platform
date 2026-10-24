<?php

declare(strict_types=1);

namespace App\Vendors\PlayTechSW;

use App\Data\GameInfoData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * PlayTech 官方遊戲清單活頁簿（SharePoint 上的 xlsx）
 *
 * PT 沒有提供遊戲清單 API，官方以一份 SharePoint 活頁簿發佈，內含 8 個分頁；
 * 本類只取我方有開放的類型分頁（見 SHEET_GAME_TYPES），其餘分頁不取。
 *
 * 取檔網址由觸發者於執行當下提供（update_game_list --sheet-url，見 NeedsGameListSourceUrl）。
 * PT 給的共用連結是給人在瀏覽器開 Excel Online 用的，程式抓它只會拿到網頁，
 * 故於 resolveDownloadUrl() 自動改寫成下載直鏈——直鏈也照收，兩種形式都能用。
 *
 * ⚠️ 這條連結一旦被撤銷、改權限，或 PT 改成「上傳新檔」而非「更新同一個檔」
 * （檔名帶日期，如 Main Playtech Game List 20260901.xlsx）就會失效，
 * 屆時同步會拋 ApiException。沒有自動回復的辦法，只能換新連結。
 *
 * @knowledge rules/game-list-sync
 */
class GameListSheet
{
    public function __construct(private readonly string $sourceUrl) {}

    /**
     * 要取的分頁 → 我方遊戲類型。
     *
     * **分頁即類型**：分頁內雖有 Game Type 欄，實測值是 PT 自己的分類字串
     * （POP Slots／POP Arcade／POP Crash Games…）且有髒值，不可作為判斷依據；
     * 分頁歸屬才是穩定的。
     *
     * 爆點分頁混了 PT 的 Arcade 與 Crash 兩種分類，我方一律歸街機（ARCADE）。
     *
     * 任一分頁在活頁簿中找不到即拋出（PT 改分頁名時會炸而非靜默少一類）。
     *
     * @var array<string, GameTypeEnum>
     */
    private const array SHEET_GAME_TYPES = [
        'Slots 电子' => GameTypeEnum::SLOTS,
        'Crash 爆点游戏' => GameTypeEnum::ARCADE,
    ];

    /** 分頁的英文表頭欄名 → GameInfoData 用途 */
    private const string COL_GAME_CODE = 'Game Code';

    /**
     * 遊戲代碼欄的中文表頭字樣。
     *
     * 分頁有兩列表頭（英文一列、中文一列），中文那列在遊戲代碼欄有值，
     * 不攔會被當成一筆遊戲。刻意比對字樣而非「跳過表頭下一列」——
     * PT 哪天拿掉中文表頭時，後者會靜默吃掉第一款真實遊戲。
     */
    private const string COL_GAME_CODE_ZH = '游戏代码';

    /** 只有電子分頁有此欄；爆點分頁沒有，取不到時視為無值 */
    private const string COL_NEW_GAME_CODE = 'New Game Code';

    private const string COL_NAME_EN = 'English Game Name';

    private const string COL_NAME_ZH = 'Chinese Game Name';

    private const string COL_NAME_KO = 'Korean Game Name';

    /**
     * 主清單結束的區塊標題字樣（比對前轉小寫）。
     *
     * 電子分頁在正式清單之後另接「維護中」與「已下架」兩個區塊，格式是一列
     * 只有標題文字的分隔列，底下再列遊戲。那些遊戲不該進我方清單：
     * 已下架的開不起來，維護中的恢復後會回到主清單自然被補進來。
     * 且下架區塊的欄位比主清單少（無日／韓文名稱），照主清單的欄位位置讀
     * 會把 RTP 數值寫進韓文名稱。
     *
     * 讀到標記列即停止解析該分頁。PT 改字樣時會退回「連下架區一起收」的行為，
     * 並在 log 留下 warning（見 readRows），不會靜默漏掉主清單的遊戲。
     *
     * @var list<string>
     */
    private const array LIST_END_MARKERS = ['removed games', 'games maintance', 'games maintenance'];

    /** 空值佔位；PT 以 `-` 表示該欄無值 */
    private const string EMPTY_PLACEHOLDER = '-';

    private const int DOWNLOAD_TIMEOUT_SECONDS = 120;

    /** xlsx 即 zip，檔頭固定為此四個位元組 */
    private const string ZIP_MAGIC = "PK\x03\x04";

    private const int ZIP_MAGIC_LENGTH = 4;

    /** 非 xlsx 時錯誤訊息帶的內容長度 */
    private const int ERROR_BODY_PREVIEW_BYTES = 300;

    /**
     * SharePoint 共用連結的路徑形式：`/:<檔類代號>:/s/<站台>/<token>`
     *
     * 檔類代號是 Office 的檔案類型（x=Excel、w=Word、p=PowerPoint…），一律單一字母。
     */
    private const string SHARE_PATH_PATTERN = '#^/:[a-z]:/s/([^/]+)/([^/]+)$#i';

    /**
     * 讀取所有目標分頁，回傳以 game code 為鍵的清單。
     *
     * @return array<string, GameInfoData>
     *
     * @throws ApiException 下載失敗、活頁簿缺分頁或缺必要欄位
     */
    public function readGames(): array
    {
        $path = $this->download();

        try {
            return $this->parseSheets($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * 下載活頁簿到暫存檔，回傳檔案路徑。
     *
     * 刻意不走 Http::logAction：該 macro 會把完整 response body 原文寫進 log
     * （HttpLoggerCustom::extractResponseContext 未截斷），而本次回應是數百 KB 的
     * xlsx 二進位，落 log 只會產生垃圾並灌爆日誌量。改為自行記錄「這次下載的結果」
     * （狀態碼、大小、耗時），那才是排查需要的資訊。
     *
     * @throws ApiException
     */
    private function download(): string
    {
        $url = trim($this->sourceUrl);

        if ($url === '') {
            throw new ApiException('PTSW game list: source url is empty');
        }

        $url = $this->resolveDownloadUrl($url);
        $path = tempnam(sys_get_temp_dir(), 'ptsw_game_list_');

        if ($path === false) {
            throw new ApiException('PTSW game list: unable to create temp file');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
                ->sink($path)
                ->get($url);
        } catch (Throwable $e) {
            @unlink($path);
            Log::error('PTSW 遊戲清單下載失敗', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw new ApiException('PTSW game list download failed: ' . $e->getMessage());
        }

        $size = is_file($path) ? (int) filesize($path) : 0;
        $contentType = (string) $response->header('Content-Type');

        Log::info('PTSW 遊戲清單下載完成', [
            'url' => $url,
            'status' => $response->status(),
            'bytes' => $size,
            'content_type' => $contentType,
            'elapsed_sec' => round(microtime(true) - $startedAt, 3),
        ]);

        // 連結失效時 SharePoint 回 403 並附一小段 HTML／純文字，不是 xlsx；
        // 以狀態碼與大小攔下，避免把錯誤頁丟給 xlsx reader 得到難解的解析例外
        if (!$response->successful() || $size === 0) {
            @unlink($path);

            throw new ApiException(sprintf(
                'PTSW game list download failed: HTTP %d, %d bytes (share link may be revoked or replaced)',
                $response->status(),
                $size,
            ));
        }

        $this->assertXlsx($path, $response->status(), $size, $contentType);

        return $path;
    }

    /**
     * 把 PT 的共用連結改寫成下載直鏈；已是直鏈或認不出的形式原樣返回。
     *
     * PT 給的連結（`/:x:/s/<站台>/<token>`）是給人在瀏覽器開 Excel Online 用的，
     * 程式抓它只會拿到網頁——實測跟隨重導向後得到 200 加五萬多字元的 HTML，
     * 接著 openspout 拋「Not a zip archive」，看不出真正的原因。
     *
     * 改寫在程式裡做而不是要求操作者手動組：客服拿到的一定是前者，
     * 每次都得抓出站台名與 token 再套範本，漏一次就是一次假故障。
     *
     * 認不出的形式**原樣送出**而不是拋錯：可能已經是直鏈，也可能 PT 換了新形式，
     * 讓 SharePoint 自己回應比在這裡猜更安全——真的拿不到 xlsx 仍有 assertXlsx() 攔下。
     *
     * 原連結的 query（`?rtime=…` 之類）一併丟棄，那是頁面參數、對下載無用。
     */
    private function resolveDownloadUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return $url;
        }

        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        if ($host === '' || preg_match(self::SHARE_PATH_PATTERN, $path, $matches) !== 1) {
            return $url;
        }

        [, $site, $token] = $matches;

        // 只記站台：token 是憑證，能不落 log 就不落
        Log::info('PTSW 遊戲清單：共用連結已改寫為下載直鏈', ['site' => $site]);

        return sprintf(
            '%s://%s/sites/%s/_layouts/15/download.aspx?share=%s',
            $parts['scheme'] ?? 'https',
            $host,
            $site,
            $token,
        );
    }

    /**
     * 確認下載到的真的是 xlsx。
     *
     * 以檔頭的 zip magic bytes 判斷而非 Content-Type：xlsx 就是一個 zip，
     * 而 Content-Type 取決於伺服器設定（實測 SharePoint 回
     * application/vnd.openxmlformats-officedocument.spreadsheetml.sheet，
     * 但代理層可能改寫、也見過 application/octet-stream），拿它當唯一依據會誤擋。
     *
     * ⚠️ 存取被拒時 SharePoint 未必回 4xx——常見的是 **200 帶一頁 HTML**
     * （登入頁、同意頁、錯誤頁），來源 IP 不被信任或連結需登入時都是這樣。
     * 不在這裡攔，openspout 會拋「Not a zip archive」，把真正的原因蓋掉。
     * 錯誤訊息帶上 Content-Type 與內容開頭，讓人一眼看出拿到的是什麼。
     *
     * @throws ApiException
     */
    private function assertXlsx(string $path, int $status, int $size, string $contentType): void
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, self::ZIP_MAGIC_LENGTH);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($magic === self::ZIP_MAGIC) {
            return;
        }

        // 只取開頭一小段：HTML 錯誤頁動輒數十 KB，全塞進錯誤訊息會灌爆 log
        // 與 admin_operation_logs.output；開頭足以判斷是 HTML、JSON 還是別的
        $head = (string) file_get_contents($path, false, null, 0, self::ERROR_BODY_PREVIEW_BYTES);
        @unlink($path);

        Log::error('PTSW 遊戲清單下載到非 xlsx 內容', [
            'status' => $status,
            'bytes' => $size,
            'content_type' => $contentType,
            'body_head' => $head,
        ]);

        throw new ApiException(sprintf(
            'PTSW game list: response is not an xlsx (HTTP %d, %d bytes, Content-Type: %s). '
            . 'SharePoint returns an HTML page with HTTP 200 when the link needs sign-in or the caller is not allowed; '
            . 'verify the share link and that this environment may reach SharePoint. Body starts with: %s',
            $status,
            $size,
            $contentType !== '' ? $contentType : '(none)',
            Str::limit(trim(preg_replace('/\s+/', ' ', $head) ?? $head), self::ERROR_BODY_PREVIEW_BYTES),
        ));
    }

    /**
     * 逐一解析目標分頁並合併。
     *
     * 一次 reader 迭代讀完全部分頁：xlsx reader 是單向串流，重開一次就要重解壓一次。
     *
     * @return array<string, GameInfoData>
     *
     * @throws ApiException
     */
    private function parseSheets(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);

        $games = [];
        $found = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $name = $sheet->getName();
                $gameType = self::SHEET_GAME_TYPES[$name] ?? null;

                if ($gameType === null) {
                    continue;
                }

                $found[] = $name;
                $games = $this->merge($games, $this->readRows($sheet->getRowIterator(), $name, $gameType), $name);
            }
        } finally {
            $reader->close();
        }

        $missing = array_diff(array_keys(self::SHEET_GAME_TYPES), $found);

        if ($missing !== []) {
            throw new ApiException(sprintf(
                'PTSW game list: sheet "%s" not found',
                implode('", "', $missing),
            ));
        }

        return $games;
    }

    /**
     * 合併分頁結果。
     *
     * 同一個遊戲代碼理應只出現在一個分頁；真的撞到時保留先讀到的那筆並留 log，
     * 讓後蓋前導致「類型被默默改掉」不會發生。
     *
     * @param  array<string, GameInfoData>  $games
     * @param  array<string, GameInfoData>  $incoming
     * @return array<string, GameInfoData>
     */
    private function merge(array $games, array $incoming, string $sheetName): array
    {
        $duplicated = array_keys(array_intersect_key($incoming, $games));

        if ($duplicated !== []) {
            Log::warning('PTSW 遊戲清單：分頁間遊戲代碼重複，保留先讀到的分頁', [
                'sheet' => $sheetName,
                'game_codes' => $duplicated,
            ]);
        }

        return $games + $incoming;
    }

    /**
     * 解析單一分頁。
     *
     * 欄位一律依**表頭名稱**定位，不用固定索引：PT 插一欄就會讓固定索引整排錯位，
     * 而那是靜默的——不會報錯，只會把錯的字串寫進遊戲代碼與名稱。
     *
     * @param  iterable<int, \OpenSpout\Common\Entity\Row>  $rows
     * @return array<string, GameInfoData>
     *
     * @throws ApiException
     */
    private function readRows(iterable $rows, string $sheetName, GameTypeEnum $gameType): array
    {
        /** @var array<string, int>|null $columns 表頭名稱 => 欄索引 */
        $columns = null;
        $games = [];

        foreach ($rows as $row) {
            $cells = $this->rowValues($row);

            if ($columns === null) {
                $columns = $this->detectColumns($cells);

                continue;
            }

            $gameCode = $this->cell($cells, $columns, self::COL_GAME_CODE);

            // 中文表頭列在遊戲代碼欄有值，不是一筆遊戲
            if ($gameCode === self::COL_GAME_CODE_ZH) {
                continue;
            }

            if ($gameCode === '' || $gameCode === self::EMPTY_PLACEHOLDER) {
                $label = $this->rowLabel($cells);

                if ($label === '') {
                    continue;
                }

                if ($this->isListEndMarker($label)) {
                    Log::info('PTSW 遊戲清單：主清單結束，後續區塊不納入', [
                        'sheet' => $sheetName,
                        'marker' => $label,
                        'games' => count($games),
                    ]);

                    break;
                }

                Log::warning('PTSW 遊戲清單：略過無遊戲代碼的資料列', [
                    'sheet' => $sheetName,
                    'row' => $label,
                ]);

                continue;
            }

            $games[$gameCode] = $this->toGameInfo($gameCode, $cells, $columns, $gameType);
        }

        if ($columns === null) {
            throw new ApiException(sprintf('PTSW game list: header row not found in sheet "%s"', $sheetName));
        }

        if ($games === []) {
            throw new ApiException(sprintf('PTSW game list: sheet "%s" has no game rows', $sheetName));
        }

        return $games;
    }

    /**
     * 表頭列偵測：同時含遊戲代碼與英文名稱兩欄者即是。
     *
     * @param  array<int, string>  $cells
     * @return array<string, int>|null 表頭名稱 => 欄索引；非表頭列回 null
     */
    private function detectColumns(array $cells): ?array
    {
        $columns = [];

        foreach ($cells as $index => $value) {
            if ($value !== '') {
                $columns[$value] = $index;
            }
        }

        $required = [self::COL_GAME_CODE, self::COL_NAME_EN];

        foreach ($required as $name) {
            if (!isset($columns[$name])) {
                return null;
            }
        }

        return $columns;
    }

    /**
     * 整列的非空內容串成一行，供區塊標題判斷與 log。
     *
     * @param  array<int, string>  $cells
     */
    private function rowLabel(array $cells): string
    {
        return implode(' ', array_filter($cells, static fn (string $value): bool => $value !== ''));
    }

    private function isListEndMarker(string $label): bool
    {
        $lowered = mb_strtolower($label);

        foreach (self::LIST_END_MARKERS as $marker) {
            if (str_contains($lowered, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<string, int>  $columns
     */
    private function toGameInfo(string $gameCode, array $cells, array $columns, GameTypeEnum $gameType): GameInfoData
    {
        $nameEn = $this->cell($cells, $columns, self::COL_NAME_EN);
        $nameZh = $this->cell($cells, $columns, self::COL_NAME_ZH);
        $nameKo = $this->cell($cells, $columns, self::COL_NAME_KO);
        $newGameCode = $this->cell($cells, $columns, self::COL_NEW_GAME_CODE);

        $gameInfo = GameInfoData::from([
            'gameCode' => $gameCode,
            // 名稱一律有值：英文名缺漏時退回代碼，避免 games.name 空白
            'gameName' => $nameEn !== '' ? $nameEn : $gameCode,
            // 活頁簿沒有圖片欄位，PT 也不提供圖檔，一律留空由客服於後台補圖。
            // 原 CSV 匯入是按 <代碼>.png 推導我方 CDN 路徑，但那個 bucket 的圖是人工
            // 上傳的，新遊戲多半還沒放圖（實測將新增的 123 款有 49 款推導出來是死連結），
            // 推導只是把破圖寫進 DB 且看起來像有值
            'imageUrl' => '',
            'gameType' => $gameType->value,
            'status' => 1,
            // PT 部分遊戲另有新代碼，開遊戲仍以 Game Code 為準，新代碼存 remark 供對照
            'remark' => ($newGameCode !== '' && $newGameCode !== self::EMPTY_PLACEHOLDER) ? $newGameCode : null,
        ]);

        // 活頁簿另有日文名稱，但 LanguageEnum 目前沒有日文，帶不進來
        $names = [
            LanguageEnum::EN->value => $nameEn,
            LanguageEnum::ZH_TW->value => $nameZh,
            LanguageEnum::ZH_CN->value => $nameZh,
            LanguageEnum::KO->value => $nameKo,
        ];

        foreach ($names as $languageId => $name) {
            if ($name !== '' && $name !== self::EMPTY_PLACEHOLDER) {
                $gameInfo->setGameNames($languageId, $name);
            }
        }

        return $gameInfo;
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<string, int>  $columns
     */
    private function cell(array $cells, array $columns, string $name): string
    {
        $index = $columns[$name] ?? null;

        return $index === null ? '' : ($cells[$index] ?? '');
    }

    /**
     * 整列取值並轉為字串（日期欄會是 DateTimeImmutable，直接轉字串會 TypeError）。
     *
     * @return array<int, string>
     */
    private function rowValues(\OpenSpout\Common\Entity\Row $row): array
    {
        return array_map(
            function ($cell): string {
                $value = $cell->getValue();

                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                return is_scalar($value) ? trim((string) $value) : '';
            },
            $row->getCells(),
        );
    }
}
