package gateway

// https://example.invalid/api/sms

import (
	"encoding/json"
	"fmt"
	"time"
)

// status
const (
	accepted  = "accepted"  // Message has been accepted for delivery, but has not yet been delivered
	delivered = "delivered" // Message has been delivered
	buffered  = "buffered"  // Message has been buffered for later delivery
	expired   = "expired"   // Message was held at downstream carrier's retry scheme and could not be delivered within the expiry time
	failed    = "failed"    // Message not delivered
	rejected  = "rejected"  // Downstream carrier refuses to deliver message
	unknown   = "unknown"   // No useful information available
)

type GatewayNexmo struct {
	Gateway
}

func (g *GatewayNexmo) Setting() GatewaySetting {
	return GatewaySetting{
		Api:         "https://example.invalid/sms/json",
		AppID:       "c2954279",
		Secret:      [REDACTED]",
		ContentType: "application/x-www-form-urlencoded; charset=utf-8",
		// Method:      http.MethodPost,
		CallbackUrl: "http://0.0.0.0/api/v1/dlr/notice?token=[REDACTED]",
		UseAuth:     false,
	}
}

func (g *GatewayNexmo) Payload(gate int, numpber string, message string) interface{} {
	// var noticeURI = fmt.Sprintf("%s:%s/api/v1/dlr/notice", viper.GetString("host"), viper.GetString("port"))

	type PayloadData struct {
		From      string `json:"from"`
		Text      string `json:"text"`
		To        string `json:"to"`
		ApiKey    string `json:"api_key"`
		ApiSecret string `json:"api_secret"`
		Callback  string `json:"callback"`
		Type      string `json:"type"`
	}

	return PayloadData{
		From:      "TCLSMS",
		Text:      message,
		To:        numpber,
		ApiKey:    [REDACTED],
		ApiSecret: [REDACTED],
		Callback:  g.Setting().CallbackUrl,
		Type:      "unicode",
	}
}

func (g *GatewayNexmo) ParseUID(jsonBytes []byte) (uid string, err error) {
	type Message struct {
		To               string `json:"to"`
		MessageID        string `json:"message-id"`
		Status           string `json:"status"`
		RemainingBalance string `json:"remaining-balance"`
		MessagePrice     string `json:"message-price"`
		Network          string `json:"network"`
	}
	type PayloadResponse struct {
		MessageCount string    `json:"message-count"`
		Messages     []Message `json:"messages"`
	}

	// fmt.Printf(string(jsonBytes))
	var response = PayloadResponse{}
	err = json.Unmarshal(jsonBytes, &response)
	if err != nil {
		return "", fmt.Errorf("ParseUID error, %s", err.Error())
	}

	// fmt.Println("response: ", response)
	return response.Messages[0].MessageID, nil
}

func (g *GatewayNexmo) Callback(jsonBytes []byte) {
	type DLRResponse struct {
		ApiKey           string    `json:"api-key"`
		ErrCode          string    `json:"err-code"`
		MessageTimestamp time.Time `json:"message-timestamp"`
		MessageId        string    `json:"messageId"`
		Msisdn           string    `json:"msisdn"`
		NetworkCode      string    `json:"network-code"`
		Price            string    `json:"price"`
		Scts             string    `json:"scts"`
		Status           string    `json:"status"`
		To               string    `json:"to"`
		Token            string    `json:"token"`
	}

	var response = DLRResponse{}

	err := json.Unmarshal(jsonBytes, &response)
	if err != nil {
		fmt.Println(err.Error())
	}

	fmt.Println("Nexmo Notice: ", string(jsonBytes))

	if response.Status == delivered {
		result, err := UpdateTicketStatusSuccess(NEXMO, response.MessageId)
		if err != nil {
			fmt.Println(err.Error())
		}
		fmt.Println("success, ", result)
	} else {
		result, err := UpdateTicketStatusFailure(NEXMO, response.MessageId, fmt.Sprintf("err dlr: %s", string(jsonBytes)))
		if err != nil {
			fmt.Println(err.Error())
		}
		fmt.Println("fail, ", result)
	}
	// fmt.Println(response)
}
