package controller

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"text/template"

	"example.invalid/gin-gonic/gin"

	db "example.invalid/target/SMSGateway/db/mysql"
	"example.invalid/target/SMSGateway/model"
	"example.invalid/target/SMSGateway/pkg/httputil"
)

// verification code godoc
// @Summary send verification code
// @Description SMS 發送驗證碼
// @Tags SMSGateway
// @Accept json
// @Produce json
// @Param sendCode body model.SendVerificationCode true "發送驗證碼"
// @Success 200 {object} controller.Message
// @Failure 400 {object} httputil.HTTPError
// @Router /verification_code/send [post]
func (c *Controller) SendVerificationCode(ctx *gin.Context) {

	_, jsonMap, err := c.ProcessPost(ctx)
	if err != nil {
		fmt.Println(err.Error())
		ctx.JSON(http.StatusOK, Message{Message: "failed", Code: -1})
		return
	}

	var sendCode = model.SendVerificationCode{}
	jsonMap["gateway"] = fmt.Sprintf("%v", jsonMap["gateway"])

	var request_json = toJSON(jsonMap)
	fmt.Println("Data: ", request_json)

	err = json.Unmarshal([]byte(request_json), &sendCode)
	if err != nil {
		fmt.Println("Bind Model Error. ", err.Error())
		// httputil.NewError(ctx, http.StatusBadRequest, err)
		ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1001})
		return
	}

	if err := sendCode.Validation(); err != nil {
		fmt.Println("sendCode.Validation() Error. ", err.Error())
		// httputil.NewError(ctx, http.StatusBadRequest, err)
		ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1002})
		return
	}

	// fmt.Println(sendCode)
	t, err := template.ParseFiles("templates/verification_code.template")
	if err != nil {
		fmt.Println("template.ParseFiles Error. ", err.Error())
		// httputil.NewError(ctx, http.StatusBadRequest, err)
		ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1003})
		return
	}

	var tmpl bytes.Buffer
	if err := t.Execute(&tmpl, sendCode); err != nil {
		fmt.Println("t.Execute() Error. ", err.Error())
		// httputil.NewError(ctx, http.StatusBadRequest, err)
		ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1004})
		return
	}

	payload, body, uid, err := httputil.SendSMSMessage(sendCode.Gateway, sendCode.Phone, tmpl.String())
	if err != nil {
		fmt.Println("SendSMSMessage Error. ", err.Error())
		// httputil.NewError(ctx, http.StatusBadRequest, err)
		ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1005})
		return
	}

	// reportPayload, reportBody, err := httputil.Report(2, "886911845361")
	// if err != nil {
	// 	fmt.Println("Report Error. ", err.Error())
	// 	// httputil.NewError(ctx, http.StatusBadRequest, err)
	// 	ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -2001})
	// 	return
	// }

	fmt.Println("SendSMSMessage Success. ", string(body))
	var jsonData interface{}
	err = json.Unmarshal(body, &jsonData)
	if err != nil {
		// httputil.NewError(ctx, http.StatusBadRequest, err)
		ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1006})
		return
	}

	dbObj, err := db.CreateTicket(sendCode.Gateway, uid, sendCode.Phone, payload, body)
	if err != nil {
		fmt.Println("CreateTicket Error, ", err.Error())
	}
	// d, _ := db.GetTicket()
	// fmt.Println("CreateTicket Success dbObj: ", d[0].Payload)
	result, err := db.QueryTicketByUID(dbObj.UID)
	if err != nil {
		fmt.Println("QueryTicketByUID Error, ", err.Error())
	}
	fmt.Println(dbObj.UID, "QueryTicketByUID: ", toJSON(result))

	ctx.JSON(http.StatusOK, Message{Message: "success", Code: 0, Id: dbObj.UID})
	return
}
