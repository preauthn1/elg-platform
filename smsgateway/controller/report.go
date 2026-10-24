package controller

// import (
// 	"fmt"
// 	"net/http"

// 	"example.invalid/gin-gonic/gin"
// 	"example.invalid/target/SMSGateway/pkg/httputil"
// )

// func (c *Controller) Report(ctx *gin.Context) {

// 	jsonBytes, _, err := c.ProcessPost(ctx)
// 	if err != nil {
// 		fmt.Println(err.Error())
// 		ctx.JSON(http.StatusOK, Message{Message: "failed", Code: -1})
// 		return
// 	}
// 	fmt.Println("Data: ", string(jsonBytes))

// 	// var sendCode = model.SendVerificationCode{}

// 	// err = json.Unmarshal(jsonBytes, &sendCode)
// 	// if err != nil {
// 	// 	fmt.Println("Bind Model Error. ", err.Error())
// 	// 	// httputil.NewError(ctx, http.StatusBadRequest, err)
// 	// 	ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1001})
// 	// 	return
// 	// }

// 	// if err := sendCode.Validation(); err != nil {
// 	// 	fmt.Println("sendCode.Validation() Error. ", err.Error())
// 	// 	// httputil.NewError(ctx, http.StatusBadRequest, err)
// 	// 	ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1002})
// 	// 	return
// 	// }

// 	// // fmt.Println(sendCode)
// 	// t, err := template.ParseFiles("templates/verification_code.template")
// 	// if err != nil {
// 	// 	fmt.Println("template.ParseFiles Error. ", err.Error())
// 	// 	// httputil.NewError(ctx, http.StatusBadRequest, err)
// 	// 	ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1003})
// 	// 	return
// 	// }

// 	// var tmpl bytes.Buffer
// 	// if err := t.Execute(&tmpl, sendCode); err != nil {
// 	// 	fmt.Println("t.Execute() Error. ", err.Error())
// 	// 	// httputil.NewError(ctx, http.StatusBadRequest, err)
// 	// 	ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1004})
// 	// 	return
// 	// }

// 	payload, body, err := httputil.Report(2, "886911845361")
// 	if err != nil {
// 		fmt.Println("Report Error. ", err.Error())
// 		// httputil.NewError(ctx, http.StatusBadRequest, err)
// 		ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -2001})
// 		return
// 	}
// 	fmt.Println("report payload: ", string(payload))
// 	fmt.Println("report response: ", string(body))
// 	// fmt.Println("SendSMSMessage Success. ", string(body))
// 	// var jsonData interface{}
// 	// err = json.Unmarshal(body, &jsonData)
// 	// if err != nil {
// 	// 	// httputil.NewError(ctx, http.StatusBadRequest, err)
// 	// 	ctx.JSON(http.StatusOK, Message{Message: err.Error(), Code: -1006})
// 	// 	return
// 	// }

// 	// dbObj, err := db.CreateTicket(sendCode.Gateway, jsonBytes, payload, body)
// 	// if err != nil {
// 	// 	fmt.Println("CreateTicket Error, ", err.Error())
// 	// }
// 	// d, _ := db.GetTicket()
// 	// fmt.Println("CreateTicket Success dbObj: ", d[0].Payload)

// 	ctx.JSON(http.StatusOK, Message{Message: "success", Code: 0})
// 	return
// }
