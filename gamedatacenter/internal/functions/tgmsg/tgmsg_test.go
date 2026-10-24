package tgmsg

import (
	"fmt"
	"os"
	"testing"
)

func TestMain(m *testing.M) {
	TestInit()
	exitVal := m.Run()
	os.Exit(exitVal)
}

func TestGetConfig(t *testing.T) {
	tgConfig := getConfig("error")
	fmt.Println(tgConfig)
}

func TestSendMsg(t *testing.T) {
	SendMsg("error", "vpower", "test", false)
}
