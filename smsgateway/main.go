package main

import (
	"example.invalid/target/SMSGateway/controller"
	"example.invalid/target/SMSGateway/docs"

	swaggerFiles "example.invalid/swaggo/files"     // swagger embed files
	ginSwagger "example.invalid/swaggo/gin-swagger" // gin-swagger middleware

	"example.invalid/gin-contrib/cors"
	"example.invalid/gin-gonic/gin"
	"example.invalid/spf13/viper"

	"fmt"
	"os"
	"path"
)

// @contact.name API Support
// @contact.email user@example.invalid

// @x-extension-openapi {"SMSGateway": "value on a json format"}

func main() {

	loadConfig()

	var host = viper.GetString("host")
	var port = viper.GetString("port")
	if port == "" {
		port = "80"
	}

	// programatically set swagger info
	docs.SwaggerInfo.Title = "SMSGateway API"
	docs.SwaggerInfo.Description = "管理 SMS"
	docs.SwaggerInfo.Version = "1.0.0"
	docs.SwaggerInfo.Host = fmt.Sprintf("%s:%s", host, port)
	docs.SwaggerInfo.BasePath = "/api/v1"
	// docs.SwaggerInfo.Schemes = []string{"http", "https"}

	// // gin.DisableConsoleColor()

	// // Logging to a file.

	fmt.Println("debug: ", viper.GetBool("debug"))

	var router *gin.Engine
	if viper.GetBool("debug") {
		router = gin.Default()
		// router.Use(middleware.LoggerToFile())

	} else {
		gin.SetMode(gin.ReleaseMode)
		router = gin.New()
		router.Use(gin.Logger())
		router.Use(gin.Recovery())
	}

	// router.Use(middleware.LoggerToFile())

	// cors allows all origins
	router.Use(cors.Default())

	// route
	var c = controller.NewController()
	var v1 = router.Group("/api/v1")
	{
		var code = v1.Group("verification_code")
		{
			code.POST("send", c.SendVerificationCode)
		}

		var dlr = v1.Group("dlr")
		{
			dlr.GET("notice", c.NoticeGET)
			dlr.POST("notice", c.NoticePOST)
		}

		var ticket = v1.Group("ticket")
		{
			ticket.GET("/:uid", c.TicketGET)
		}

		// v1.GET("/allticket", c.AllTicketGET)

		// v1.POST("report", c.Report)
	}

	if viper.GetBool("showswagger") {
		router.GET("/swagger/*any", ginSwagger.WrapHandler(swaggerFiles.Handler))
		fmt.Println(fmt.Sprintf("swagger: http://%s:%s/swagger/index.html", host, port))
	}

	router.Run(fmt.Sprintf(":%s", port))
}

func loadConfig() {
	viper.AutomaticEnv() // read in environment variables that match

	pwd, err := os.Getwd()
	if err != nil {
		fmt.Println(err.Error())
		// os.Exit(1)
	}
	var configPath = path.Join(pwd, "config")
	viper.SetConfigType("yaml")

	// config.yaml
	viper.SetConfigName("config")
	viper.AddConfigPath(configPath)
	fmt.Println("configPath: ", configPath)
	if err := viper.ReadInConfig(); err == nil {
		fmt.Println("Load config.yaml: ", viper.ConfigFileUsed())
	} else {
		fmt.Println("Fatal error config file: ", err.Error())
	}

	// internal_config
	viper.SetConfigName("internal_config")
	viper.AddConfigPath(configPath)
	if err := viper.MergeInConfig(); err == nil {
		fmt.Println("Load internal_config.yaml: ", viper.ConfigFileUsed())
	}

}

//...
