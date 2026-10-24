package gateway

import (
	"encoding/json"
	"fmt"
	"time"
)

// tata

const (
	dlr_mstate_enroute       = 1 // Message was sent to SMSC and is waiting to be delivered
	dlr_mstate_delivered     = 2 // Message was delivered successfully to intended receiver
	dlr_mstate_expired       = 3 // Message was not delivered due to expiration of timer or validity period
	dlr_mstate_deleted       = 4 // Message is sent to SMSC but got no further report from SMSC. Message is subsequently removed from queue due to timer expired
	dlr_mstate_undeliverable = 5 // Message was not delivered due to one of the possible errors reported by SMSC:Undeliverable
	dlr_mstate_accepted      = 6 // Message is sent to SMSC.
	dlr_mstate_unknown       = 7 // Message was not delivered due to one of the possible errors reported by SMSC: Unknown
	dlr_mstate_rejected      = 8 // Message was not delivered due to one of the possible errors reported by SMSC: Rejected
)

const (
	CODING_7bit = 0
	CODING_8bit = 1
	CODING_UCS2 = 2
)

type GatewayTATA struct {
	Gateway
}

func (g *GatewayTATA) Setting() GatewaySetting {
	return GatewaySetting{
		Api:    "https://example.invalid:443/mmx/v1/messaging/sms",
		AppID:  "tcl-AwgJu5i3S7xLkIN2tBvWQAAF",
		Secret: [REDACTED]",

		ContentType: "application/json; charset=utf-8",
		// Method:      http.MethodPost,
		CallbackUrl: "http://0.0.0.0/api/v1/dlr/notice?token=[REDACTED]",
		UseAuth:     true,
	}
}

func (g *GatewayTATA) Payload(gate int, numpber string, message string) interface{} {

	// var query = url.Values{}
	// query.Add("token", "tata")

	// u, err := url.Parse(g.Setting().CallbackUrl)
	// if err != nil {
	// 	fmt.Println(err.Error())
	// }
	// u.RawQuery = query.Encode()

	// padding payload struct
	type DCSData struct {
		Coding int `json:"coding"`
	}

	const (
		MASK_NO_DLR               = 0
		MASK_SUCCESS_AND_FAILURES = 1
		MASK_FAILURES_ONLY        = 2
	)

	type DLRData struct {
		Mask int    `json:"mask"`
		Url  string `json:"url"`
	}

	type PayloadData struct {
		From string  `json:"from"`
		To   string  `json:"to"`
		Msg  string  `json:"msg"`
		Dcs  DCSData `json:"dcs"`
		Dlr  DLRData `json:"dlr"`
	}

	return PayloadData{
		From: "TCLSMS",
		To:   numpber,
		Msg:  message,
		Dcs: DCSData{
			Coding: CODING_UCS2,
		},
		Dlr: DLRData{
			Mask: MASK_SUCCESS_AND_FAILURES,
			Url:  g.Setting().CallbackUrl, //u.String(),
		},
	}
}

func (g *GatewayTATA) ParseUID(jsonBytes []byte) (uid string, err error) {
	type PayloadResponse struct {
		ID string `json:"id"`
	}

	// fmt.Printf(string(jsonBytes))
	var response = PayloadResponse{}
	err = json.Unmarshal(jsonBytes, &response)
	if err != nil {
		return "", fmt.Errorf("ParseUID error, %s", err.Error())
	}
	return response.ID, nil
}

func (g *GatewayTATA) Callback(jsonBytes []byte) {
	type DLRResponse struct {
		DoneDate   time.Time `json:"doneDate"`
		Err        int       `json:"err"`
		ID         string    `json:"id"`
		Status     string    `json:"status"`
		StatusCode int       `json:"statusCode"`
		SubmitDate time.Time `json:"submitDate"`
	}

	var response = DLRResponse{}

	err := json.Unmarshal(jsonBytes, &response)
	if err != nil {
		fmt.Println(err.Error())
	}

	fmt.Println("TATA Notice: ", string(jsonBytes))

	if response.StatusCode == dlr_mstate_delivered {
		result, err := UpdateTicketStatusSuccess(TATA, response.ID)
		if err != nil {
			fmt.Println(err.Error())
		}
		fmt.Println("success, ", result)
	} else {
		result, err := UpdateTicketStatusFailure(TATA, response.ID, fmt.Sprintf("err dlr: %s", string(jsonBytes)))
		if err != nil {
			fmt.Println(err.Error())
		}
		fmt.Println("fail, ", result)
	}
	// fmt.Println(response)
}
