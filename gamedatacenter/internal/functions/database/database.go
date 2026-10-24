package database

import (
	"fmt"
	"log"
	"os"
	"time"

	"example.invalid/spf13/viper"
	"example.invalid/driver/mysql"
	"example.invalid/gorm"
	"example.invalid/gorm/logger"
	"example.invalid/plugin/dbresolver"
)

var DB *gorm.DB

func Connect() {
	newLogger := logger.New(
		log.New(os.Stdout, "\r\n", log.LstdFlags), // io writer
		logger.Config{
			SlowThreshold: time.Second,   // 慢 SQL 阈值
			LogLevel:      logger.Silent, // Log level
			Colorful:      false,         // 禁用彩色打印
		},
	)
	var err error
	DB, err = gorm.Open(mysql.Open(sourceDSN()), &gorm.Config{
		Logger: newLogger,
	})
	if err != nil {
		panic(err)
	}

	var replicasDialector []gorm.Dialector
	replicas := replicasDSN()
	for _, dsn := range replicas {
		replicasDialector = append(replicasDialector, mysql.Open(dsn))
	}
	DB.Use(dbresolver.Register(dbresolver.Config{
		// use `db2` as sources, `db3`, `db4` as replicas
		Sources:  []gorm.Dialector{mysql.Open(sourceDSN())},
		Replicas: replicasDialector,
		// sources/replicas load balancing policy
		Policy: dbresolver.RandomPolicy{},
	}))
}

func sourceDSN() string {
	dbname := viper.GetString("database.dbname")
	username := viper.GetString("database.write.username")
	password := viper.GetString("database.write.password")
	host := viper.GetString("database.write.host")
	port := viper.GetInt("database.write.port")
	return fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?charset=utf8&parseTime=True", username, password, host, port, dbname)
}

func replicasDSN() (slices []string) {
	var replicas []struct {
		Host     string
		Port     int
		Username string
		Password string
	}
	dbname := viper.GetString("database.dbname")
	viper.UnmarshalKey("database.read", &replicas)
	for _, replica := range replicas {
		slices = append(
			slices,
			fmt.Sprintf(
				"%s:%s@tcp(%s:%d)/%s?charset=utf8&parseTime=True",
				replica.Username,
				replica.Password,
				replica.Host,
				replica.Port,
				dbname))
	}
	return
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
