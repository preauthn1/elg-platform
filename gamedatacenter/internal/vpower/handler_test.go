package vpower

import (
	"fmt"
	"testing"
	"time"

	"example.invalid/target/gamedatacenter/internal/functions/database"
)

func TestHandler(t *testing.T) {
	start := time.Now()
	database.TestInit()
	type args struct {
		startTime time.Time
		endTime   time.Time
	}
	//2023-11-23 19:49:19
	nt, _ := time.ParseInLocation("2006-01-02 15:04:05", "2024-07-11 17:00:00", loc)
	tests := []struct {
		name string
		args args
	}{
		// TODO: Add test cases.
		{"aaa", args{nt, nt.Add(time.Duration(60) * time.Minute)}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			Handler(tt.args.startTime, tt.args.endTime)
			fmt.Println(time.Since(start))
		})
	}
}

func TestStringTruthly(t *testing.T) {
	str_empty := ""

	if len(str_empty) > 0 {

	}

}

func TestRangeEmptyValue(t *testing.T) {
	var dicLoginID = map[string]int{}
	dicLoginID["1"] = 0
	dicLoginID["2"] = 0

	for _, v := range dicLoginID {
		fmt.Println(v)
	}
}
