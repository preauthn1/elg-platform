package vpower

import (
	"encoding/json"
	"fmt"
	"time"

	"example.invalid/shopspring/decimal"
)

type VPowerInfo struct {
	APIURL          string `json:"API_URL"`
	APIGameurl      string `json:"API_GAMEURL"`
	APIAppid        string `json:"API_APPID"`
	APIKey          string `json:"API_KEY"`
	IosDownload     string `json:"IOS_DOWNLOAD"`
	AndroidDownload string `json:"ANDROID_DOWNLOAD"`
}

type VpowerBetRecord struct {
	ID            int32
	Uniqleid      string
	Uid           string
	Gameid        string
	Tableid       string
	Username      string
	Pay           decimal.Decimal
	Win           decimal.Decimal
	Startscore    decimal.Decimal
	Finalscore    decimal.Decimal
	Time          string
	UtcTime       string
	OriginUtcTime string
	CreatedAt     time.Time
	UpdatedAt     time.Time
}

type MinuteAttr struct {
	StartTime time.Time
	EndTime   time.Time
}

func (a MinuteAttr) String() string {
	diff := a.EndTime.Sub(a.StartTime)
	return fmt.Sprintf("%s %v", a.StartTime.Format("2006-01-02 15:04:05"), diff)
}

// type GameLogRequestParams struct {
// 	Act        string `json:"Act"`
// 	GameId     string `json:"GameId"`
// 	Username   string `json:"Username"`
// 	BenginTime string `json:"BeginTime"`
// 	EndTime    string `json:"EndTime"`
// 	PageIndex  int    `json:"PageIndex"`
// 	Timestamp  string `json:"Timestamp"`
// 	AppId      string `json:"AppId"`
// 	Signature  string `json:"Signature"`
// }

type GameLogResponseData struct {
	UID        json.Number     `json:"uid"` // API 可能返回数字或字符串
	Username   string          `json:"username"`
	Gameid     json.Number     `json:"gameid"`
	Gameocode  string          `json:"gameocode"`
	Tableid    string          `json:"tableid"`
	Pay        decimal.Decimal `json:"pay"`
	Win        decimal.Decimal `json:"win"`
	Uniqleid   string          `json:"uniqleid"`
	Startscore decimal.Decimal `json:"startscore"`
	Finalscore decimal.Decimal `json:"finalscore"`
	Time       string          `json:"time"`
}

type GameLogResponse struct {
	Data      []GameLogResponseData `json:"Data"`
	Total     int                   `json:"Total"`
	ErrorCode int                   `json:"ErrorCode"`
}
