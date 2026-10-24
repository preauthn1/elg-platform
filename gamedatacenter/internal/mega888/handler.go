package mega888

import (
	"bytes"
	"crypto/md5"
	"encoding/json"
	"fmt"
	"io"
	"io/ioutil"
	"net"
	"net/http"
	"net/url"
	"os"
	"strings"
	"sync"
	"time"

	"crypto/tls"

	"example.invalid/google/uuid"
	"example.invalid/shopspring/decimal"
	"example.invalid/target/gamedatacenter/internal/functions/database"
	"example.invalid/target/gamedatacenter/internal/functions/datetime"
	"example.invalid/target/gamedatacenter/internal/functions/util"
	"example.invalid/target/gamedatacenter/internal/functions/vendorinfo"
)

var loc = time.FixedZone("UTC+8", 8*60*60)
var locUTC = time.FixedZone("UTC+0", 0)

var vendorId = 10

// 全局 HTTP 客戶端，強制使用 HTTP/1.1
var httpClient *http.Client

func init() {
	// 創建自定義 Transport 強制使用 HTTP/1.1
	transport := &http.Transport{
		// 強制使用 HTTP/1.1
		ForceAttemptHTTP2: false,
		// 設置 TLS 配置
		TLSHandshakeTimeout: 10 * time.Second,
		// 設置連接超時
		DialContext: (&net.Dialer{
			Timeout:   30 * time.Second,
			KeepAlive: 30 * time.Second,
		}).DialContext,
		// 設置最大閒置連接數
		MaxIdleConns:    100,
		IdleConnTimeout: 90 * time.Second,
		TLSClientConfig: &tls.Config{
			// 強制使用 TLS 1.2
			MinVersion: tls.VersionTLS12,
			MaxVersion: tls.VersionTLS13,
		},
	}

	httpClient = &http.Client{
		Transport: transport,
		Timeout:   30 * time.Second,
	}
}

// Handler command
func Handler(startTime, endTime time.Time) {
	startTime = startTime.In(loc)
	endTime = endTime.In(loc)
	vendorInfoList := vendorinfo.GetVendorInfoList(vendorId)
	dictUsed := map[string]int{}
	//promptChoseVendorInfo(vendorInfoList)
	for _, vendorInfo := range vendorInfoList {
		var info Mega888Info
		fmt.Println(vendorInfo.Name + " ...")
		json.Unmarshal([]byte(example.invalid), &info)
		hash := info.Account + "_" + info.APIKey
		// 判斷商戶資料是否跑過
		if _, exists := dictUsed[hash]; !exists {
			dictUsed[info.Account+"_"+info.APIKey] = 1
			var Records []Mega888BetRecord
			//抓取 [1 老虎機, 2 真人] 注單
			for i := 1; i < 3; i++ {
				// 如果有跨日, 則需要跑兩天
				for rd := datetime.RangeDate(startTime, endTime); ; {
					var dicLoginID = map[string]int{}
					date := rd()
					if date.IsZero() {
						break
					}
					st := startTime //當天的開始時間
					et := endTime   //當天的結束時間
					if date.After(startTime) {
						st = date
					}
					if date.AddDate(0, 0, 1).Before(endTime) {
						et = date.AddDate(0, 0, 1)
					}
					loginIds := getNeedFetchLoginIds(date, i, vendorInfo.ID)
					for _, id := range loginIds {
						dicLoginID[id] = i
					}
					/*
						resp := getDailyWin(date, i, info)
						// 整理用戶識別碼
						for _, record := range resp.Result {
							if _, exists := dicLoginID[record.LoginID]; !exists {
								dicLoginID[record.LoginID] = i
							}
						}
					*/
					fmt.Println(i, "fetch data from ", st, " to ", et)
					temp := getBetRecordsWorker(10, dicLoginID, i, st, et, info)
					Records = append(Records, temp...)

				}
			}
			fmt.Println("total count", len(Records))
			createRecords := filterRecordsNotExists(Records, 1000)
			database.DB.CreateInBatches(createRecords, 1000)
			fmt.Println("create count", len(createRecords))
			fmt.Println("====== total bet record start ======")
			database.DB.Exec("CALL SP_transfer_bet_record_mega888(null, null, null)")
			fmt.Println("====== total bet record end ======")

		}
	}
}

func filterRecordsNotExists(Records []Mega888BetRecord, size int) (output []Mega888BetRecord) {
	var exists_ref_ids []string
	var ref_ids []string
	for _, record := range Records {
		ref_ids = append(ref_ids, record.RefId)
	}
	chunks := util.ChunkStringSlice(ref_ids, size)
	for _, chunk := range chunks {
		var ids []string
		database.DB.Table("mega888_bet_records").Where("ref_id in ?", chunk).Pluck("ref_id", &ids)
		exists_ref_ids = append(exists_ref_ids, ids...)
	}
	for _, record := range Records {
		//fmt.Println("--------------", record.RefId)
		if util.InArray(record.RefId, exists_ref_ids) {

			continue
		}
		output = append(output, record)
	}
	return
}

func getBetRecordsWorker(workerCount int, dicLoginID map[string]int, t int, startTime, endTime time.Time, info Mega888Info) (Records []Mega888BetRecord) {
	var wg sync.WaitGroup
	var mu sync.Mutex
	var chans = make(chan string, len(dicLoginID))
	var currentNum = 0
	for i := 0; i < workerCount; i++ {
		go func() {
			for loginId := range chans {
				func() {
					defer wg.Done()
					temp, pageIndex := getBetRecords(t, loginId, startTime, endTime, info)
					mu.Lock()
					defer mu.Unlock()
					Records = append(Records, temp...)
					currentNum++
					fmt.Printf("[%d/%d %s (%+d|%d) %d],", currentNum, len(dicLoginID), loginId, len(temp), len(Records), pageIndex)
				}()
			}
		}()
	}
	for loginId := range dicLoginID {
		wg.Add(1)
		chans <- loginId
	}
	wg.Wait()
	fmt.Println()
	return
}

func getBetRecords(t int, loginId string, startTime, endTime time.Time, info Mega888Info) (Records []Mega888BetRecord, pageIndex int) {
	defer util.PanicErrStack()
	var result []playerGameLogResponseResult
	var liveResult []videoOrderPageResponseResultItem
	pageIndex = 1
	for {
		nextLoop := false
		pageSize := 20
		if t == 1 {
			temp := getPlayerBetRecordsByPage(loginId, startTime, endTime, pageIndex, pageSize, info)
			result = append(result, temp...)
			if len(temp) > 0 {
				nextLoop = true
				pageIndex++
			}
		} else if t == 2 {
			temp := getPlayerLiveBetRecordByPage(loginId, startTime, endTime, pageIndex, pageSize, info)
			liveResult = append(liveResult, temp...)
			if len(temp) > 0 {
				nextLoop = true
				pageIndex++
			}
		}
		if !nextLoop {
			break
		}
	}
	if t == 1 {
		for _, record := range result {
			create_time_utc, _ := time.ParseInLocation("2006-01-02 15:04:05", record.CreateTime, locUTC) //for timestamp 使用，因為之前的邏輯就是把時間 parser成 utc 的timestamp.
			betTimeUTC, _ := time.ParseInLocation("2006-01-02 15:04:05", record.CreateTime, loc)
			betTime := betTimeUTC.Add(time.Duration(8) * time.Hour) // 因為 time 結構與 mysql 我們是沒使用loc=Local 所以傳進去的時間會被轉換成utc 更新進去，所以betTime 要自行 +8 Hours
			//fmt.Println(create_time, create_time_utc.UTC().Unix(), create_time_utc.Unix())
			Records = append(Records, Mega888BetRecord{
				RefId:        fmt.Sprintf("%s-%d-%d-%d", loginId, record.GameID, create_time_utc.Unix(), record.ID),
				Btype:        "single",
				ClassId:      int32(record.ClassID),
				PlayerId:     loginId,
				BetTime:      betTime,
				BetTimeUtc:   betTimeUTC,
				BeginBalance: record.BeginBlance,
				EndBalance:   record.EndBlance,
				GameId:       int32(record.GameID),
				GameName:     record.GameName,
				LineNum:      int32(record.LineNum),
				LogDataStr:   "",
				LogDataType:  int32(record.LogDataType),
				RoundNo:      int32(record.RoundNO),
				RowNum:       int32(record.Rownum),
				TableId:      int32(record.TableID),
				Win:          record.Win,
				Bet:          record.Bet,
				BetId:        int32(record.ID),
			})
		}
	} else if t == 2 {
		for _, record := range liveResult {
			betTimeUTC, _ := time.ParseInLocation("2006-01-02 15:04:05", record.OrderTimeBj, loc)
			betTime := betTimeUTC.Add(time.Duration(8) * time.Hour) // 因為 mysql 我們是沒使用loc=Local 所以time 結構傳進去的時間會被轉換成utc 更新進去，所以betTime 要自行 +8 Hours
			Records = append(Records, Mega888BetRecord{
				RefId:        fmt.Sprintf("%d", record.OrderID),
				Btype:        "live",
				ClassId:      0,
				PlayerId:     loginId,
				BetTime:      betTime,
				BetTimeUtc:   betTimeUTC,
				BeginBalance: decimal.New(0, 0),
				EndBalance:   decimal.New(0, 0),
				GameId:       int32(record.GameID),
				GameName:     record.GameNameEn,
				LineNum:      int32(0),
				LogDataStr:   "",
				LogDataType:  0,
				RoundNo:      0,
				RowNum:       0,
				TableId:      0,
				Win:          record.ValidBet.Add(record.Payment),
				Bet:          record.ValidBet,
				BetId:        0,
			})
		}
	}
	return
}

func getPlayerLiveBetRecordByPage(loginId string, startTime, endTime time.Time, pageIndex, pageSize int, info Mega888Info) (result []videoOrderPageResponseResultItem) {
	api := "open.mega.video.order.page"
	myurl := info.APIURL + api
	uuid := uuid.NewString()
	requestBody := videoOrderPageRequest{
		uuid,
		api,
		videoOrderPageRequestParams{
			uuid,
			digestAccount(uuid, loginId, info),
			info.Sn,
			loginId,
			startTime.Format("2006-01-02 15:04:05"),
			endTime.Format("2006-01-02 15:04:05"),
			pageIndex,
			pageSize,
		},
		"2.0",
	}
	b, _ := json.Marshal(requestBody)
	req, _ := http.NewRequest("POST", myurl, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	var response videoOrderPageResponse

	body, err := fetchApi(req)
	if err != nil {
		fmt.Printf("getPlayerLiveBetRecordByPage failed for loginId %s: %v\n", loginId, err)
		return
	}

	if err := json.Unmarshal(body, &response); err != nil {
		fmt.Printf("getPlayerLiveBetRecordByPage JSON unmarshal failed for loginId %s: %v, body: %s\n", loginId, err, string(body))
		return
	}

	result = response.Result.Items
	return
}

func getPlayerBetRecordsByPage(loginId string, startTime, endTime time.Time, pageIndex, pageSize int, info Mega888Info) (result []playerGameLogResponseResult) {
	api := "open.mega.player.game.log.url.get"
	myurl := info.APIURL + api
	uuid := uuid.NewString()
	requestBody := playerGameLogUrlRequest{
		uuid,
		api,
		playerGameLogUrlRequestParams{
			uuid,
			digestAccount(uuid, loginId, info),
			info.Sn,
			loginId,
			startTime.Format("2006-01-02 15:04:05"),
			endTime.Format("2006-01-02 15:04:05"),
		},
		"2.0",
	}
	b, _ := json.Marshal(requestBody)
	req, _ := http.NewRequest("POST", myurl, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")

	var response playerGameLogUrlResponse
	body, err := fetchApi(req)
	if err != nil {
		fmt.Printf("getPlayerBetRecordsByPage URL request failed for loginId %s: %v\n", loginId, err)
		return
	}

	if err := json.Unmarshal(body, &response); err != nil {
		fmt.Printf("getPlayerBetRecordsByPage URL JSON unmarshal failed for loginId %s: %v, body: %s\n", loginId, err, string(body))
		return
	}

	if response.Error == nil && response.Result != "" {
		gameLogUrl := response.Result
		u, err := url.Parse(gameLogUrl)
		if err != nil {
			fmt.Printf("Failed to parse game log URL for loginId %s: %v\n", loginId, err)
			return
		}
		values, _ := url.ParseQuery(u.RawQuery)

		values.Add("action", "playerGameLog")
		values.Add("user", values.Get("playername"))
		values.Add("sDate", values.Get("logdate_begin"))
		values.Add("eDate", values.Get("logdate_end"))
		values.Add("pageIndex", fmt.Sprintf("%d", pageIndex))
		values.Add("pageSize", fmt.Sprintf("%d", pageSize))
		logUrl := u.Scheme + "://" + u.Host + "/ashx/log/GameLog.ashx"

		var response playerGameLogResponse
		if err := fetchApiRetry(3, logUrl, values, &response); err != nil {
			fmt.Printf("getPlayerBetRecordsByPage game log request failed for loginId %s, pageIndex %d: %v\n", loginId, pageIndex, err)
		}
		result = response.Results
	}
	return
}

func fetchApiRetry(n int, logUrl string, values url.Values, response interface{}) (ret error) {
	bodyStr := values.Encode()
	for i := 0; i < n; i++ {
		req, _ := http.NewRequest("POST", logUrl, strings.NewReader(bodyStr))
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		// 設定 GetBody 讓重試時能重新讀取 body，避免 "ContentLength=278 with Body length 0"
		req.GetBody = func() (io.ReadCloser, error) {
			return ioutil.NopCloser(strings.NewReader(bodyStr)), nil
		}
		body, err := fetchApi(req)
		if err != nil {
			ret = err
			fmt.Printf("API request failed (attempt %d/%d): %v\n", i+1, n, err)
			if i < n-1 { // 如果不是最後一次嘗試，則等待
				fmt.Println("wait 60 seconds")
				time.Sleep(time.Duration(60) * time.Second)
			}
			continue
		}

		if err := json.Unmarshal(body, &response); err != nil {
			ret = fmt.Errorf("JSON unmarshal failed: %w, body: %s", err, string(body))
			fmt.Printf("JSON parsing failed (attempt %d/%d): %v\n", i+1, n, ret)
			if i < n-1 {
				fmt.Println("wait 60 seconds")
				time.Sleep(time.Duration(60) * time.Second)
			}
			continue
		}

		// 成功處理
		ret = nil
		break
	}
	return
}

/*
func reqToString(req *http.Request) string {
	body, _ := req.GetBody()
	var p []byte
	if body != nil {
		p, _ = ioutil.ReadAll(body)
	}
	s := fmt.Sprintf("%s %s header:\"%v\" body:\"%s\"\n", req.Method, req.URL, req.Header, string(p))
	return s
}

/*
func getDailyWin(date time.Time, t int, info Mega888Info) dailyWinResponse {
	api := "open.mega.player.total.report"
	url := info.APIURL + api
	uuid := uuid.NewString()
	requestBody := dailyWinRequest{
		uuid,
		api,
		dailyWinRequestParams{
			uuid,
			digest(uuid, info),
			info.Sn,
			info.Account,
			fmt.Sprintf("%d", t),
			date.Format("2006-01-02 15:04:05"),
			date.AddDate(0, 0, 1).Format("2006-01-02 15:04:05"),
		},
		"2.0",
	}
	b, _ := json.Marshal(requestBody)
	req, _ := http.NewRequest("POST", url, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")

	body, err := fetchApi(req)
	if err != nil {
		fmt.Printf("getDailyWin failed: %v\n", err)
		return dailyWinResponse{}
	}

	var response dailyWinResponse
	if err := json.Unmarshal(body, &response); err != nil {
		fmt.Printf("getDailyWin JSON unmarshal failed: %v, body: %s\n", err, string(body))
		return dailyWinResponse{}
	}
	return response
}
*/
/*
func getDailyWinFromDB(date time.Time, t int, vendor_info_id int32) []string {
	var ids []string
	database.DB.Table("mega888_daily_wins").Where("`date` = ? AND vendor_info_id = ? AND `type` = ?",
		date.Format("2006-01-02"),
		vendor_info_id,
		t,
	).Pluck("loginId", &ids)
	return ids
}
*/

func getNeedFetchLoginIds(date time.Time, t int, vendor_info_id int32) []string {
	var ids []string
	// date tz as utc+8 bet_time tz as utc+8
	startTime := date.Format("2006-01-02 15:04:05")
	endTime := date.AddDate(0, 0, 1).Format("2006-01-02 15:04:05")
	query := fmt.Sprintf(`
		select mdw.loginId
		from mega888_daily_wins as mdw
		join mega888_accounts as c on c.player_id = mdw.loginId
		left join (
			select player_id, SUM(bet) as bet, (SUM(win - bet) * -1) as winlose
			from mega888_bet_records 
			where bet_time >= '%s'
			and bet_time < '%s'
			group by player_id
		) as mbr
			on mbr.player_id = c.player_id
		where date = '%s' 
		and (NOT mdw.bet <=> mbr.bet or NOT mdw.win <=> mbr.winlose)
	`, startTime, endTime, date.Format("2006-01-02"))
	fmt.Println(query)
	database.DB.Raw(query).Scan(&ids)
	return ids
}

func fetchApi(req *http.Request) ([]byte, error) {
	// 強制使用 HTTP/1.1 避免 HTTP/2 協議錯誤
	req.Proto = "HTTP/1.1"
	req.ProtoMajor = 1
	req.ProtoMinor = 1

	// 設置 User-Agent 確保相容性
	if req.Header.Get("User-Agent") == "" {
		req.Header.Set("User-Agent", "Mozilla/5.0 (compatible; Mega888-Client/1.0)")
	}

	// 設置連接為 close 避免連接重用問題
	req.Header.Set("Connection", "close")

	// 明確禁用 HTTP/2
	req.Header.Set("Upgrade", "")
	req.Header.Set("HTTP2-Settings", "")

	// 調試信息
	if os.Getenv("DEBUG") == "true" {
		fmt.Printf("DEBUG: Making request to %s with protocol %s\n", req.URL.String(), req.Proto)
	}

	// 使用全局 HTTP 客戶端，最多嘗試 5 次，失敗後依序等待 1,2,3,5,8 秒再重試
	waits := []int{1, 2, 3, 5, 8}
	var resp *http.Response
	var err error
	for i := 0; i < 5; i++ {
		resp, err = httpClient.Do(req)
		if err == nil {
			break
		}
		if i < len(waits) {
			fmt.Printf("HTTP request failed (attempt %d/5): %v, waiting %d seconds before retry\n", i+1, err, waits[i])
			time.Sleep(time.Duration(waits[i]) * time.Second)
			// 重試前換成新的 body，否則 body 已被讀完會出現 ContentLength=278 with Body length 0
			if req.GetBody != nil {
				var newBody io.ReadCloser
				newBody, bodyErr := req.GetBody()
				if bodyErr != nil {
					return nil, fmt.Errorf("get body for retry: %w", bodyErr)
				}
				req.Body = newBody
			}
		}
	}
	if err != nil {
		return nil, fmt.Errorf("HTTP request failed: %w", err)
	}
	defer resp.Body.Close()

	// 調試信息
	if os.Getenv("DEBUG") == "true" {
		fmt.Printf("DEBUG: Response status: %s, protocol: %s\n", resp.Status, resp.Proto)
	}

	// 檢查 HTTP 狀態碼
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("HTTP error: %s", resp.Status)
	}

	body, err := ioutil.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response body: %w", err)
	}

	return body, nil
}

/*
	func digest(random string, info Mega888Info) string {
		text := random + info.Sn + info.Account + info.APIKey
		data := []byte(text)
		hash := md5.Sum(data)
		return strings.ToUpper(fmt.Sprintf("%x", hash)) //將[]byte轉成16進制
	}
*/

func digestAccount(random, account string, info Mega888Info) string {
	text := random + info.Sn + account + info.APIKey
	data := []byte(text)
	hash := md5.Sum(data)
	return strings.ToUpper(fmt.Sprintf("%x", hash)) //將[]byte轉成16進制
}

// func promptChoseVendorInfo(vendorInfoList []vendorinfo.VendorInfo) {
// 	vendorTips := ""
// 	defaultOptions := []string{}
// 	for idx, vendorInfo := range vendorInfoList {
// 		vendorTips += fmt.Sprintf(" [%d:%s]", idx, vendorInfo.Name)
// 		defaultOptions = append(defaultOptions, fmt.Sprintf("%d", idx))
// 	}

// 	fmt.Println("Enter the number of vendor info serial.")
// 	fmt.Println(vendorTips, fmt.Sprintf("default \"%s\"\n", strings.Join(defaultOptions, ",")))
// 	var options string
// 	fmt.Scanln(&options)
// 	if len(options) == 0 {
// 		options = strings.Join(defaultOptions, ",")
// 	}
// }
