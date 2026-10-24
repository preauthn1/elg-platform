const fs = require("fs-extra");
const Handlebars = require("handlebars");

const templateSource = fs.readFileSync("./src/template.html", "utf8");
const template = Handlebars.compile(templateSource);
const pegasusSource = fs.readFileSync("./src/pe-x-pegasus.html", "utf8");
const pegasusTemplate = Handlebars.compile(pegasusSource);
const languages = require("./src/translations.json");

const outputDir = "./output";
if (fs.existsSync(outputDir)) {
  fs.emptyDirSync(outputDir);
} else {
  fs.mkdirSync(outputDir);
}

function copyAssets() {
  const sourceDirList = [
    "./src/css",
    "./src/images",
    "./src/js",
    "./src/videos",
  ];

  sourceDirList.forEach((sourceDir) => {
    const dirName = sourceDir.split("/").pop();
    const dirPath = `${outputDir}/${dirName}`;

    fs.copy(sourceDir, dirPath, (err) => {
      if (err) {
        console.error(err);
      }
    });
  });
}

for (let lang in languages) {
  const htmlOutput = template(languages[lang]);
  const pegasusLang = ['zh-CN', 'ms'].includes(lang) ? lang : (lang === "zh-TW" ? "zh-CN" : "en");
  const pegasusOutput = pegasusTemplate(languages[pegasusLang]);
  const langDir = `${outputDir}/${lang}`;
  const pegasusDir = `${langDir}/pe-x-pegasus`;

  if (!fs.existsSync(langDir)) {
    fs.mkdirSync(langDir);
  }
  if (!fs.existsSync(pegasusDir)) {
    fs.mkdirSync(pegasusDir);
  }

  fs.writeFileSync(`${langDir}/index.html`, htmlOutput);
  fs.writeFileSync(`${pegasusDir}/index.html`, pegasusOutput);
  if (lang === "en") {
    fs.writeFileSync(`${outputDir}/index.html`, htmlOutput);
    const pegasusRootDir = `${outputDir}/pe-x-pegasus`;
    if (!fs.existsSync(pegasusRootDir)) {
      fs.mkdirSync(pegasusRootDir);
    }
    fs.writeFileSync(`${pegasusRootDir}/index.html`, pegasusOutput);
  }
}

copyAssets();
