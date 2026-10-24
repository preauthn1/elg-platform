/*
Copyright © 2021 NAME HERE <EMAIL ADDRESS>

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://example.invalid/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/
package cmd

import (
	"fmt"
	"os"
	"time"

	"example.invalid/spf13/cobra"
	"example.invalid/target/gamedatacenter/internal/functions/datetime"

	homedir "example.invalid/mitchellh/go-homedir"
	"example.invalid/spf13/viper"
)

var cfgFile string
var start, end, date string
var interval, timezone int

var startTime, endTime time.Time

// rootCmd represents the base command when called without any subcommands
var rootCmd = &cobra.Command{
	Use:   "getBetRecords",
	Short: "A brief description of your application",
	Long: `A longer description that spans multiple lines and likely contains
examples and usage of using your application. For example:

Cobra is a CLI library for Go that empowers applications.
This application is a tool to generate the needed files
to quickly create a Cobra application.`,
	// Uncomment the following line if your bare application
	// has an action associated with it:
	//	Run: func(cmd *cobra.Command, args []string) { },
}

// Execute adds all child commands to the root command and sets flags appropriately.
// This is called by main.main(). It only needs to happen once to the rootCmd.
func Execute() {
	if err := rootCmd.Execute(); err != nil {
		fmt.Println(err)
		os.Exit(1)
	}
}

func init() {
	cobra.OnInitialize(initConfig)

	// Here you will define your flags and configuration settings.
	// Cobra supports persistent flags, which, if defined here,
	// will be global for your application.

	rootCmd.PersistentFlags().StringVar(&cfgFile, "config", "", "config file (default is $HOME/.getBetRecords.yaml)")

	rootCmd.PersistentFlags().StringVarP(&start, "start", "s", "", "start time string tz +8")
	rootCmd.PersistentFlags().StringVarP(&end, "end", "e", "", "end time string tz +8")
	rootCmd.PersistentFlags().StringVarP(&date, "date", "d", "", "date string tz +8")
	rootCmd.PersistentFlags().IntVarP(&interval, "interval", "i", 1, "set interval for start to end")
	rootCmd.PersistentFlags().IntVarP(&timezone, "timezone", "z", 8, "set timezone")

	// Cobra also supports local flags, which will only run
	// when this action is called directly.
	rootCmd.Flags().BoolP("toggle", "t", false, "Help message for toggle")

}

// initConfig reads in config file and ENV variables if set.
func initConfig() {
	viper.SetConfigName("getBetRecords")
	viper.SetConfigType("yaml")
	if cfgFile != "" {
		// Use config file from the flag.
		viper.SetConfigFile(cfgFile)
	} else {
		// Find home directory.
		home, err := homedir.Dir()
		if err != nil {
			fmt.Println(err)
			os.Exit(1)
		}

		// Search config in home directory with name ".getBetRecords" (without extension).
		viper.AddConfigPath(home)
		viper.AddConfigPath(".")
	}

	viper.AutomaticEnv() // read in environment variables that match

	// If a config file is found, read it in.
	if err := viper.ReadInConfig(); err == nil {
		fmt.Println("Using config file:", viper.ConfigFileUsed())
	}
}

// initDateOrTime 按傳入參數來決定開始及結束的日期
func initDateOrTime() {
	var err error
	datetime.SetFixedZone(timezone)
	if len(date) > 0 { //如果 date 有值則取一整天
		startTime, _ = datetime.DateTimeParse(date)
		endTime = startTime.Add(time.Hour * time.Duration(24))
		start = startTime.Format("2006-01-02@15:04:05")
		end = endTime.Format("2006-01-02@15:04:05")
	} else if len(start) > 0 { //如果只有 start 沒有 end 就取 start 後的一個小時
		startTime, _ = datetime.DateTimeParse(start)
		endTime, err = datetime.DateTimeParse(end)
		if err != nil {
			endTime = startTime.Add(time.Hour * time.Duration(interval))
		}
		end = endTime.Format("2006-01-02@15:04:05")
	} else { //都沒輸入就取前一小時
		endTime = time.Now()
		startTime = endTime.Add(-1 * time.Hour * time.Duration(interval))
		start = startTime.Format("2006-01-02@15:04:05")
		end = endTime.Format("2006-01-02@15:04:05")
	}
	fmt.Println("Begin", startTime.String(), "to", endTime.String())
}
