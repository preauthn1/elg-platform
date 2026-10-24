package datetime

import (
	"fmt"
	"testing"
)

func Test_DateTimeParse(t *testing.T) {
	type args struct {
		s string
	}
	tests := []struct {
		name string
		args args
	}{
		// TODO: Add test cases.
		{"1", args{"aaaa"}},
		{"2", args{"2021-09-14"}},
		{"3", args{"2021-0914"}},
		{"4", args{"2021-09-14@01"}},
		{"5", args{"2021-09-14@11:00:12"}},
		{"6", args{"2021-09-14@1234455"}},
		{"7", args{"2021-09-14@zzzzzzz"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if testTime, err := DateTimeParse(tt.args.s); err != nil {
				fmt.Print(err.Error())
			} else {
				fmt.Print(testTime.String())
			}
		})
	}
}
