package mega888

import (
	"fmt"
	"testing"
	"time"

	"example.invalid/target/gamedatacenter/internal/functions/database"
)

//rJK4o17oVGm40O3yNATv

func TestHandler(t *testing.T) {
	start := time.Now()
	database.TestInit()
	// 設置 DEBUG 環境變量
	//os.Setenv("DEBUG", "true")

	type args struct {
		startTime time.Time
		endTime   time.Time
	}
	nt, _ := time.ParseInLocation("2006-01-02 15:04:05", "2025-08-27 00:00:00", time.Local)
	tests := []struct {
		name string
		args args
	}{
		// TODO: Add test cases.
		{"aaa", args{nt, nt.Add(time.Duration(24)*time.Hour - 1)}},
		//{"aaa", args{nt, nt.Add(time.Duration(1) * time.Hour)}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			// fmt.Println(tt.args.startTime, tt.args.endTime)
			// for i := 0; i < 24; i++ {
			// 	Handler(tt.args.startTime.Add(time.Duration(i)*time.Hour), tt.args.endTime.Add(time.Duration(i)*time.Hour))
			// 	fmt.Println(time.Since(start))
			// }
			Handler(tt.args.startTime, tt.args.endTime)
			fmt.Println(time.Since(start))
		})
	}
}
