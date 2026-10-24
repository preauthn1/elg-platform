package httputil

import (
	"encoding/json"
	"fmt"
	"strings"

	smsgateway "example.invalid/target/SMSGateway/gateway"
)

func SendSMSMessage(gateway int, msisdn string, message string) (payloadReq []byte, payloadRes []byte, uid string, err error) {
	// var smsConfig = viper.GetStringMapString("sms")

	var apiUrl = smsgateway.Setting(gateway).Api
	var user = smsgateway.Setting(gateway).AppID
	var password = [REDACTED]
	var contentType = smsgateway.Setting(gateway).ContentType

	var payload = smsgateway.Payload(gateway, msisdn, message)
	jsonBytes, err := json.Marshal(payload)
	if err != nil {
		fmt.Println(err.Error())
	}

	fmt.Println("payload: ", string(jsonBytes))

	var body []byte
	if strings.Contains(contentType, "application/x-www-form-urlencoded") {
		body, err = PostUrlByForm(contentType, jsonBytes, apiUrl)
		if err != nil {
			fmt.Println(err.Error())
		}
	} else {
		if smsgateway.Setting(gateway).UseAuth {
			body, err = PostUrlByJsonSetAuth(contentType, jsonBytes, apiUrl, user, password)
			if err != nil {
				fmt.Println(err.Error())
			}
		} else {
			body, err = PostUrlByJson(contentType, jsonBytes, apiUrl)
			if err != nil {
				fmt.Println(err.Error())
			}
		}
	}

	uid, err = smsgateway.ParseUID(gateway, body)
	if err != nil {
		fmt.Println(err.Error())
	}
	fmt.Println("payload response: ", string(body))

	return jsonBytes, body, uid, err
}

// func Report(gateway int, msisdn string) (req []byte, rep []byte, err error) {
// 	// var smsConfig = viper.GetStringMapString("sms")

// 	var apiUrl = "https://example.invalid/v2/reports"
// 	var user = smsgateway.Setting(gateway).AppID
// 	var password = [REDACTED]
// 	// var contentType = smsgateway.Setting(gateway).ContentType

// 	// var payload = smsgateway.Payload(gateway, msisdn, message)
// 	var payload = map[string]interface{}{
// 		"account_id": user,
// 		"product":    "SMS",
// 		"direction":  "outbound",
// 		// "date_start": "2019-12-11T00:00:00+0000",
// 		// "date_end":     "2019-12-11T00:00:00+0000",
// 		"to": msisdn,
// 		// "uuid":         msisdn,
// 		"callback_url": "http://0.0.0.0/api/v1/dlr/notice?token=[REDACTED]",
// 	}
// 	jsonBytes, err := json.Marshal(payload)
// 	if err != nil {
// 		fmt.Println(err.Error())
// 	}

// 	// fmt.Println("payload: ", string(jsonBytes))
// 	body, err := PostUrlByJsonSetAuth("application/json; charset=utf-8", jsonBytes, apiUrl, user, password)
// 	return jsonBytes, body, err

// 	// if strings.Contains(contentType, "application/x-www-form-urlencoded") {
// 	// 	body, err := PostUrlByFormSetAuth(contentType, jsonBytes, apiUrl, user, password)
// 	// 	return jsonBytes, body, err
// 	// } else {
// 	// 	if smsgateway.Setting(gateway).UseAuth {
// 	// 		body, err := PostUrlByJsonSetAuth(contentType, jsonBytes, apiUrl, user, password)
// 	// 		return jsonBytes, body, err
// 	// 	} else {
// 	// 		body, err := PostUrlByJson(contentType, jsonBytes, apiUrl)
// 	// 		return jsonBytes, body, err
// 	// 	}
// 	// }
// }
