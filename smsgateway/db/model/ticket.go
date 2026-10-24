package model

type Ticket struct {
	UID             string `json:"uid" db:"uid"`
	MSISDN          string `json:"msisdn" db:"msisdn"`
	Gateway         int    `json:"gateway,string" db:"gateway"`
	GatewayUID      string `json:"gatewayuid" db:"gatewayuid"`
	PayloadReq      string `json:"payload_req" db:"payload_req_json"`
	PayloadRes      string `json:"payload_res" db:"payload_res_json"`
	DLR             string `json:"dlr" db:"dlr_json"`
	Status          int    `json:"status" db:"status"`
	ErrMessage      string `json:"errmsg,string" db:"errmsg"`
	CreateTimestamp int64  `json:"createtimestamp" db:"createtimestamp"`
	UpdateTimestamp int64  `json:"updatetimestamp" db:"updatetimestamp"`
}

type QueryTicket struct {
	UID             string `json:"uid" db:"uid"`
	MSISDN          string `json:"msisdn" db:"msisdn"`
	Gateway         int    `json:"gateway,string" db:"gateway"`
	GatewayUID      string `json:"gatewayuid" db:"gatewayuid"`
	Status          int    `json:"status" db:"status"`
	ErrMessage      string `json:"errmsg,string" db:"errmsg"`
	CreateTimestamp int64  `json:"createtimestamp" db:"createtimestamp"`
	UpdateTimestamp int64  `json:"updatetimestamp" db:"updatetimestamp"`
}
