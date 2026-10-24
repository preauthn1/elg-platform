package database

import (
	"os"
	"reflect"
	"testing"
)

func TestMain(m *testing.M) {
	TestInit()
	exitVal := m.Run()
	os.Exit(exitVal)
}

func Test_sourceDSN(t *testing.T) {
	tests := []struct {
		name string
		want string
	}{
		// TODO: Add test cases.
		{"", "developer:!QAZ2wsxForXSGames_online@tcp(0.0.0.0:3306)/xsgcasino?charset=utf8&parseTime=True"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := sourceDSN(); got != tt.want {
				t.Errorf("sourceDSN() = %v, want %v", got, tt.want)
			}
		})
	}
}

func Test_replicasDSN(t *testing.T) {
	tests := []struct {
		name       string
		wantSlices []string
	}{
		// TODO: Add test cases.
		{"", []string{
			"developer:!QAZ2wsxForXSGames_online@tcp(0.0.0.0:3306)/xsgcasino?charset=utf8&parseTime=True",
			"developer:!QAZ2wsxForXSGames_online@tcp(0.0.0.0:3306)/xsgcasino?charset=utf8&parseTime=True"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if gotSlices := replicasDSN(); !reflect.DeepEqual(gotSlices, tt.wantSlices) {
				t.Errorf("replicasDSN() = %v, want %v", gotSlices, tt.wantSlices)
			}
		})
	}
}
