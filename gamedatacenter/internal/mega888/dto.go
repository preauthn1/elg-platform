package mega888

import (
	"time"

	"example.invalid/shopspring/decimal"
)

type Mega888Info struct {
	APIURL       string `json:"API_URL"`
	APIKey       string `json:"API_KEY"`
	Sn           string `json:"SN"`
	Account      string `json:"ACCOUNT"`
	DownloadURL  string `json:"DOWNLOAD_URL"`
	DownloadURL2 string `json:"DOWNLOAD_URL2"`
}

type Mega888BetRecord struct {
	ID           int32
	RefId        string
	Btype        string
	ClassId      int32
	PlayerId     string
	BetTime      time.Time
	BetTimeUtc   time.Time
	BeginBalance decimal.Decimal
	EndBalance   decimal.Decimal
	GameId       int32
	GameName     string
	LineNum      int32
	LogDataStr   string
	LogDataType  int32
	RoundNo      int32
	RowNum       int32
	TableId      int32
	Win          decimal.Decimal
	Bet          decimal.Decimal
	BetId        int32
	CreatedAt    time.Time
	UpdatedAt    time.Time
	CreatedBy    int32
	UpdatedBy    int32
}

type dailyWinRequestParams struct {
	Random       string `json:"random"`
	Digest       string `json:"digest"`
	Sn           string `json:"sn"`
	AgentLoginID string `json:"agentLoginId"`
	Type         string `json:"type"`
	StartTime    string `json:"startTime"`
	EndTime      string `json:"endTime"`
}

type dailyWinRequest struct {
	Id      string                `json:"id"`
	Method  string                `json:"method"`
	Params  dailyWinRequestParams `json:"params"`
	Jsonrpc string                `json:"jsonrpc"`
}

type dailyWinResponse struct {
	ID     string `json:"id"`
	Result []struct {
		LoginID       string          `json:"loginId"`
		Memo          string          `json:"memo"`
		StatisticDate interface{}     `json:"statisticDate"`
		UserID        int             `json:"userId"`
		Bet           decimal.Decimal `json:"bet"`
		Yield         decimal.Decimal `json:"yield"`
		Name          string          `json:"name"`
		Tel           string          `json:"tel"`
		Sn            string          `json:"sn"`
		Idx           int             `json:"idx"`
		Win           decimal.Decimal `json:"win"`
	} `json:"result"`
	Error   interface{} `json:"error"`
	Jsonrpc string      `json:"jsonrpc"`
}

type playerGameLogUrlRequestParams struct {
	Random       string `json:"random"`
	Digest       string `json:"digest"`
	Sn           string `json:"sn"`
	AgentLoginID string `json:"loginId"`
	StartTime    string `json:"startTime"`
	EndTime      string `json:"endTime"`
}

type playerGameLogUrlRequest struct {
	Id      string                        `json:"id"`
	Method  string                        `json:"method"`
	Params  playerGameLogUrlRequestParams `json:"params"`
	Jsonrpc string                        `json:"jsonrpc"`
}

type playerGameLogUrlResponse struct {
	ID      string      `json:"id"`
	Result  string      `json:"result"`
	Error   interface{} `json:"error"`
	Jsonrpc string      `json:"jsonrpc"`
}

type playerGameLogResponseResult struct {
	BeginBlance decimal.Decimal `json:"BeginBlance"`
	ClassID     int             `json:"ClassID"`
	CreateTime  string          `json:"CreateTime"`
	EndBlance   decimal.Decimal `json:"EndBlance"`
	GameID      int             `json:"GameID"`
	GameName    string          `json:"GameName"`
	LineNum     int             `json:"LineNum"`
	LogDataStr  string          `json:"LogDataStr"`
	LogDataType int             `json:"LogDataType"`
	RoundNO     int             `json:"RoundNO"`
	Rownum      int             `json:"Rownum"`
	TableID     int             `json:"TableID"`
	Win         decimal.Decimal `json:"Win"`
	Bet         decimal.Decimal `json:"bet"`
	ID          int             `json:"id"`
}

type playerGameLogResponse struct {
	Msg      string                        `json:"msg"`
	Success  bool                          `json:"success"`
	ViewTYPE int                           `json:"ViewTYPE"`
	Results  []playerGameLogResponseResult `json:"results"`
	Total    int                           `json:"total"`
}

type videoOrderPageRequestParams struct {
	Random       string `json:"random"`
	Digest       string `json:"digest"`
	Sn           string `json:"sn"`
	AgentLoginID string `json:"loginId"`
	StartTime    string `json:"startTime"`
	EndTime      string `json:"endTime"`
	PageIndex    int    `json:"pageIndex"`
	PageSize     int    `json:"pageSize"`
}

type videoOrderPageRequest struct {
	Id      string                      `json:"id"`
	Method  string                      `json:"method"`
	Params  videoOrderPageRequestParams `json:"params"`
	Jsonrpc string                      `json:"jsonrpc"`
}
type videoOrderPageResponseResultItem struct {
	TranID           interface{}     `json:"tranId"`
	AAmount          decimal.Decimal `json:"aAmount"`
	LoginID          string          `json:"loginId"`
	OrderID          int64           `json:"orderId"`
	ModuleName       string          `json:"moduleName"`
	OrderStatus      int             `json:"orderStatus"`
	PlayID           string          `json:"playId"`
	UID              int             `json:"uid"`
	OrderTime        string          `json:"orderTime"`
	GameName         string          `json:"gameName"`
	Payment          decimal.Decimal `json:"payment"`
	Sn               string          `json:"sn"`
	BAmount          decimal.Decimal `json:"bAmount"`
	ModuleID         int             `json:"moduleId"`
	NoComm           string          `json:"noComm"`
	GameID           int             `json:"gameId"`
	PlayNameEn       string          `json:"playNameEn"`
	IssueID          string          `json:"issueId"`
	PlayName         string          `json:"playName"`
	UserID           int             `json:"userId"`
	ValidAmount      decimal.Decimal `json:"validAmount"`
	GameNameEn       string          `json:"gameNameEn"`
	FromIP           string          `json:"fromIp"`
	TableID          string          `json:"tableId"`
	OrderFrom        int             `json:"orderFrom"`
	BetContent       interface{}     `json:"betContent"`
	ValidBet         decimal.Decimal `json:"validBet"`
	LastUpdateTime   string          `json:"lastUpdateTime"`
	OrderTimeBj      string          `json:"orderTimeBj"`
	LastUpdateTimeBj string          `json:"lastUpdateTimeBj"`
}
type videoOrderPageResponse struct {
	ID     string `json:"id"`
	Result struct {
		Total     int                                `json:"total"`
		Stats     interface{}                        `json:"stats"`
		PageIndex int                                `json:"pageIndex"`
		PageSize  int                                `json:"pageSize"`
		Etag      interface{}                        `json:"etag"`
		Page      int                                `json:"page"`
		Items     []videoOrderPageResponseResultItem `json:"items"`
	} `json:"result"`
	Error   interface{} `json:"error"`
	Jsonrpc string      `json:"jsonrpc"`
}
