package controller

import (
	"fmt"
	"net/http"

	"example.invalid/gin-gonic/gin"

	db "example.invalid/target/SMSGateway/db/mysql"
)

func (c *Controller) TicketGET(ctx *gin.Context) {

	uid := ctx.Param("uid")

	dbObj, err := db.QueryTicketByUID(uid)
	if err != nil {
		fmt.Println("QueryTicketByUID Error, ", err.Error())
	}

	var datas = toSliceMap([]byte(toJSON(dbObj)))
	for _, v := range datas {

		// 	v["payload_req"] = toInterface([]byte(fmt.Sprintf("%v", v["payload_req"])))
		// 	v["payload_res"] = toInterface([]byte(fmt.Sprintf("%v", v["payload_res"])))

		// var dlr = fmt.Sprintf("%v", v["dlr"])
		// if dlr == "" {
		// 	v["dlr"] = ""
		// } else {
		// 	v["dlr"] = toInterface([]byte(dlr))
		// }

		v["errmsg"] = toInterface([]byte(fmt.Sprintf("%v", v["errmsg"])))
	}

	fmt.Println("ticket: ", toJSON(datas))

	ctx.JSON(http.StatusOK, datas)
	return
}

// func (c *Controller) AllTicketGET(ctx *gin.Context) {

// 	dbObj, err := db.GetTicket()
// 	if err != nil {
// 		fmt.Println("GetTicket Error, ", err.Error())
// 	}

// 	var datas = toSliceMap([]byte(toJSON(dbObj)))
// 	for _, v := range datas {

// 		v["payload_req"] = toInterface([]byte(fmt.Sprintf("%v", v["payload_req"])))
// 		v["payload_res"] = toInterface([]byte(fmt.Sprintf("%v", v["payload_res"])))

// 		var dlr = fmt.Sprintf("%v", v["dlr"])
// 		if dlr == "" {
// 			v["dlr"] = ""
// 		} else {
// 			v["dlr"] = toInterface([]byte(dlr))
// 		}

// 		v["errmsg"] = toInterface([]byte(fmt.Sprintf("%v", v["errmsg"])))
// 	}

// 	fmt.Println("ticket: ", toJSON(datas))

// 	ctx.JSON(http.StatusOK, datas)
// 	return
// }
