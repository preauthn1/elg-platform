package db

import (
	_ "example.invalid/go-sql-driver/mysql"
	"example.invalid/jmoiron/sqlx"
	"example.invalid/spf13/viper"
)

import (
	"fmt"
)

var dsn string
var connector *sqlx.DB

func init() {
	var err error
	var connectFlag = -1

	// dsn = fmt.Sprintf("%s:%s@tcp(%s)/%s", "sa", "terpro123!@#", "mysql:3306", "smsdata")
	// connector, err = sqlx.Open("mysql", dsn)
	// if err == nil {
	// 	connectFlag = 1 // connect docker container
	// } else {
	// 	fmt.Println("[DB]1 mysql connect error:", err.Error())
	// }

	if connectFlag != 1 {
		dsn = fmt.Sprintf("%s:%s@tcp(%s)/%s", "sa", "terpro123!@#", "0.0.0.0:3306", "smsdata")
		connector, err = sqlx.Open("mysql", dsn)
		if err == nil {
			connectFlag = 2 // connect 0.0.0.0
		} else {
			fmt.Println("[DB]2 mysql connect error:", err.Error())
		}
	}

	if connectFlag != 1 && connectFlag != 2 {
		dsn = fmt.Sprintf("%s:%s@tcp(%s)/%s", "sa", "terpro123!@#", viper.GetString("db"), "smsdata")
		connector, err = sqlx.Open("mysql", dsn)
		if err != nil {
			fmt.Println("[DB]3 mysql connect error:", err.Error())
			return
		}
	}

	// err = connector.Ping()
	// //client.Set()
	// if err != nil {
	// 	fmt.Println("[DB] mysql connect error:", err.Error())
	// 	panic(err.Error())
	// }
	fmt.Println("[DB] mysql init success... ")

	// _ = dropTable()
	// _ = createTable()
}

func createTable() (err error) {
	var schema = `CREATE TABLE tickets (
		uid text,		
		msisdn text,
		gateway int,
		gatewayuid text,		
		payload_req_json text,
		payload_res_json text,
		dlr_json text,
		status int,
		errmsg text,
		createtimestamp bigint,
		updatetimestamp bigint)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;`

	// execute a query on the server
	result, err := connector.Exec(schema)
	if err != nil {
		fmt.Println("[DB] mysql create table[tickets] error:", err.Error())
		return err
	}
	fmt.Println(result)
	return nil
}

func dropTable() (err error) {
	var schema = `DROP TABLE tickets;`

	// execute a query on the server
	_, err = connector.Exec(schema)
	if err != nil {
		fmt.Println("[DB] mysql drop table[tickets] error:", err.Error())
		return err
	}
	// fmt.Println(result)
	return nil
}

func ensureConnection() (err error) {
	err = connector.Ping()
	if err != nil {
		fmt.Println("[DB] mysql ping failed, try reconnect. ", err.Error())
		connector, err = sqlx.Open("mysql", dsn)
		if err != nil {
			fmt.Println("[DB] mysql reconnect failed. ", err.Error())
			return err
		}
	}
	return nil
}
