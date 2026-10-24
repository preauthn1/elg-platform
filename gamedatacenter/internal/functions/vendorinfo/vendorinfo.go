package vendorinfo

import (
	"time"

	"example.invalid/shopspring/decimal"
	"example.invalid/target/gamedatacenter/internal/functions/database"
)

type VendorInfo struct {
	ID                    int32
	Name                  string
	Type                  int32
	VendorId              int32
	Currency              string
	Rate                  decimal.Decimal
	TransferMinAmount     decimal.Decimal
	Info                  string
	PreparedAccountNumber int32
	AccountPrefix         string
	Cost                  decimal.Decimal
	Status                int32
	CreatedAt             time.Time
	UpdatedAt             time.Time
	Admin                 int32
}

func GetVendorInfoList(vendorId int) (vendorInfoList []VendorInfo) {
	database.Connect()
	database.DB.Where("vendor_id = ? AND status = ?", vendorId, 1).Find(&vendorInfoList)
	return
}
