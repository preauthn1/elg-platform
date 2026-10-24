package db

import (
	"fmt"
	"reflect"
	"strings"
	"time"

	// "example.invalid/target/SMSGateway/pkg/uid"

	"example.invalid/jmoiron/sqlx"
	uuid "example.invalid/satori/go.uuid"

	"example.invalid/target/SMSGateway/db/model"
	// smsgateway "example.invalid/target/SMSGateway/gateway"
)

func getMysqlInsertStringAndArgs(tableName string, o interface{}) (string, []interface{}) {
	var colNames = []string{}
	var colValues = []string{}
	var rtnArgs = []interface{}{}
	var rtnString = "insert into " + tableName + "("
	var t = reflect.TypeOf(o)
	var v = reflect.ValueOf(o)
	var colName string
	for i := 0; i < t.NumField(); i++ {
		if colName = t.Field(i).Tag.Get("db"); colName != "" {
			if t.Field(i).Tag.Get("dbinsert") != "false" {
				colNames = append(colNames, fmt.Sprintf("`%s`", colName))
				colValues = append(colValues, "?")
				rtnArgs = append(rtnArgs, v.Field(i).Interface())
			}
		}
	}
	rtnString = rtnString + strings.Join(colNames, ",") + ") values(" + strings.Join(colValues, ",") + ")"

	return rtnString, rtnArgs
}

func ResetTable() {
	_ = dropTable()
	_ = createTable()
}

func CreateTicket(gateway int, gatewayuid string, phone string, payload []byte, response []byte) (dbObj *model.Ticket, err error) {

	err = ensureConnection()
	if err != nil {
		fmt.Println(err.Error())
		return nil, err
	}

	dbObj = new(model.Ticket)
	// dbObj.UID, err = uid.UidWorker.NextId()
	dbObj.UID = uuid.NewV4().String()
	dbObj.MSISDN = phone
	dbObj.Gateway = gateway
	dbObj.GatewayUID = gatewayuid
	// dbObj.Request = string(request)
	dbObj.PayloadReq = string(payload)
	dbObj.PayloadRes = string(response)
	dbObj.DLR = ""
	dbObj.Status = 0
	dbObj.ErrMessage = ""
	dbObj.CreateTimestamp = time.Now().UTC().Unix()
	dbObj.UpdateTimestamp = -1

	var insertString string
	var insertArgs []interface{}

	insertString, insertArgs = getMysqlInsertStringAndArgs("tickets", *dbObj)
	// fmt.Println("insertString: ", insertString)
	// fmt.Println("insertArgs: ", insertArgs)
	if _, err = connector.Exec(insertString, insertArgs...); err != nil {
		fmt.Println(insertString, err.Error())
		return nil, err
	}
	fmt.Println("Ticket Success. ", dbObj.UID)

	return dbObj, nil
}

func UpdateTicketStatus(gateway int, gatewayuid string, status int, errmsg string) (tickets []*model.Ticket, err error) {

	fmt.Println(gatewayuid, status)
	// transaction begin
	tx, err := connector.Beginx()
	if err != nil {
		return nil, err
	}

	tickets = make([]*model.Ticket, 0)
	query, args, _ := sqlx.In("select * from tickets where gatewayuid=? and gateway=? for update;", gatewayuid, gateway)
	err = tx.Select(&tickets, query, args...)
	if err != nil {
		tx.Rollback()
		return nil, err
	}

	query, args, _ = sqlx.In("update tickets set status=?,errmsg=?,updatetimestamp=? where gatewayuid=? and gateway=?", status, errmsg, time.Now().UTC().Unix(), gatewayuid, gateway)
	fmt.Println("query: ", query)
	fmt.Println("args: ", args)
	_, err = tx.Exec(query, args...)
	if err != nil {
		tx.Rollback()
		return nil, err
	}

	err = tx.Commit()
	if err != nil {
		tx.Rollback()
		return nil, err
	}

	// var sqlString = "update tickets set status=?, errmsg=?, updatetimestamp=? where gatewayuid=?"
	// if _, err = connector.Exec(sqlString, status, errmsg, time.Now().UTC().Unix(), uid); err != nil {
	// 	fmt.Println(sqlString, err.Error())
	// 	return nil, err
	// }

	// var r sql.Result
	// r, err = connector.NamedExec(`UPDATE tickets SET status=:status, errmsg=:errmsg, updatetimestamp=:updatetimestamp WHERE uid = :uid`,
	// 	map[string]interface{}{
	// 		"status":          status,
	// 		"errmsg":          errmsg,
	// 		"updatetimestamp": time.Now().UTC().Unix(),
	// 		"uid":             uid,
	// 	})

	// if err != nil {
	// 	fmt.Println("UpdateTicketStatus err, ", err.Error())
	// 	return nil, err
	// }
	// c, _ := r.RowsAffected()
	fmt.Println("UpdateTicketStatus: ", tickets)
	return tickets, nil
}

func QueryTicketByUID(uid string) ([]*model.QueryTicket, error) {
	var err error
	err = ensureConnection()
	if err != nil {
		fmt.Println(err.Error())
		return nil, err
	}
	var tickets []*model.QueryTicket

	var sqlcommand = `select `

	sqlcommand += `uid,msisdn,gateway,gatewayuid,status,errmsg,createtimestamp,updatetimestamp `

	sqlcommand += `from smsdata.tickets `

	sqlcommand += `where uid=? `

	sqlcommand += `order by createtimestamp asc;`

	err = connector.Select(&tickets, sqlcommand, uid)
	if err != nil {
		fmt.Println(err.Error())
		return nil, err
	}

	return tickets, nil
}

func QueryTicketByPhone(msisdn string) ([]*model.QueryTicket, error) {
	var err error
	err = ensureConnection()
	if err != nil {
		fmt.Println(err.Error())
		return nil, err
	}
	var tickets []*model.QueryTicket

	var sqlcommand = `select `

	sqlcommand += `uid,msisdn,gateway,gatewayuid,status,errmsg,createtimestamp,updatetimestamp `

	sqlcommand += `from smsdata.tickets `

	sqlcommand += `where uid=? `

	sqlcommand += `order by createtimestamp asc;`

	err = connector.Select(&tickets, sqlcommand, msisdn)
	if err != nil {
		fmt.Println(err.Error())
		return nil, err
	}
	return tickets, nil
}

// for test
func GetTicket() ([]*model.Ticket, error) {
	var err error
	err = ensureConnection()
	if err != nil {
		fmt.Println(err.Error())
		return nil, err
	}
	var ticket []*model.Ticket

	var sqlcommand = `select * from smsdata.tickets `

	sqlcommand += ` order by createtimestamp asc;`

	err = connector.Select(&ticket, sqlcommand)
	if err != nil {
		fmt.Println(err.Error())
		return nil, err
	}
	return ticket, nil
}
