

echo off

echo -----------------------------------------------
echo -----------------------------------------------
echo (Warning*) ready to publish to production ...
echo -----------------------------------------------
echo -----------------------------------------------

timeout /t 10

git checkout master
@echo off
for /f "delims=" %%a in ('powershell get-date((get-date^)^) -format "yyyyMMddHHmm"') do set output_result=%%a
SET production_tags="_online_%output_result%"
echo %production_tags%

git tag %production_tags%
git push origin --tags
timeout /t 10

 
