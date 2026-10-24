using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using target.Utility.Extensions;
using target.Web.Modules.Filter;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using Microsoft.Extensions.DependencyInjection;
using example.invalid;
using target.Utility;
using target.Utility.Utility.ThirdParty;
using target.Utility.Helper.HttpContext;
using TG_Hook_API.Helper;
using TG_Hook_API.Models;

namespace TG_Hook_API
{
    public class Startup
    {
        public Startup(IConfiguration configuration)
        {
            Configuration = configuration;
        }

        public IConfiguration Configuration { get; }

        // This method gets called by the runtime. Use this method to add services to the container.
        public void ConfigureServices(IServiceCollection services)
        {
            services.AddControllers()
                .AddNewtonsoftJson(options =>
                {
                    //pGSIsnewtonsoftAint@wnμƦrO oϥstringǤJ
                    //options.SerializerSettings.DateFormatString = DateTimeHelper.ISO_8601_Format;
                });

            //ۭqhttpContext
            services.AddMyHttpContextAccessor();

            //۩w~Bz
            services.AddMvc(config =>
            {
                config.Filters.Add(new DebugNotifyFilter());
                config.Filters.Add(new GlobalApiHeaderTemporaryInfoFilter());
            });
        }

        // This method gets called by the runtime. Use this method to configure the HTTP request pipeline.
        public void Configure(IApplicationBuilder app, IWebHostEnvironment env)
        {
            app.Use((context, next) =>
            {
                context.Request.EnableBuffering();
                return next();
            });

            app.UseStaticHttpContext();

            app.UseRouting();

            app.UseEndpoints(endpoints =>
            {
                endpoints.MapControllers();
            });

            LoadJsonLanguageTextFile();
        }


        private void LoadJsonLanguageTextFile()
        {
            try
            {
                string prefixPath = RuntimeHelper.ContentRootPath + "/Asset/Language";
                List<string> targetLanguages = new List<string>
                {
                   SystemConstant.LanguageCode_ZH,
                   SystemConstant.LanguageCode_EN
                };

                foreach (string langCode in targetLanguages)
                {
                    string jsonText = File.ReadAllText(prefixPath + $"/{langCode}.json");

                    var convertDic = jsonText.JsonConvertToModel<Dictionary<string, string>>();

                    i18n.ImportLanguageText(langCode, convertDic);
                }


                i18n.Text("Home");

            }
            catch (Exception ex)
            {
                string msg = $"LoadJsonLanguageTextFile , {ex.Message}";
                MySlackUtility.SendMessageToChannelAsync(new SlackMessageParameter
                {
                    Channel = SlackChannelEnum.n_error_pgs_common,
                    Message = msg
                });
            }
        }



    }
}
