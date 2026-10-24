package vendorinfo

import (
	"testing"

	"example.invalid/target/gamedatacenter/internal/functions/database"
)

func TestGetVendorInfos(t *testing.T) {
	database.TestInit()
	type args struct {
		vendorId int
	}
	tests := []struct {
		name string
		args args
	}{
		// TODO: Add test cases.
		{"aaa", args{10}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			GetVendorInfoList(tt.args.vendorId)
		})
	}
}
