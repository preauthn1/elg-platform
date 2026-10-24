package vpower

import (
	"crypto/hmac"
	"crypto/sha1"
	b64 "encoding/base64"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"example.invalid/target/gamedatacenter/internal/functions/database"
	"example.invalid/target/gamedatacenter/internal/functions/tgmsg"
	"example.invalid/target/gamedatacenter/internal/functions/util"
	"example.invalid/target/gamedatacenter/internal/functions/vendorinfo"
)

var loc = time.FixedZone("UTC+8", 8*60*60)

//var locUTC = time.FixedZone("UTC+0", 0)

var vendorId = 22

type reqApiLog struct {
	reqStr         string
	responseString string
}

var existsDictUniqueIds = map[string]int{}

// Handler command
func Handler(startTime, endTime time.Time) {
	startTime = startTime.In(loc)
	endTime = endTime.In(loc)

	vendorInfoList := vendorinfo.GetVendorInfoList(vendorId)

	getExistsDictUniqueIds(startTime, endTime)
	//fmt.Println(vendorInfoList)
	fmt.Println(startTime.Format("2006-01-02 15:04:05"), endTime.Format("2006-01-02 15:04:05"))
	dictUsed := map[string]int{}

	for _, vendorInfo := range vendorInfoList {
		var info VPowerInfo
		json.Unmarshal([]byte(example.invalid), &info)
		fmt.Println(vendorInfo.Name + " ...")
		hash := info.APIAppid + "_" + info.APIKey
		// 判斷商戶資料是否跑過
		if _, exists := dictUsed[hash]; !exists {
			dictUsed[info.APIAppid+"_"+info.APIKey] = 1
			var Records []VpowerBetRecord
			var minuteAttrSlices []MinuteAttr
			runTime := startTime
			for {
				runLength := time.Duration(time.Second) * 30
				runEndTime := runTime.Add(runLength - time.Duration(time.Second)) // + 59 seconds
				minuteAttrSlices = append(minuteAttrSlices, MinuteAttr{
					runTime,
					runEndTime,
				})
				runTime = runEndTime.Add(time.Duration(time.Second)) // +1 second
				if runTime.Add(time.Duration(time.Second)).After(endTime) {
					break
				}
			}
			results := getBetRecordsWorker(10, minuteAttrSlices, info)
			fmt.Println("results count", len(results))
			uniqleIdMaps := map[string]int{}
			for key, row := range results {

				t, err := strconv.ParseInt(row.Time, 10, 64)
				if err != nil {
					panic(err)
				}
				// 這邊整理注單時間的時候要特別注意時區問題
				betTimeUTC := time.Unix(t, 0).UTC()
				//betTime := betTimeUTC.Add(time.Duration(8) * time.Hour) // 因為 time 結構與 mysql 我們是沒使用loc=Local 所以傳進去的時間會被轉換成utc 更新進去，所以betTime 要自行 +8 Hours

				// if row.Uniqleid exists in uniqleIdMaps then continue else add to uniqleIdMaps
				if _, ok := uniqleIdMaps[row.Uniqleid]; !ok {
					uniqleIdMaps[row.Uniqleid] = 1
				} else {
					continue
				}

				// if ok := uniqleIdMaps[row.Uniqleid] == 1; ok {
				// 	continue
				// }

				// uniqleIdExists := false

				// for _, r := range Records {
				// 	if r.Uniqleid == row.Uniqleid {
				// 		uniqleIdExists = true
				// 		break
				// 	}
				// }
				// if uniqleIdExists {
				// 	continue
				// }

				Records = append(Records, VpowerBetRecord{
					ID:         0,
					Uniqleid:   row.Uniqleid,
					Uid:        string(row.UID),
					Gameid:     string(row.Gameid),
					Tableid:    row.Tableid,
					Username:   strings.ReplaceAll(row.Username, "vworld", ""),
					Pay:        row.Pay.Div(vendorInfo.Rate),
					Win:        row.Win.Div(vendorInfo.Rate),
					Startscore: row.Startscore.Div(vendorInfo.Rate),
					Finalscore: row.Finalscore.Div(vendorInfo.Rate),
					Time:       row.Time,
					UtcTime:    betTimeUTC.Format("2006-01-02 15:04:05"),
				})
				if (key+1)%10000 == 0 {
					fmt.Println("row.Uniqleid key", key, row.Uniqleid)
				}
			}
			fmt.Println("total count", len(Records))
			createRecords := filterRecordsNotExists(Records, 1000)
			// for _, r := range Records {
			// 	if r.Uniqleid == "4011_353334558e0bf0c90cfd9f9c6c0b1d98" {
			// 		fmt.Println(r)
			// 	}
			// }
			fmt.Println("create count", len(createRecords))
			// 把 createRecords 切成 20000 筆記錄, 然後 20000 筆用 CreateInBatches 塞一次

			for i := 0; i < len(createRecords); i += 20000 {
				end := i + 20000
				if end > len(createRecords) {
					end = len(createRecords)
				}
				tx := database.DB.CreateInBatches(createRecords[i:end], 1000)
				fmt.Println("CreateInBatches", i, end, len(createRecords))
				if tx.Error != nil {
					fmt.Println("Create error", tx.Error)
				}
				time.Sleep(time.Duration(1) * time.Second)
			}

			// tx := database.DB.CreateInBatches(createRecords, 1000)
			// if tx.Error != nil {
			// 	fmt.Println("CreateInBatches error", tx.Error)
			// }

			fmt.Println("====== total bet record start ======")
			database.DB.Exec("CALL SP_transfer_bet_record_vpower(null, null, null)")
			fmt.Println("====== total bet record end ======")
		}
	}

}

func getBetRecordsWorker(workerCount int, minuteAttrSlices []MinuteAttr, info VPowerInfo) (results []GameLogResponseData) {
	var wg sync.WaitGroup
	var mu sync.Mutex
	var chans = make(chan MinuteAttr, len(minuteAttrSlices))
	var currentNum = 0
	for i := 0; i < workerCount; i++ {
		go func() {
			for attr := range chans {
				func() {
					defer wg.Done()
					temp, totalPage := getBetRecords(attr, info)
					mu.Lock()
					defer mu.Unlock()
					results = append(results, temp...)
					currentNum++
					fmt.Printf("[%d/%d %s (%+d|%d) %d],", currentNum, len(minuteAttrSlices), attr, len(temp), len(results), totalPage)
				}()
			}
		}()
	}
	for _, m := range minuteAttrSlices {
		wg.Add(1)
		chans <- m
	}
	wg.Wait()
	return
}
func getBetRecords(attr MinuteAttr, info VPowerInfo) (Records []GameLogResponseData, pageIndex int) {
	defer util.PanicErrStack()
	pageIndex = 1
	for {
		nextLoop := false
		pageSize := 100
		temp := getBetRecordsByPage(attr, pageIndex, info)

		if temp.ErrorCode == 0 && len(temp.Data) > 0 {
			Records = append(Records, temp.Data...)
			if temp.Total > (pageIndex * pageSize) {
				nextLoop = true
				pageIndex++
			}
		}

		if !nextLoop {
			break
		}
	}
	return
}

func getBetRecordsByPage(attr MinuteAttr, pageIndex int, info VPowerInfo) GameLogResponse {
	uri := info.APIURL
	app_id := info.APIAppid
	t := time.Now().Unix()
	u := ""
	params := fmt.Sprintf(
		"Act=glog&GameId=&Username=%s&BeginTime=%s&EndTime=%s&PageIndex=%d&Timestamp=%d&AppId=%s",
		u,
		attr.StartTime.Format("2006-01-02 15:04:05"),
		attr.EndTime.Format("2006-01-02 15:04:05"),
		pageIndex,
		t,
		app_id,
	)

	signature := digest(params, info)
	signature = strings.Replace(url.QueryEscape(signature), "+", "%20", -1)
	//fmt.Println(params, signature)

	uri = uri + "?" + strings.ReplaceAll(params, " ", "%20") + "&Signature=" + signature
	fmt.Println(uri)
	req, _ := http.NewRequest("GET", uri, nil)
	req.Header.Set("Content-Type", "application/json")
	var response GameLogResponse
	if err := fetchApiRetry(3, req, &response); err != nil {
		fmt.Println("error:", err, uri)
	}

	if response.ErrorCode == 8 {
		panic("Exceeded the Maximum Limit : " + uri)
	}

	for _, row := range response.Data {
		if row.Username == "" {
			msg := fmt.Sprintf("username of Uniqleid [%s] Uid [%s] is empty from uri %s", row.Uniqleid, string(row.UID), uri)
			tgmsg.SendMsg("error", "vpower_bet_record", msg, false)
			break
		}
	}

	return response
}

func fetchApiRetry(n int, req *http.Request, response interface{}) (ret error) {
	for i := 0; i < n; i++ {
		body, _ := fetchApi(req)
		foo := reqToString(req)
		// if strings.Contains(string(body), "4011_353334558e0bf0c90cfd9f9c6c0b1d98") {
		// 	fmt.Println(foo)
		// }
		if err := json.Unmarshal(body, &response); err != nil {
			ret = err
			fmt.Println(foo, ret)
			//fmt.Println(reqToString(req), "error: ", ret, " retry: ", i)
			time.Sleep(time.Duration(3) * time.Second)
		} else {
			ret = nil
			break
		}
	}
	return
}

func reqToString(req *http.Request) string {
	var p []byte
	if req.Body != nil {
		body, _ := req.GetBody()

		if body != nil {
			p, _ = ioutil.ReadAll(body)
		}
	}
	s := fmt.Sprintf("%s %s header:\"%v\" body:\"%s\"\n", req.Method, req.URL, req.Header, string(p))
	return s
}

func fetchApi(req *http.Request) ([]byte, error) {
	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	return ioutil.ReadAll(resp.Body)
}

func digest(req string, info VPowerInfo) string {
	key := []byte(info.APIKey)
	mac := hmac.New(sha1.New, key)
	mac.Write([]byte(req))
	return b64.StdEncoding.EncodeToString(mac.Sum(nil))
}

func filterRecordsNotExists(Records []VpowerBetRecord, size int) (output []VpowerBetRecord) {
	// 把不存在日間範圍內的注單篩選出來
	var notExistsRecords []VpowerBetRecord
	for _, record := range Records {
		if _, ok := existsDictUniqueIds[record.Uniqleid]; ok {
			continue
		}
		notExistsRecords = append(notExistsRecords, record)
	}
	// 取出注單的 uniqleid
	dict_exists_ref_ids := map[string]int{}
	var ref_ids []string
	for _, record := range notExistsRecords {
		ref_ids = append(ref_ids, record.Uniqleid)
	}
	// 批次去資料庫把存在的 uniqleid 篩選出來
	chunks := util.ChunkStringSlice(ref_ids, size)
	for _, chunk := range chunks {
		var ids []string
		database.DB.Table("vpower_bet_records").Where("uniqleid in ?", chunk).Pluck("uniqleid", &ids)
		for _, id := range ids {
			dict_exists_ref_ids[id] = 1
		}

		//exists_ref_ids = append(exists_ref_ids, ids...)
	}

	for _, record := range notExistsRecords {
		//fmt.Println("--------------", record.RefId)
		if _, ok := dict_exists_ref_ids[record.Uniqleid]; ok {
			//do something here
			continue
		}
		// if util.InArray(record.Uniqleid, exists_ref_ids) {
		// 	continue
		// }
		output = append(output, record)
	}
	return
}

/*
func filterRecordsNotExists(Records []VpowerBetRecord, size int) (output []VpowerBetRecord) {
	//var exists_ref_ids []string
	dict_exists_ref_ids := map[string]int{}
	var ref_ids []string
	for _, record := range Records {
		ref_ids = append(ref_ids, record.Uniqleid)
	}
	chunks := util.ChunkStringSlice(ref_ids, size)
	for _, chunk := range chunks {
		var ids []string
		database.DB.Table("vpower_bet_records").Where("uniqleid in ?", chunk).Pluck("uniqleid", &ids)
		for _, id := range ids {
			dict_exists_ref_ids[id] = 1
		}

		//exists_ref_ids = append(exists_ref_ids, ids...)
	}

	for _, record := range Records {
		//fmt.Println("--------------", record.RefId)
		if _, ok := dict_exists_ref_ids[record.Uniqleid]; ok {
			//do something here
			continue
		}
		// if util.InArray(record.Uniqleid, exists_ref_ids) {
		// 	continue
		// }
		output = append(output, record)
	}
	return
}
*/

func getExistsDictUniqueIds(startTime, endTime time.Time) {
	var ids []string
	fmt.Println(startTime.UTC().Format("2006-01-02 15:04:05"), endTime.UTC().Format("2006-01-02 15:04:05"))
	database.DB.Table("vpower_bet_records").Where("`utc_time` >= ?", startTime.UTC().Format("2006-01-02 15:04:05")).Where("`utc_time` <= ?", endTime.UTC().Format("2006-01-02 15:04:05")).Pluck("uniqleid", &ids)

	for _, id := range ids {
		existsDictUniqueIds[id] = 1
	}
}
