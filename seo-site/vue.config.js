const { defineConfig } = require("@vue/cli-service");
const path = require("path");
const fs = require("fs");
const XLSX = require("xlsx");
const PrerenderSPAPlugin = require("@dreysolano/prerender-spa-plugin");

module.exports = defineConfig({
  transpileDependencies: true,
  productionSourceMap: false,
  configureWebpack: (config) => {
    if (process.env.NODE_ENV === "production") {
      let games = [];
      const file = fs.readFileSync("./public/data.xlsx");
      const workbook = XLSX.read(file, { type: "buffer" });
      const worksheet = workbook.Sheets["games"];
      games = XLSX.utils.sheet_to_json(worksheet);
      const gameRoutes = games
        .filter((game) => game.id != null)
        .map((game) => `/game/${game.id}`);
      const gameTypeRoutes = [2, 3, 5, 6, 15, 21].map(
        (gameTypeId) => `/game-type/${gameTypeId}`
      );

      config.plugins = (config.plugins || []).concat([
        new PrerenderSPAPlugin({
          staticDir: path.join(__dirname, "dist"),
          routes: ["/", ...gameTypeRoutes, ...gameRoutes],
          renderer: new PrerenderSPAPlugin.PuppeteerRenderer({
            maxConcurrentRoutes: 10,
            renderAfterElementExists: "#app",
            navigationOptions: {
              timeout: 0,
            }
          }),
        }),
      ]);
    }
  },
});
