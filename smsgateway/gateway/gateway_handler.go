package gateway

import (
	"reflect"

	dbmodel "example.invalid/target/SMSGateway/db/model"
	db "example.invalid/target/SMSGateway/db/mysql"
)

// gateway
const (
	TATA  = 1
	NEXMO = 2
)

// status
const (
	PENDING   = 0
	DELIVERED = 1
	FAILURE   = -1000
)

type GatewaySetting struct {
	Api    string
	AppID  string
	Secret string

	ContentType string
	// Method      string
	CallbackUrl string

	UseAuth bool
}

type Gateway interface {
	Setting() GatewaySetting
	Payload(gate int, numpber string, message string) interface{}
	ParseUID(jsonBytes []byte) (uid string, err error)
	Callback(jsonBytes []byte)
}

type GatewayHandler struct {
	gatewayMap map[int]Gateway
}

var (
	gatewayHandler *GatewayHandler
)

func init() {
	gatewayHandler = new(GatewayHandler)
	gatewayHandler.Init()
}

func Setting(gate int) GatewaySetting {
	return gatewayHandler.Select(gate).Setting()
}

func Payload(gate int, numpber string, message string) interface{} {
	return gatewayHandler.Select(gate).Payload(gate, numpber, message)
}

func ParseUID(gate int, jsonBytes []byte) (uid string, err error) {
	return gatewayHandler.Select(gate).ParseUID(jsonBytes)
}

func Callback(gate int, jsonBytes []byte) {
	gatewayHandler.Select(gate).Callback(jsonBytes)
}

func UpdateTicketStatusSuccess(gate int, uid string) ([]*dbmodel.Ticket, error) {
	return db.UpdateTicketStatus(gate, uid, DELIVERED, "")
}

func UpdateTicketStatusFailure(gate int, uid string, errmsg string) ([]*dbmodel.Ticket, error) {
	return db.UpdateTicketStatus(gate, uid, FAILURE, errmsg)
}

func (handler *GatewayHandler) Init() {
	handler.gatewayMap = make(map[int]Gateway)

	handler.gatewayMap = map[int]Gateway{
		TATA:  reflect.New(reflect.TypeOf(GatewayTATA{})).Interface().(Gateway),
		NEXMO: reflect.New(reflect.TypeOf(GatewayNexmo{})).Interface().(Gateway),
	}
}

func (handler *GatewayHandler) Select(gate int) Gateway {
	return handler.gatewayMap[gate]
}
