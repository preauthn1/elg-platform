package controller

import (
	"fmt"
	"net/http"

	"example.invalid/gin-gonic/gin"

	smsgateway "example.invalid/target/SMSGateway/gateway"
)

func (c *Controller) NoticeGET(ctx *gin.Context) {
	// var jsonData interface{}
	// var err error
	// err = json.Unmarshal(ctx.Request, &jsonData)
	// if err != nil {
	// 	fmt.Println(err.Error())
	// }
	// ctx.Params
	// u, err := url.ParseQuery(ctx.Request.URL.Query())
	// if err != nil {
	// 	fmt.Println(err.Error())
	// }
	var data = map[string]interface{}{}
	for k, v := range ctx.Request.URL.Query() {
		// fmt.Println(v)
		if len(v) == 1 {
			data[k] = v[0]
		}
	}

	fmt.Println("GET Query Data: ", toJSON(data))

	ctx.JSON(http.StatusOK, Message{Message: "success", Code: 0})
	return
}

func (c *Controller) NoticePOST(ctx *gin.Context) {

	token := ctx.Query("token")

	jsonBytes, _, err := c.ProcessPost(ctx)
	if err != nil {
		fmt.Println(err.Error())
		ctx.JSON(http.StatusOK, Message{Message: "failed", Code: -1})
		return
	}

	fmt.Println("Data: ", string(jsonBytes))

	if token == "tata" {
		smsgateway.Callback(smsgateway.TATA, jsonBytes)
	} else if token == "nexmo" {
		smsgateway.Callback(smsgateway.NEXMO, jsonBytes)
	}

	ctx.JSON(http.StatusOK, Message{Message: "success", Code: 0})
	return
}
