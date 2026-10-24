package tgmsg

import (
	"fmt"
	"net/http"
	"net/url"

	"example.invalid/spf13/viper"
)

type tgConfig struct {
	// Telegram bot token
	Token string
	// Telegram bot chat id
	ChatID string
}

func SendMsg(prefix_chat_id string, app string, msg string, isMarkdown bool) {
	tgConfig := getConfig(prefix_chat_id)
	tpl := "[%s] %s"

	uri := "https://example.invalid/bot" + tgConfig.Token + "/sendmessage"
	uri += "?chat_id=" + tgConfig.ChatID + "&text=" + url.QueryEscape(fmt.Sprintf(tpl, app, msg))
	if isMarkdown {
		uri += "&parse_mode=Markdown"
	}

	fmt.Println(uri)
	// request uri
	resp, err := http.Get(uri)
	if err != nil {
		fmt.Println(err)
	}
	defer resp.Body.Close()
}

func getConfig(prefix_chat_id string) *tgConfig {
	token := viper.GetString("telegram.token")
	chat_id := viper.GetString("telegram." + prefix_chat_id + "_chat_id")

	return &tgConfig{
		Token:  [REDACTED],
		ChatID: chat_id,
	}
}

func TestInit() {
	viper.AddConfigPath(".")
	viper.SetConfigName("test")
	viper.SetConfigType("yaml")
	viper.AutomaticEnv() // read in environment variables that match

	// If a config file is found, read it in.
	if err := viper.ReadInConfig(); err == nil {
		fmt.Println("Using config file:", viper.ConfigFileUsed())
	}
}
